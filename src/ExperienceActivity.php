<?php

namespace BinktermPHP;

use PDO;

/**
 * Read-side normalization of historical activity for an Experience.
 *
 * ActivityTracker remains the persistence authority. This class translates
 * existing backend play events into an Experience-level activity contract.
 */
final class ExperienceActivity
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getPdo();
    }

    /**
     * Return recent normalized activity for an Experience.
     *
     * @param array<string,mixed> $experience
     * @return array<int,array<string,mixed>>
     */
    public function recent(array $experience, int $limit = 10): array
    {
        // A grouped Experience (ExperienceComposition) has more than one member
        // backend id; a raw play row under any of them is this Experience's
        // activity. play_number is partitioned by user only, so it is still the
        // person's Nth play of the Experience across every surface. An ungrouped
        // entry yields one member id equal to today's backend.id -- unchanged.
        $backendIds = array_values(array_unique(array_map(
            static fn(array $m): string => $m['id'],
            ExperienceComposition::backendMembers($experience)
        )));

        if ($backendIds === []) {
            return [];
        }

        $limit = max(1, min($limit, 50));
        $inPlaceholders = implode(',', array_fill(0, count($backendIds), '?'));

        $stmt = $this->db->prepare("
            WITH experience_plays AS (
                SELECT
                    al.id,
                    al.user_id,
                    al.activity_type_id,
                    al.object_name,
                    al.created_at,
                    ROW_NUMBER() OVER (
                        PARTITION BY al.user_id
                        ORDER BY al.created_at ASC, al.id ASC
                    ) AS play_number
                FROM user_activity_log al
                WHERE al.activity_type_id IN (?, ?)
                  AND al.object_name IN ({$inPlaceholders})
            )
            SELECT
                ep.id,
                ep.user_id,
                u.username,
                ep.activity_type_id,
                ep.object_name,
                ep.created_at,
                ep.play_number
            FROM experience_plays ep
            LEFT JOIN users u
              ON u.id = ep.user_id
            ORDER BY ep.created_at DESC, ep.id DESC
            LIMIT {$limit}
        ");

        $stmt->execute([
            ActivityTracker::TYPE_WEBDOOR_PLAY,
            ActivityTracker::TYPE_DOSDOOR_PLAY,
            ...$backendIds,
        ]);

        $activity = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $activity[] = [
                'id' => (int)$row['id'],
                'type' => $row['user_id'] !== null
                    && (int)$row['play_number'] === 1
                        ? 'first_play'
                        : 'play',
                'user_id' => $row['user_id'] !== null
                    ? (int)$row['user_id']
                    : null,
                'username' => $row['username'] !== null
                    ? (string)$row['username']
                    : null,
                'occurred_at' => (string)$row['created_at'],
            ];
        }

        return $activity;
    }

    /**
     * Return a small, bounded set of the most recent DISTINCT
     * person + Experience play footprints across an already-authorized
     * Experience catalog.
     *
     * This is the read model for the authenticated web Crossroads "Recently in
     * the Crossroads" section: truthful historical evidence that the place is
     * used even when nobody is present right now. It is NOT live presence and
     * NOT an activity feed.
     *
     * Only the existing play activity types are consulted
     * ({@see ActivityTracker::TYPE_WEBDOOR_PLAY},
     * {@see ActivityTracker::TYPE_DOSDOOR_PLAY}) — no new event semantics.
     *
     * Distinct-pair collapsing (arrival-page composition only): repeated plays
     * by the SAME user in the SAME Experience collapse to that pair's single
     * newest qualifying footprint, and the five newest distinct pairs overall
     * are returned. This is a purely structural rule — one newest footprint per
     * (user, backend id) pair — NOT time-window de-duplication (no "once per
     * N minutes / per session / per day"). A user may appear more than once for
     * different Experiences; different users may appear for the same Experience.
     * {@see recent()} for individual Experience detail is untouched and keeps
     * its existing raw activity semantics.
     *
     * The caller passes its own authorized `GameCatalog::getEnabledGames()`
     * result. Every returned row is therefore guaranteed to belong to an
     * Experience the viewer is authorized to discover:
     *
     *   - a historical `object_name` that is not in the authorized catalog
     *     (hidden, admin-only, disabled, removed, or renamed/orphaned) simply
     *     never matches and disappears;
     *   - the presentation name is always the CURRENT catalog name, not the
     *     stale snapshot stored on the activity row;
     *   - system users (e.g. the shared `_guest` account) are excluded;
     *   - rows whose user has been deleted (`user_id` nulled by
     *     `ON DELETE SET NULL`) are dropped rather than shown as "Unknown user".
     *
     * First-play status is derived from the FULL matching history, not just the
     * collapsed result: a `play_number` window partitioned by (user, backend
     * id) over every matching row runs alongside a recency window, so the
     * selected (newest) footprint for a pair renders `first_play` only when it
     * is genuinely that user's first-ever recorded play of the Experience, and
     * ordinary `play` when older plays exist — even though those older rows are
     * collapsed out of the output. One query, no N+1.
     *
     * Distinct-pair selection happens in SQL BEFORE the five-row limit: the
     * result is the five newest distinct footprints, never five raw rows deduped
     * down to fewer afterwards.
     *
     * Ordering is deterministic, newest first (`created_at DESC, id DESC`).
     * Timestamps are the durable `created_at` values, unmodified. No historical
     * row is mutated, pruned, or backfilled, and no writer is altered.
     *
     * @param array<mixed> $experiences Authorized normalized Experiences,
     *     e.g. `GameCatalog::getEnabledGames($user, 'web')` (keyed or a list).
     * @param int $limit Requested cap; bounded defensively to at most 25.
     * @return array<int,array{
     *     id:int,
     *     type:string,
     *     user_id:int,
     *     username:string,
     *     experience_id:string,
     *     experience_name:string,
     *     occurred_at:string
     * }>
     */
    public function recentAcrossCatalog(array $experiences, int $limit = 5): array
    {
        $limit = max(1, min($limit, 25));

        [$nameByBackendId, $idByBackendId] = $this->buildCatalogAllowList($experiences);

        if ($nameByBackendId === []) {
            return [];
        }

        $backendIds = array_keys($nameByBackendId);
        $inPlaceholders = implode(',', array_fill(0, count($backendIds), '?'));

        // SQL still de-dupes per (user, object_name). When the catalog contains a
        // grouped Experience, two member backend ids can each yield one row for
        // the same person that then attribute to the one canonical Experience, so
        // over-fetch (a group has at most one member per surface -> at most 2x)
        // and collapse + slice in PHP. Ungrouped catalogs over-fetch nothing and
        // the SQL LIMIT is unchanged.
        $canonicalIds = array_values($idByBackendId);
        $hasGrouped = count($backendIds) > count(array_unique($canonicalIds));
        $fetchLimit = $hasGrouped ? $limit * 2 : $limit;

        $stmt = $this->db->prepare("
            WITH experience_plays AS (
                SELECT
                    al.id,
                    al.user_id,
                    al.object_name,
                    al.created_at,
                    ROW_NUMBER() OVER (
                        PARTITION BY al.user_id, al.object_name
                        ORDER BY al.created_at ASC, al.id ASC
                    ) AS play_number,
                    ROW_NUMBER() OVER (
                        PARTITION BY al.user_id, al.object_name
                        ORDER BY al.created_at DESC, al.id DESC
                    ) AS recency_rank
                FROM user_activity_log al
                WHERE al.activity_type_id IN (?, ?)
                  AND al.user_id IS NOT NULL
                  AND al.object_name IN ({$inPlaceholders})
            )
            SELECT
                ep.id,
                ep.user_id,
                u.username,
                ep.object_name,
                ep.created_at,
                ep.play_number
            FROM experience_plays ep
            JOIN users u
              ON u.id = ep.user_id
             AND u.is_system = FALSE
            WHERE ep.recency_rank = 1
            ORDER BY ep.created_at DESC, ep.id DESC
            LIMIT {$fetchLimit}
        ");

        $params = [
            ActivityTracker::TYPE_WEBDOOR_PLAY,
            ActivityTracker::TYPE_DOSDOOR_PLAY,
        ];
        foreach ($backendIds as $backendId) {
            $params[] = $backendId;
        }
        $stmt->execute($params);

        $activity = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $backendId = (string)$row['object_name'];

            // Defensive: a row can only reach here if its object_name is in the
            // authorized allow-list, but re-assert before presenting.
            if (!isset($nameByBackendId[$backendId])) {
                continue;
            }

            $activity[] = [
                'id' => (int)$row['id'],
                'type' => (int)$row['play_number'] === 1 ? 'first_play' : 'play',
                'user_id' => (int)$row['user_id'],
                'username' => (string)$row['username'],
                'experience_id' => $idByBackendId[$backendId],
                'experience_name' => $nameByBackendId[$backendId],
                'occurred_at' => (string)$row['created_at'],
            ];
        }

        return array_slice(
            $this->collapseByUserAndExperience($activity),
            0,
            $limit
        );
    }

    /**
     * The viewer's own single most-recent authorized Experience play footprint
     * — the read behind the dashboard Crossroads pulse "You played …" personal
     * continuity state.
     *
     * This is a **historical personal relationship** only. A returned row means
     * the viewer entered or returned to that Experience at that time (see the
     * door-play activity contract in `docs/EXPERIENCE_ARCHITECTURE.md`) and
     * nothing more: not current participation, not resumability / Return, not
     * saved progress or a persisted character, not current presence, not
     * duration, not completion.
     *
     * Authorization is identical to {@see recentAcrossCatalog()}: the caller
     * passes its own already-authorized catalog (e.g. the `experience` column of
     * `ExperienceState::getExperienceStates($user, 'web')`). A footprint whose
     * backend id is not in that catalog — disabled, hidden, admin-only, removed,
     * renamed/orphaned, or never authorized for this viewer — simply never
     * matches and cannot leak onto the dashboard. The presentation name is
     * always the CURRENT catalog name, never the stale snapshot on the row. No
     * historical row is mutated, pruned, or backfilled.
     *
     * Both existing play activity types are consulted
     * ({@see ActivityTracker::TYPE_WEBDOOR_PLAY},
     * {@see ActivityTracker::TYPE_DOSDOOR_PLAY}); no new event semantics. This
     * adds no table, endpoint, cache, or realtime mechanism.
     *
     * Ordering is deterministic, newest first (`created_at DESC, id DESC`).
     *
     * @param array<mixed> $experiences Authorized normalized Experiences,
     *     keyed or a list.
     * @param int $userId Numeric id of the authenticated viewer; <= 0 yields [].
     * @param int $limit Requested cap; bounded defensively to at most 5.
     * @return array<int,array{
     *     id:int,
     *     user_id:int,
     *     experience_id:string,
     *     experience_name:string,
     *     occurred_at:string
     * }>
     */
    public function recentForUser(array $experiences, int $userId, int $limit = 1): array
    {
        if ($userId <= 0) {
            return [];
        }

        $limit = max(1, min($limit, 5));

        [$nameByBackendId, $idByBackendId] = $this->buildCatalogAllowList($experiences);

        if ($nameByBackendId === []) {
            return [];
        }

        $backendIds = array_keys($nameByBackendId);
        $inPlaceholders = implode(',', array_fill(0, count($backendIds), '?'));

        // Over-fetch + collapse when a grouped Experience is in the catalog so
        // two member ids' rows for this viewer resolve to one canonical footprint
        // without shortening the result. Unchanged for ungrouped catalogs.
        $canonicalIds = array_values($idByBackendId);
        $hasGrouped = count($backendIds) > count(array_unique($canonicalIds));
        $fetchLimit = $hasGrouped ? $limit * 2 : $limit;

        $stmt = $this->db->prepare("
            SELECT
                al.id,
                al.user_id,
                al.object_name,
                al.created_at
            FROM user_activity_log al
            WHERE al.activity_type_id IN (?, ?)
              AND al.user_id = ?
              AND al.object_name IN ({$inPlaceholders})
            ORDER BY al.created_at DESC, al.id DESC
            LIMIT {$fetchLimit}
        ");

        $params = [
            ActivityTracker::TYPE_WEBDOOR_PLAY,
            ActivityTracker::TYPE_DOSDOOR_PLAY,
            $userId,
        ];
        foreach ($backendIds as $backendId) {
            $params[] = $backendId;
        }
        $stmt->execute($params);

        $activity = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $backendId = (string)$row['object_name'];

            // Defensive: a row can only reach here if its object_name is in the
            // authorized allow-list, but re-assert before presenting.
            if (!isset($nameByBackendId[$backendId])) {
                continue;
            }

            $activity[] = [
                'id' => (int)$row['id'],
                'user_id' => (int)$row['user_id'],
                'experience_id' => $idByBackendId[$backendId],
                'experience_name' => $nameByBackendId[$backendId],
                'occurred_at' => (string)$row['created_at'],
            ];
        }

        return array_slice(
            $this->collapseByUserAndExperience($activity),
            0,
            $limit
        );
    }

    /**
     * Build the authorized-catalog allow-list shared by the recent-activity
     * reads: presentation name and current canonical catalog id keyed by every
     * backend id that belongs to the Experience. A grouped Experience
     * (ExperienceComposition) contributes one entry per member backend id, all
     * pointing at the one canonical id and name, so historical activity stored
     * under any member id resolves to the single card. An ungrouped Experience
     * contributes one entry equal to today's `backend.id`, so its behaviour is
     * unchanged. First entry wins on the unlikely event of a backend-id
     * collision across backend types.
     *
     * @param array<mixed> $experiences
     * @return array{0:array<string,string>,1:array<string,string>}
     *     `[nameByBackendId, idByBackendId]`.
     */
    private function buildCatalogAllowList(array $experiences): array
    {
        $nameByBackendId = [];
        $idByBackendId = [];

        foreach ($experiences as $experience) {
            if (!is_array($experience)) {
                continue;
            }

            $catalogId = trim((string)($experience['id'] ?? ''));
            if ($catalogId === '') {
                continue;
            }

            $name = trim((string)($experience['name'] ?? ''));
            $name = $name !== '' ? $name : $catalogId;

            foreach (ExperienceComposition::backendMembers($experience) as $member) {
                $backendId = $member['id'];
                if ($backendId === '' || isset($nameByBackendId[$backendId])) {
                    continue;
                }
                $nameByBackendId[$backendId] = $name;
                $idByBackendId[$backendId] = $catalogId;
            }
        }

        return [$nameByBackendId, $idByBackendId];
    }

    /**
     * Collapse a newest-first activity list to one row per
     * (user_id, canonical experience_id) pair, keeping the first (newest) seen.
     *
     * A grouped Experience can legitimately return one raw row per member
     * backend id for the same person; after those rows are attributed to the one
     * canonical Experience this removes the resulting duplicate footprint while
     * preserving order. An ungrouped Experience has one row per pair already, so
     * this is a no-op for it.
     *
     * @param array<int,array<string,mixed>> $activity newest-first
     * @return array<int,array<string,mixed>>
     */
    private function collapseByUserAndExperience(array $activity): array
    {
        $seen = [];
        $out = [];

        foreach ($activity as $row) {
            $key = (string)($row['user_id'] ?? '') . "\0" . (string)($row['experience_id'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $row;
        }

        return $out;
    }
}
