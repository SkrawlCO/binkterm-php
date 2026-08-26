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
                username TEXT
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

}
