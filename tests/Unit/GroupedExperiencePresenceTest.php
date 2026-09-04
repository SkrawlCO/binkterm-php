<?php

declare(strict_types=1);

use BinktermPHP\ActivityTracker;
use BinktermPHP\ExperienceActivity;
use BinktermPHP\ExperienceState;
use BinktermPHP\GameCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Read-side normalization for multi-backend (grouped) Experiences: presence
 * (ExperienceState) and recent activity (ExperienceActivity) must resolve every
 * member backend id to the one canonical Experience, without duplicate cards or
 * broken counts, and without changing anything for ungrouped Experiences.
 *
 * Fixtures are normalized entries exactly as ExperienceComposition::compose()
 * emits them (keyed by canonical id, carrying `members`).
 */
final class GroupedExperiencePresenceTest extends TestCase
{
    private function stateDb(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->exec("
            CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT);
            CREATE TABLE door_sessions (
                session_id TEXT, user_id INTEGER, door_id TEXT, node_number INTEGER,
                started_at TEXT, ended_at TEXT, expires_at TEXT
            );
            CREATE TABLE webdoor_sessions (
                session_id TEXT, user_id INTEGER, game_id TEXT,
                created_at TEXT, ended_at TEXT, expires_at TEXT
            );
            CREATE TABLE user_sessions (
                user_id INTEGER, public_activity TEXT, last_activity TEXT, expires_at TEXT
            );
            INSERT INTO users (id, username) VALUES (3,'Skrawl'),(4,'Matt'),(5,'Third');
        ");
        return $db;
    }

