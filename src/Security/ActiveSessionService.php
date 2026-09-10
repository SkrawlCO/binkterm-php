<?php

namespace BinktermPHP\Security;

use BinktermPHP\Binkp\Logger;
use BinktermPHP\Config;
use BinktermPHP\Database;
use BinktermPHP\DoorSessionManager;
use BinktermPHP\Realtime\BinkStream;
use PDO;

/**
 * Transport-neutral owner of authenticated-session revocation.
 *
 * A "session" here is a row in `user_sessions` — the same record web, Telnet,
 * SSH, NNTP and FTP all create at login. Revoking one must:
 *
 *   1. resolve the session and its owning user
 *   2. enforce caller ownership where a self-service path requires it
 *   3. delete the `user_sessions` row
 *   4. end any door sessions still attached via `door_sessions.auth_session_id`
 *      (through {@see DoorSessionManager::endSession()}, not a parallel path)
 *   5. publish a targeted `session.kick` event on the realtime bus so a live
 *      Telnet/SSH child that is polling can terminate itself promptly
 *
 * Ordering guarantees:
 *   - a kick is emitted **only** after a row was actually deleted; a lookup
 *     miss or an ownership mismatch emits nothing
 *   - door-session cleanup is best-effort but never silent — a failure is
 *     logged and the revoke still completes
 *
 * This class knows nothing about live processes; the terminal side reacts to
 * the emitted event (see {@see \BinktermPHP\TelnetServer\SessionKickHandler}).
 */
final class ActiveSessionService
{
    /** Realtime event type consumed by the terminal session-kick handler. */
    public const EVENT_KICK = 'session.kick';

    /** Payload `code` values. The terminal maps each to a fixed localized
     *  message; it never renders free text from the payload. */
    public const CODE_REVOKED = 'revoked';
    public const CODE_REVOKED_ALL = 'revoked_all';

    private PDO $db;
    private ?Logger $logger;
    private ?DoorSessionManager $doorSessions;

    public function __construct(
        ?PDO $db = null,
        ?Logger $logger = null,
        ?DoorSessionManager $doorSessions = null
    ) {
        $this->db = $db ?? Database::getInstance()->getPdo();
        $this->logger = $logger;
        $this->doorSessions = $doorSessions;
    }

    /**
     * Revoke a single authenticated session.
     *
     * @param string   $sessionId      `user_sessions.session_id` to revoke.
     * @param int|null  $requireOwnerId When non-null, the session must belong to
     *                                  this user id or the revoke is refused
     *                                  (self-service ownership enforcement).
     * @param string   $code           One of the CODE_* constants; carried to
     *                                  the terminal in the kick payload.
     * @return bool True when a row was deleted and a kick emitted.
     */
    public function revokeSession(string $sessionId, ?int $requireOwnerId, string $code = self::CODE_REVOKED): bool
    {
        if ($sessionId === '') {
            return false;
        }

        $sel = $this->db->prepare('SELECT user_id FROM user_sessions WHERE session_id = ?');
        $sel->execute([$sessionId]);
        $ownerRaw = $sel->fetchColumn();
        if ($ownerRaw === false) {
            return false; // nothing to revoke — emit nothing
        }
        $ownerId = (int) $ownerRaw;

        if ($requireOwnerId !== null && $ownerId !== $requireOwnerId) {
            return false; // not the caller's session — refuse, emit nothing
        }

        if ($requireOwnerId !== null) {
            $del = $this->db->prepare('DELETE FROM user_sessions WHERE session_id = ? AND user_id = ?');
            $del->execute([$sessionId, $requireOwnerId]);
        } else {
            $del = $this->db->prepare('DELETE FROM user_sessions WHERE session_id = ?');
            $del->execute([$sessionId]);
        }

        if ($del->rowCount() < 1) {
            return false; // lost a race — already gone. emit nothing
        }

        $this->cascadeDoorSessions($sessionId);

        BinkStream::emit(
            $this->db,
            self::EVENT_KICK,
            ['session_id' => $sessionId, 'code' => $this->normalizeCode($code)],
            $ownerId
        );

        return true;
    }

    /**
     * Revoke every session owned by $userId. One `session.kick` is emitted per
     * removed session, each targeted at $userId.
     *
     * @return int Number of sessions revoked.
     */
    public function revokeAllForUser(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $stmt = $this->db->prepare('SELECT session_id FROM user_sessions WHERE user_id = ?');
        $stmt->execute([$userId]);
        $sessionIds = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $revoked = 0;
        foreach ($sessionIds as $sessionId) {
            if ($this->revokeSession((string) $sessionId, $userId, self::CODE_REVOKED_ALL)) {
                $revoked++;
            }
        }

        return $revoked;
    }

    private function normalizeCode(string $code): string
    {
        return in_array($code, [self::CODE_REVOKED, self::CODE_REVOKED_ALL], true)
            ? $code
            : self::CODE_REVOKED;
    }

    /**
     * End any not-yet-ended door sessions still owned by this auth session,
     * reusing {@see DoorSessionManager::endSession()} so the dosbox/bridge
     * runtime is torn down the same way a normal door exit does it.
     *
     * Best-effort: a failure is logged (never silent) and never blocks the
     * revoke. Stale rows are also swept by DoorSessionManager's own expiry.
     */
    private function cascadeDoorSessions(string $authSessionId): void
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT session_id FROM door_sessions WHERE auth_session_id = ? AND ended_at IS NULL'
            );
            $stmt->execute([$authSessionId]);
            $doorSessionIds = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

            if ($doorSessionIds === []) {
                return;
            }

            $manager = $this->doorSessions ?? new DoorSessionManager();
            foreach ($doorSessionIds as $doorSessionId) {
                try {
                    $manager->endSession((string) $doorSessionId);
                } catch (\Throwable $e) {
                    $this->log('door session cleanup failed for ' . $doorSessionId . ': ' . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            $this->log('door session cascade failed for auth session ' . $authSessionId . ': ' . $e->getMessage());
        }
    }

    private function log(string $message): void
    {
        try {
            if ($this->logger === null) {
                $this->logger = new Logger(Config::getLogPath('server.log'), Logger::LEVEL_INFO, false);
            }
            $this->logger->warning('[ActiveSessionService] ' . $message);
        } catch (\Throwable $e) {
            // Logging must never be the thing that breaks a revoke.
        }
    }
}
