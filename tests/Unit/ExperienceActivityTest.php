<?php

declare(strict_types=1);

use BinktermPHP\ActivityTracker;
use BinktermPHP\ExperienceActivity;
use PHPUnit\Framework\TestCase;

final class ExperienceActivityTest extends TestCase
{
    private function database(): \PDO
    {
        $db = new \PDO('sqlite::memory:');

        $db->exec("
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
                created_at TEXT
            );
        ");

        return $db;
    }

    /**
     * Normalized catalog entry as produced by GameCatalog::getEnabledGames().
     *
     * @return array<string,mixed>
     */
    private function experience(string $id, string $name, string $backendType = 'native', ?string $backendId = null): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'backend' => ['type' => $backendType, 'id' => $backendId ?? $id],
        ];
    }

    public function testRecentActivityNormalizesDoorPlayEvents(): void
    {
        $db = $this->database();

        $db->exec("
            INSERT INTO users (id, username)
            VALUES
                (3, 'Skrawl'),
                (7, 'PlayerTwo');

            INSERT INTO user_activity_log (
                user_id,
                activity_type_id,
                object_name,
                created_at
            ) VALUES
                (
                    7,
                    " . ActivityTracker::TYPE_DOSDOOR_PLAY . ",
                    'usurper',
                    '2026-08-26 07:45:51+00'
                ),
                (
                    3,
                    " . ActivityTracker::TYPE_DOSDOOR_PLAY . ",
                    'usurper',
                    '2026-08-26 16:18:28+00'
                );
        ");

        $activity = (new ExperienceActivity($db))->recent([
            'backend' => [
                'type' => 'native',
                'id' => 'usurper',
            ],
        ]);

        self::assertCount(2, $activity);

        self::assertSame('first_play', $activity[0]['type']);
        self::assertSame(3, $activity[0]['user_id']);
        self::assertSame('Skrawl', $activity[0]['username']);
        self::assertSame(
            '2026-08-26 16:18:28+00',
            $activity[0]['occurred_at']
        );

        self::assertSame(7, $activity[1]['user_id']);
        self::assertSame('PlayerTwo', $activity[1]['username']);
        self::assertSame('first_play', $activity[1]['type']);
    }

    public function testRecentActivityAcceptsWebDoorPlayEvents(): void
    {
        $db = $this->database();

        $db->exec("
            INSERT INTO users (id, username)
            VALUES (3, 'Skrawl');

            INSERT INTO user_activity_log (
                user_id,
                activity_type_id,
                object_name,
                created_at
            ) VALUES (
                3,
                " . ActivityTracker::TYPE_WEBDOOR_PLAY . ",
                'blackjack',
                '2026-08-26 16:20:00+00'
            );
        ");

        $activity = (new ExperienceActivity($db))->recent([
            'backend' => [
                'type' => 'web',
                'id' => 'blackjack',
            ],
        ]);

        self::assertCount(1, $activity);
        self::assertSame('first_play', $activity[0]['type']);
        self::assertSame('Skrawl', $activity[0]['username']);
    }

    public function testActivityIsScopedToExperienceBackendId(): void
    {
        $db = $this->database();

        $db->exec("
            INSERT INTO users (id, username)
            VALUES (3, 'Skrawl');

            INSERT INTO user_activity_log (
                user_id,
                activity_type_id,
                object_name,
                created_at
            ) VALUES
                (
                    3,
                    " . ActivityTracker::TYPE_DOSDOOR_PLAY . ",
                    'usurper',
                    '2026-08-26 16:18:28+00'
                ),
                (
                    3,
                    " . ActivityTracker::TYPE_DOSDOOR_PLAY . ",
                    'lord',
                    '2026-08-26 16:19:00+00'
                );
        ");

        $activity = (new ExperienceActivity($db))->recent([
            'backend' => [
                'type' => 'native',
                'id' => 'usurper',
            ],
        ]);

        self::assertCount(1, $activity);
        self::assertSame('Skrawl', $activity[0]['username']);
    }

    public function testRecentActivityHonorsLimit(): void
    {
        $db = $this->database();

        $db->exec("
            INSERT INTO users (id, username)
            VALUES (3, 'Skrawl');

            INSERT INTO user_activity_log (
                user_id,
                activity_type_id,
                object_name,
                created_at
            ) VALUES
                (
                    3,
                    " . ActivityTracker::TYPE_DOSDOOR_PLAY . ",
                    'usurper',
                    '2026-08-26 16:18:00+00'
                ),
                (
                    3,
                    " . ActivityTracker::TYPE_DOSDOOR_PLAY . ",
                    'usurper',
                    '2026-08-26 16:19:00+00'
                );
        ");

        $activity = (new ExperienceActivity($db))->recent(
            [
                'backend' => [
                    'type' => 'native',
                    'id' => 'usurper',
                ],
            ],
            1
        );

        self::assertCount(1, $activity);
        self::assertSame(
            '2026-08-26 16:19:00+00',
            $activity[0]['occurred_at']
        );
    }

    public function testMissingBackendProducesNoActivity(): void
    {
        $db = $this->database();

        self::assertSame(
            [],
            (new ExperienceActivity($db))->recent([])
        );
    }

    public function testOnlyEarliestPlayIsFirstPlay(): void
    {
        $db = $this->database();

        $db->exec("
            INSERT INTO users (id, username)
            VALUES (3, 'Skrawl');

            INSERT INTO user_activity_log (
                user_id,
                activity_type_id,
                object_name,
                created_at
            ) VALUES
                (
                    3,
                    " . ActivityTracker::TYPE_DOSDOOR_PLAY . ",
                    'usurper',
                    '2026-08-21 09:05:58+00'
                ),
                (
                    3,
                    " . ActivityTracker::TYPE_DOSDOOR_PLAY . ",
                    'usurper',
                    '2026-08-26 16:18:28+00'
                );
        ");

        $activity = (new ExperienceActivity($db))->recent([
            'backend' => [
                'type' => 'native',
                'id' => 'usurper',
            ],
        ]);

        self::assertCount(2, $activity);

        self::assertSame(
            'play',
            $activity[0]['type']
        );

        self::assertSame(
            'first_play',
            $activity[1]['type']
        );
    }

    // ---- recentAcrossCatalog(): Crossroads "Recently in the Crossroads" ----

    private function seedPlays(\PDO $db): void
    {
        $web = ActivityTracker::TYPE_WEBDOOR_PLAY;
        $dos = ActivityTracker::TYPE_DOSDOOR_PLAY;

        $db->exec("
            INSERT INTO users (id, username, is_system) VALUES
                (3, 'Skrawl', 0),
                (7, 'Bard', 0),
                (9, '_guest', 1);

            INSERT INTO user_activity_log (user_id, activity_type_id, object_name, created_at) VALUES
                (7, {$dos}, 'lord',     '2026-08-30 09:00:00+00'),
                (3, {$dos}, 'usurper',  '2026-08-30 10:00:00+00'),
                (7, {$dos}, 'lord',     '2026-08-30 11:00:00+00'),
                (3, {$web}, 'blackjack','2026-08-30 12:00:00+00'),
                (9, {$dos}, 'lord',     '2026-08-30 12:30:00+00'),
                (3, {$dos}, 'hidden-door', '2026-08-30 13:00:00+00'),
                (99, {$dos}, 'lord',    '2026-08-30 13:30:00+00');
        ");
    }

    public function testRecentAcrossCatalogReturnsAuthorizedFootprintsNewestFirst(): void
    {
        $db = $this->database();
        $this->seedPlays($db);

        $catalog = [
            'lord'      => $this->experience('lord', 'Legend of the Red Dragon'),
            'usurper'   => $this->experience('usurper', 'Usurper Reborn'),
            'blackjack' => $this->experience('blackjack', 'Blackjack', 'web'),
        ];

        $rows = (new ExperienceActivity($db))->recentAcrossCatalog($catalog, 5);

        // Newest first; hidden-door (unauthorized), _guest (system) and user 99
        // (deleted -> not in users) are all absent.
        self::assertSame(
            ['blackjack', 'lord', 'usurper', 'lord'],
            array_column($rows, 'experience_id')
        );
        self::assertSame(['Skrawl', 'Bard', 'Skrawl', 'Bard'], array_column($rows, 'username'));
        // Current catalog name, not the raw object_name snapshot.
        self::assertSame('Legend of the Red Dragon', $rows[1]['experience_name']);
        // Durable timestamps, unmodified.
        self::assertSame('2026-08-30 12:00:00+00', $rows[0]['occurred_at']);
    }

    public function testRecentAcrossCatalogHidesUnauthorizedExperienceActivity(): void
    {
        $db = $this->database();
        $this->seedPlays($db);

        // Viewer's catalog does NOT include 'hidden-door'.
        $rows = (new ExperienceActivity($db))->recentAcrossCatalog([
            'lord' => $this->experience('lord', 'Legend of the Red Dragon'),
        ], 5);

        foreach ($rows as $row) {
            self::assertNotSame('hidden-door', $row['experience_id']);
        }
        self::assertSame(['lord', 'lord'], array_column($rows, 'experience_id'));
    }

    public function testRecentAcrossCatalogDropsOrphanedBackendIds(): void
    {
        $db = $this->database();
        $this->seedPlays($db);

        // 'lord' was renamed: its backend id is now 'lotrd'. Historical rows
        // still carry object_name 'lord', which no longer matches -> gone.
        $rows = (new ExperienceActivity($db))->recentAcrossCatalog([
            'lord' => $this->experience('lord', 'Legend of the Red Dragon', 'native', 'lotrd'),
        ], 5);

        self::assertSame([], $rows);
    }

    public function testRecentAcrossCatalogExcludesSystemUsers(): void
    {
        $db = $this->database();
        $this->seedPlays($db);

        $rows = (new ExperienceActivity($db))->recentAcrossCatalog([
            'lord' => $this->experience('lord', 'Legend of the Red Dragon'),
        ], 5);

        self::assertNotContains('_guest', array_column($rows, 'username'));
    }

    public function testRecentAcrossCatalogExcludesDeletedUsers(): void
    {
        $db = $this->database();
        $this->seedPlays($db);

        // user 99 has a 'lord' play row but no users row (deleted).
        $rows = (new ExperienceActivity($db))->recentAcrossCatalog([
            'lord' => $this->experience('lord', 'Legend of the Red Dragon'),
        ], 5);

        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertNotSame(99, $row['user_id']);
            self::assertNotNull($row['username']);
        }
    }

    public function testRecentAcrossCatalogEnforcesHardLimit(): void
    {
        $db = $this->database();
        $db->exec("INSERT INTO users (id, username) VALUES (3, 'Skrawl');");
        $values = [];
        foreach (range(1, 12) as $i) {
            $ts = sprintf('2026-08-30 %02d:00:00+00', $i);
            $values[] = "(3, " . ActivityTracker::TYPE_DOSDOOR_PLAY . ", 'lord', '{$ts}')";
        }
        $db->exec("INSERT INTO user_activity_log (user_id, activity_type_id, object_name, created_at) VALUES " . implode(',', $values));

        $rows = (new ExperienceActivity($db))->recentAcrossCatalog([
            'lord' => $this->experience('lord', 'Legend of the Red Dragon'),
        ], 5);

        self::assertCount(5, $rows);

        // Requesting more than the defensive ceiling is clamped, not honored.
        $clamped = (new ExperienceActivity($db))->recentAcrossCatalog([
            'lord' => $this->experience('lord', 'Legend of the Red Dragon'),
        ], 9999);
        self::assertLessThanOrEqual(25, count($clamped));
    }

    public function testRecentAcrossCatalogPreservesFirstPlayDistinction(): void
    {
        $db = $this->database();
        $db->exec("
            INSERT INTO users (id, username) VALUES (3, 'Skrawl'), (7, 'Bard');
            INSERT INTO user_activity_log (user_id, activity_type_id, object_name, created_at) VALUES
                (3, " . ActivityTracker::TYPE_DOSDOOR_PLAY . ", 'lord', '2026-08-01 10:00:00+00'),
                (3, " . ActivityTracker::TYPE_DOSDOOR_PLAY . ", 'lord', '2026-08-30 10:00:00+00'),
                (7, " . ActivityTracker::TYPE_DOSDOOR_PLAY . ", 'lord', '2026-08-30 11:00:00+00');
        ");

        $rows = (new ExperienceActivity($db))->recentAcrossCatalog([
            'lord' => $this->experience('lord', 'Legend of the Red Dragon'),
        ], 5);

        // Newest first: Bard's only play (his first), Skrawl's recent play (not
        // his first), Skrawl's original play back on Aug 1 (his first).
        self::assertSame(['Bard', 'Skrawl', 'Skrawl'], array_column($rows, 'username'));
        self::assertSame(['first_play', 'play', 'first_play'], array_column($rows, 'type'));
    }

    public function testRecentAcrossCatalogReturnsEmptyForEmptyCatalog(): void
    {
        $db = $this->database();
        $this->seedPlays($db);

        self::assertSame([], (new ExperienceActivity($db))->recentAcrossCatalog([], 5));
        self::assertSame([], (new ExperienceActivity($db))->recentAcrossCatalog([
            ['name' => 'No backend id'],
        ], 5));
    }

    public function testRecentAcrossCatalogReturnsEmptyWhenNoActivity(): void
    {
        $db = $this->database();
        $db->exec("INSERT INTO users (id, username) VALUES (3, 'Skrawl');");

        self::assertSame([], (new ExperienceActivity($db))->recentAcrossCatalog([
            'lord' => $this->experience('lord', 'Legend of the Red Dragon'),
        ], 5));
    }

    public function testRecentAcrossCatalogAcceptsAListNotJustAMap(): void
    {
        $db = $this->database();
        $this->seedPlays($db);

        // Route passes array_values(getEnabledGames(...)) — a 0-indexed list.
        $rows = (new ExperienceActivity($db))->recentAcrossCatalog([
            $this->experience('lord', 'Legend of the Red Dragon'),
            $this->experience('usurper', 'Usurper Reborn'),
        ], 5);

        self::assertNotSame([], $rows);
        self::assertSame(['lord', 'usurper', 'lord'], array_column($rows, 'experience_id'));
    }

}
