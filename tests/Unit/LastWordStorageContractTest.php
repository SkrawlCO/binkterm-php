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
 * M1A storage-client repair proof: the current Hangman client
 * (js/webdoor.js) calls the obsolete, nonexistent /api/webdoor/save,
 * /load, and /delete endpoints, which 404 and are silently swallowed (see
 * docs/WebDoors.md). js/lastword/storage.js instead targets the real,
 * currently-implemented contract:
 *
 *   GET/PUT/DELETE /api/webdoor/storage/{slot}?game_id=<id>
 *
 * which routes to WebDoorController::loadSave()/saveGame()/deleteSave().
 * This test exercises that REAL controller code (not a reimplementation) to
 * prove the read and delete sides of the contract round-trip a
 * representative Last Word session without structural loss and stay scoped
 * to (user_id, game_id, slot).
 *
 * NOTE ON COVERAGE: WebDoorController::saveGame()'s SQL uses PostgreSQL-only
 * `?::jsonb` casts, which fatally abort SQLite's PDO driver at prepare()
 * time (not a catchable PDOException) — the same reason the existing
 * WebDoorEndSessionTest/WebDoorSessionAuthorizationTest suites only exercise
 * webdoor_sessions (which has no jsonb casts) and never call saveGame()/
 * loadSave() against SQLite either. This is a pre-existing testability gap
 * in the generic WebDoorController, not something introduced or fixed by
 * this M1A slice — the PUT/save side of the contract is instead proven at
 * the client layer in tests/js/lastword/storage.test.js against a mocked
 * fetch. See the M1A report for this as a flagged item for later.
 */
final class LastWordStorageContractTest extends TestCase
{
    private const GAME_ID = 'hangman'; // Last Word ships under the existing hangman WebDoor path in M1A.
    private const SLOT = 0;

    private PDO $db;
    private Auth&MockObject $auth;

    protected function setUp(): void
    {
        $_GET = [];
        unset($_SERVER['HTTP_REFERER']);

        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE webdoor_storage (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            game_id TEXT NOT NULL,
            slot INTEGER NOT NULL DEFAULT 0,
            data TEXT NOT NULL DEFAULT \'{}\',
            metadata TEXT NOT NULL DEFAULT \'{}\',
            saved_at TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(user_id, game_id, slot)
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
        unset($_SERVER['HTTP_REFERER']);
    }

    /** A representative Last Word session, matching js/lastword/state.js's shape. */
    private function representativeSession(): array
    {
        return [
            'stateVersion' => 1,
            'round' => 3,
            'cumulativeScore' => 2100,
            'categoriesUsed' => ['Movies & TV', 'Music'],
            'rounds' => [
                ['round' => 1, 'outcome' => 'solved', 'pointsThisRound' => 900],
                ['round' => 2, 'outcome' => 'struck-out', 'pointsThisRound' => 350],
            ],
            'currentRound' => [
                'round' => 3,
                'puzzleId' => 'games-0001',
                'category' => 'Games',
                'consonantsGuessed' => ['T', 'H', 'L'],
                'purchasedVowels' => ['E'],
                'strikes' => 1,
            ],
            'finalState' => null,
            'startedAt' => 1710000000000,
            'finished' => null,
        ];
    }

    public function testLoadSaveReturnsAnUnmodifiedRepresentativeSessionViaTheRealContract(): void
    {
        $session = $this->representativeSession();
        $this->insertStoredSession(42, self::GAME_ID, self::SLOT, $session, ['kind' => 'lastword-session']);
        $_GET['game_id'] = self::GAME_ID;

        $result = $this->controller()->loadSave(self::SLOT);

        self::assertNotNull($result);
        self::assertSame(self::SLOT, $result['slot']);
        self::assertSame($session, $result['data']);
        self::assertSame('lastword-session', $result['metadata']['kind']);
    }

    public function testLoadSaveReturnsNullWhenNoSaveExistsYet(): void
    {
        $_GET['game_id'] = self::GAME_ID;

        $result = $this->controller()->loadSave(self::SLOT);

        self::assertNull($result);
    }

    public function testDeleteSaveRemovesOnlyTheScopedSlot(): void
    {
        $this->insertStoredSession(42, self::GAME_ID, self::SLOT, $this->representativeSession());
        $this->insertStoredSession(42, self::GAME_ID, 1, ['stateVersion' => 1, 'round' => 1]); // another slot
        $this->insertStoredSession(99, self::GAME_ID, self::SLOT, ['stateVersion' => 1, 'round' => 1]); // another user
        $_GET['game_id'] = self::GAME_ID;

        $result = $this->controller()->deleteSave(self::SLOT);

        self::assertSame(['success' => true], $result);
        self::assertNull($this->controller()->loadSave(self::SLOT));
        // Another slot for the same user, and the same slot for another user,
        // must be untouched — a Last Word reset must not clobber unrelated saves.
        self::assertSame(1, $this->rowCountForUser(42));
        self::assertSame(1, $this->rowCountForUser(99));
    }

    public function testGameIdIsScopedSoAnotherWebDoorsSaveIsNeverReturned(): void
    {
        $this->insertStoredSession(42, 'wordle', self::SLOT, ['stateVersion' => 1, 'round' => 1]);
        $_GET['game_id'] = self::GAME_ID; // asking for Last Word/hangman's slot, not wordle's

        $result = $this->controller()->loadSave(self::SLOT);

        self::assertNull($result);
    }

    private function controller(): WebDoorController
    {
        return new WebDoorController(
            $this->db,
            $this->auth,
            $this->createMock(GameCatalog::class)
        );
    }

    private function insertStoredSession(int $userId, string $gameId, int $slot, array $data, array $metadata = []): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO webdoor_storage (user_id, game_id, slot, data, metadata) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $gameId, $slot, json_encode($data), json_encode($metadata)]);
    }

    private function rowCountForUser(int $userId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM webdoor_storage WHERE user_id = ?');
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }
}
