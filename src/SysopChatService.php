<?php

namespace BinktermPHP;

use BinktermPHP\Binkp\Logger;
use BinktermPHP\Realtime\BinkStream;
use PDO;
use PDOException;

/**
 * SysOp Chat — M1A backend lifecycle.
 *
 * Owns the `sysop_pages` table: a caller's "Page SysOp" request, from
 * creation through acceptance/decline/expiry/cancellation to completion. This
 * class is the *only* place page-status SQL should live; routes and terminal
 * handlers (added in later slices — M1B web admin, M1C terminal) must go
 * through it rather than writing `sysop_pages` directly.
 *
 * This is a lifecycle/event service, not a chat-transcript store. Message
 * transport is deliberately out of scope for M1A — see docs/SysopChat/M1A.md
 * "Message transport recon" for why {@see \BinktermPHP\Chat\ChatMessageService}
 * (durable, cross-network-fanned-out `chat_messages`) is the wrong fit for
 * this feature's ephemeral-chat intent, and is left to a later slice instead
 * of reused here.
 *
 * Session model (see docs/SysopChat/M1A.md "Session model" for the full S0
 * rationale): a Telnet/SSH/Web-terminal caller pages from a live BbsSession
 * bound to one `user_sessions.session_id`, recorded as `caller_session_id`.
 * An ordinary Web UI caller has no equivalent terminal-session concept and
 * pages from authenticated identity alone, so `caller_session_id` is nullable
 * and its absence is not an error — it is how a `web_ui` page always looks.
 *
 * Concurrency invariants are enforced primarily at the data layer (two
 * partial-unique indexes on `sysop_pages` — see the M1A migration), not by
 * PHP-side locking:
 *   - at most one WAITING page per caller
 *   - at most one ACCEPTED page globally (the M1 singleton-Matt-chat rule)
 * Both are backstopped here by treating a unique-violation the same way as a
 * zero-row `WHERE status = ...` update: a clean "blocked", never a thrown
 * error surfacing to a route.
 *
 * Every state-changing method emits a targeted {@see BinkStream} event of the
 * matching `sysop_chat.*` type, following the exact emit-after-mutate pattern
 * proven by {@see \BinktermPHP\Security\ActiveSessionService} — an event is
 * emitted only after a row was actually changed; a refused/no-op transition
 * emits nothing.
 */
class SysopChatService
{
    public const STATUS_WAITING   = 'waiting';
    public const STATUS_ACCEPTED  = 'accepted';
    public const STATUS_DECLINED  = 'declined';
    public const STATUS_EXPIRED   = 'expired';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    private const SURFACES = ['telnet', 'ssh', 'web_terminal', 'web_ui'];

    /** Realtime event types emitted for each lifecycle transition. */
    public const EVENT_REQUEST   = 'sysop_chat.request';
    public const EVENT_ACCEPTED  = 'sysop_chat.accepted';
    public const EVENT_DECLINED  = 'sysop_chat.declined';
    public const EVENT_CANCELLED = 'sysop_chat.cancelled';
    public const EVENT_EXPIRED   = 'sysop_chat.expired';
    public const EVENT_COMPLETED = 'sysop_chat.completed';

    /** PostgreSQL SQLSTATE for a unique-constraint violation. */
    private const SQLSTATE_UNIQUE_VIOLATION = '23505';

    /** Default waiting-page lifetime; overridable for local tuning without a
     *  code change (see `SYSOP_CHAT_PAGE_TTL_SECONDS` in `.env`). */
    private const DEFAULT_TTL_SECONDS = 300;

    private PDO $db;
    private ?SysopChatMessageService $messages;

    public function __construct(?PDO $db = null, ?SysopChatMessageService $messages = null)
    {
        $this->db = $db ?? Database::getInstance()->getPdo();
        $this->messages = $messages;
    }

    // -----------------------------------------------------------------------
    // CREATE
    // -----------------------------------------------------------------------

