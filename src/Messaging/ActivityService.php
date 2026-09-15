<?php

namespace BinktermPHP\Messaging;

use BinktermPHP\BulletinManager;
use BinktermPHP\Database;
use BinktermPHP\MessageHandler;
use PDO;

/**
 * Resolves "what happened since your last call?" (Messaging Evolution Slice 2)
 * by composing {@see VisitTracker}'s visit boundary with the platform's
 * existing, canonical visibility/filter machinery. It is transport-agnostic —
 * intended for both a future Web and a future Telnet presentation to call
 * identically, exactly as {@see \BinktermPHP\Newscan\UnifiedNewscanService}
 * already is by both surfaces today.
 *
 * Read-only contract (non-negotiable):
 *   - {@see plan()} performs ZERO writes — no read-state mutation, no visit-state
 *     mutation. A message becomes "read" only when it is actually opened
 *     through the normal message path, which this service never calls.
 *   - IDs and counts only. Never a message body, subject, or sender preview —
 *     resolving an id into display content is a future presentation layer's
 *     job through the normal, already-filtered read path.
 *
 * Deliberately separate from, and never mutating, {@see \BinktermPHP\Newscan\UnifiedNewscanService}:
 * that class owns per-area explicit-read-watermark ("newscan") semantics
 * (`user_echoarea_subscriptions.last_read_id`); this class owns cross-surface
 * visit-boundary ("since your last call") semantics
 * (`users.messaging_visit_boundary_at`, via {@see VisitTracker}). The two are
 * intentionally independent temporal axes — see
 * /root/L33TEST_Messaging_Activity_Model_Recon_2026-09-14.md §3/§17 and
 * /root/L33TEST_Messaging_Activity_Service_Design_2026-09-14.md for the full
 * design rationale. Every visibility predicate below is called from
 * {@see MessageHandler}, never re-derived.
 *
 * Netmail "replies" are deliberately NOT modeled or exposed here — unlike
 * Echomail's `reply_to_id`, which is backed by an out-of-order backfill
 * mechanism ({@see \BinktermPHP\BinkdProcessor}), Netmail has no equivalent
 * repair path, so an out-of-order Netmail reply would be a silent, permanent
 * false negative. Netmail is still fully represented as personal activity —
 * "received since your visit" and "unread" — just not as a reply category.
 */
class ActivityService
{
    public const DEFAULT_NETMAIL_CAP     = 300;
    public const DEFAULT_REPLY_CAP       = 300;
    public const DEFAULT_AREA_CAP        = 60;
    public const DEFAULT_PARTICIPATED_CAP = 300;

    protected PDO $db;
    protected MessageHandler $messages;
    protected VisitTracker $visits;

    public function __construct(?PDO $db = null, ?MessageHandler $messages = null, ?VisitTracker $visits = null)
    {
        $this->db       = $db ?? Database::getInstance()->getPdo();
        $this->messages = $messages ?? new MessageHandler();
        $this->visits   = $visits ?? new VisitTracker();
    }

