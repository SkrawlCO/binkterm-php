<?php

declare(strict_types=1);

namespace BinktermPHP\Tests\Unit;

use BinktermPHP\ActivityTracker;
use BinktermPHP\Crossroads\DoorPlayActivity;
use BinktermPHP\ExperienceActivity;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The door-play footprint contract: records a successful Experience entry or
 * return, and collapses reload / double-request repeats within a short window.
 * Deterministic — no sleeps; the window is exercised by rewriting created_at.
 */
final class CrossroadsDoorPlayActivityTest extends TestCase
{
    private const DOSDOOR = ActivityTracker::TYPE_DOSDOOR_PLAY;   // 9
    private const WEBDOOR = ActivityTracker::TYPE_WEBDOOR_PLAY;   // 8

    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                username TEXT,
                is_system BOOLEAN NOT NULL DEFAULT 0
            );
            CREATE TABLE user_activity_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                activity_type_id INTEGER NOT NULL,
                object_id INTEGER,
                object_name TEXT,
                meta TEXT,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            );
            INSERT INTO users (id, username) VALUES (10, 'ten'), (11, 'eleven'), (99, 'guest');
        ");
    }

    private function record(?int $userId, int $type, ?string $object): void
    {
        DoorPlayActivity::record($userId, $type, $object, $this->db);
    }

    private function rows(?int $userId = null, ?int $type = null, ?string $object = null): int
    {
        $sql = 'SELECT COUNT(*) FROM user_activity_log WHERE 1=1';
        $params = [];
        if ($userId !== null) { $sql .= ' AND user_id = ?'; $params[] = $userId; }
        if ($type !== null)   { $sql .= ' AND activity_type_id = ?'; $params[] = $type; }
        if ($object !== null) { $sql .= ' AND object_name = ?'; $params[] = $object; }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /** Age every matching footprint so the next record() call is outside the window. */
    private function ageBeyondWindow(int $userId, int $type, string $object): void
    {
        $old = gmdate('Y-m-d H:i:s', time() - (DoorPlayActivity::DEDUP_WINDOW_SECONDS + 60));
        $stmt = $this->db->prepare(
            'UPDATE user_activity_log SET created_at = ?
              WHERE user_id = ? AND activity_type_id = ? AND object_name = ?'
        );
        $stmt->execute([$old, $userId, $type, $object]);
    }

    public function testFreshManagedLaunchRecordsPlay(): void
    {
        $this->record(10, self::DOSDOOR, 'lord');
        self::assertSame(1, $this->rows(10, self::DOSDOOR, 'lord'));
    }

    public function testManagedReturnRecordsPlayWhenOutsideWindow(): void
    {
        $this->record(10, self::DOSDOOR, 'lord');
        $this->ageBeyondWindow(10, self::DOSDOOR, 'lord');
        $this->record(10, self::DOSDOOR, 'lord'); // a genuine later return

        self::assertSame(2, $this->rows(10, self::DOSDOOR, 'lord'));
    }

    public function testImmediateDuplicateManagedReturnIsSuppressed(): void
    {
        $this->record(10, self::DOSDOOR, 'lord');
        $this->record(10, self::DOSDOOR, 'lord');
        $this->record(10, self::DOSDOOR, 'lord');

        self::assertSame(1, $this->rows(10, self::DOSDOOR, 'lord'));
    }

    public function testWebDoorRapidDuplicateSessionRequestsAreCollapsed(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->record(10, self::WEBDOOR, 'wordle');
        }
        self::assertSame(1, $this->rows(10, self::WEBDOOR, 'wordle'));
    }

    public function testJsdosRapidDuplicateEntriesAreCollapsed(): void
    {
        // JS-DOS also routes through DoorPlayActivity::record with WEBDOOR type.
        $this->record(10, self::WEBDOOR, 'commander-keen');
        $this->record(10, self::WEBDOOR, 'commander-keen');
        self::assertSame(1, $this->rows(10, self::WEBDOOR, 'commander-keen'));
    }

    public function testAnotherExperienceIsNotSuppressed(): void
    {
        $this->record(10, self::DOSDOOR, 'lord');
        $this->record(10, self::DOSDOOR, 'greendragon');

        self::assertSame(1, $this->rows(10, self::DOSDOOR, 'lord'));
        self::assertSame(1, $this->rows(10, self::DOSDOOR, 'greendragon'));
    }

    public function testAnotherUserIsNotSuppressed(): void
    {
        $this->record(10, self::DOSDOOR, 'lord');
        $this->record(11, self::DOSDOOR, 'lord');

        self::assertSame(1, $this->rows(10, self::DOSDOOR, 'lord'));
        self::assertSame(1, $this->rows(11, self::DOSDOOR, 'lord'));
    }

    public function testWebdoorAndDosdoorTypesDoNotSuppressOneAnother(): void
    {
        $this->record(10, self::DOSDOOR, 'lord');
        $this->record(10, self::WEBDOOR, 'lord'); // same object_name, different type

        self::assertSame(1, $this->rows(10, self::DOSDOOR, 'lord'));
        self::assertSame(1, $this->rows(10, self::WEBDOOR, 'lord'));
        self::assertSame(2, $this->rows(10, null, 'lord'));
    }

    public function testNullEmptyAndNonPositiveUserAreNoOps(): void
    {
        $this->record(null, self::DOSDOOR, 'lord');
        $this->record(0, self::DOSDOOR, 'lord');
        $this->record(-1, self::DOSDOOR, 'lord');
        $this->record(10, self::DOSDOOR, '');
        $this->record(10, self::DOSDOOR, '   ');
        $this->record(10, self::DOSDOOR, null);

        self::assertSame(0, $this->rows());
    }

    public function testObjectNameIsTrimmedForBothMatchingAndStorage(): void
    {
        $this->record(10, self::DOSDOOR, '  lord  ');
        self::assertSame(1, $this->rows(10, self::DOSDOOR, 'lord'));

        // A follow-up with surrounding whitespace still matches the stored row.
        $this->record(10, self::DOSDOOR, 'lord ');
        self::assertSame(1, $this->rows(10, self::DOSDOOR, 'lord'));
    }

    public function testUnrelatedActivityIsUnaffectedByTheDoorPlayWindow(): void
    {
        // A non-door activity type written directly is never touched by
        // DoorPlayActivity — no suppression, no interference.
        $insert = $this->db->prepare(
            'INSERT INTO user_activity_log (user_id, activity_type_id, object_name) VALUES (?, ?, ?)'
        );
        $insert->execute([10, ActivityTracker::TYPE_CHAT_SEND, 'lobby']);
        $insert->execute([10, ActivityTracker::TYPE_CHAT_SEND, 'lobby']);

        self::assertSame(2, $this->rows(10, ActivityTracker::TYPE_CHAT_SEND, 'lobby'));

        // And a door-play footprint for the same user is independent of it.
        $this->record(10, self::DOSDOOR, 'lord');
        self::assertSame(1, $this->rows(10, self::DOSDOOR, 'lord'));
    }

    public function testRecentAcrossCatalogStillCollapsesToDistinctPairsOverDedupedRows(): void
    {
        // Two users, both "entering" LORD several times (deduped to one row
        // each within the window), then aged and re-entered once more.
        foreach ([10, 11] as $uid) {
            $this->record($uid, self::DOSDOOR, 'lord');
            $this->record($uid, self::DOSDOOR, 'lord'); // suppressed
            $this->ageBeyondWindow($uid, self::DOSDOOR, 'lord');
            $this->record($uid, self::DOSDOOR, 'lord'); // a second, real entry
        }

        $catalog = [[
            'id' => 'lord',
            'name' => 'Legend of the Red Dragon',
            'backend' => ['type' => 'native', 'id' => 'lord'],
        ]];

        $rows = (new ExperienceActivity($this->db))->recentAcrossCatalog($catalog, 5);

        // recentAcrossCatalog still returns one newest footprint per (user, id)
        // pair — unchanged by the upstream de-dup.
        self::assertCount(2, $rows);
        self::assertSame(
            ['eleven', 'ten'],
            array_values(array_unique(array_map(
                static fn(array $r): string => $r['username'],
                $rows
            )))
        );
        foreach ($rows as $r) {
            self::assertSame('lord', $r['experience_id']);
            self::assertSame('Legend of the Red Dragon', $r['experience_name']);
        }
    }

    public function testRecordNeverThrowsEvenIfTheTableIsMissing(): void
    {
        // A tracking failure must never interrupt an Experience launch: if the
        // SELECT/INSERT raises, record() swallows it.
        $this->db->exec('DROP TABLE user_activity_log');
        $this->record(10, self::DOSDOOR, 'lord'); // must not raise

        $this->addToAssertionCount(1);
    }
}
