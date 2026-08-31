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

    public function testRecentAcrossCatalogReturnsDistinctFootprintsNewestFirst(): void
    {
        $db = $this->database();
        $this->seedPlays($db);

        $catalog = [
            'lord'      => $this->experience('lord', 'Legend of the Red Dragon'),
            'usurper'   => $this->experience('usurper', 'Usurper Reborn'),
            'blackjack' => $this->experience('blackjack', 'Blackjack', 'web'),
        ];

        $rows = (new ExperienceActivity($db))->recentAcrossCatalog($catalog, 5);

        // One footprint per (user, Experience) pair, newest first. Bard's two
        // 'lord' plays (09:00, 11:00) collapse to the 11:00 one. hidden-door
        // (unauthorized), _guest (system) and user 99 (deleted) are all absent.
        self::assertSame(
            ['blackjack', 'lord', 'usurper'],
            array_column($rows, 'experience_id')
        );
        self::assertSame(['Skrawl', 'Bard', 'Skrawl'], array_column($rows, 'username'));
        // Current catalog name, not the raw object_name snapshot.
        self::assertSame('Legend of the Red Dragon', $rows[1]['experience_name']);
        // Durable timestamps, unmodified; Bard's collapsed footprint is the newest.
        self::assertSame('2026-08-30 12:00:00+00', $rows[0]['occurred_at']);
        self::assertSame('2026-08-30 11:00:00+00', $rows[1]['occurred_at']);
    }

    public function testRecentAcrossCatalogCollapsesRepeatedSamePairToNewest(): void
    {
        $db = $this->database();
        $db->exec("
            INSERT INTO users (id, username) VALUES (7, 'Bard');
            INSERT INTO user_activity_log (user_id, activity_type_id, object_name, created_at) VALUES
                (7, " . ActivityTracker::TYPE_WEBDOOR_PLAY . ", 'lord', '2026-08-30 12:00:00+00'),
                (7, " . ActivityTracker::TYPE_WEBDOOR_PLAY . ", 'lord', '2026-08-30 12:00:30+00'),
                (7, " . ActivityTracker::TYPE_WEBDOOR_PLAY . ", 'lord', '2026-08-30 12:01:00+00'),
                (7, " . ActivityTracker::TYPE_WEBDOOR_PLAY . ", 'lord', '2026-08-30 12:15:00+00');
        ");

        $rows = (new ExperienceActivity($db))->recentAcrossCatalog([
            'lord' => $this->experience('lord', 'Legend of the Red Dragon'),
        ], 5);

        // Four raw plays -> exactly one footprint, the newest.
        self::assertCount(1, $rows);
        self::assertSame('2026-08-30 12:15:00+00', $rows[0]['occurred_at']);
        // The pair has older plays, so the surviving footprint is ordinary play.
        self::assertSame('play', $rows[0]['type']);
    }

    public function testRecentAcrossCatalogKeepsSameUserAcrossDifferentExperiences(): void
    {
        $db = $this->database();
        $db->exec("
            INSERT INTO users (id, username) VALUES (7, 'Bard');
            INSERT INTO user_activity_log (user_id, activity_type_id, object_name, created_at) VALUES
                (7, " . ActivityTracker::TYPE_DOSDOOR_PLAY . ", 'lord',    '2026-08-30 12:00:00+00'),
                (7, " . ActivityTracker::TYPE_DOSDOOR_PLAY . ", 'usurper', '2026-08-30 09:00:00+00');
        ");

        $rows = (new ExperienceActivity($db))->recentAcrossCatalog([
            'lord'    => $this->experience('lord', 'Legend of the Red Dragon'),
            'usurper' => $this->experience('usurper', 'Usurper Reborn'),
        ], 5);

        self::assertSame(['lord', 'usurper'], array_column($rows, 'experience_id'));
        self::assertSame(['Bard', 'Bard'], array_column($rows, 'username'));
    }

    public function testRecentAcrossCatalogKeepsDifferentUsersInTheSameExperience(): void
    {
        $db = $this->database();
        $db->exec("
            INSERT INTO users (id, username) VALUES (3, 'Skrawl'), (7, 'Bard');
            INSERT INTO user_activity_log (user_id, activity_type_id, object_name, created_at) VALUES
                (7, " . ActivityTracker::TYPE_DOSDOOR_PLAY . ", 'lord', '2026-08-30 12:00:00+00'),
                (3, " . ActivityTracker::TYPE_DOSDOOR_PLAY . ", 'lord', '2026-08-30 11:00:00+00');
        ");

        $rows = (new ExperienceActivity($db))->recentAcrossCatalog([
            'lord' => $this->experience('lord', 'Legend of the Red Dragon'),
        ], 5);

        self::assertSame(['lord', 'lord'], array_column($rows, 'experience_id'));
        self::assertSame(['Bard', 'Skrawl'], array_column($rows, 'username'));
    }

    public function testRecentAcrossCatalogSelectsDistinctPairsBeforeApplyingLimit(): void
    {
        $db = $this->database();
        $db->exec("
            INSERT INTO users (id, username) VALUES
                (10, 'userA'), (11, 'userB'), (12, 'userC'),
                (13, 'userD'), (14, 'userE'), (15, 'userF');
        ");
        $dos = ActivityTracker::TYPE_DOSDOOR_PLAY;
        $values = [];
        // userA played 'lord' eight times, all more recent than everyone else.
        foreach (range(0, 7) as $m) {
            $ts = sprintf('2026-08-30 12:%02d:00+00', $m);
            $values[] = "(10, {$dos}, 'lord', '{$ts}')";
        }
        // Five other users each played 'lord' once, older, one per hour.
        foreach ([11 => 11, 12 => 10, 13 => 9, 14 => 8, 15 => 7] as $uid => $hour) {
            $ts = sprintf('2026-08-30 %02d:00:00+00', $hour);
            $values[] = "({$uid}, {$dos}, 'lord', '{$ts}')";
        }
        $db->exec("INSERT INTO user_activity_log (user_id, activity_type_id, object_name, created_at) VALUES " . implode(',', $values));

        $rows = (new ExperienceActivity($db))->recentAcrossCatalog([
            'lord' => $this->experience('lord', 'Legend of the Red Dragon'),
        ], 5);

        // Six distinct pairs exist. If de-dup happened AFTER the limit the
        // result would be just [userA] (five raw rows all belong to userA's
        // pair). Distinct-pair-then-limit yields the five newest distinct
        // pairs: userA (newest of its eight), then userB..userE. userF drops.
        self::assertCount(5, $rows);
        self::assertSame(['userA', 'userB', 'userC', 'userD', 'userE'], array_column($rows, 'username'));
        self::assertSame('2026-08-30 12:07:00+00', $rows[0]['occurred_at']);
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
        // Bard's two 'lord' plays collapse to one footprint.
        self::assertSame(['lord'], array_column($rows, 'experience_id'));
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

        self::assertCount(1, $rows);
        foreach ($rows as $row) {
            self::assertNotSame(99, $row['user_id']);
            self::assertNotNull($row['username']);
        }
    }

    public function testRecentAcrossCatalogEnforcesHardLimit(): void
    {
        $db = $this->database();
        $dos = ActivityTracker::TYPE_DOSDOOR_PLAY;

        // 30 distinct (user, 'lord') pairs.
        $userValues = [];
        $logValues = [];
        foreach (range(1, 30) as $i) {
            $userValues[] = "({$i}, 'user{$i}')";
            $ts = sprintf('2026-08-%02d 12:00:00+00', $i);
            $logValues[] = "({$i}, {$dos}, 'lord', '{$ts}')";
        }
        $db->exec("INSERT INTO users (id, username) VALUES " . implode(',', $userValues));
        $db->exec("INSERT INTO user_activity_log (user_id, activity_type_id, object_name, created_at) VALUES " . implode(',', $logValues));

        $catalog = ['lord' => $this->experience('lord', 'Legend of the Red Dragon')];

        self::assertCount(5, (new ExperienceActivity($db))->recentAcrossCatalog($catalog, 5));

        // Requesting more than the defensive ceiling is clamped, not honored.
        $clamped = (new ExperienceActivity($db))->recentAcrossCatalog($catalog, 9999);
        self::assertLessThanOrEqual(25, count($clamped));
    }

    public function testRecentAcrossCatalogFirstPlayStatusUsesFullHistoryNotTheCollapsedResult(): void
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

        // One footprint per pair: Bard (his only, ever -> first_play), Skrawl
        // (his newest, but he has an older Aug 1 play -> ordinary play, NOT
        // first_play just because the older row collapsed out).
        self::assertSame(['Bard', 'Skrawl'], array_column($rows, 'username'));
        self::assertSame(['first_play', 'play'], array_column($rows, 'type'));
        self::assertSame('2026-08-30 10:00:00+00', $rows[1]['occurred_at']);
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
        // Bard/lord (collapsed to 11:00) then Skrawl/usurper (10:00).
        self::assertSame(['lord', 'usurper'], array_column($rows, 'experience_id'));
    }

    // ---- recentForUser(): dashboard "You played ..." personal continuity ----

    public function testRecentForUserReturnsViewerNewestAuthorizedFootprint(): void
    {
        $db = $this->database();
        $this->seedPlays($db);

        $catalog = [
            'lord'      => $this->experience('lord', 'Legend of the Red Dragon'),
            'usurper'   => $this->experience('usurper', 'Usurper Reborn'),
            'blackjack' => $this->experience('blackjack', 'Blackjack', 'web'),
        ];

        // Skrawl (id 3) played: usurper 10:00, blackjack 12:00, hidden-door
        // 13:00. hidden-door is not in the catalog, so the newest *authorized*
        // footprint is blackjack.
        $rows = (new ExperienceActivity($db))->recentForUser($catalog, 3, 1);

        self::assertCount(1, $rows);
        self::assertSame('blackjack', $rows[0]['experience_id']);
        self::assertSame('Blackjack', $rows[0]['experience_name']);
        self::assertSame(3, $rows[0]['user_id']);
        self::assertSame('2026-08-30 12:00:00+00', $rows[0]['occurred_at']);
        // Never leaks the community/first-play shape.
        self::assertArrayNotHasKey('username', $rows[0]);
        self::assertArrayNotHasKey('type', $rows[0]);
    }

    public function testRecentForUserNeverReturnsAnotherUsersActivity(): void
    {
        $db = $this->database();
        $this->seedPlays($db);

        $catalog = ['lord' => $this->experience('lord', 'Legend of the Red Dragon')];

        // Bard (id 7) has 'lord' plays; the viewer (id 3) has none in 'lord'.
        $rows = (new ExperienceActivity($db))->recentForUser($catalog, 3, 1);

        self::assertSame([], $rows);
    }

    public function testRecentForUserHidesUnauthorizedExperienceButKeepsOlderAuthorizedOne(): void
    {
        $db = $this->database();
        $db->exec("
            INSERT INTO users (id, username) VALUES (3, 'Skrawl');
            INSERT INTO user_activity_log (user_id, activity_type_id, object_name, created_at) VALUES
                (3, " . ActivityTracker::TYPE_DOSDOOR_PLAY . ", 'lord',        '2026-08-30 10:00:00+00'),
                (3, " . ActivityTracker::TYPE_DOSDOOR_PLAY . ", 'hidden-door', '2026-08-30 13:00:00+00');
        ");

        // Catalog authorizes only 'lord'. The newer 'hidden-door' row is filtered
        // out in SQL, so 'lord' surfaces instead of nothing.
        $rows = (new ExperienceActivity($db))->recentForUser([
            'lord' => $this->experience('lord', 'Legend of the Red Dragon'),
        ], 3, 1);

        self::assertCount(1, $rows);
        self::assertSame('lord', $rows[0]['experience_id']);
    }

    public function testRecentForUserReturnsEmptyWhenOnlyUnauthorizedActivityExists(): void
    {
        $db = $this->database();
        $db->exec("
            INSERT INTO users (id, username) VALUES (3, 'Skrawl');
            INSERT INTO user_activity_log (user_id, activity_type_id, object_name, created_at) VALUES
                (3, " . ActivityTracker::TYPE_DOSDOOR_PLAY . ", 'hidden-door', '2026-08-30 13:00:00+00');
        ");

        $rows = (new ExperienceActivity($db))->recentForUser([
            'lord' => $this->experience('lord', 'Legend of the Red Dragon'),
        ], 3, 1);

        self::assertSame([], $rows);
    }

    public function testRecentForUserDropsRenamedOrphanedBackendIds(): void
    {
        $db = $this->database();
        $db->exec("
            INSERT INTO users (id, username) VALUES (3, 'Skrawl');
            INSERT INTO user_activity_log (user_id, activity_type_id, object_name, created_at) VALUES
                (3, " . ActivityTracker::TYPE_DOSDOOR_PLAY . ", 'lord', '2026-08-30 10:00:00+00');
        ");

        // 'lord' was renamed; its backend id is now 'lotrd'. The historical row
        // still says 'lord' and no longer matches.
        $rows = (new ExperienceActivity($db))->recentForUser([
            'lord' => $this->experience('lord', 'Legend of the Red Dragon', 'native', 'lotrd'),
        ], 3, 1);

        self::assertSame([], $rows);
    }

    public function testRecentForUserAcceptsBothWebAndDosPlayTypesAndPicksNewest(): void
    {
        $db = $this->database();
        $db->exec("
            INSERT INTO users (id, username) VALUES (3, 'Skrawl');
            INSERT INTO user_activity_log (user_id, activity_type_id, object_name, created_at) VALUES
                (3, " . ActivityTracker::TYPE_DOSDOOR_PLAY . ", 'lord',      '2026-08-30 10:00:00+00'),
                (3, " . ActivityTracker::TYPE_WEBDOOR_PLAY . ", 'blackjack', '2026-08-30 11:00:00+00');
        ");

        $rows = (new ExperienceActivity($db))->recentForUser([
            'lord'      => $this->experience('lord', 'Legend of the Red Dragon'),
            'blackjack' => $this->experience('blackjack', 'Blackjack', 'web'),
        ], 3, 1);

        self::assertCount(1, $rows);
        self::assertSame('blackjack', $rows[0]['experience_id']);
    }

    public function testRecentForUserReturnsEmptyForNonPositiveUserOrEmptyCatalog(): void
    {
        $db = $this->database();
        $this->seedPlays($db);

        $catalog = ['lord' => $this->experience('lord', 'Legend of the Red Dragon')];

        self::assertSame([], (new ExperienceActivity($db))->recentForUser($catalog, 0, 1));
        self::assertSame([], (new ExperienceActivity($db))->recentForUser($catalog, -5, 1));
        self::assertSame([], (new ExperienceActivity($db))->recentForUser([], 3, 1));
    }

}
