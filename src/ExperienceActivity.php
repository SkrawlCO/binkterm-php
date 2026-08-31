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
        $backend = $experience['backend'] ?? null;

        if (!is_array($backend)) {
            return [];
        }

        $backendId = trim((string)($backend['id'] ?? ''));

        if ($backendId === '') {
            return [];
        }

        $limit = max(1, min($limit, 50));

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
                  AND al.object_name = ?
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
            $backendId,
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
     * Return a small, bounded set of the most recent play footprints across an
     * already-authorized Experience catalog.
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
     * The first-play distinction is preserved without an N+1 query: a single
     * window function partitions by (user, backend id) over the full matching
     * history, so a row is `first_play` when it is that user's chronologically
     * earliest recorded play of that Experience.
     *
     * Ordering is deterministic, newest first. Timestamps are the durable
     * `created_at` values, unmodified. No historical row is mutated, pruned, or
     * backfilled, and no writer is altered.
     *
     * Known activity-data limitations are intentionally not corrected here:
     * managed doors record a play on a fresh session but not on resume; the
     * WebDoor session endpoint can record repeated `webdoor_play` rows on
     * player reload. Raw ordering is preserved.
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

        // Allow-list keyed by backend id; remember the current catalog id (for
        // linking) and current name (for presentation). First entry wins on the
        // unlikely event of a backend-id collision across backend types.
        $nameByBackendId = [];
        $idByBackendId = [];

        foreach ($experiences as $experience) {
            if (!is_array($experience)) {
                continue;
            }

            $backendId = trim((string)($experience['backend']['id'] ?? ''));
            $catalogId = trim((string)($experience['id'] ?? ''));

            if ($backendId === '' || $catalogId === '' || isset($nameByBackendId[$backendId])) {
                continue;
            }

            $name = trim((string)($experience['name'] ?? ''));
            $nameByBackendId[$backendId] = $name !== '' ? $name : $catalogId;
            $idByBackendId[$backendId] = $catalogId;
        }

        if ($nameByBackendId === []) {
            return [];
        }

        $backendIds = array_keys($nameByBackendId);
        $inPlaceholders = implode(',', array_fill(0, count($backendIds), '?'));

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
                    ) AS play_number
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
            ORDER BY ep.created_at DESC, ep.id DESC
            LIMIT {$limit}
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

        return $activity;
    }
}
