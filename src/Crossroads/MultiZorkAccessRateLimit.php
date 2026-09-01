<?php

declare(strict_types=1);

namespace BinktermPHP\Crossroads;

use BinktermPHP\Database;
use PDO;

/**
 * L33TEST/Crossroads-owned rate limit for MultiZork access-code submission.
 *
 * Mirrors {@see \BinktermPHP\PacketBbs\PacketBbsLoginRateLimit}'s shape
 * (rolling window, block after N failures, clear on success) rather than a
 * new generic rate-limit framework — see Correction 3 in
 * docs/Crossroads/MultiZorkSlice1.md. Exists because the MultiZork runtime
 * proof confirmed multizorkd itself does not throttle wrong access-code
 * guesses; this guards the one code path in this codebase that submits a
 * MultiZork access code, whether that value came from our own stored
 * mapping or (in a future slice) anywhere less trusted. The access code
 * itself is never written to this table.
 */
final class MultiZorkAccessRateLimit
{
    /** Maximum failed attempts allowed within the window before blocking. */
    private const MAX_FAILURES = 5;

    /** Rolling window length in minutes. */
    private const WINDOW_MINUTES = 10;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getPdo();
    }

    /**
     * Return true if this user is currently allowed to attempt an access-code
     * submission for this expedition, false if blocked.
     */
    public function check(int $userId, string $expeditionId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM multizork_access_attempts
             WHERE user_id = ? AND expedition_id = ? AND success = FALSE
               AND attempted_at > NOW() - INTERVAL '1 minute' * ?"
        );
        $stmt->execute([$userId, $expeditionId, self::WINDOW_MINUTES]);

        return (int)$stmt->fetchColumn() < self::MAX_FAILURES;
    }

    /**
     * Record a failed access-code submission.
     */
    public function recordFailure(int $userId, string $expeditionId): void
    {
        $this->db->prepare(
            'INSERT INTO multizork_access_attempts (user_id, expedition_id, success) VALUES (?, ?, FALSE)'
        )->execute([$userId, $expeditionId]);
    }

    /**
     * Record a successful access-code submission and clear prior failures
     * for this user + expedition.
     */
    public function recordSuccess(int $userId, string $expeditionId): void
    {
        $this->db->prepare(
            'INSERT INTO multizork_access_attempts (user_id, expedition_id, success) VALUES (?, ?, TRUE)'
        )->execute([$userId, $expeditionId]);
        $this->db->prepare(
            'DELETE FROM multizork_access_attempts WHERE user_id = ? AND expedition_id = ? AND success = FALSE'
        )->execute([$userId, $expeditionId]);
    }

    /**
     * Delete attempt rows older than one hour (call opportunistically).
     */
    public function cleanOld(): void
    {
        $this->db->exec("DELETE FROM multizork_access_attempts WHERE attempted_at < NOW() - INTERVAL '1 hour'");
    }
}
