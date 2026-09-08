<?php

declare(strict_types=1);

namespace BinktermPHP\Qwk;

use BinktermPHP\Database;
use PDO;

/**
 * Turns the `qwk_outbound_messages` queue for one mailbox into a `<BBSID>.REP`
 * archive and manages its `qwk_rep_batches` lifecycle.
 *
 * Retry model: a batch's message set is frozen the moment it is created (its
 * rows go `pending` -> `batched`). Rebuilding a `built` or `failed` batch reads
 * exactly those rows and produces a semantically identical REP. An
 * `upload_attempted` batch (a STOR issued but never confirmed) is left for an
 * operator -- it is never rebuilt or re-uploaded automatically.
 */
class QwkOutbound
{
    private PDO $db;
    private RepPacketBuilder $builder;

    public function __construct(?PDO $db = null, ?RepPacketBuilder $builder = null)
    {
        $this->db = $db ?? Database::getInstance()->getPdo();
        $this->builder = $builder ?? new RepPacketBuilder();
    }

    /**
     * Return the batch that should be uploaded next for this mailbox, building a
     * new one from pending rows if required. Returns null when there is nothing
     * to send.
     *
     * @return array{batch_id:int, path:string, message_count:int, archive_sha256:string, rebuilt:bool}|null
     * @throws \RuntimeException on an `upload_attempted` batch that needs a human
     */
    public function prepareBatch(int $mailboxId): ?array
    {
        $bbsId = $this->mailboxBbsId($mailboxId);

        $uncertain = $this->latestBatchInState($mailboxId, 'upload_attempted');
        if ($uncertain !== null) {
            throw new \RuntimeException(sprintf(
                'QWK mailbox %d has batch %d in an uncertain (upload_attempted) state; '
                . 'an operator must confirm or clear it before further polling.',
                $mailboxId,
                (int)$uncertain['id']
            ));
        }

        // Reuse an un-uploaded batch (built or failed) before creating a new one.
        $existing = $this->latestBatchInState($mailboxId, 'built')
            ?? $this->latestBatchInState($mailboxId, 'failed');
        if ($existing !== null) {
            $rows = $this->batchedRows($mailboxId, (int)$existing['id']);
            if ($rows === []) {
                // Nothing left attached (rows deleted) -> retire the batch.
                $this->setBatchState((int)$existing['id'], 'failed', 'batch had no messages');
                return $this->prepareBatch($mailboxId);
            }
            $path = $this->buildArchive($bbsId, $rows);
            $sha = hash_file('sha256', $path);
            $this->db->prepare('UPDATE qwk_rep_batches SET state = ?, archive_sha256 = ?, last_error = NULL WHERE id = ?')
                ->execute(['built', $sha, (int)$existing['id']]);

            return [
                'batch_id' => (int)$existing['id'],
                'path' => $path,
                'message_count' => count($rows),
                'archive_sha256' => $sha,
                'rebuilt' => true,
            ];
        }

        // Create a new batch from pending rows.
        $this->db->beginTransaction();
        try {
            $pending = $this->db->prepare('
                SELECT id FROM qwk_outbound_messages
                WHERE mailbox_id = ? AND state = ?
                ORDER BY id
                FOR UPDATE
            ');
            $pending->execute([$mailboxId, 'pending']);
            $ids = $pending->fetchAll(PDO::FETCH_COLUMN);
            if ($ids === []) {
                $this->db->commit();
                return null;
            }

            $batchStmt = $this->db->prepare('
                INSERT INTO qwk_rep_batches (mailbox_id, state, message_count) VALUES (?, ?, ?)
                RETURNING id
            ');
            $batchStmt->execute([$mailboxId, 'built', count($ids)]);
            $batchId = (int)$batchStmt->fetch(PDO::FETCH_ASSOC)['id'];

            $in = implode(',', array_fill(0, count($ids), '?'));
            $this->db->prepare("
                UPDATE qwk_outbound_messages
                SET state = 'batched', batch_id = ?, batched_at = NOW(), last_error = NULL
                WHERE id IN ($in)
            ")->execute(array_merge([$batchId], array_map('intval', $ids)));

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        $rows = $this->batchedRows($mailboxId, $batchId);
        try {
            $path = $this->buildArchive($bbsId, $rows);
        } catch (\Throwable $e) {
            // Build failed: release the rows and drop the empty batch so the next
            // attempt starts clean rather than inheriting a broken batch.
            $this->db->prepare("
                UPDATE qwk_outbound_messages
                SET state = 'pending', batch_id = NULL, batched_at = NULL
                WHERE batch_id = ?
            ")->execute([$batchId]);
            $this->db->prepare('DELETE FROM qwk_rep_batches WHERE id = ?')->execute([$batchId]);
            throw $e;
        }
        $sha = hash_file('sha256', $path);
        $this->db->prepare('UPDATE qwk_rep_batches SET archive_sha256 = ? WHERE id = ?')->execute([$sha, $batchId]);

        return [
            'batch_id' => $batchId,
            'path' => $path,
            'message_count' => count($rows),
            'archive_sha256' => $sha,
            'rebuilt' => false,
        ];
    }

    public function markUploadAttempted(int $batchId): void
    {
        $this->db->prepare("
            UPDATE qwk_rep_batches
            SET state = 'upload_attempted', upload_attempted_at = NOW()
            WHERE id = ? AND state IN ('built', 'failed')
        ")->execute([$batchId]);
    }

    public function markUploaded(int $batchId): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->prepare("
                UPDATE qwk_rep_batches
                SET state = 'uploaded', uploaded_at = NOW(), last_error = NULL
                WHERE id = ?
            ")->execute([$batchId]);
            $this->db->prepare("
                UPDATE qwk_outbound_messages
                SET state = 'sent', sent_at = NOW()
                WHERE batch_id = ? AND state = 'batched'
            ")->execute([$batchId]);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function markFailed(int $batchId, string $error): void
    {
        $this->db->prepare("
            UPDATE qwk_rep_batches SET state = 'failed', last_error = ? WHERE id = ?
        ")->execute([mb_substr($error, 0, 2000), $batchId]);
    }

    public function pendingCount(int $mailboxId): int
    {
        $stmt = $this->db->prepare("SELECT count(*) FROM qwk_outbound_messages WHERE mailbox_id = ? AND state = 'pending'");
        $stmt->execute([$mailboxId]);

        return (int)$stmt->fetchColumn();
    }

    // --- internals ---------------------------------------------------------

    /** @param array<int,array<string,mixed>> $rows */
    private function buildArchive(string $bbsId, array $rows): string
    {
        $messages = [];
        foreach ($rows as $row) {
            $messages[] = [
                'conference_number' => (int)$row['conference_number'],
                'from_name' => (string)$row['from_name'],
                'to_name' => (string)($row['to_name'] ?? 'All'),
                'subject' => (string)($row['subject'] ?? '(no subject)'),
                'body' => (string)($row['message_text'] ?? ''),
                'written_at' => (string)($row['written_at'] ?? ''),
                'msgid' => $row['msgid'] !== null ? (string)$row['msgid'] : null,
                'reply_msgid' => $row['reply_msgid'] !== null ? (string)$row['reply_msgid'] : null,
                'reply_to_num' => $row['reply_to_num'] !== null ? (int)$row['reply_to_num'] : 0,
            ];
        }

        return $this->builder->build($bbsId, $messages);
    }

    /** @return array<int,array<string,mixed>> queued rows joined to their echomail, in queue order */
    private function batchedRows(int $mailboxId, int $batchId): array
    {
        $stmt = $this->db->prepare('
            SELECT q.id, q.conference_number, q.msgid,
                   e.from_name, e.to_name, e.subject, e.message_text,
                   COALESCE(e.date_written, e.date_received) AS written_at,
                   parent.message_id AS parent_message_id,
                   parent_side.external_msgid AS parent_external_msgid,
                   parent_side.qwk_message_number AS reply_to_num
            FROM qwk_outbound_messages q
            JOIN echomail e ON e.id = q.echomail_id
            LEFT JOIN echomail parent ON parent.id = e.reply_to_id
            LEFT JOIN qwk_inbound_messages parent_side
                   ON parent_side.echomail_id = e.reply_to_id
                  AND parent_side.mailbox_id = q.mailbox_id
                  AND parent_side.conference_number = q.conference_number
            WHERE q.mailbox_id = ? AND q.batch_id = ? AND q.state = ?
            ORDER BY q.id
        ');
        $stmt->execute([$mailboxId, $batchId, 'batched']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as &$row) {
            // Prefer the parent's own MSGID; fall back to a QWK-imported parent's external one.
            $row['reply_msgid'] = $row['parent_message_id'] ?: $row['parent_external_msgid'];
            // reply_to_num only means anything when the parent was imported from this same mailbox+conference.
            $row['reply_to_num'] = $row['reply_to_num'] !== null ? (int)$row['reply_to_num'] : null;
        }
        unset($row);

        return $rows;
    }

    /** @return array<string,mixed>|null */
    private function latestBatchInState(int $mailboxId, string $state): ?array
    {
        $stmt = $this->db->prepare('
            SELECT * FROM qwk_rep_batches
            WHERE mailbox_id = ? AND state = ?
            ORDER BY id DESC LIMIT 1
        ');
        $stmt->execute([$mailboxId, $state]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function setBatchState(int $batchId, string $state, ?string $error = null): void
    {
        $this->db->prepare('UPDATE qwk_rep_batches SET state = ?, last_error = ? WHERE id = ?')
            ->execute([$state, $error, $batchId]);
    }

    private function mailboxBbsId(int $mailboxId): string
    {
        $stmt = $this->db->prepare('SELECT bbs_id FROM qwk_mailboxes WHERE id = ?');
        $stmt->execute([$mailboxId]);
        $bbsId = $stmt->fetchColumn();
        if ($bbsId === false) {
            throw new \InvalidArgumentException('QWK mailbox ' . $mailboxId . ' not found');
        }

        return (string)$bbsId;
    }
}
