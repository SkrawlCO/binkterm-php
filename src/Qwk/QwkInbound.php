<?php

declare(strict_types=1);

namespace BinktermPHP\Qwk;

use BinktermPHP\Database;
use BinktermPHP\QwkNet\Message;
use BinktermPHP\QwkNet\Packet;
use BinktermPHP\QwkNet\Parser;
use PDO;

/**
 * Imports a locally-obtained QWK archive into existing, explicitly-mapped
 * BinkTermPHP echo areas.
 *
 * Scope of this slice: local file only. There is no FTP, no polling, no
 * outbound REP, no scheduler, and no relay/gating. The archive is parsed by the
 * committed hardened M1 parser ({@see \BinktermPHP\QwkNet\Parser}); this class
 * only validates identity, resolves mappings, deduplicates, and stores.
 *
 * Storage rules for imported messages (a remote network post, never a local one):
 *   - inserted straight into `echomail` with `user_id` NULL and the default
 *     `moderation_status` ('approved'); no local author is attributed;
 *   - `from_address` is the synthetic, non-FTN token `qwk:<BBSID>` -- it parses
 *     to the null FTN address (0:0/0) so no FTN spool/export path can mistake it
 *     for a real node;
 *   - the FTN outbound spool, hub fan-out, and uplink relay are never invoked,
 *     so an imported message is stored and visible but not retransmitted;
 *   - QWK provenance (MSGID / REPLY / VIA / TZ / record offset / remote message
 *     number) is written to the `qwk_inbound_messages` sidecar, which also
 *     marks the row as network-imported for {@see \BinktermPHP\MessageHandler}
 *     so a coincidental display-name match cannot confer delete ownership.
 *
 * Concept adapted from the old upstream `src/Qwk/QwkInbound.php`; the upstream
 * `QwkPacketParser`, `getOrCreateSubscriptionForConference()` auto-creation,
 * `importExternalEchomail()` relay-policy path, and provenance-columns-on-echomail
 * approach are deliberately not carried over.
 */
class QwkInbound
{
    /** echomail.message_id is VARCHAR(100); the full id is always kept in the sidecar. */
    private const ECHOMAIL_MSGID_MAX = 100;

    /** Bound for the reply-chain cycle check; not a general resolver. */
    private const CYCLE_WALK_LIMIT = 32;

    private PDO $db;
    private Parser $parser;

    public function __construct(?PDO $db = null, ?Parser $parser = null)
    {
        $this->db = $db ?? Database::getInstance()->getPdo();
        $this->parser = $parser ?? new Parser();
    }

