<?php

declare(strict_types=1);

namespace BinktermPHP\Tests\Unit;

use BinktermPHP\ExperienceScoreboard;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class ExperienceScoreboardTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            username TEXT NOT NULL,
            real_name TEXT
        )');
        $this->db->exec("CREATE TABLE webdoor_leaderboards (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            game_id TEXT NOT NULL,
            board TEXT NOT NULL,
            score INTEGER NOT NULL,
            metadata TEXT NOT NULL DEFAULT '{}',
            created_at TEXT NOT NULL
        )");
    }

    public function testFiltersUndiscoverableScoresBeforeCompactLimit(): void
    {
        $this->insertScore(1, 'Hidden Player', 'hidden-game', 'hidden-board', 9999);
        for ($userId = 2; $userId <= 7; $userId++) {
            $this->insertScore(
                $userId,
                'Visible Player ' . $userId,
                'public-game',
                'public-board',
                1000 - $userId
            );
        }

        $scores = (new ExperienceScoreboard())->getMonthlyScores(
            $this->db,
            [
                'public-game' => [
                    'name' => 'Public Game',
                    'launch' => ['url' => '/games/public-game'],
                ],
            ],
            new DateTimeImmutable('2026-08-01 00:00:00'),
            new DateTimeImmutable('2026-09-01 00:00:00'),
            false
        );

        self::assertCount(5, $scores);
        self::assertSame([1, 2, 3, 4, 5], array_column($scores, 'rank'));
        self::assertSame(['public-game'], array_values(array_unique(array_column($scores, 'game_id'))));
        self::assertSame(['Public Game'], array_values(array_unique(array_column($scores, 'game_name'))));

        $serialized = json_encode($scores, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('hidden-game', $serialized);
        self::assertStringNotContainsString('hidden-board', $serialized);
        self::assertStringNotContainsString('Hidden Player', $serialized);
        self::assertStringNotContainsString('9999', $serialized);
    }

    public function testEmptyCatalogReturnsNoScores(): void
    {
        $scores = (new ExperienceScoreboard())->getMonthlyScores(
            $this->db,
            [],
            new DateTimeImmutable('2026-08-01 00:00:00'),
            new DateTimeImmutable('2026-09-01 00:00:00'),
            false
        );

        self::assertSame([], $scores);
    }

    private function insertScore(
        int $userId,
        string $username,
        string $gameId,
        string $board,
        int $score
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO users (id, username, real_name) VALUES (?, ?, ?)'
        );
        $stmt->execute([$userId, $username, $username]);

        $stmt = $this->db->prepare(
            'INSERT INTO webdoor_leaderboards '
            . '(user_id, game_id, board, score, created_at) '
            . 'VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $gameId,
            $board,
            $score,
            '2026-08-15 12:00:00',
        ]);
    }
}
