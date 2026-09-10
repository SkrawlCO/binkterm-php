<?php

namespace BinktermPHP\Security;

use BinktermPHP\Binkp\Logger;
use BinktermPHP\Config;
use BinktermPHP\Database;
use BinktermPHP\DoorBridgeControlClient;
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
 *   4. forcibly end any door sessions still attached via
 *      `door_sessions.auth_session_id`: for a bridge-managed runtime, ask the
 *      bridge to kill the process group it owns (through
 *      {@see DoorBridgeControlClient::terminate()} — PHP running as the web
 *      user cannot signal a root-owned process group itself), then run the
 *      normal {@see DoorSessionManager::endSession()} cleanup. Not a parallel
 *      revocation path — the same teardown a voluntary door exit performs.
 *   5. publish a targeted `session.kick` event on the realtime bus so a live
 *      Telnet/SSH child that is polling can terminate itself promptly
 *
 * Ordering guarantees:
 *   - a kick is emitted **only** after a row was actually deleted; a lookup
 *     miss or an ownership mismatch emits nothing
 *   - bridge runtime termination is attempted **before** `endSession()` stamps
 *     `door_sessions.ended_at`, because the bridge only authorizes a control
 *     request against a row whose `ended_at IS NULL`
 *   - door-session cleanup is best-effort but never silent — a failure is
 *     logged and the revoke still completes (the auth-session security
 *     boundary must not depend on door-runtime cleanup succeeding)
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
    public const CODE_ADMIN_REVOKED = 'admin_revoked';

    /** Length (hex chars) of the opaque admin session reference. 16 = 64 bits. */
    private const REF_LENGTH = 16;

    private PDO $db;
    private ?Logger $logger;
    private ?DoorSessionManager $doorSessions;
    private ?DoorBridgeControlClient $bridgeControl;

    public function __construct(
        ?PDO $db = null,
        ?Logger $logger = null,
        ?DoorSessionManager $doorSessions = null,
        ?DoorBridgeControlClient $bridgeControl = null
    ) {
        $this->db = $db ?? Database::getInstance()->getPdo();
        $this->logger = $logger;
        $this->doorSessions = $doorSessions;
        $this->bridgeControl = $bridgeControl;
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
     * @param string $code One of the CODE_* constants for the kick payload
     *                     (self-service passes the default; admin passes
     *                     CODE_ADMIN_REVOKED).
     * @return int Number of sessions revoked.
     */
    public function revokeAllForUser(int $userId, string $code = self::CODE_REVOKED_ALL): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $stmt = $this->db->prepare('SELECT session_id FROM user_sessions WHERE user_id = ?');
        $stmt->execute([$userId]);
        $sessionIds = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $revoked = 0;
        foreach ($sessionIds as $sessionId) {
            if ($this->revokeSession((string) $sessionId, $userId, $code)) {
                $revoked++;
            }
        }

        return $revoked;
    }

    // -----------------------------------------------------------------------
    // Admin listing + opaque session references (Slice B)
    // -----------------------------------------------------------------------

    /**
     * Opaque, non-secret administrative reference for a session.
     *
     * `substr(HMAC-SHA256(domain || session_id, server key), 0, 16)` — 64 bits,
     * hex. Deterministic (the list and a later kick agree), one-way (SHA-256
     * is not invertible, so the bearer `session_id` cannot be reconstructed
     * from the reference), and collision-resistant far beyond the handful of
     * sessions a single user holds. The keying provides defence-in-depth
     * against offline precomputation; the essential property — no bearer-token
     * disclosure — holds from the hash's one-wayness regardless of the key.
     */
    public static function sessionRef(string $sessionId): string
    {
        return substr(
            hash_hmac('sha256', 'binkterm.admin-session-ref.v1|' . $sessionId, self::refKey()),
            0,
            self::REF_LENGTH
        );
    }

    /**
     * Resolve (target user id, opaque reference) to exactly one live
     * `session_id`, or null. Zero matches -> null (caller returns 404). More
     * than one match -> null (fail closed; never guess). A reference computed
     * for a different user simply will not match this user's sessions.
     */
    public function resolveSessionRef(int $userId, string $ref): ?string
    {
        $ref = strtolower(trim($ref));
        if ($userId <= 0 || !preg_match('/^[0-9a-f]{' . self::REF_LENGTH . '}$/', $ref)) {
            return null;
        }

        $stmt = $this->db->prepare(
            'SELECT session_id FROM user_sessions WHERE user_id = ? AND expires_at > NOW()'
        );
        $stmt->execute([$userId]);

        $match = null;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $sessionId) {
            if (hash_equals(self::sessionRef((string) $sessionId), $ref)) {
                if ($match !== null) {
                    return null; // ambiguous — fail closed
                }
                $match = (string) $sessionId;
            }
        }

        return $match;
    }

    /**
     * Admin-facing list of live (non-expired) sessions.
     *
     * The full `session_id` is used internally only to derive `ref` and is
     * never included in a returned row.
     *
     * @param int|null $userId Scope to one user, or null for all users.
     * @param int      $limit  Row cap (1..2000).
     * @return list<array{ref:string,user_id:int,username:string,service:string,
     *   ip_address:?string,created_at:?string,last_activity:?string,
     *   activity:?string,is_online:bool}>
     */
    public function listActive(?int $userId = null, int $limit = 500): array
    {
        $limit = max(1, min(2000, $limit));

        $sql = "
            SELECT s.session_id, s.user_id, u.username, s.service,
                   s.ip_address::text AS ip_address,
                   s.created_at, s.last_activity, s.activity,
                   (s.last_activity > NOW() - INTERVAL '15 minutes') AS is_online
            FROM user_sessions s
            JOIN users u ON u.id = s.user_id
            WHERE s.expires_at > NOW()
        ";
        $params = [];
        if ($userId !== null) {
            $sql .= ' AND s.user_id = ?';
            $params[] = $userId;
        }
        $sql .= ' ORDER BY u.username ASC, s.created_at DESC LIMIT ' . $limit;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = [
                'ref'           => self::sessionRef((string) $row['session_id']),
                'user_id'       => (int) $row['user_id'],
                'username'      => (string) $row['username'],
                'service'       => (string) $row['service'],
                'ip_address'    => $row['ip_address'] !== null ? (string) $row['ip_address'] : null,
                'created_at'    => $row['created_at'] !== null ? (string) $row['created_at'] : null,
                'last_activity' => $row['last_activity'] !== null ? (string) $row['last_activity'] : null,
                'activity'      => $row['activity'] !== null ? (string) $row['activity'] : null,
                'is_online'     => (bool) $row['is_online'],
            ];
        }

        return $out;
    }

    /** Key for {@see sessionRef()}: the site secret, else APP_SECRET, else a
     *  fixed domain string (still one-way + collision-resistant). */
    private static function refKey(): string
    {
        $key = Config::terminalRegistrationSecret();
        if ($key === '') {
            $key = trim((string) Config::env('APP_SECRET', ''));
        }
        if ($key === '') {
            $key = 'binkterm.admin-session-ref.static-fallback';
        }
        return $key;
    }

    private function normalizeCode(string $code): string
    {
        return in_array($code, [self::CODE_REVOKED, self::CODE_REVOKED_ALL, self::CODE_ADMIN_REVOKED], true)
            ? $code
            : self::CODE_REVOKED;
    }

    /**
     * Forcibly end any not-yet-ended door sessions still owned by this auth
     * session.
     *
     * For a bridge-managed runtime (a row with a recorded `dosbox_pid` and a
     * `ws_token`) the bridge is asked to kill the process group it owns —
     * {@see DoorBridgeControlClient::terminate()} — because this code runs in
     * the web-request context as the unprivileged web user and cannot signal a
     * root-owned process group. This happens **before**
     * {@see DoorSessionManager::endSession()}, which stamps `ended_at` and would
     * then cause the bridge to reject the control request as unauthorized.
     * `endSession()` still runs afterwards for the drop-file / presence / DB
     * bookkeeping (and, when the bridge did not confirm, its own best-effort
     * fallback kill).
     *
     * Best-effort throughout: a bridge failure or an `endSession()` failure is
     * logged (never silent) and never blocks the revoke — the auth-session
     * security boundary must not depend on door-runtime cleanup succeeding.
     * Stale rows are also swept by DoorSessionManager's own expiry.
     */
    private function cascadeDoorSessions(string $authSessionId): void
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT session_id, ws_token, dosbox_pid
                 FROM door_sessions
                 WHERE auth_session_id = ? AND ended_at IS NULL'
            );
            $stmt->execute([$authSessionId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            if ($rows === []) {
                return;
            }

            $manager = $this->doorSessions ?? new DoorSessionManager();
            foreach ($rows as $row) {
                $doorSessionId = (string) $row['session_id'];
                $wsToken = (string) ($row['ws_token'] ?? '');
                $hasManagedRuntime = (int) ($row['dosbox_pid'] ?? 0) > 0 && $wsToken !== '';
                $runtimeTerminationConfirmed = false;

                if ($hasManagedRuntime) {
                    try {
                        $result = $this->bridgeControl()->terminate($doorSessionId, $wsToken);
                        if (($result['success'] ?? false) === true) {
                            $runtimeTerminationConfirmed = true;
                        } else {
                            $this->log(
                                'bridge did not confirm runtime termination for door session '
                                . $doorSessionId . ': ' . ($result['error'] ?? 'unknown failure')
                            );
                        }
                    } catch (\Throwable $e) {
                        $this->log(
                            'bridge termination request failed for door session '
                            . $doorSessionId . ': ' . $e->getMessage()
                        );
                    }
                }

                try {
                    $manager->endSession($doorSessionId, $runtimeTerminationConfirmed);
                } catch (\Throwable $e) {
                    $this->log('door session cleanup failed for ' . $doorSessionId . ': ' . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            $this->log('door session cascade failed for auth session ' . $authSessionId . ': ' . $e->getMessage());
        }
    }

    /** Lazy accessor for the bridge control-socket client (test seam). */
    private function bridgeControl(): DoorBridgeControlClient
    {
        return $this->bridgeControl ??= new DoorBridgeControlClient();
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
