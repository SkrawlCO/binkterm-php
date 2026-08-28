<?php

declare(strict_types=1);

namespace BinktermPHP;

use DateTimeInterface;
use PDO;

/**
 * Read viewer-visible Experience scores without bypassing catalog discovery.
 */
final class ExperienceScoreboard
{
    /**
     * Load the selected month's best scores for discoverable Experiences.
     *
     * @param array<string, array{name:string, launch:mixed}> $experienceLookup
     * @return array<int, array<string, mixed>>
     */
    public function getMonthlyScores(
        PDO $db,
        array $experienceLookup,
        DateTimeInterface $monthStart,
        DateTimeInterface $monthEnd,
        bool $expanded,
        int $compactLimit = 5
    ): array {
        $allowedIds = array_keys($experienceLookup);
        if ($allowedIds === []) {
            return [];
        }

        $idPlaceholders = implode(', ', array_fill(0, count($allowedIds), '?'));
        $limitClause = $expanded ? '' : 'LIMIT ?';
        $stmt = $db->prepare(''
            . 'WITH ranked_scores AS ('
            . ' SELECT l.user_id, l.game_id, l.board, l.score, l.created_at,'
            . ' ROW_NUMBER() OVER ('
            . ' PARTITION BY l.user_id, l.game_id, l.board'
            . ' ORDER BY l.score DESC, l.created_at DESC'
            . ' ) AS score_rank'
            . ' FROM webdoor_leaderboards l'
            . ' WHERE l.created_at >= ? AND l.created_at < ?'
            . " AND l.game_id IN ({$idPlaceholders})"
            . ' )'
            . ' SELECT r.game_id, r.board, u.real_name, u.username,'
            . ' r.score, r.created_at'
            . ' FROM ranked_scores r'
            . ' JOIN users u ON r.user_id = u.id'
            . ' WHERE r.score_rank = 1'
            . ' ORDER BY r.score DESC, r.created_at DESC '
            . $limitClause
        );

        $parameter = 1;
        $stmt->bindValue($parameter++, $monthStart->format('Y-m-d H:i:s'));
        $stmt->bindValue($parameter++, $monthEnd->format('Y-m-d H:i:s'));
        foreach ($allowedIds as $allowedId) {
            $stmt->bindValue($parameter++, $allowedId);
        }
        if (!$expanded) {
            $stmt->bindValue($parameter, $compactLimit, PDO::PARAM_INT);
        }
        $stmt->execute();

        $leaderboard = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $gameInfo = $experienceLookup[$row['game_id']] ?? null;
            if ($gameInfo === null) {
                continue;
            }

            $leaderboard[] = [
                'rank' => count($leaderboard) + 1,
                'display_name' => $row['username'],
                'score' => (int)$row['score'],
                'game_id' => $row['game_id'],
                'game_name' => $gameInfo['name'],
                'game_launch' => $gameInfo['launch'] ?? null,
                'board' => $row['board'],
                'date' => substr($row['created_at'], 0, 10),
            ];
        }

        return $leaderboard;
    }
}
