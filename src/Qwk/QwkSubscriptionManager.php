<?php

declare(strict_types=1);

namespace BinktermPHP\Qwk;

use BinktermPHP\Database;
use PDO;
use PDOException;

/**
 * CRUD and lookups for echoarea to remote QWK conference mappings
 * (`echo_area_qwk_subscriptions`).
 *
 * A subscription is an explicit operator decision that a local echoarea
 * exchanges messages with one conference on one {@see QwkMailboxManager}
 * mailbox. This class never creates echoareas and never creates subscriptions
 * implicitly — the upstream `getOrCreateSubscriptionForConference()` /
 * `createQwkPlaceholderArea()` behaviour is deliberately not forward-ported.
 *
 * `conference_number` here is the *remote peer's* conference number, taken from
 * that peer's CONTROL.DAT. It is unrelated to `echoareas.qwk_conference_number`,
 * which is this BBS's own offline-reader numbering handled by
 * {@see QwkConferenceNumberManager}.
 *
 * Forward-ported and adapted from the upstream `qwknet` branch
 * (`src/Qwk/QwkSubscriptionManager.php`).
 */
class QwkSubscriptionManager
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getPdo();
    }

    /**
     * Create one explicit subscription.
     *
     * @param int         $echoareaId       existing echoarea
     * @param int         $mailboxId        existing qwk_mailboxes row
     * @param int         $conferenceNumber remote conference number (>= 0)
     * @param string|null $conferenceTag    optional remote conference label
     * @return int new subscription id
     * @throws \InvalidArgumentException on bad input, unknown FK, or a duplicate
     */
    public function create(int $echoareaId, int $mailboxId, int $conferenceNumber, ?string $conferenceTag = null): int
    {
        if ($echoareaId <= 0 || $mailboxId <= 0) {
            throw new \InvalidArgumentException('A valid echoarea and mailbox are required');
        }
        if ($conferenceNumber < 0) {
            throw new \InvalidArgumentException('Conference number must be zero or positive');
        }

        $tag = $conferenceTag !== null ? trim($conferenceTag) : '';
        try {
            $stmt = $this->db->prepare('
                INSERT INTO echo_area_qwk_subscriptions
                    (echoarea_id, mailbox_id, conference_number, conference_tag, created_at)
                VALUES (?, ?, ?, ?, NOW())
                RETURNING id
            ');
            $stmt->execute([$echoareaId, $mailboxId, $conferenceNumber, $tag !== '' ? $tag : null]);
        } catch (PDOException $e) {
            throw self::translate($e);
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? (int)$row['id'] : 0;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM echo_area_qwk_subscriptions WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM echo_area_qwk_subscriptions WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSubscriptionForConference(int $mailboxId, int $conferenceNumber): ?array
    {
        $stmt = $this->db->prepare('
            SELECT s.*, e.tag, e.domain, e.is_local, e.uplink_address
            FROM echo_area_qwk_subscriptions s
            JOIN echoareas e ON e.id = s.echoarea_id
            WHERE s.mailbox_id = ? AND s.conference_number = ?
            LIMIT 1
        ');
        $stmt->execute([$mailboxId, $conferenceNumber]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getSubscriptionsForArea(int $echoareaId): array
    {
        $stmt = $this->db->prepare('
            SELECT s.id, s.echoarea_id, s.mailbox_id, s.conference_number, s.conference_tag, s.created_at,
                   m.name AS mailbox_name, m.bbs_id AS mailbox_bbs_id, m.enabled AS mailbox_enabled
            FROM echo_area_qwk_subscriptions s
            JOIN qwk_mailboxes m ON m.id = s.mailbox_id
            WHERE s.echoarea_id = ?
            ORDER BY LOWER(m.name), s.conference_number
        ');
        $stmt->execute([$echoareaId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getSubscriptionsForMailbox(int $mailboxId): array
    {
        $stmt = $this->db->prepare('
            SELECT s.id, s.echoarea_id, s.mailbox_id, s.conference_number, s.conference_tag, s.created_at,
                   e.tag, e.domain, e.is_local, e.uplink_address
            FROM echo_area_qwk_subscriptions s
            JOIN echoareas e ON e.id = s.echoarea_id
            WHERE s.mailbox_id = ?
            ORDER BY s.conference_number, e.id
        ');
        $stmt->execute([$mailboxId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Replace the full subscription set for one echoarea in a single transaction.
     * Every entry must name an existing mailbox; nothing is auto-created.
     *
     * @param array<int, array<string, mixed>> $subscriptions each: mailbox_id, conference_number, conference_tag?
     * @throws \InvalidArgumentException
     */
    public function replaceAreaSubscriptions(int $echoareaId, array $subscriptions): void
    {
        if ($echoareaId <= 0) {
            throw new \InvalidArgumentException('A valid echoarea is required');
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM echo_area_qwk_subscriptions WHERE echoarea_id = ?')
                ->execute([$echoareaId]);

            foreach ($subscriptions as $subscription) {
                $mailboxId = (int)($subscription['mailbox_id'] ?? 0);
                $conferenceNumber = (int)($subscription['conference_number'] ?? -1);
                $conferenceTag = isset($subscription['conference_tag']) ? (string)$subscription['conference_tag'] : null;
                $this->create($echoareaId, $mailboxId, $conferenceNumber, $conferenceTag);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private static function translate(PDOException $e): \InvalidArgumentException
    {
        $sqlState = $e->getCode();
        if ($sqlState === '23505') {
            return new \InvalidArgumentException('That echoarea/mailbox/conference mapping already exists');
        }
        if ($sqlState === '23503') {
            return new \InvalidArgumentException('The referenced echoarea or mailbox does not exist');
        }
        if ($sqlState === '23514') {
            return new \InvalidArgumentException('Invalid QWK subscription value');
        }

        return new \InvalidArgumentException('Could not store the QWK subscription: ' . $e->getMessage());
    }
}
