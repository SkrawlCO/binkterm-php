<?php

declare(strict_types=1);

namespace BinktermPHP\Tests\Unit;

use BinktermPHP\Auth;
use BinktermPHP\GameCatalog;
use BinktermPHP\WebDoorController;
use PDO;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class WebDoorLeaderboardAuthorizationTest extends TestCase
{
    private PDO $db;
    private Auth&MockObject $auth;
    private GameCatalog&MockObject $catalog;

    protected function setUp(): void
    {
        $_GET = [];
        unset($_SERVER['HTTP_REFERER']);
        http_response_code(200);

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
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $this->db->exec("INSERT INTO users (id, username, real_name)
            VALUES (42, 'viewer', 'Viewer')");

        $this->auth = $this->createMock(Auth::class);
        $this->auth->method('getCurrentUser')->willReturn([
            'user_id' => 42,
            'username' => 'viewer',
            'real_name' => 'Viewer',
        ]);
        $this->catalog = $this->createMock(GameCatalog::class);
    }

    protected function tearDown(): void
    {
        $_GET = [];
        unset($_SERVER['HTTP_REFERER']);
        http_response_code(200);
    }

    public function testGetRejectsUndiscoverableExperienceWithoutReadingScores(): void
    {
        $this->insertScore('hidden-game', 'secret-board', 9000);
        $_GET['game_id'] = 'hidden-game';
        $this->catalog->expects(self::once())
            ->method('getEnabledGames')
            ->with(self::isType('array'), 'web')
            ->willReturn($this->authorizedCatalog());

        $result = $this->controller()->getLeaderboard('secret-board');

        self::assertFalse($result['success']);
        self::assertSame('errors.webdoor.game_unavailable', $result['error_code']);
        self::assertSame(404, http_response_code());
        self::assertArrayNotHasKey('entries', $result);
    }

    public function testGetSucceedsForAuthorizedWebDoorUsingExplicitId(): void
    {
        $this->insertScore('public-game', 'scores', 1200);
        $_GET['game_id'] = 'public-game';
        $this->catalog->method('getEnabledGames')->willReturn($this->authorizedCatalog());

        $result = $this->controller()->getLeaderboard('scores');

        self::assertSame('scores', $result['board']);
        self::assertSame(1200, $result['entries'][0]['score']);
        self::assertSame('viewer', $result['entries'][0]['display_name']);
    }

    public function testGetPreservesReferrerInferredWebDoorIdentity(): void
    {
        $this->insertScore('public-game', 'scores', 1200);
        $_SERVER['HTTP_REFERER'] = 'https://bbs.example/webdoors/public-game/index.html';
        $this->catalog->method('getEnabledGames')->willReturn($this->authorizedCatalog());

        $result = $this->controller()->getLeaderboard('scores');

        self::assertSame(1, $result['total_entries']);
    }

    public function testPostRejectsUnknownIdentityWithoutPersistingIt(): void
    {
        $this->catalog->method('getEnabledGames')->willReturn($this->authorizedCatalog());

        $result = $this->controller()->submitScore('scores');

        self::assertFalse($result['success']);
        self::assertSame('errors.webdoor.game_unavailable', $result['error_code']);
        self::assertSame(0, $this->scoreCount());
        self::assertSame(0, $this->scoreCount('unknown'));
    }

    public function testPostRejectsNonWebExperienceWithoutPersistingIt(): void
    {
        $_GET['game_id'] = 'terminal-only';
        $catalog = $this->authorizedCatalog();
        $catalog['terminal-only'] = [
            'id' => 'terminal-only',
            'backend' => ['type' => 'native', 'id' => 'terminal-only'],
            'surfaces' => ['web' => 'full', 'telnet' => 'full'],
        ];
        $this->catalog->method('getEnabledGames')->willReturn($catalog);

        $result = $this->controller()->submitScore('scores');

        self::assertFalse($result['success']);
        self::assertSame(0, $this->scoreCount());
    }

    public function testPostSucceedsForAuthorizedWebDoor(): void
    {
        $_GET['game_id'] = 'public-game';
        $this->catalog->method('getEnabledGames')->willReturn($this->authorizedCatalog());

        $result = $this->controller()->submitScore('scores');

        self::assertTrue($result['accepted']);
        self::assertSame(1, $this->scoreCount('public-game'));
        self::assertSame(0, $this->scoreCount('unknown'));
    }

    private function controller(): WebDoorController
    {
        return new WebDoorController($this->db, $this->auth, $this->catalog);
    }

    /** @return array<string, array<string, mixed>> */
    private function authorizedCatalog(): array
    {
        return [
            'public-game' => [
                'id' => 'public-game',
                'backend' => ['type' => 'web', 'id' => 'public-game'],
                'surfaces' => ['web' => 'full', 'telnet' => 'planned'],
            ],
        ];
    }

    private function insertScore(string $gameId, string $board, int $score): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO webdoor_leaderboards '
            . '(user_id, game_id, board, score, metadata) '
            . 'VALUES (42, ?, ?, ?, ? )'
        );
        $stmt->execute([$gameId, $board, $score, '{}']);
    }

    private function scoreCount(?string $gameId = null): int
    {
        if ($gameId === null) {
            return (int)$this->db->query(
                'SELECT COUNT(*) FROM webdoor_leaderboards'
            )->fetchColumn();
        }

        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM webdoor_leaderboards WHERE game_id = ?'
        );
        $stmt->execute([$gameId]);
        return (int)$stmt->fetchColumn();
    }
}
