<?php

declare(strict_types=1);

namespace BinktermPHP\Tests\Unit;

use BinktermPHP\Auth;
use BinktermPHP\GameCatalog;
use BinktermPHP\WebDoorController;
use PDO;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class WebDoorSessionAuthorizationTest extends TestCase
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
        $this->db->sqliteCreateFunction(
            'NOW',
            static fn(): string => gmdate('Y-m-d H:i:s')
        );
        $this->db->exec('CREATE TABLE webdoor_sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            session_id TEXT NOT NULL UNIQUE,
            user_id INTEGER NOT NULL,
            game_id TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at TEXT NOT NULL,
            ended_at TEXT,
            playtime_seconds INTEGER NOT NULL DEFAULT 0
        )');

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

    public function testAuthorizedExplicitWebDoorCreatesSession(): void
    {
        $_GET['game_id'] = 'public-game';
        $this->expectSingleCatalogLookup($this->authorizedCatalog());

        $result = $this->controller()->getSession();

        self::assertArrayHasKey('session_id', $result);
        self::assertSame('public-game', $result['game']['id']);
        self::assertSame(1, $this->sessionCount('public-game'));
    }

    public function testAuthorizedRefererDerivedWebDoorCreatesSession(): void
    {
        $_SERVER['HTTP_REFERER'] =
            'https://bbs.example/webdoors/public-game/index.html';
        $this->expectSingleCatalogLookup($this->authorizedCatalog());

        $result = $this->controller()->getSession();

        self::assertArrayHasKey('session_id', $result);
        self::assertSame('public-game', $result['game']['id']);
        self::assertSame(1, $this->sessionCount('public-game'));
    }

    public function testMissingIdentityFailsClosedWithoutUnknownSession(): void
    {
        $this->catalog->expects(self::never())
            ->method('getEnabledGames');

        $result = $this->controller()->getSession();

        $this->assertUnavailable($result);
        self::assertSame(0, $this->sessionCount());
        self::assertSame(0, $this->sessionCount('unknown'));
    }

    public function testInventedIdentityFailsClosedBeforeSessionCreation(): void
    {
        $_GET['game_id'] = 'invented-game';
        $this->expectSingleCatalogLookup($this->authorizedCatalog());

        $result = $this->controller()->getSession();

        $this->assertUnavailable($result);
        self::assertSame(0, $this->sessionCount());
    }

    public function testUndiscoverableWebDoorFailsClosed(): void
    {
        $_GET['game_id'] = 'disabled-game';
        $this->expectSingleCatalogLookup($this->authorizedCatalog());

        $result = $this->controller()->getSession();

        $this->assertUnavailable($result);
        self::assertSame(0, $this->sessionCount());
    }

    public function testHiddenFromWebExperienceFailsClosed(): void
    {
        $_GET['game_id'] = 'hidden-game';
        $this->expectSingleCatalogLookup($this->authorizedCatalog());

        $result = $this->controller()->getSession();

        $this->assertUnavailable($result);
        self::assertSame(0, $this->sessionCount());
    }

    public function testNonWebDoorCatalogIdentityFailsClosed(): void
    {
        $_GET['game_id'] = 'native-door';
        $catalog = $this->authorizedCatalog();
        $catalog['native-door'] = [
            'id' => 'native-door',
            'backend' => ['type' => 'native', 'id' => 'native-door'],
        ];
        $this->expectSingleCatalogLookup($catalog);

        $result = $this->controller()->getSession();

        $this->assertUnavailable($result);
        self::assertSame(0, $this->sessionCount());
    }

    public function testUnauthorizedExistingSessionIsNeitherReusedNorDisclosed(): void
    {
        $this->insertSession('secret-session', 'hidden-game');
        $_GET['game_id'] = 'hidden-game';
        $this->expectSingleCatalogLookup($this->authorizedCatalog());

        $result = $this->controller()->getSession();

        $this->assertUnavailable($result);
        self::assertArrayNotHasKey('session_id', $result);
        self::assertSame(1, $this->sessionCount('hidden-game'));
    }

    public function testAuthorizedExistingSessionIsReused(): void
    {
        $this->insertSession('existing-session', 'public-game');
        $_GET['game_id'] = 'public-game';
        $this->expectSingleCatalogLookup($this->authorizedCatalog());

        $result = $this->controller()->getSession();

        self::assertSame('existing-session', $result['session_id']);
        self::assertSame(1, $this->sessionCount('public-game'));
    }

    /** @param array<string,array<string,mixed>> $catalog */
    private function expectSingleCatalogLookup(array $catalog): void
    {
        $this->catalog->expects(self::once())
            ->method('getEnabledGames')
            ->with(self::isType('array'), 'web')
            ->willReturn($catalog);
    }

    private function controller(): WebDoorController
    {
        return new WebDoorController($this->db, $this->auth, $this->catalog);
    }

    /** @return array<string,array<string,mixed>> */
    private function authorizedCatalog(): array
    {
        return [
            'public-game' => [
                'id' => 'public-game',
                'backend' => ['type' => 'web', 'id' => 'public-game'],
            ],
        ];
    }

    /** @param array<string,mixed> $result */
    private function assertUnavailable(array $result): void
    {
        self::assertFalse($result['success']);
        self::assertSame(
            'errors.webdoor.game_unavailable',
            $result['error_code']
        );
        self::assertSame('Experience is not available', $result['error']);
        self::assertSame(404, http_response_code());
    }

    private function insertSession(string $sessionId, string $gameId): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO webdoor_sessions '
            . '(session_id, user_id, game_id, expires_at) '
            . 'VALUES (?, 42, ?, ?)'
        );
        $stmt->execute([
            $sessionId,
            $gameId,
            gmdate('Y-m-d H:i:s', time() + 3600),
        ]);
    }

    private function sessionCount(?string $gameId = null): int
    {
        if ($gameId === null) {
            return (int)$this->db->query(
                'SELECT COUNT(*) FROM webdoor_sessions'
            )->fetchColumn();
        }

        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM webdoor_sessions WHERE game_id = ?'
        );
        $stmt->execute([$gameId]);
        return (int)$stmt->fetchColumn();
    }
}