    /**
     * @return array{
     *   status: string, mailbox_id: int, bbs_id: string, archive_sha256: string,
     *   messages_parsed: int, imported: int, skipped: int,
     *   skipped_unmapped: int, skipped_duplicate: int,
     *   unmapped_conferences: array<int,int>, reply_links: int, backfilled: int,
     *   packet_id: int
     * }
     * @throws QwkImportException  unknown mailbox, or CONTROL.DAT identity mismatch
     * @throws \RuntimeException    malformed packet (from the parser)
     */
    public function importLocalPacket(int $mailboxId, string $packetPath): array
    {
        $mailbox = $this->loadMailbox($mailboxId);
        $expectedBbsId = self::normalizeBbsId((string)$mailbox['bbs_id']);

        // 1. Parse and validate entirely outside any DB transaction.
        $packet = $this->parser->parse($packetPath);
        $packetBbsId = self::normalizeBbsId((string)($packet->control['packet_id'] ?? ''));
        if ($packetBbsId === '' || $packetBbsId !== $expectedBbsId) {
            throw new QwkImportException(sprintf(
                'Packet identity "%s" does not match mailbox %d ("%s"); nothing was imported.',
                $packetBbsId !== '' ? $packetBbsId : '(none)',
                $mailboxId,
                $expectedBbsId
            ));
        }

        // 2. Resolve conference -> echoarea from explicit subscriptions only.
        $subscriptions = $this->loadSubscriptions($mailboxId); // [conf => echoarea_id]

        $summary = [
            'status' => 'imported',
            'mailbox_id' => $mailboxId,
            'bbs_id' => $expectedBbsId,
            'archive_sha256' => $packet->sha256,
            'messages_parsed' => count($packet->messages),
            'imported' => 0,
            'skipped' => 0,
            'skipped_unmapped' => 0,
            'skipped_duplicate' => 0,
            'unmapped_conferences' => [],
            'reply_links' => 0,
            'backfilled' => 0,
            'packet_id' => 0,
        ];

        $this->db->beginTransaction();
        try {
            // 3. Claim the replay-ledger row. A byte-identical re-import conflicts here.
            $packetId = $this->claimPacketLedger($mailboxId, $packet);
            if ($packetId === null) {
                $existing = $this->existingPacketLedger($mailboxId, $packet->sha256);
                $this->db->commit();

                return array_merge($summary, [
                    'status' => 'already_imported',
                    'packet_id' => (int)($existing['id'] ?? 0),
                    'imported' => 0,
                    'skipped' => $summary['messages_parsed'],
                    'skipped_duplicate' => $summary['messages_parsed'],
                    'originally_imported' => (int)($existing['message_count'] ?? 0),
                ]);
            }
            $summary['packet_id'] = $packetId;

            /** @var array<int,int> $touchedAreas  echoarea_id => count imported */
            $touchedAreas = [];
            $touchedConferences = [];
            $lastPerArea = []; // echoarea_id => ['subject'=>, 'from'=>]

            // 4-5. Insert eligible new messages + sidecars.
            foreach ($packet->messages as $message) {
                $conference = (int)$message->header['conference'];
                if (!array_key_exists($conference, $subscriptions)) {
                    $summary['skipped']++;
                    $summary['skipped_unmapped']++;
                    $summary['unmapped_conferences'][$conference] =
                        ($summary['unmapped_conferences'][$conference] ?? 0) + 1;
                    continue;
                }

                $echoareaId = $subscriptions[$conference];
                $dedupeKey = self::dedupeKey($message);

                if ($this->messageExists($mailboxId, $conference, $dedupeKey)) {
                    $summary['skipped']++;
                    $summary['skipped_duplicate']++;
                    continue;
                }

                $replyToId = $this->resolveReplyTo($mailboxId, $conference, $message->externalReplyId);
                $echomailId = $this->insertEchomail($echoareaId, $expectedBbsId, $message, $replyToId);
                $this->insertSidecar($echomailId, $packetId, $mailboxId, $conference, $dedupeKey, $message);

                $summary['imported']++;
                if ($replyToId !== null) {
                    $summary['reply_links']++;
                }
                $touchedAreas[$echoareaId] = ($touchedAreas[$echoareaId] ?? 0) + 1;
                $touchedConferences[$conference] = true;
                $lastPerArea[$echoareaId] = [
                    'subject' => $message->subject !== '' ? $message->subject : '(no subject)',
                    'from' => $message->sender !== '' ? $message->sender : 'Unknown',
                ];
            }

            // 6. One bounded reply backfill pass over the conferences this packet touched.
            $summary['backfilled'] = $this->backfillReplies($mailboxId, array_keys($touchedConferences));

            // 7. Finalise counts.
            $this->db->prepare('
                UPDATE qwk_inbound_packets SET message_count = ?, skipped_count = ? WHERE id = ?
            ')->execute([$summary['imported'], $summary['skipped'], $packetId]);

            foreach ($touchedAreas as $echoareaId => $count) {
                $this->db->prepare('
                    UPDATE echoareas
                    SET message_count     = COALESCE(message_count, 0) + ?,
                        last_post_subject = ?,
                        last_post_author  = ?,
                        last_post_date    = NOW()
                    WHERE id = ?
                ')->execute([
                    $count,
                    mb_substr($lastPerArea[$echoareaId]['subject'], 0, 255),
                    mb_substr($lastPerArea[$echoareaId]['from'], 0, 100),
                    $echoareaId,
                ]);
            }

            // 8. Commit.
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        $summary['unmapped_conferences'] = array_map('intval', $summary['unmapped_conferences']);

        return $summary;
    }

    // --- identity / mapping ---------------------------------------------------

    /** @return array<string,mixed> */
    private function loadMailbox(int $mailboxId): array
    {
        $stmt = $this->db->prepare('SELECT id, name, bbs_id FROM qwk_mailboxes WHERE id = ?');
        $stmt->execute([$mailboxId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new QwkImportException('QWK mailbox ' . $mailboxId . ' not found');
        }

        return $row;
    }

    /** @return array<int,int> conference_number => echoarea_id */
    private function loadSubscriptions(int $mailboxId): array
    {
        $stmt = $this->db->prepare('
            SELECT conference_number, echoarea_id
            FROM echo_area_qwk_subscriptions
            WHERE mailbox_id = ?
        ');
        $stmt->execute([$mailboxId]);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(int)$row['conference_number']] = (int)$row['echoarea_id'];
        }

        return $map;
    }

    private static function normalizeBbsId(string $value): string
    {
        return strtoupper(substr((string)preg_replace('/[^A-Za-z0-9]/', '', trim($value)), 0, 8));
    }

    private static function dedupeKey(Message $message): string
    {
        if ($message->externalMessageId !== null && trim($message->externalMessageId) !== '') {
            return 'msgid:' . trim($message->externalMessageId);
        }

        return 'qwknum:' . (int)$message->header['number'];
    }

    // --- replay ledger -------------------------------------------------------

    private function claimPacketLedger(int $mailboxId, Packet $packet): ?int
    {
        $stmt = $this->db->prepare('
            INSERT INTO qwk_inbound_packets (mailbox_id, archive_sha256, packet_bbs_id, packet_user)
            VALUES (?, ?, ?, ?)
            ON CONFLICT (mailbox_id, archive_sha256) DO NOTHING
            RETURNING id
        ');
        $stmt->execute([
            $mailboxId,
            $packet->sha256,
            self::normalizeBbsId((string)($packet->control['packet_id'] ?? '')),
            mb_substr(trim((string)($packet->control['user'] ?? '')), 0, 64) ?: null,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? (int)$row['id'] : null;
    }

    /** @return array<string,mixed>|null */
    private function existingPacketLedger(int $mailboxId, string $sha256): ?array
    {
        $stmt = $this->db->prepare('
            SELECT id, message_count, skipped_count
            FROM qwk_inbound_packets
            WHERE mailbox_id = ? AND archive_sha256 = ?
        ');
        $stmt->execute([$mailboxId, $sha256]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // --- message storage ---------------------------------------------------

    private function messageExists(int $mailboxId, int $conference, string $dedupeKey): bool
    {
        $stmt = $this->db->prepare('
            SELECT 1 FROM qwk_inbound_messages
            WHERE mailbox_id = ? AND conference_number = ? AND dedupe_key = ?
            LIMIT 1
        ');
        $stmt->execute([$mailboxId, $conference, $dedupeKey]);

        return (bool)$stmt->fetchColumn();
    }

    private function insertEchomail(int $echoareaId, string $bbsId, Message $message, ?int $replyToId): int
    {
        $written = null;
        if ($message->writtenTimestamp !== null) {
            // M1 emits "Y-m-d\TH:i:s\Z" only when it has an explicit, cross-checked offset.
            $written = str_replace(['T', 'Z'], [' ', ''], $message->writtenTimestamp);
        }

        $stmt = $this->db->prepare('
            INSERT INTO echomail
                (echoarea_id, from_address, from_name, to_name, subject, message_text,
                 message_charset, date_written, reply_to_id, message_id, user_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)
            RETURNING id
        ');
        $stmt->execute([
            $echoareaId,
            'qwk:' . $bbsId,
            self::clip($message->sender !== '' ? $message->sender : 'Unknown', 100),
            self::clip($message->recipient !== '' ? $message->recipient : 'All', 100),
            self::clip($message->subject !== '' ? $message->subject : '(no subject)', 255),
            $message->displayBody,
            'CP437',
            $written,
            $replyToId,
            $message->externalMessageId !== null
                ? self::clip($message->externalMessageId, self::ECHOMAIL_MSGID_MAX)
                : null,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? (int)$row['id'] : 0;
    }

    private function insertSidecar(
        int $echomailId,
        int $packetId,
        int $mailboxId,
        int $conference,
        string $dedupeKey,
        Message $message
    ): void {
        $via = $message->via !== [] ? implode(',', array_map('strval', $message->via)) : null;
        $tzToken = isset($message->timezone['token']) && $message->timezone['token'] !== null
            ? substr((string)$message->timezone['token'], 0, 16)
            : null;

        $this->db->prepare('
            INSERT INTO qwk_inbound_messages
                (echomail_id, inbound_packet_id, mailbox_id, conference_number,
                 qwk_message_number, record_offset, external_msgid, external_reply_id,
                 via, tz_token, dedupe_key)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ')->execute([
            $echomailId,
            $packetId,
            $mailboxId,
            $conference,
            (int)$message->header['number'],
            (int)$message->header['offset'],
            $message->externalMessageId,
            $message->externalReplyId,
            $via,
            $tzToken,
            $dedupeKey,
        ]);
    }

    // --- reply linking ---------------------------------------------------

    private function resolveReplyTo(int $mailboxId, int $conference, ?string $externalReplyId): ?int
    {
        $externalReplyId = $externalReplyId !== null ? trim($externalReplyId) : '';
        if ($externalReplyId === '') {
            return null;
        }

        $stmt = $this->db->prepare('
            SELECT echomail_id FROM qwk_inbound_messages
            WHERE mailbox_id = ? AND conference_number = ? AND dedupe_key = ?
            LIMIT 1
        ');
        $stmt->execute([$mailboxId, $conference, 'msgid:' . $externalReplyId]);
        $parentId = $stmt->fetchColumn();

        return $parentId ? (int)$parentId : null;
    }

    /** @param array<int,int> $conferences */
    private function backfillReplies(int $mailboxId, array $conferences): int
    {
        if ($conferences === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($conferences), '?'));
        $stmt = $this->db->prepare("
            SELECT s.echomail_id, s.conference_number, s.external_reply_id
            FROM qwk_inbound_messages s
            JOIN echomail e ON e.id = s.echomail_id
            WHERE s.mailbox_id = ?
              AND s.conference_number IN ($placeholders)
              AND s.external_reply_id IS NOT NULL
              AND e.reply_to_id IS NULL
        ");
        $stmt->execute(array_merge([$mailboxId], array_map('intval', $conferences)));
        $unresolved = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $linked = 0;
        foreach ($unresolved as $row) {
            $childId = (int)$row['echomail_id'];
            $parentId = $this->resolveReplyTo(
                $mailboxId,
                (int)$row['conference_number'],
                (string)$row['external_reply_id']
            );
            if ($parentId === null || $parentId === $childId) {
                continue; // no parent yet, or a self-link
            }
            if ($this->wouldCycle($childId, $parentId)) {
                continue;
            }

            $updated = $this->db->prepare('
                UPDATE echomail SET reply_to_id = ? WHERE id = ? AND reply_to_id IS NULL
            ');
            $updated->execute([$parentId, $childId]);
            $linked += $updated->rowCount();
        }

        return $linked;
    }

    /** Bounded walk up the reply chain from $parentId; true if it reaches $childId. */
    private function wouldCycle(int $childId, int $parentId): bool
    {
        $seen = [];
        $cursor = $parentId;
        for ($i = 0; $i < self::CYCLE_WALK_LIMIT && $cursor > 0; $i++) {
            if ($cursor === $childId) {
                return true;
            }
            if (isset($seen[$cursor])) {
                return true; // pre-existing loop; do not extend it
            }
            $seen[$cursor] = true;

            $stmt = $this->db->prepare('SELECT reply_to_id FROM echomail WHERE id = ?');
            $stmt->execute([$cursor]);
            $next = $stmt->fetchColumn();
            $cursor = $next ? (int)$next : 0;
        }

        return false;
    }

    private static function clip(string $value, int $max): string
    {
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
    }
}
