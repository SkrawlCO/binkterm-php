<?php

declare(strict_types=1);

namespace BinktermPHP\Tests\Unit;

use BinktermPHP\Auth;
use BinktermPHP\GameCatalog;
use BinktermPHP\WebDoorController;
use PDO;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Ownership semantics of WebDoorController::endSession() — the endpoint the
 * WebDoor host page's unload beacon calls so leaving a game clears live
 * presence instead of leaving a stale "active player" for up to an hour.
 */
final class WebDoorEndSessionTest extends TestCase
{
    private PDO $db;
    private Auth&MockObject $auth;

    protected function setUp(): void
    {
        $_GET = [];
        unset($_SERVER['HTTP_REFERER']);

        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->sqliteCreateFunction('NOW', static fn(): string => gmdate('Y-m-d H:i:s'));
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
    }

    protected function tearDown(): void
    {
        $_GET = [];
    }

    public function testScopedEndClosesThatGamesActiveSession(): void
    {
        $this->insertSession('wordle-s', 42, 'wordle');
        $_GET['game_id'] = 'wordle';

        $result = $this->controller()->endSession();

        self::assertSame(['success' => true], $result);
        self::assertFalse($this->isActive('wordle-s'));
        self::assertSame(0, $this->activeCount(42, 'wordle'));
    }

    public function testScopedEndDoesNotTouchAnotherGameStillOpenInAnotherTab(): void
    {
        // Wordle opened first, Hangman opened second (newer). Closing the Wordle
        // tab must not end the newer Hangman session — the bug the scoping fixes.
        $this->insertSession('wordle-s', 42, 'wordle', '-120 seconds');
        $this->insertSession('hangman-s', 42, 'hangman', '-10 seconds');
        $_GET['game_id'] = 'wordle';

        $this->controller()->endSession();

        self::assertFalse($this->isActive('wordle-s'));
        self::assertTrue($this->isActive('hangman-s'));
    }

    public function testEndNeverClosesAnotherUsersSession(): void
    {
        $this->insertSession('mine', 42, 'wordle');
        $this->insertSession('theirs', 99, 'wordle');
        $_GET['game_id'] = 'wordle';

        $this->controller()->endSession();

        self::assertFalse($this->isActive('mine'));
        self::assertTrue($this->isActive('theirs'));
    }

    public function testRepeatedDeliveryIsHarmlessNoOp(): void
    {
        $this->insertSession('wordle-s', 42, 'wordle');
        $_GET['game_id'] = 'wordle';

        $first = $this->controller()->endSession();
        $endedAt = $this->endedAt('wordle-s');
        $second = $this->controller()->endSession();

        self::assertSame(['success' => true], $first);
        self::assertSame(['success' => true], $second);
        self::assertNotNull($endedAt);
        // Second delivery found no active session, so the recorded end time is
        // not rewritten.
        self::assertSame($endedAt, $this->endedAt('wordle-s'));
        self::assertSame(1, $this->rowCount());
    }

    public function testUnknownGameIdEndsNothingRatherThanFallingBack(): void
    {
        $this->insertSession('wordle-s', 42, 'wordle');
        $_GET['game_id'] = 'not-a-real-game';

        $this->controller()->endSession();

        // A provided-but-unmatched id is still "scoped" — it must not silently
        // fall through to closing an unrelated active session.
        self::assertTrue($this->isActive('wordle-s'));
    }

    public function testLegacyFallbackEndsMostRecentSessionWhenNoGameIdGiven(): void
    {
        $this->insertSession('older', 42, 'wordle', '-120 seconds');
        $this->insertSession('newer', 42, 'hangman', '-10 seconds');

        $this->controller()->endSession();

        self::assertTrue($this->isActive('older'));
        self::assertFalse($this->isActive('newer'));
    }

    private function controller(): WebDoorController
    {
        return new WebDoorController(
            $this->db,
            $this->auth,
            $this->createMock(GameCatalog::class)
        );
    }

    private function insertSession(
        string $sessionId,
        int $userId,
        string $gameId,
        string $createdOffset = 'now'
    ): void {
        $created = gmdate('Y-m-d H:i:s', strtotime($createdOffset) ?: time());
        $stmt = $this->db->prepare(
            'INSERT INTO webdoor_sessions '
            . '(session_id, user_id, game_id, created_at, expires_at) '
            . 'VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $sessionId,
            $userId,
            $gameId,
            $created,
            gmdate('Y-m-d H:i:s', time() + 3600),
        ]);
    }

    private function isActive(string $sessionId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT ended_at FROM webdoor_sessions WHERE session_id = ?'
        );
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false && $row['ended_at'] === null;
    }

    private function endedAt(string $sessionId): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT ended_at FROM webdoor_sessions WHERE session_id = ?'
        );
        $stmt->execute([$sessionId]);
        $value = $stmt->fetchColumn();

        return $value === false || $value === null ? null : (string)$value;
    }

    private function activeCount(int $userId, string $gameId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM webdoor_sessions '
            . 'WHERE user_id = ? AND game_id = ? AND ended_at IS NULL'
        );
        $stmt->execute([$userId, $gameId]);

        return (int)$stmt->fetchColumn();
    }

    private function rowCount(): int
    {
        return (int)$this->db->query('SELECT COUNT(*) FROM webdoor_sessions')->fetchColumn();
    }
}
