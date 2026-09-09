<?php

namespace BinktermPHP\Newscan;

use BinktermPHP\BulletinManager;
use BinktermPHP\Database;
use BinktermPHP\MessageHandler;
use PDO;

/**
 * Resolves "what is new for this caller?" across the platform's real message
 * sources, by composing existing canonical read state. It is transport-agnostic
 * — the terminal newscan is the first consumer, but nothing here is
 * terminal-specific.
 *
 * Read-state contract (non-negotiable):
 *   - {@see plan()} performs ZERO writes. Every data-access method runs SELECTs
 *     only. A message becomes "read" only when it is actually opened through the
 *     normal message path, which this service never calls.
 *
 * Sources:
 *   - Netmail — unread = no `message_read_status` row. The recipient / access /
 *     soft-delete predicate is intricate and is reused verbatim from
 *     {@see MessageHandler::getNetmail()} with the `unread` filter; it is never
 *     re-derived here.
 *   - Echomail — "new" = messages in a subscribed, accessible area that arrived
 *     *after* the caller's per-area last-read high-watermark
 *     (`user_echoarea_subscriptions.last_read_id`), that the caller has not
 *     individually read, and that survive the caller's ignore / moderation /
 *     future-date filters. This is the platform's default badge semantics
 *     (`echomail_badge_mode = 'new'`) and the classic BBS newscan pointer: an
 *     `idx_echomail(echoarea_id, id)` range scan per candidate area, never a
 *     global `message_read_status` scan.
 *   - Bulletins — a summary count only ({@see BulletinManager::getUnreadCount()}).
 *     Bulletins are not traversed message-by-message.
 *   - QWK — deliberately absent: it is offline transport/export of the same
 *     netmail/echomail and would double-count.
 *
 * The four `protected` data-access methods are the seam for deterministic
 * tests: a subclass overrides them with fixtures and exercises the composition,
 * ordering, cap and truncation logic without a database.
 */
class UnifiedNewscanService
{
    public const DEFAULT_NETMAIL_CAP  = 300;
    public const DEFAULT_AREA_CAP     = 60;
    public const DEFAULT_PER_AREA_CAP = 300;

    protected PDO $db;
    protected MessageHandler $messages;

    public function __construct(?PDO $db = null, ?MessageHandler $messages = null)
    {
        $this->db       = $db ?? Database::getInstance()->getPdo();
        $this->messages = $messages ?? new MessageHandler();
    }

    /**
     * @param array<string,mixed> $user caller record (needs `user_id`/`id`, `is_admin`)
     * @param array<string,int>   $opts optional caps: `netmail_cap`, `area_cap`, `per_area_cap`
     */
    public function plan(array $user, array $opts = []): NewscanPlan
    {
        $userId = (int) ($user['user_id'] ?? $user['id'] ?? 0);
        if ($userId <= 0) {
            return NewscanPlan::empty();
        }
        $isAdmin = !empty($user['is_admin']);

        $netmailCap = max(1, (int) ($opts['netmail_cap'] ?? self::DEFAULT_NETMAIL_CAP));
        $areaCap    = max(1, (int) ($opts['area_cap'] ?? self::DEFAULT_AREA_CAP));
        $perAreaCap = max(1, (int) ($opts['per_area_cap'] ?? self::DEFAULT_PER_AREA_CAP));

        $truncated = false;

        // --- Netmail: unread, oldest first, bounded ---
        $netmailIds = array_values(array_slice($this->unreadNetmailIds($userId, $netmailCap), 0, $netmailCap));
        if (count($netmailIds) >= $netmailCap) {
            $truncated = true;
        }

        // --- Echomail: phase 1 (cheap) — candidate areas above the watermark ---
        $candidates = $this->candidateAreas($userId, $isAdmin, $areaCap);
        if (count($candidates) >= $areaCap) {
            $truncated = true;
        }

        // --- Echomail: phase 2 (bounded) — the new ids per candidate area ---
        $areas = [];
        foreach ($candidates as $c) {
            $ids = array_values(array_slice($this->newMessageIds($userId, (int) $c['id'], $perAreaCap), 0, $perAreaCap));
            if ($ids === []) {
                continue; // filtered by ignore/moderation, or a watermark race
            }
            if (count($ids) >= $perAreaCap) {
                $truncated = true;
            }
            $areas[] = new NewscanArea(
                (int) $c['id'],
                (string) $c['tag'],
                (string) ($c['domain'] ?? ''),
                (string) ($c['description'] ?? ''),
                $ids,
            );
        }

        $bulletinUnread = max(0, $this->bulletinUnreadCount($userId));

        return new NewscanPlan($netmailIds, $areas, $bulletinUnread, $truncated);
    }