    /**
     * @param array<string,mixed> $user caller record (needs `user_id`/`id`, `is_admin`)
     * @param array<string,int>   $opts optional caps: `netmail_cap`, `reply_cap`, `area_cap`
     */
    public function plan(array $user, array $opts = []): ActivityPlan
    {
        $userId = (int) ($user['user_id'] ?? $user['id'] ?? 0);
        $unread = ['netmailUnread' => 0, 'bulletinUnread' => 0];
        if ($userId <= 0) {
            return ActivityPlan::firstVisit($unread);
        }

        $isAdmin = !empty($user['is_admin']);

        // Unread orientation is visit-independent (read-state only) and is
        // always computed, even on a caller's first-ever tracked visit.
        $unread = [
            'netmailUnread'  => $this->netmailUnreadCount($userId),
            'bulletinUnread' => $this->bulletinUnreadCount($userId),
        ];

        $boundary = $this->visits->getBoundary($userId);
        if ($boundary === null) {
            // No previous visit family has ever closed for this caller — there
            // is no since-visit period to report on. Do not manufacture one.
            return ActivityPlan::firstVisit($unread);
        }

        $netmailCap      = max(1, (int) ($opts['netmail_cap'] ?? self::DEFAULT_NETMAIL_CAP));
        $replyCap        = max(1, (int) ($opts['reply_cap'] ?? self::DEFAULT_REPLY_CAP));
        $areaCap         = max(1, (int) ($opts['area_cap'] ?? self::DEFAULT_AREA_CAP));
        $participatedCap = max(1, (int) ($opts['participated_cap'] ?? self::DEFAULT_PARTICIPATED_CAP));

        $netmailIds = $this->netmailReceivedSinceIds($userId, $boundary, $netmailCap + 1);
        $netmailTruncated = count($netmailIds) > $netmailCap;
        $netmailIds = array_slice($netmailIds, 0, $netmailCap);

        $replyIds = $this->repliesToCallerSinceIds($userId, $boundary, $isAdmin, $replyCap + 1);
        $repliesTruncated = count($replyIds) > $replyCap;
        $replyIds = array_slice($replyIds, 0, $replyCap);

        // Excludes anything already carried in $replyIds — a direct reply is
        // never double-counted as broader conversation activity (Messaging
        // Evolution Phase 1; see Track D of
        // /root/L33TEST_Messaging_Phase1_Personal_Relevance_Design_2026-09-14.md).
        $participatedIds = $this->participatedActivitySinceIds($userId, $boundary, $isAdmin, $replyIds, $participatedCap + 1);
        $participatedTruncated = count($participatedIds) > $participatedCap;
        $participatedIds = array_slice($participatedIds, 0, $participatedCap);

        $areas = $this->areasWithActivitySinceBoundary($userId, $isAdmin, $boundary, $areaCap + 1);
        $areasTruncated = count($areas) > $areaCap;
        $areas = array_slice($areas, 0, $areaCap);

        return new ActivityPlan(
            true,
            $boundary,
            [
                'netmailIds'            => $netmailIds,
                'netmailTruncated'      => $netmailTruncated,
                'replyIds'              => $replyIds,
                'repliesTruncated'      => $repliesTruncated,
                'participatedIds'       => $participatedIds,
                'participatedTruncated' => $participatedTruncated,
            ],
            [
                'areas'          => $areas,
                'areasTruncated' => $areasTruncated,
            ],
            $unread,
        );
    }

    // ----- data-access seam (SELECT-only; overridable in tests) -----