    /**
     * Create a new waiting page for an authenticated caller.
     *
     * Opportunistically expires this caller's own stale waiting page first
     * (see {@see expireStalePages()}), so a caller whose previous page simply
     * timed out is never blocked from paging again by a row that is only
     * "waiting" on paper.
     *
     * @return array{id:int,status:string,expires_at:string}|null Null when
     *   the caller already has a live waiting page (invariant A).
     */
    public function createPage(int $callerUserId, string $surface, ?string $callerSessionId = null): ?array
    {
        if ($callerUserId <= 0 || !in_array($surface, self::SURFACES, true)) {
            return null;
        }

        $this->expireStalePages($callerUserId);

        $ttl = self::ttlSeconds();

        $row = $this->runGuardedAgainstUniqueViolation(function () use ($callerUserId, $callerSessionId, $surface, $ttl) {
            $stmt = $this->db->prepare("
                INSERT INTO sysop_pages (caller_user_id, caller_session_id, surface, status, expires_at)
                VALUES (?, ?, ?, ?, NOW() + (? || ' seconds')::interval)
                RETURNING id, status, expires_at::text AS expires_at
            ");
            $stmt->execute([$callerUserId, $callerSessionId, $surface, self::STATUS_WAITING, $ttl]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        });
        if (!$row) {
            return null;
        }

        $page = [
            'id'         => (int) $row['id'],
            'status'     => (string) $row['status'],
            'expires_at' => (string) $row['expires_at'],
        ];

        BinkStream::emit(
            $this->db,
            self::EVENT_REQUEST,
            [
                'page_id'        => $page['id'],
                'caller_user_id' => $callerUserId,
                'surface'        => $surface,
            ],
            null,   // no single target — every admin viewer should see it
            true    // adminOnly broadcast (mirrors DashboardCardRegistry's admin_only convention)
        );

        return $page;
    }

    // -----------------------------------------------------------------------
    // CANCEL (caller-initiated, while still WAITING)
    // -----------------------------------------------------------------------

    /**
     * Cancel the caller's own waiting page. Safe/idempotent: a page that is
     * already gone (wrong id, not the caller's, already transitioned) simply
     * returns false — never an error.
     */
    public function cancelPage(int $pageId, int $callerUserId): bool
    {
        if ($pageId <= 0 || $callerUserId <= 0) {
            return false;
        }

        $stmt = $this->db->prepare("
            UPDATE sysop_pages
            SET status = ?, updated_at = NOW()
            WHERE id = ? AND caller_user_id = ? AND status = ?
        ");
        $stmt->execute([self::STATUS_CANCELLED, $pageId, $callerUserId, self::STATUS_WAITING]);

        if ($stmt->rowCount() < 1) {
            return false;
        }

        BinkStream::emit(
            $this->db,
            self::EVENT_CANCELLED,
            ['page_id' => $pageId, 'caller_user_id' => $callerUserId],
            null,
            true // admin side removes it from the waiting list
        );

        return true;
    }

    // -----------------------------------------------------------------------
    // DECLINE (admin-initiated, while still WAITING)
    // -----------------------------------------------------------------------

    /**
     * Decline a waiting page. `$actingAdminUserId` is recorded nowhere on a
     * decline (there is no "declined by" field — M1A keeps this purpose-built)
     * but is required in the signature so a route cannot call this without an
     * explicit acting identity in hand.
     */
    public function declinePage(int $pageId, int $actingAdminUserId): bool
    {
        if ($pageId <= 0 || $actingAdminUserId <= 0) {
            return false;
        }

        $stmt = $this->db->prepare("
            UPDATE sysop_pages
            SET status = ?, updated_at = NOW()
            WHERE id = ? AND status = ?
            RETURNING caller_user_id
        ");
        $stmt->execute([self::STATUS_DECLINED, $pageId, self::STATUS_WAITING]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return false;
        }

        BinkStream::emit(
            $this->db,
            self::EVENT_DECLINED,
            ['page_id' => $pageId],
            (int) $row['caller_user_id']
        );

        return true;
    }

    // -----------------------------------------------------------------------
    // ACCEPT
    // -----------------------------------------------------------------------

    /**
     * Accept a waiting page, atomically enforcing every M1 concurrency
     * invariant:
     *   - the page must still be WAITING and not past `expires_at`
     *     (a stale/expired/declined/cancelled/already-accepted page fails)
     *   - at most one page may be ACCEPTED globally at a time (the singleton
     *     partial-unique index rejects a second concurrent accept outright)
     *   - if the page carries a terminal `caller_session_id`, that session
     *     must still exist and be unexpired — an admin accepting after the
     *     caller has disconnected is a clean failure, not a silent no-op
     *     chat: the page is rolled forward to EXPIRED instead
     *
     * @return array{id:int,caller_user_id:int,surface:string}|null Null on
     *   any failure path above.
     */
    public function acceptPage(int $pageId, int $actingAdminUserId): ?array
    {
        if ($pageId <= 0 || $actingAdminUserId <= 0) {
            return null;
        }

        $row = $this->runGuardedAgainstUniqueViolation(function () use ($pageId, $actingAdminUserId) {
            $stmt = $this->db->prepare("
                UPDATE sysop_pages
                SET status = ?, accepted_by_user_id = ?, accepted_at = NOW(), updated_at = NOW()
                WHERE id = ? AND status = ? AND expires_at > NOW()
                RETURNING id, caller_user_id, caller_session_id, surface
            ");
            $stmt->execute([self::STATUS_ACCEPTED, $actingAdminUserId, $pageId, self::STATUS_WAITING]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        });

        if (!$row) {
            return null; // not waiting, already gone, expired, or another page is already the active chat
        }

        $callerSessionId = $row['caller_session_id'];
        if ($callerSessionId !== null && $callerSessionId !== '' && !$this->callerSessionIsLive((string) $callerSessionId)) {
            // Caller is gone — this is not a live chat. Roll forward to
            // EXPIRED (a single independent statement — the accept above
            // already committed as its own atomic step) rather than leaving
            // a phantom ACCEPTED row that would (correctly) block any other
            // page under the M1 singleton constraint forever.
            $expireStmt = $this->db->prepare("
                UPDATE sysop_pages SET status = ?, updated_at = NOW() WHERE id = ?
            ");
            $expireStmt->execute([self::STATUS_EXPIRED, $pageId]);
            return null;
        }

        $page = [
            'id'             => (int) $row['id'],
            'caller_user_id' => (int) $row['caller_user_id'],
            'surface'        => (string) $row['surface'],
        ];

        BinkStream::emit(
            $this->db,
            self::EVENT_ACCEPTED,
            ['page_id' => $page['id'], 'surface' => $page['surface']],
            $page['caller_user_id']
        );

        return $page;
    }

    // -----------------------------------------------------------------------
    // COMPLETE (either party ends an active chat)
    // -----------------------------------------------------------------------

    /**
     * End an ACCEPTED/CHATTING page. Only the caller or the accepting admin
     * may end it; a waiting page cannot be completed (it must be accepted
     * first). The realtime event is targeted at whichever party did NOT
     * initiate the end.
     */
    public function completePage(int $pageId, int $actingUserId): bool
    {
        if ($pageId <= 0 || $actingUserId <= 0) {
            return false;
        }

        $stmt = $this->db->prepare("
            UPDATE sysop_pages
            SET status = ?, completed_at = NOW(), updated_at = NOW()
            WHERE id = ? AND status = ? AND (caller_user_id = ? OR accepted_by_user_id = ?)
            RETURNING caller_user_id, accepted_by_user_id
        ");
        $stmt->execute([
            self::STATUS_COMPLETED, $pageId, self::STATUS_ACCEPTED, $actingUserId, $actingUserId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return false;
        }

        $callerUserId = (int) $row['caller_user_id'];
        $adminUserId = $row['accepted_by_user_id'] !== null ? (int) $row['accepted_by_user_id'] : null;
        $notifyUserId = $actingUserId === $callerUserId ? $adminUserId : $callerUserId;

        if ($notifyUserId !== null) {
            BinkStream::emit(
                $this->db,
                self::EVENT_COMPLETED,
                ['page_id' => $pageId],
                $notifyUserId
            );
        }

        // Ephemeral by design (see docs/SysopChat/M1A.md "Message transport
        // recon") -- a chat leaves no transcript once it ends. Best-effort:
        // the page has already, correctly, transitioned to COMPLETED above: a
        // purge failure must never be reported back as an "end chat" failure
        // (the caller would then see a false error, or retry into a 409 on an
        // already-completed page). Mirrors ActiveSessionService's cascade
        // cleanup -- log it, never let it block or misreport the transition
        // that already succeeded.
        try {
            ($this->messages ??= new SysopChatMessageService($this->db))->purgeForPage($pageId);
        } catch (\Throwable $e) {
            $this->log('message purge failed for page ' . $pageId . ': ' . $e->getMessage());
        }

        return true;
    }

    // -----------------------------------------------------------------------
    // EXPIRE (opportunistic sweep — no daemon required)
    // -----------------------------------------------------------------------

    /**
     * Flip stale WAITING rows to EXPIRED and emit one targeted event per row.
     * Called opportunistically from {@see createPage()} and the query
     * methods below rather than from a dedicated daemon (per M1A scope).
     *
     * @param int|null $callerUserId Scope the sweep to one caller (the common
     *   case, called from {@see createPage()}); null sweeps every stale row
     *   (called from the admin-facing query methods).
     * @return int Number of rows expired.
     */
    public function expireStalePages(?int $callerUserId = null): int
    {
        $sql = "
            UPDATE sysop_pages
            SET status = ?, updated_at = NOW()
            WHERE status = ? AND expires_at <= NOW()
        ";
        $params = [self::STATUS_EXPIRED, self::STATUS_WAITING];
        if ($callerUserId !== null) {
            $sql .= ' AND caller_user_id = ?';
            $params[] = $callerUserId;
        }
        $sql .= ' RETURNING id, caller_user_id';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            BinkStream::emit(
                $this->db,
                self::EVENT_EXPIRED,
                ['page_id' => (int) $row['id']],
                (int) $row['caller_user_id']
            );
        }

        return count($rows);
    }

    // -----------------------------------------------------------------------
    // QUERY
    // -----------------------------------------------------------------------

    /**
     * Admin-facing list of currently waiting pages, oldest first.
     *
     * @return list<array{id:int,caller_user_id:int,caller_username:string,surface:string,created_at:string,expires_at:string}>
     */
    public function getWaitingPages(): array
    {
        $this->expireStalePages();

        $stmt = $this->db->query("
            SELECT p.id, p.caller_user_id, u.username AS caller_username, p.surface,
                   p.created_at::text AS created_at, p.expires_at::text AS expires_at
            FROM sysop_pages p
            JOIN users u ON u.id = p.caller_user_id
            WHERE p.status = '" . self::STATUS_WAITING . "'
            ORDER BY p.created_at ASC
        ");

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = [
                'id'              => (int) $row['id'],
                'caller_user_id'  => (int) $row['caller_user_id'],
                'caller_username' => (string) $row['caller_username'],
                'surface'         => (string) $row['surface'],
                'created_at'      => (string) $row['created_at'],
                'expires_at'      => (string) $row['expires_at'],
            ];
        }
        return $out;
    }

    /**
     * The caller's current waiting-or-active page, if any.
     *
     * @return array{id:int,status:string,surface:string}|null
     */
    public function getCallerPage(int $callerUserId): ?array
    {
        if ($callerUserId <= 0) {
            return null;
        }

        $this->expireStalePages($callerUserId);

        $stmt = $this->db->prepare("
            SELECT id, status, surface
            FROM sysop_pages
            WHERE caller_user_id = ? AND status IN (?, ?)
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$callerUserId, self::STATUS_WAITING, self::STATUS_ACCEPTED]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return [
            'id'      => (int) $row['id'],
            'status'  => (string) $row['status'],
            'surface' => (string) $row['surface'],
        ];
    }

    /**
     * The single globally-active (ACCEPTED/CHATTING) page, if any.
     *
     * @return array{id:int,caller_user_id:int,caller_username:string,accepted_by_user_id:int,accepted_by_username:string,surface:string}|null
     */
    public function getActiveChat(): ?array
    {
        $stmt = $this->db->query("
            SELECT p.id, p.caller_user_id, cu.username AS caller_username,
                   p.accepted_by_user_id, au.username AS accepted_by_username, p.surface
            FROM sysop_pages p
            JOIN users cu ON cu.id = p.caller_user_id
            JOIN users au ON au.id = p.accepted_by_user_id
            WHERE p.status = '" . self::STATUS_ACCEPTED . "'
            LIMIT 1
        ");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return [
            'id'                    => (int) $row['id'],
            'caller_user_id'        => (int) $row['caller_user_id'],
            'caller_username'       => (string) $row['caller_username'],
            'accepted_by_user_id'   => (int) $row['accepted_by_user_id'],
            'accepted_by_username'  => (string) $row['accepted_by_username'],
            'surface'               => (string) $row['surface'],
        ];
    }

    // -----------------------------------------------------------------------
    // Internal
    // -----------------------------------------------------------------------

    private function callerSessionIsLive(string $sessionId): bool
    {
        $stmt = $this->db->prepare("
            SELECT 1 FROM user_sessions WHERE session_id = ? AND expires_at > NOW()
        ");
        $stmt->execute([$sessionId]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Run one write that might hit a partial-unique-index violation, folding
     * that specific failure into a plain `null` return instead of a thrown
     * exception or (worse) a poisoned surrounding transaction.
     *
     * A caught PostgreSQL error aborts the rest of the current transaction
     * block, so this always wraps the write in a SAVEPOINT — nested inside
     * an already-open transaction (e.g. a test harness's outer transaction)
     * it rolls back only to that savepoint; standalone (the normal request
     * path, no explicit transaction open) it opens and closes its own.
     *
     * @template T
     * @param callable():T $write
     * @return T|null
     */
    private function runGuardedAgainstUniqueViolation(callable $write)
    {
        $nested = $this->db->inTransaction();
        if ($nested) {
            $this->db->exec('SAVEPOINT sysop_chat_write');
        } else {
            $this->db->beginTransaction();
        }

        try {
            $result = $write();
            if ($nested) {
                $this->db->exec('RELEASE SAVEPOINT sysop_chat_write');
            } else {
                $this->db->commit();
            }
            return $result;
        } catch (PDOException $e) {
            if ($nested) {
                $this->db->exec('ROLLBACK TO SAVEPOINT sysop_chat_write');
            } else {
                $this->db->rollBack();
            }
            if ($this->isUniqueViolation($e)) {
                return null;
            }
            throw $e;
        }
    }

    private function isUniqueViolation(PDOException $e): bool
    {
        $sqlState = is_array($e->errorInfo ?? null) ? ($e->errorInfo[0] ?? null) : null;
        return $sqlState === self::SQLSTATE_UNIQUE_VIOLATION || $e->getCode() === self::SQLSTATE_UNIQUE_VIOLATION;
    }

    private static function ttlSeconds(): int
    {
        $ttl = (int) Config::env('SYSOP_CHAT_PAGE_TTL_SECONDS', (string) self::DEFAULT_TTL_SECONDS);
        return $ttl > 0 ? $ttl : self::DEFAULT_TTL_SECONDS;
    }

    private function log(string $message): void
    {
        try {
            (new Logger(Config::getLogPath('server.log'), Logger::LEVEL_INFO, false))
                ->warning('[SysopChatService] ' . $message);
        } catch (\Throwable $e) {
            // Logging must never be the thing that breaks a completion.
        }
    }
}
