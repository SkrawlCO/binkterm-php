<?php

declare(strict_types=1);

use BinktermPHP\Database;
use BinktermPHP\ExperienceState;
use BinktermPHP\GameCatalog;
use PHPUnit\Framework\TestCase;

final class ExperienceStateTest extends TestCase
{
    public function testUnknownExperienceReturnsNull(): void
    {
        $state = new ExperienceState(
            new PDO('sqlite::memory:'),
            new TestExperienceStateCatalog([])
        );

        self::assertNull(
            $state->getExperienceState('missing')
        );
    }

    public function testExperienceStateReportsActivePlayerAndPublicPresence(): void
    {
        $db = new PDO('sqlite::memory:');

        $db->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                username TEXT
            );

            CREATE TABLE door_sessions (
                session_id TEXT,
                user_id INTEGER,
                door_id TEXT,
                node_number INTEGER,
                started_at TEXT,
                ended_at TEXT,
                expires_at TEXT
            );

            CREATE TABLE user_sessions (
                user_id INTEGER,
                public_activity TEXT,
                last_activity TEXT,
                expires_at TEXT
            );
        ");

        $db->exec("
            INSERT INTO users (id, username)
            VALUES (3, 'Skrawl');

            INSERT INTO door_sessions (
                session_id,
                user_id,
                door_id,
                node_number,
                started_at,
                ended_at,
                expires_at
            ) VALUES (
                'door_3_node1_test',
                3,
                'usurper',
                1,
                '2026-08-25 22:00:00',
                NULL,
                '2099-01-01 00:00:00'
            );

            INSERT INTO user_sessions (
                user_id,
                public_activity,
                last_activity,
                expires_at
            ) VALUES (
                3,
                'Playing Usurper Reborn',
                datetime('now', '-1 minute'),
                '2099-01-01 00:00:00'
            );
        ");

        $state = new ExperienceState(
            $db,
            new TestExperienceStateCatalog([
                'usurper' => [
                    'id' => 'usurper',
                    'name' => 'Usurper Reborn',
                    'category' => 'game',
                ],
            ])
        );

        $result = $state->getExperienceState('usurper');

        self::assertIsArray($result);
        self::assertTrue($result['active']);
        self::assertSame(1, $result['session_count']);
        self::assertSame(1, $result['player_count']);
        self::assertCount(1, $result['players']);

        self::assertSame(3, $result['players'][0]['user_id']);
        self::assertSame('Skrawl', $result['players'][0]['username']);
        self::assertSame(
            'Playing Usurper Reborn',
            $result['players'][0]['presence']
        );
        self::assertSame(
            'door_3_node1_test',
            $result['players'][0]['session_id']
        );
    }

    public function testMultipleSessionsForOneUserCountAsOnePlayer(): void
    {
        $db = new PDO('sqlite::memory:');

        $db->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                username TEXT
            );

            CREATE TABLE door_sessions (
                session_id TEXT,
                user_id INTEGER,
                door_id TEXT,
                node_number INTEGER,
                started_at TEXT,
                ended_at TEXT,
                expires_at TEXT
            );

            CREATE TABLE user_sessions (
                user_id INTEGER,
                public_activity TEXT,
                last_activity TEXT,
                expires_at TEXT
            );
        ");

        $db->exec("
            INSERT INTO users (id, username)
            VALUES (3, 'Skrawl');

            INSERT INTO door_sessions (
                session_id, user_id, door_id, node_number,
                started_at, ended_at, expires_at
            ) VALUES
                (
                    'door_3_node1_test',
                    3,
                    'usurper',
                    1,
                    '2026-08-25 22:00:00',
                    NULL,
                    '2099-01-01 00:00:00'
                ),
                (
                    'door_3_node2_test',
                    3,
                    'usurper',
                    2,
                    '2026-08-25 22:01:00',
                    NULL,
                    '2099-01-01 00:00:00'
                );

            INSERT INTO user_sessions (
                user_id,
                public_activity,
                last_activity,
                expires_at
            ) VALUES (
                3,
                'Playing Usurper Reborn',
                datetime('now', '-1 minute'),
                '2099-01-01 00:00:00'
            );
        ");

        $state = new ExperienceState(
            $db,
            new TestExperienceStateCatalog([
                'usurper' => [
                    'id' => 'usurper',
                    'name' => 'Usurper Reborn',
                    'category' => 'game',
                ],
            ])
        );

        $result = $state->getExperienceState('usurper');

        self::assertIsArray($result);
        self::assertTrue($result['active']);
        self::assertSame(2, $result['session_count']);
        self::assertSame(1, $result['player_count']);
        self::assertCount(2, $result['players']);

        self::assertSame(3, $result['players'][0]['user_id']);
        self::assertSame(3, $result['players'][1]['user_id']);
    }

    public function testExperienceStateReportsInactiveExperienceWithNoSessions(): void
    {
        $db = new PDO('sqlite::memory:');

        $db->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                username TEXT
            );

            CREATE TABLE door_sessions (
                session_id TEXT,
                user_id INTEGER,
                door_id TEXT,
                node_number INTEGER,
                started_at TEXT,
                ended_at TEXT,
                expires_at TEXT
            )
        ");

        $state = new ExperienceState(
            $db,
            new TestExperienceStateCatalog([
                'usurper' => [
                    'id' => 'usurper',
                    'name' => 'Usurper Reborn',
                    'category' => 'game',
                ],
            ])
        );

        $result = $state->getExperienceState('usurper');

        self::assertIsArray($result);
        self::assertFalse($result['active']);
        self::assertSame(0, $result['session_count']);
        self::assertSame(0, $result['player_count']);
        self::assertSame([], $result['players']);
        self::assertSame(
            'Usurper Reborn',
            $result['experience']['name']
        );
    }

    public function testUnavailableExperienceReturnsNull(): void
    {
        $db = new PDO('sqlite::memory:');

        $db->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                username TEXT
            );

            CREATE TABLE door_sessions (
                session_id TEXT,
                user_id INTEGER,
                door_id TEXT,
                node_number INTEGER,
                started_at TEXT,
                ended_at TEXT,
                expires_at TEXT
            );

            CREATE TABLE user_sessions (
                user_id INTEGER,
                public_activity TEXT,
                last_activity TEXT,
                expires_at TEXT
            );
        ");

        $state = new ExperienceState(
            $db,
            new TestExperienceStateCatalog([])
        );

        self::assertNull(
            $state->getExperienceState(
                'usurper',
                ['user_id' => 3],
                'web'
            )
        );
    }

    }

final class TestExperienceStateCatalog extends GameCatalog
{
    /** @var array<string,array<string,mixed>> */
    private array $experiences;

    /**
     * @param array<string,array<string,mixed>> $experiences
     */
    public function __construct(array $experiences)
    {
        $this->experiences = $experiences;
    }

    public function getEnabledGames(
        ?array $user = null,
        string $surface = 'web'
    ): array {
        return $this->experiences;
    }
}