    private function activityDb(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->exec("
            CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, is_system BOOLEAN NOT NULL DEFAULT 0);
            CREATE TABLE user_activity_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER,
                activity_type_id INTEGER NOT NULL, object_id INTEGER, object_name TEXT,
                meta TEXT, created_at TEXT
            );
            INSERT INTO users (id, username) VALUES (3,'Skrawl'),(4,'Matt');
        ");
        return $db;
    }

    /** A grouped normalized entry as ExperienceComposition::compose() emits it. */
    private function groupedShared(string $webId = 'sg-web', string $termId = 'sg-term'): array
    {
        return [
            'id' => 'shared-game',
            'name' => 'Shared Game',
            'category' => 'game',
            'backend' => ['type' => 'web', 'id' => $webId],            // primary member
            'surfaces' => ['web' => 'full', 'telnet' => 'full'],
            'surface_backends' => [
                'web' => ['type' => 'web', 'id' => $webId],
                'telnet' => ['type' => 'native', 'id' => $termId],
            ],
            'members' => [
                ['type' => 'web', 'id' => $webId],
                ['type' => 'native', 'id' => $termId],
            ],
        ];
    }

    private function ungroupedNative(string $id, string $name): array
    {
        return ['id' => $id, 'name' => $name, 'category' => 'game',
                'backend' => ['type' => 'native', 'id' => $id]];
    }

    private function catalog(array $entries): GameCatalog
    {
        return new class($entries) extends GameCatalog {
            public function __construct(private array $e) {}
            public function getEnabledGames(?array $user = null, string $surface = 'web'): array
            {
                return $this->e;
            }
        };
    }

    // ---- A. UNGROUPED LEGACY UNCHANGED -----------------------------------

    public function testUngroupedStateAndActivityUnchanged(): void
    {
        $sdb = $this->stateDb();
        $sdb->exec("INSERT INTO door_sessions VALUES ('s1',3,'usurper',1,'2026-08-25 22:00:00',NULL,'2099-01-01 00:00:00');");
        $state = (new ExperienceState($sdb, $this->catalog([
            'usurper' => $this->ungroupedNative('usurper', 'Usurper'),
        ])))->getExperienceStates();
        self::assertTrue($state['usurper']['active']);
        self::assertSame(1, $state['usurper']['session_count']);
        self::assertSame(1, $state['usurper']['player_count']);

        $adb = $this->activityDb();
        $adb->exec("INSERT INTO user_activity_log (user_id,activity_type_id,object_name,created_at)
                    VALUES (3," . ActivityTracker::TYPE_DOSDOOR_PLAY . ",'usurper','2026-08-25 22:00:00');");
        $act = (new ExperienceActivity($adb))->recentAcrossCatalog([
            $this->ungroupedNative('usurper', 'Usurper'),
        ], 5);
        self::assertCount(1, $act);
        self::assertSame('usurper', $act[0]['experience_id']);
        self::assertSame('Usurper', $act[0]['experience_name']);
    }

    // ---- B. GROUPED STATE (presence from either member) ----------------

    public function testGroupedStatePresenceFromEitherMemberUnderCanonicalId(): void
    {
        $db = $this->stateDb();
        $db->exec("
            INSERT INTO door_sessions VALUES ('t1',3,'sg-term',2,'2026-08-25 22:00:00',NULL,'2099-01-01 00:00:00');
            INSERT INTO webdoor_sessions VALUES ('w1',4,'sg-web','2026-08-25 22:05:00',NULL,'2099-01-01 00:00:00');
        ");
        $catalog = $this->catalog(['shared-game' => $this->groupedShared()]);

        $bulk = (new ExperienceState($db, $catalog))->getExperienceStates();
        self::assertArrayHasKey('shared-game', $bulk);
        self::assertArrayNotHasKey('sg-web', $bulk);
        self::assertArrayNotHasKey('sg-term', $bulk);
        self::assertTrue($bulk['shared-game']['active']);
        self::assertSame(2, $bulk['shared-game']['session_count']);
        self::assertSame(2, $bulk['shared-game']['player_count']);
        $names = array_column($bulk['shared-game']['players'], 'username');
        sort($names);
        self::assertSame(['Matt', 'Skrawl'], $names);

        $single = (new ExperienceState($db, $catalog))->getExperienceState('shared-game');
        self::assertSame(2, $single['session_count']);
        self::assertSame(2, $single['player_count']);

        $agg = (new ExperienceState($db, $catalog))->getPublicExperienceAggregates();
        self::assertArrayNotHasKey('sg-web', $agg);
        self::assertSame(2, $agg['shared-game']['session_count']);
        self::assertSame(2, $agg['shared-game']['player_count']);

        self::assertSame(
            2,
            (new ExperienceState($db, $catalog))->getPublicActivePeopleCount()
        );
    }

    // ---- C. GROUPED ACTIVITY (either member -> one canonical) --------

    public function testGroupedActivityFromEitherMemberAttributedToCanonical(): void
    {
        $db = $this->activityDb();
        $db->exec("
            INSERT INTO user_activity_log (user_id,activity_type_id,object_name,created_at) VALUES
              (3," . ActivityTracker::TYPE_WEBDOOR_PLAY . ",'sg-web','2026-08-25 10:00:00'),
              (4," . ActivityTracker::TYPE_DOSDOOR_PLAY . ",'sg-term','2026-08-25 11:00:00');
        ");
        $act = (new ExperienceActivity($db))->recentAcrossCatalog([$this->groupedShared()], 5);

        self::assertCount(2, $act);
        foreach ($act as $row) {
            self::assertSame('shared-game', $row['experience_id']);
            self::assertSame('Shared Game', $row['experience_name']);
        }
        // newest first
        self::assertSame(4, $act[0]['user_id']);
        self::assertSame(3, $act[1]['user_id']);

        $detail = (new ExperienceActivity($db))->recent($this->groupedShared(), 10);
        self::assertCount(2, $detail);
    }

    // ---- D. BOTH MEMBERS ACTIVE FOR ONE PERSON -> NO DUPLICATE ------

    public function testSamePersonOnBothMembersIsOnePlayerAndOneFootprint(): void
    {
        $sdb = $this->stateDb();
        $sdb->exec("
            INSERT INTO door_sessions VALUES ('t1',3,'sg-term',1,'2026-08-25 22:00:00',NULL,'2099-01-01 00:00:00');
            INSERT INTO webdoor_sessions VALUES ('w1',3,'sg-web','2026-08-25 22:05:00',NULL,'2099-01-01 00:00:00');
        ");
        $bulk = (new ExperienceState($sdb, $this->catalog(['shared-game' => $this->groupedShared()])))
            ->getExperienceStates();
        self::assertSame(2, $bulk['shared-game']['session_count']);
        self::assertSame(1, $bulk['shared-game']['player_count']); // one person, two surfaces

        $adb = $this->activityDb();
        $adb->exec("
            INSERT INTO user_activity_log (user_id,activity_type_id,object_name,created_at) VALUES
              (3," . ActivityTracker::TYPE_WEBDOOR_PLAY . ",'sg-web','2026-08-25 09:00:00'),
              (3," . ActivityTracker::TYPE_DOSDOOR_PLAY . ",'sg-term','2026-08-25 12:00:00');
        ");
        $across = (new ExperienceActivity($adb))->recentAcrossCatalog([$this->groupedShared()], 5);
        self::assertCount(1, $across, 'one canonical footprint for one (user, Experience) pair');
        self::assertSame('2026-08-25 12:00:00', $across[0]['occurred_at'], 'newest kept');

        $forUser = (new ExperienceActivity($adb))->recentForUser([$this->groupedShared()], 3, 5);
        self::assertCount(1, $forUser);
        self::assertSame('shared-game', $forUser[0]['experience_id']);
    }

    // ---- E. BACKEND-TYPE SAFETY (same text id, different backend) ---

    public function testSameTextIdUnderAnUnrelatedBackendTypeIsNotAbsorbed(): void
    {
        // grouped Experience whose WEB member id is literally "foo";
        // an unrelated native door is also literally "foo".
        $grouped = $this->groupedShared('foo', 'sg-term');
        $nativeFoo = $this->ungroupedNative('foo', 'Unrelated Native Foo');

        $sdb = $this->stateDb();
        $sdb->exec("
            INSERT INTO door_sessions VALUES ('d',5,'foo',1,'2026-08-25 22:00:00',NULL,'2099-01-01 00:00:00');
            INSERT INTO webdoor_sessions VALUES ('w',4,'foo','2026-08-25 22:00:00',NULL,'2099-01-01 00:00:00');
        ");
        $bulk = (new ExperienceState($sdb, $this->catalog([
            'shared-game' => $grouped,
            'foo' => $nativeFoo,
        ])))->getExperienceStates();

        // web session for "foo" -> the grouped Experience (its web member)
        self::assertSame(1, $bulk['shared-game']['session_count']);
        self::assertSame('Matt', $bulk['shared-game']['players'][0]['username']);
        // door session for "foo" -> the unrelated native door, not the group
        self::assertSame(1, $bulk['foo']['session_count']);
        self::assertSame('Third', $bulk['foo']['players'][0]['username']);

        $adb = $this->activityDb();
        $adb->exec("
            INSERT INTO user_activity_log (user_id,activity_type_id,object_name,created_at) VALUES
              (4," . ActivityTracker::TYPE_WEBDOOR_PLAY . ",'foo','2026-08-25 10:00:00');
        ");
        // Only the grouped catalog authorizes 'foo' here; it must present as the
        // canonical grouped Experience, not leak a second 'foo' identity.
        $act = (new ExperienceActivity($adb))->recentAcrossCatalog([$grouped], 5);
        self::assertCount(1, $act);
        self::assertSame('shared-game', $act[0]['experience_id']);
    }
}
