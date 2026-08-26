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

    public function testParticipantContractIncludesNodeAndStartedAtForDoorSessions(): void
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
            ) VALUES (
                'door_3_node2_contract',
                3,
                'usurper',
                2,
                '2026-08-25 22:00:00',
                NULL,
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
        self::assertCount(1, $result['players']);

        $player = $result['players'][0];

        self::assertSame(3, $player['user_id']);
        self::assertSame('Skrawl', $player['username']);
        self::assertSame('door_3_node2_contract', $player['session_id']);
        self::assertNull($player['presence']);
        self::assertSame(2, $player['node']);
        self::assertIsInt($player['started_at']);
        self::assertGreaterThan(0, $player['started_at']);
    }

    public function testWebExperienceParticipantHasNullNode(): void
    {
        $db = new PDO('sqlite::memory:');

        $db->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                username TEXT
            );

            CREATE TABLE webdoor_sessions (
                session_id TEXT,
                user_id INTEGER,
                game_id TEXT,
                created_at TEXT,
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
            VALUES (4, 'WebPlayer');

            INSERT INTO webdoor_sessions (
                session_id, user_id, game_id,
                created_at, ended_at, expires_at
            ) VALUES (
                'web_4_contract',
                4,
                'webgame',
                '2026-08-25 22:00:00',
                NULL,
                '2099-01-01 00:00:00'
            );
        ");

        $state = new ExperienceState(
            $db,
            new TestExperienceStateCatalog([
                'webgame' => [
                    'id' => 'webgame',
                    'name' => 'Web Game',
                    'category' => 'game',
                    'backend' => [
                        'type' => 'web',
                    ],
                ],
            ])
        );

        $result = $state->getExperienceState('webgame');

        self::assertIsArray($result);
        self::assertCount(1, $result['players']);

        $player = $result['players'][0];

        self::assertSame(4, $player['user_id']);
        self::assertSame('WebPlayer', $player['username']);
        self::assertSame('web_4_contract', $player['session_id']);
        self::assertNull($player['presence']);
        self::assertNull($player['node']);
        self::assertIsInt($player['started_at']);
        self::assertGreaterThan(0, $player['started_at']);
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

    public function testBulkExperienceStatesSeparateExperiencesAndDeduplicatePlayers(): void
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
            VALUES
                (3, 'Skrawl'),
                (4, 'TestUser');

            INSERT INTO door_sessions (
                session_id,
                user_id,
                door_id,
                node_number,
                started_at,
                ended_at,
                expires_at
            ) VALUES
                (
                    'usurper_3_node1_test',
                    3,
                    'usurper',
                    1,
                    '2026-08-25 22:00:00',
                    NULL,
                    '2099-01-01 00:00:00'
                ),
                (
                    'usurper_3_node2_test',
                    3,
                    'usurper',
                    2,
                    '2026-08-25 22:01:00',
                    NULL,
                    '2099-01-01 00:00:00'
                ),
                (
                    'lateportal_4_node3_test',
                    4,
                    'lateportal',
                    3,
                    '2026-08-25 22:02:00',
                    NULL,
                    '2099-01-01 00:00:00'
                );

            INSERT INTO user_sessions (
                user_id,
                public_activity,
                last_activity,
                expires_at
            ) VALUES
                (
                    3,
                    'Playing Usurper Reborn',
                    datetime('now', '-1 minute'),
                    '2099-01-01 00:00:00'
                ),
                (
                    4,
                    'Playing Lateportal',
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
                'lateportal' => [
                    'id' => 'lateportal',
                    'name' => 'Lateportal',
                    'category' => 'game',
                ],
                'offline' => [
                    'id' => 'offline',
                    'name' => 'Offline Experience',
                    'category' => 'game',
                ],
            ])
        );

        $result = $state->getExperienceStates();

        self::assertCount(3, $result);

        self::assertTrue($result['usurper']['active']);
        self::assertSame(2, $result['usurper']['session_count']);
        self::assertSame(1, $result['usurper']['player_count']);
        self::assertCount(2, $result['usurper']['players']);

        self::assertSame(
            'Playing Usurper Reborn',
            $result['usurper']['players'][0]['presence']
        );
        self::assertSame(
            'Playing Usurper Reborn',
            $result['usurper']['players'][1]['presence']
        );

        self::assertTrue($result['lateportal']['active']);
        self::assertSame(1, $result['lateportal']['session_count']);
        self::assertSame(1, $result['lateportal']['player_count']);
        self::assertCount(1, $result['lateportal']['players']);
        self::assertSame(
            'Playing Lateportal',
            $result['lateportal']['players'][0]['presence']
        );

        self::assertFalse($result['offline']['active']);
        self::assertSame(0, $result['offline']['session_count']);
        self::assertSame(0, $result['offline']['player_count']);
        self::assertSame([], $result['offline']['players']);
    }

    public function testWebExperienceStateReadsActiveWebdoorSession(): void
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

            CREATE TABLE webdoor_sessions (
                session_id TEXT,
                user_id INTEGER,
                game_id TEXT,
                created_at TEXT,
                ended_at TEXT,
                expires_at TEXT
            );

            CREATE TABLE user_sessions (
                user_id INTEGER,
                public_activity TEXT,
                last_activity TEXT,
                expires_at TEXT
            );

            INSERT INTO users (id, username)
            VALUES (3, 'Skrawl');

            INSERT INTO webdoor_sessions (
                session_id,
                user_id,
                game_id,
                created_at,
                ended_at,
                expires_at
            ) VALUES (
                'webdoor-blackjack-test',
                3,
                'blackjack',
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
                'Playing Blackjack',
                datetime('now', '-1 minute'),
                '2099-01-01 00:00:00'
            );
        ");

        $state = new ExperienceState(
            $db,
            new TestExperienceStateCatalog([
                'blackjack' => [
                    'id' => 'blackjack',
                    'name' => 'Blackjack',
                    'category' => 'game',
                    'backend' => [
                        'type' => 'web',
                        'id' => 'blackjack',
                    ],
                ],
            ])
        );

        $result = $state->getExperienceState('blackjack');

        self::assertIsArray($result);
        self::assertTrue($result['active']);
        self::assertSame(1, $result['session_count']);
        self::assertSame(1, $result['player_count']);
        self::assertCount(1, $result['players']);

        self::assertSame(3, $result['players'][0]['user_id']);
        self::assertSame('Skrawl', $result['players'][0]['username']);
        self::assertSame(
            'webdoor-blackjack-test',
            $result['players'][0]['session_id']
        );
        self::assertSame(
            'Playing Blackjack',
            $result['players'][0]['presence']
        );
        self::assertNull($result['players'][0]['node']);
    }

    public function testJsdosExperiencePreservesNullNode(): void
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

            CREATE TABLE webdoor_sessions (
                session_id TEXT,
                user_id INTEGER,
                game_id TEXT,
                created_at TEXT,
                ended_at TEXT,
                expires_at TEXT
            );

            CREATE TABLE user_sessions (
                user_id INTEGER,
                public_activity TEXT,
                last_activity TEXT,
                expires_at TEXT
            );

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
                'jsdos-doomsw-test',
                3,
                'doomsw',
                NULL,
                '2026-08-25 22:00:00',
                NULL,
                '2099-01-01 00:00:00'
            );
        ");

        $state = new ExperienceState(
            $db,
            new TestExperienceStateCatalog([
                'doomsw' => [
                    'id' => 'doomsw',
                    'name' => 'Doom',
                    'category' => 'game',
                    'backend' => [
                        'type' => 'jsdos',
                        'id' => 'doomsw',
                    ],
                ],
            ])
        );

        $result = $state->getExperienceState('doomsw');

        self::assertIsArray($result);
        self::assertTrue($result['active']);
        self::assertSame(1, $result['session_count']);
        self::assertSame(1, $result['player_count']);
        self::assertCount(1, $result['players']);
        self::assertNull($result['players'][0]['node']);
    }

    public function testBulkExperienceStatesCombineDoorAndWebdoorSessions(): void
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

            CREATE TABLE webdoor_sessions (
                session_id TEXT,
                user_id INTEGER,
                game_id TEXT,
                created_at TEXT,
                ended_at TEXT,
                expires_at TEXT
            );

            CREATE TABLE user_sessions (
                user_id INTEGER,
                public_activity TEXT,
                last_activity TEXT,
                expires_at TEXT
            );

            INSERT INTO users (id, username)
            VALUES
                (3, 'Skrawl'),
                (4, 'TestUser');

            INSERT INTO door_sessions (
                session_id,
                user_id,
                door_id,
                node_number,
                started_at,
                ended_at,
                expires_at
            ) VALUES (
                'native-usurper-test',
                3,
                'usurper',
                1,
                '2026-08-25 22:00:00',
                NULL,
                '2099-01-01 00:00:00'
            );

            INSERT INTO webdoor_sessions (
                session_id,
                user_id,
                game_id,
                created_at,
                ended_at,
                expires_at
            ) VALUES (
                'webdoor-blackjack-test',
                4,
                'blackjack',
                '2026-08-25 22:01:00',
                NULL,
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
                    'backend' => [
                        'type' => 'native',
                        'id' => 'usurper',
                    ],
                ],
                'blackjack' => [
                    'id' => 'blackjack',
                    'name' => 'Blackjack',
                    'category' => 'game',
                    'backend' => [
                        'type' => 'web',
                        'id' => 'blackjack',
                    ],
                ],
            ])
        );

        $result = $state->getExperienceStates();

        self::assertTrue($result['usurper']['active']);
        self::assertSame(1, $result['usurper']['session_count']);
        self::assertSame('Skrawl', $result['usurper']['players'][0]['username']);
        self::assertSame(1, $result['usurper']['players'][0]['node']);

        self::assertTrue($result['blackjack']['active']);
        self::assertSame(1, $result['blackjack']['session_count']);
        self::assertSame(
            'TestUser',
            $result['blackjack']['players'][0]['username']
        );
        self::assertNull($result['blackjack']['players'][0]['node']);
    }

    public function testBulkExperienceStatesReturnsEmptyForEmptyCatalog(): void
    {
        $db = new PDO('sqlite::memory:');

        $state = new ExperienceState(
            $db,
            new TestExperienceStateCatalog([])
        );

        self::assertSame([], $state->getExperienceStates());
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
