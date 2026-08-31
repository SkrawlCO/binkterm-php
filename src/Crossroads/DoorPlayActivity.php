<?php

declare(strict_types=1);

namespace BinktermPHP\Crossroads;

use BinktermPHP\Database;
use PDO;

/**
 * The Crossroads door-play activity footprint contract.
 *
 * A `*_play` row (`ActivityTracker::TYPE_DOSDOOR_PLAY` / `TYPE_WEBDOOR_PLAY`,
 * written from an Experience launch/entry route) means exactly one thing:
 *
 *   "The user entered or returned to this Experience."
 *
 * It is historical event data. It does **not** imply a new runtime was created,
 * that the user completed anything, or anything about duration, score, progress,
 * current presence, or resumability.
 *
 * Managed DOS/native/RLogin doors, WebDoors, and JS-DOS all re-hit their entry
 * route on a browser reload, bfcache restore, or double request, so a single
 * genuine entry can arrive several times within seconds. {@see record()}
 * collapses that: a footprint for the same
 * `(user_id, activity_type_id, object_name)` written within
 * {@see DEDUP_WINDOW_SECONDS} is not repeated. A real later return, outside that
 * window, records normally.
 *
 * This is scoped to door-play deliberately: it does not touch
 * `ActivityTracker::track()` and no other activity type is affected. The
 * `INSERT` mirrors `ActivityTracker::track()`'s column contract (object_id and
 * meta unused for door-play) but is kept local so the de-dup check and the
 * write share one connection and the whole operation is injectable for tests.
 */
final class DoorPlayActivity
{
    /**
     * Long enough to absorb reload / bfcache / double-submit storms; short
     * enough that a deliberate re-entry a minute later still records.
     */
    public const DEDUP_WINDOW_SECONDS = 60;

    /**
     * Record a door-play footprint, unless an identical one is already fresh.
     *
     * @param int|null    $userId        Authenticated user id (>0); no-op otherwise.
     * @param int         $activityTypeId `TYPE_DOSDOOR_PLAY` or `TYPE_WEBDOOR_PLAY`.
     * @param string|null $objectName    Stable Experience/backend id; no-op if empty.
     * @param PDO|null    $db            Connection override (tests); defaults to the app connection.
     */
    public static function record(
        ?int $userId,
        int $activityTypeId,
        ?string $objectName,
        ?PDO $db = null
    ): void {
        if ($userId === null || $userId <= 0) {
            return;
        }

        $objectName = $objectName === null ? '' : trim($objectName);
        if ($objectName === '') {
            return;
        }

        try {
            $db = $db ?? Database::getInstance()->getPdo();

            // Portable, deterministic window bound: compute the cutoff in PHP
            // (UTC) rather than depending on NOW()/INTERVAL SQL semantics.
            $recentCutoff = gmdate('Y-m-d H:i:s', time() - self::DEDUP_WINDOW_SECONDS);

            $check = $db->prepare(
                'SELECT 1
                   FROM user_activity_log
                  WHERE user_id = ?
                    AND activity_type_id = ?
                    AND object_name = ?
                    AND created_at > ?
                  LIMIT 1'
            );
            $check->execute([$userId, $activityTypeId, $objectName, $recentCutoff]);

            if ($check->fetchColumn() !== false) {
                return;
            }

            $insert = $db->prepare(
                'INSERT INTO user_activity_log
                     (user_id, activity_type_id, object_id, object_name, meta)
                 VALUES (?, ?, NULL, ?, NULL)'
            );
            $insert->execute([$userId, $activityTypeId, $objectName]);
        } catch (\Throwable $e) {
            // Activity tracking must never break an Experience launch.
        }
    }
}
