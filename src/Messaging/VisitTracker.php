<?php

namespace BinktermPHP\Messaging;

use BinktermPHP\Database;

/**
 * Tracks the logical "visit family" boundary used for Since-Your-Last-Call
 * semantics (Messaging Evolution Slice 1).
 *
 * A visit family is per-user, not per-connection: as long as ANY of a
 * caller's Web/Telnet/SSH sessions renews within GRACE_SECONDS of the last
 * renewal, the family stays open. Once a gap exceeding GRACE_SECONDS is
 * observed, the family closes and `messaging_visit_boundary_at` freezes to
 * the exact timestamp of the last renewal in the closed family — that
 * frozen value is the "since your last call" boundary a future feature will
 * read from. `messaging_visit_renewed_at` always reflects the most recent
 * renewal, open family or not.
 *
 * Deliberately separate from:
 *  - `user_sessions` (per-connection, expires on its own schedule)
 *  - `users.last_caller_visit_at` (public arrival ticker, coalesced to 30
 *    minutes, clobbered on every explicit login — see Auth::recordCallerVisit())
 *
 * There is intentionally no renewal throttle: every meaningful authenticated
 * request calls renew(), which is a single indexed-PK UPDATE and already
 * comparable in cost to the unconditional user_sessions.last_activity write
 * that occurs on the same request path. A throttle that suppresses writes
 * would let messaging_visit_renewed_at go stale relative to true last
 * activity, which can incorrectly close a family early (premature split) —
 * a correctness bug, not a performance tradeoff. See
 * /root/L33TEST_Messaging_Visit_Lifecycle_Recon_2026-09-14.md for the full
 * design rationale.
 */
class VisitTracker
{
    public const GRACE_SECONDS = 900;

    private \PDO $db;
    private ?bool $columnsAvailable = null;

    public function __construct()
    {
        $this->db = Database::getInstance()->getPdo();
    }

    /**
     * True once the additive migration has run. Guards every read/write below
     * so this class deploys safely before or after the migration is applied.
     */
    public function visitColumnsAvailable(): bool
    {
        if ($this->columnsAvailable === null) {
            $this->columnsAvailable = (bool)$this->db->query("
                SELECT EXISTS (SELECT 1 FROM pg_attribute
                    WHERE attrelid = 'users'::regclass
                      AND attname = 'messaging_visit_boundary_at' AND NOT attisdropped)
            ")->fetchColumn();
        }
        return $this->columnsAvailable;
    }

    /**
     * Renew the caller's visit family. Call on every meaningful authenticated
     * request (Web page/API request, Telnet/SSH activity, explicit login, and
     * the trusted foreground-return signal) — never from unauthenticated or
     * inactive-account paths. No-ops safely while the migration is pending.
     *
     * Single atomic statement: if the previous renewal is missing or older
     * than GRACE_SECONDS, the current family (if any) is closed by freezing
     * the boundary to that previous renewal timestamp (or NOW() on a genuine
     * first-ever visit) before the new renewal is recorded. Otherwise the
     * family simply continues and the boundary is left untouched. Safe under
     * concurrent renewals from multiple sessions belonging to the same user —
     * PostgreSQL's MVCC/EvalPlanQual re-evaluates the CASE against the
     * winning row on any concurrent UPDATE, so no explicit locking is needed.
     */
    public function renew(int $userId): void
    {
        if (!$this->visitColumnsAvailable()) {
            return;
        }
        // A genuine first-ever renewal (messaging_visit_renewed_at IS NULL) has
        // no prior visit to freeze, so the boundary is left untouched (NULL) —
        // only a renewal that finds an actual STALE prior renewal closes a
        // family and freezes the boundary to that renewal's exact timestamp.
        $sql = "UPDATE users SET
                    messaging_visit_boundary_at = CASE
                        WHEN messaging_visit_renewed_at IS NOT NULL
                         AND messaging_visit_renewed_at < NOW() - INTERVAL '" . self::GRACE_SECONDS . " seconds'
                        THEN messaging_visit_renewed_at
                        ELSE messaging_visit_boundary_at
                    END,
                    messaging_visit_renewed_at = NOW()
                WHERE id = :id AND is_active = TRUE";
        $this->db->prepare($sql)->execute([':id' => $userId]);
    }

    /**
     * Read-only accessor for the frozen previous-visit boundary. Never
     * mutates state. Returns null while the migration is pending, the user
     * has no prior visit, or the user is unknown.
     */
    public function getBoundary(int $userId): ?string
    {
        if (!$this->visitColumnsAvailable()) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT messaging_visit_boundary_at FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : $value;
    }
}
