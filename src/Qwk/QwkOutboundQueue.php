<?php

declare(strict_types=1);

namespace BinktermPHP\Qwk;

use BinktermPHP\Config;
use BinktermPHP\Database;
use PDO;

/**
 * Enqueues a locally-authored echomail message for QWKnet export.
 *
 * Call {@see enqueueForEchomail()} from the local posting path only (it is wired
 * from `MessageHandler::postEchomail()` / `approveEchomail()`). For every
 * `echo_area_qwk_subscriptions` row on the message's echo area, one
 * `qwk_outbound_messages` row is created (state `pending`) with a stable MSGID.
 *
 * Guardrails, so this stays safe even if it is ever called from elsewhere:
 *   - a message with a `qwk_inbound_messages` sidecar (imported from QWK) is
 *     never queued -- it must not be echoed back;
 *   - a message with `user_id IS NULL` (inbound FTN, or any non-local origin) is
 *     never queued -- this slice is not the FTN<->QWK relay;
 *   - `UNIQUE (mailbox_id, echomail_id)` plus `ON CONFLICT DO NOTHING` makes a
 *     repeat call a no-op.
 */
class QwkOutboundQueue
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getPdo();
    }

    /**
     * @return int number of mailbox queue rows created
     */
    public function enqueueForEchomail(int $echomailId): int
    {
        if ($echomailId <= 0) {
            return 0;
        }

        $stmt = $this->db->prepare('
            SELECT e.id, e.echoarea_id, e.user_id, e.message_id, e.subject,
                   EXISTS (SELECT 1 FROM qwk_inbound_messages s WHERE s.echomail_id = e.id) AS is_imported
            FROM echomail e
            WHERE e.id = ?
        ');
        $stmt->execute([$echomailId]);
        $message = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$message) {
            return 0;
        }
        if (!empty($message['is_imported'])) {
            return 0; // imported QWK message -- never re-export
        }
        if ($message['user_id'] === null) {
            return 0; // not locally authored -- not this slice's job
        }

        $subs = $this->db->prepare('
            SELECT mailbox_id, conference_number
            FROM echo_area_qwk_subscriptions
            WHERE echoarea_id = ?
        ');
        $subs->execute([(int)$message['echoarea_id']]);
        $subscriptions = $subs->fetchAll(PDO::FETCH_ASSOC);
        if ($subscriptions === []) {
            return 0;
        }

        $msgid = self::stableMsgid($message);
        $insert = $this->db->prepare('
            INSERT INTO qwk_outbound_messages (mailbox_id, echomail_id, conference_number, msgid)
            VALUES (?, ?, ?, ?)
            ON CONFLICT (mailbox_id, echomail_id) DO NOTHING
        ');

        $created = 0;
        foreach ($subscriptions as $sub) {
            $insert->execute([
                (int)$sub['mailbox_id'],
                $echomailId,
                (int)$sub['conference_number'],
                $msgid,
            ]);
            $created += $insert->rowCount();
        }

        return $created;
    }

    /**
     * The MSGID exported for this message. Reuse the FTN MSGID the local post
     * already carries; otherwise mint a deterministic one from the row id so it
     * is identical on every REP (re)build.
     *
     * @param array<string,mixed> $message
     */
    private static function stableMsgid(array $message): string
    {
        $existing = trim((string)($message['message_id'] ?? ''));
        if ($existing !== '') {
            return $existing;
        }

        $host = parse_url((string)Config::getSiteUrl(), PHP_URL_HOST) ?: 'binkterm.local';

        return sprintf('<qwk.%s@%s>', dechex((int)$message['id']), $host);
    }
}