    // ----- data-access seam (SELECT-only; overridable in tests) -----

    /**
     * Unread netmail ids, oldest first. Reuses {@see MessageHandler::getNetmail()}
     * with the `unread` filter so the (intricate) recipient / soft-delete
     * predicate is identical to the web.
     *
     * @return int[]
     */
    protected function unreadNetmailIds(int $userId, int $cap): array
    {
        $result = $this->messages->getNetmail($userId, 1, $cap, 'unread', false, 'date_asc');
        $ids = [];
        foreach (($result['messages'] ?? []) as $m) {
            if (!empty($m['id'])) {
                $ids[] = (int) $m['id'];
            }
        }

        return $ids;
    }

    /**
     * Subscribed, active, accessible areas holding at least one message above
     * the caller's per-area last-read watermark. One statement, `EXISTS` over
     * `idx_echomail(echoarea_id, id)` — no per-message read join.
     *
     * @return list<array{id:int,tag:string,domain:string,description:string}>
     */
    protected function candidateAreas(int $userId, bool $isAdmin, int $cap): array
    {
        $sysop = $isAdmin ? '' : " AND COALESCE(e.is_sysop_only, FALSE) = FALSE";

        $sql = "
            SELECT e.id, e.tag, e.domain, e.description
            FROM user_echoarea_subscriptions ues
            JOIN echoareas e ON e.id = ues.echoarea_id AND e.is_active = TRUE{$sysop}
            WHERE ues.user_id = :uid AND ues.is_active = TRUE
              AND EXISTS (
                  SELECT 1 FROM echomail em
                  WHERE em.echoarea_id = e.id
                    AND em.id > COALESCE(ues.last_read_id, 0)
                    AND (em.date_written IS NULL OR em.date_written <= (NOW() AT TIME ZONE 'UTC'))
              )
            ORDER BY e.tag ASC, COALESCE(e.domain, '') ASC
            LIMIT :cap
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':cap', $cap, PDO::PARAM_INT);
        $stmt->execute();

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'id'          => (int) $r['id'],
                'tag'         => (string) $r['tag'],
                'domain'      => (string) ($r['domain'] ?? ''),
                'description' => (string) ($r['description'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * The caller's new, unread, visible message ids in one area, oldest first,
     * bounded. `em.id > watermark` keeps this a range scan; the
     * `message_read_status` anti-join then only inspects that small window.
     * The ignore-rule and moderation-visibility fragments are exactly those the
     * web message list uses.
     *
     * @return int[]
     */
    protected function newMessageIds(int $userId, int $echoareaId, int $cap): array
    {
        $ignore     = $this->messages->buildEchomailIgnoreFilter($userId, 'em');
        $moderation = $this->messages->buildModerationVisibilityFilter($userId, 'em');

        $sql = "
            SELECT em.id
            FROM echomail em
            JOIN user_echoarea_subscriptions ues
                 ON ues.echoarea_id = em.echoarea_id AND ues.user_id = ? AND ues.is_active = TRUE
            WHERE em.echoarea_id = ?
              AND em.id > COALESCE(ues.last_read_id, 0)
              AND (em.date_written IS NULL OR em.date_written <= (NOW() AT TIME ZONE 'UTC'))
              AND NOT EXISTS (
                  SELECT 1 FROM message_read_status mrs
                  WHERE mrs.message_id = em.id
                    AND mrs.message_type = 'echomail'
                    AND mrs.user_id = ?
              )
              {$ignore['sql']}
              {$moderation['sql']}
            ORDER BY em.id ASC
            LIMIT ?
        ";

        $params = [$userId, $echoareaId, $userId];
        foreach ($ignore['params'] as $p) {
            $params[] = $p;
        }
        foreach ($moderation['params'] as $p) {
            $params[] = $p;
        }
        $params[] = $cap;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return array_map(static fn ($id) => (int) $id, $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    protected function bulletinUnreadCount(int $userId): int
    {
        try {
            return (new BulletinManager($this->db))->getUnreadCount($userId);
        } catch (\Throwable $e) {
            // Bulletins are a summary nicety; never let them break the scan.
            return 0;
        }
    }
}
