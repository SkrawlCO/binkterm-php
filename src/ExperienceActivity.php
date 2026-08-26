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
            SELECT
                al.id,
                al.user_id,
                u.username,
                al.activity_type_id,
                al.object_name,
                al.created_at
            FROM user_activity_log al
            LEFT JOIN users u
              ON u.id = al.user_id
            WHERE al.activity_type_id IN (?, ?)
              AND al.object_name = ?
            ORDER BY al.created_at DESC, al.id DESC
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
                'type' => 'play',
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
}