    /**
     * Netmail received (recipient side only — never the caller's own sent
     * mail) since the visit boundary, oldest first, bounded. Reuses
     * {@see MessageHandler::netmailVisibilityFilter()} and
     * {@see MessageHandler::netmailNotDeletedFilter()} verbatim — the
     * recipient-side ownership predicate and per-side soft-delete exclusion
     * are never re-derived here.
     *
     * @return int[]
     */
    protected function netmailReceivedSinceIds(int $userId, string $boundary, int $cap): array
    {
        $vis = $this->messages->netmailVisibilityFilter($userId, 'n', 'recipient');
        $del = $this->messages->netmailNotDeletedFilter($userId, 'n');

        $sql = "
            SELECT n.id
            FROM netmail n
            WHERE {$vis['sql']}
              AND {$del['sql']}
              AND n.date_received > ?
            ORDER BY n.date_received ASC
            LIMIT ?
        ";

        $params = array_merge($vis['params'], $del['params'], [$boundary, $cap]);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return array_map(static fn ($id) => (int) $id, $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Count of unread Netmail (recipient side, undeleted) — read-state only,
     * independent of the visit boundary. Same visibility predicate as
     * {@see netmailReceivedSinceIds()}, matching the side
     * {@see MessageHandler::getNetmail()} itself uses for its own `unread`
     * filter, so this is the same figure a future Web/Telnet unread badge
     * would show.
     */
    protected function netmailUnreadCount(int $userId): int
    {
        $vis = $this->messages->netmailVisibilityFilter($userId, 'n', 'recipient');
        $del = $this->messages->netmailNotDeletedFilter($userId, 'n');

        $sql = "
            SELECT COUNT(*)
            FROM netmail n
            LEFT JOIN message_read_status mrs
                ON (mrs.message_id = n.id AND mrs.message_type = 'netmail' AND mrs.user_id = ?)
            WHERE {$vis['sql']}
              AND {$del['sql']}
              AND mrs.read_at IS NULL
        ";

        $params = array_merge([$userId], $vis['params'], $del['params']);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Echomail replies to messages the caller themselves authored locally,
     * since the visit boundary, oldest first, bounded. "The caller's own
     * message" is resolved via `echomail.user_id` — set only for messages
     * actually composed through this system by that account (never set on
     * FTN-imported rows), so this is an exact identity test with no
     * name-matching ambiguity. `reply_to_id` linkage itself is resolved (and,
     * for out-of-order FTN arrivals, backfilled) entirely by
     * {@see \BinktermPHP\BinkdProcessor} at ingest time — never re-derived
     * here. Filtered by the same ignore/moderation/sysop-only predicates
     * every other Echomail list query uses.
     *
     * `em.user_id != ?` excludes the caller's own reply to their own
     * message — a caller replying to themselves is never "someone replied
     * to you" (fixed as part of Messaging Evolution Phase 1; this exact
     * gap was surfaced, not introduced, by that design's own Track C/J
     * review of this pre-existing Slice 2 query — see
     * /root/L33TEST_Messaging_Phase1_Personal_Relevance_Design_2026-09-14.md).
     *
     * No separate Echomail soft-delete predicate is applied here because
     * none exists: `MessageHandler::deleteEchomail()` performs a hard
     * `DELETE FROM echomail`, never a soft-delete flag — confirmed directly
     * against the schema and delete path (no `deleted_at`/`is_deleted`
     * column on `echomail` anywhere in `database/postgresql_schema.sql` or
     * any migration). A hard-deleted row cannot be selected by this or any
     * other query, so there is no visibility gap to close here — this
     * resolves the ambiguity definitively rather than adding a predicate
     * for a mechanism that doesn't exist.
     *
     * @return int[]
     */
    protected function repliesToCallerSinceIds(int $userId, string $boundary, bool $isAdmin, int $cap): array
    {
        $ignore     = $this->messages->buildEchomailIgnoreFilter($userId, 'em');
        $moderation = $this->messages->buildModerationVisibilityFilter($userId, 'em');
        $sysop      = $isAdmin ? '' : ' AND COALESCE(ea.is_sysop_only, FALSE) = FALSE';

        $sql = "
            SELECT em.id
            FROM echomail em
            JOIN echoareas ea ON ea.id = em.echoarea_id
            WHERE em.reply_to_id IN (SELECT id FROM echomail WHERE user_id = ?)
              AND (em.user_id IS NULL OR em.user_id != ?)
              AND em.date_received > ?
              AND (em.date_written IS NULL OR em.date_written <= (NOW() AT TIME ZONE 'UTC'))
              {$sysop}
              {$ignore['sql']}
              {$moderation['sql']}
            ORDER BY em.date_received ASC
            LIMIT ?
        ";

        $params = [$userId, $userId, $boundary];
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

    /**
     * Echomail activity, since the visit boundary, in any conversation the
     * caller has themselves participated in at any point (authored the root
     * or any reply within it — a lifetime fact, not bounded by the visit
     * boundary itself, per Messaging Evolution Phase 1's Track D). Excludes
     * the caller's own messages (no self-notification) and anything already
     * present in `$excludeIds` (typically the caller's `replyIds` — a direct
     * reply is never double-counted as broader conversation activity; direct
     * personal relevance outranks broader conversation activity, per the
     * approved design's Track F/G precedence).
     *
     * Keyed off `echomail.root_id`, a materialized conversation-root pointer
     * (Messaging Evolution Phase 1's Track B) — an O(1) join against the
     * caller's own already-participated roots, not a per-request recursive
     * ancestry walk. Filtered by the exact same
     * ignore/moderation/sysop-only predicates every other Echomail activity
     * query in this class already uses.
     *
     * @param int[] $excludeIds message ids already counted elsewhere (the
     *     caller's own `replyIds` for this same `plan()` call)
     * @return int[]
     */
    protected function participatedActivitySinceIds(int $userId, string $boundary, bool $isAdmin, array $excludeIds, int $cap): array
    {
        $ignore     = $this->messages->buildEchomailIgnoreFilter($userId, 'em');
        $moderation = $this->messages->buildModerationVisibilityFilter($userId, 'em');
        $sysop      = $isAdmin ? '' : ' AND COALESCE(ea.is_sysop_only, FALSE) = FALSE';

        $excludeClause = '';
        $excludeParams = [];
        if ($excludeIds !== []) {
            $placeholders = implode(',', array_fill(0, count($excludeIds), '?'));
            $excludeClause = " AND em.id NOT IN ({$placeholders})";
            $excludeParams = array_map('intval', $excludeIds);
        }

        $sql = "
            SELECT em.id
            FROM echomail em
            JOIN echoareas ea ON ea.id = em.echoarea_id
            WHERE em.root_id IN (
                    SELECT DISTINCT root_id FROM echomail
                    WHERE user_id = ? AND root_id IS NOT NULL
                  )
              AND (em.user_id IS NULL OR em.user_id != ?)
              AND em.date_received > ?
              AND (em.date_written IS NULL OR em.date_written <= (NOW() AT TIME ZONE 'UTC'))
              {$sysop}
              {$excludeClause}
              {$ignore['sql']}
              {$moderation['sql']}
            ORDER BY em.date_received ASC
            LIMIT ?
        ";

        $params = [$userId, $userId, $boundary];
        foreach ($excludeParams as $p) {
            $params[] = $p;
        }
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

    /**
     * Subscribed, active, accessible echoareas holding at least one new
     * message since the visit boundary, with a per-area count — one grouped
     * statement (filter-then-aggregate: every WHERE predicate below runs
     * before COUNT(*) groups the surviving rows), not one query per area.
     * Areas with nothing new are absent — a caller treats a missing area as
     * zero. Same ignore/moderation/sysop-only/subscription-active predicates
     * as every other ambient Echomail query in this codebase.
     *
     * @return list<array{echoareaId:int,tag:string,domain:string,isLocal:bool,sinceBoundaryCount:int}>
     */
    protected function areasWithActivitySinceBoundary(int $userId, bool $isAdmin, string $boundary, int $cap): array
    {
        $ignore     = $this->messages->buildEchomailIgnoreFilter($userId, 'em');
        $moderation = $this->messages->buildModerationVisibilityFilter($userId, 'em');
        $sysop      = $isAdmin ? '' : ' AND COALESCE(ea.is_sysop_only, FALSE) = FALSE';

        $sql = "
            SELECT ea.id AS echoarea_id, ea.tag, ea.domain, ea.is_local, COUNT(*) AS since_count
            FROM echomail em
            JOIN echoareas ea ON ea.id = em.echoarea_id AND ea.is_active = TRUE{$sysop}
            JOIN user_echoarea_subscriptions ues
                ON ues.echoarea_id = ea.id AND ues.user_id = ? AND ues.is_active = TRUE
            WHERE em.date_received > ?
              AND (em.date_written IS NULL OR em.date_written <= (NOW() AT TIME ZONE 'UTC'))
              {$ignore['sql']}
              {$moderation['sql']}
            GROUP BY ea.id, ea.tag, ea.domain, ea.is_local
            ORDER BY ea.tag ASC, COALESCE(ea.domain, '') ASC
            LIMIT ?
        ";

        $params = [$userId, $boundary];
        foreach ($ignore['params'] as $p) {
            $params[] = $p;
        }
        foreach ($moderation['params'] as $p) {
            $params[] = $p;
        }
        $params[] = $cap;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'echoareaId'         => (int) $r['echoarea_id'],
                'tag'                => (string) $r['tag'],
                'domain'             => (string) ($r['domain'] ?? ''),
                'isLocal'            => in_array($r['is_local'], [true, 1, '1', 't'], true),
                'sinceBoundaryCount' => (int) $r['since_count'],
            ];
        }

        return $out;
    }

    protected function bulletinUnreadCount(int $userId): int
    {
        try {
            return (new BulletinManager($this->db))->getUnreadCount($userId);
        } catch (\Throwable $e) {
            // Bulletins are a summary nicety; never let them break the plan.
            return 0;
        }
    }
}
