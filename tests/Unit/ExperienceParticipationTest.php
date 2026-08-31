<?php

declare(strict_types=1);

use BinktermPHP\ExperienceParticipation;
use PHPUnit\Framework\TestCase;

final class ExperienceParticipationTest extends TestCase
{
    public function testViewerActionsSeparatePlayReturnAndEnd(): void
    {
        $experience = [
            'backend' => [
                'type' => 'native',
                'id' => 'usurper',
            ],
        ];

        self::assertSame(
            [
                'play' => true,
                'return' => false,
                'end' => false,
            ],
            ExperienceParticipation::viewerActions($experience, null)
        );

        self::assertSame(
            [
                'play' => false,
                'return' => true,
                'end' => true,
            ],
            ExperienceParticipation::viewerActions(
                $experience,
                [
                    'user_id' => 3,
                    'session_id' => 'door_3_node1_test',
                ]
            )
        );
    }

    public function testViewerCannotPlayOrReturnOnUnsupportedWebSurface(): void
    {
        $experience = [
            'backend' => [
                'type' => 'native',
                'id' => 'terminal-only',
            ],
            'surfaces' => [
                'web' => 'unavailable',
                'telnet' => 'full',
            ],
            'policy' => [
                'enabled' => true,
            ],
        ];

        self::assertSame(
            ['play' => false, 'return' => false, 'end' => false],
            ExperienceParticipation::viewerActions($experience, null)
        );

        self::assertSame(
            ['play' => false, 'return' => false, 'end' => true],
            ExperienceParticipation::viewerActions($experience, [
                'user_id' => 3,
                'session_id' => 'terminal-session',
            ])
        );
    }

    public function testViewerLookupReturnsOnlyCurrentUserParticipation(): void
    {
        $state = [
            'players' => [
                [
                    'user_id' => 4,
                    'session_id' => 'other',
                ],
                [
                    'user_id' => 3,
                    'session_id' => 'mine',
                ],
            ],
        ];

        self::assertSame(
            'mine',
            ExperienceParticipation::findViewerPlayer(
                $state,
                3
            )['session_id']
        );

        self::assertNull(
            ExperienceParticipation::findViewerPlayer(
                $state,
                99
            )
        );
    }

    public function testHasDistinctOtherPlayerDetectsAnotherActiveCaller(): void
    {
        $state = [
            'players' => [
                ['user_id' => 3, 'session_id' => 'mine'],
                ['user_id' => 7, 'session_id' => 'theirs'],
            ],
        ];

        self::assertTrue(
            ExperienceParticipation::hasDistinctOtherPlayer($state, 3)
        );
    }

    public function testHasDistinctOtherPlayerIsFalseWhenOnlyTheViewerIsPresent(): void
    {
        $state = [
            'players' => [
                ['user_id' => 3, 'session_id' => 'node1'],
                // Same account on a second node: still just the viewer.
                ['user_id' => 3, 'session_id' => 'node2'],
            ],
        ];

        self::assertFalse(
            ExperienceParticipation::hasDistinctOtherPlayer($state, 3)
        );
    }

    public function testHasDistinctOtherPlayerCollapsesDuplicateOtherSessions(): void
    {
        $state = [
            'players' => [
                ['user_id' => 7, 'session_id' => 'node1'],
                ['user_id' => 7, 'session_id' => 'node2'],
            ],
        ];

        // Two sessions, one other person — the predicate is a boolean and must
        // still report "someone else is here".
        self::assertTrue(
            ExperienceParticipation::hasDistinctOtherPlayer($state, 3)
        );
    }

    public function testHasDistinctOtherPlayerTreatsEveryRealPlayerAsOtherWhenThereIsNoViewer(): void
    {
        $state = [
            'players' => [
                ['user_id' => 3, 'session_id' => 'a'],
            ],
        ];

        self::assertTrue(
            ExperienceParticipation::hasDistinctOtherPlayer($state, 0)
        );
    }

    public function testHasDistinctOtherPlayerHandlesMissingEmptyAndMalformedRostersSafely(): void
    {
        self::assertFalse(
            ExperienceParticipation::hasDistinctOtherPlayer(null, 3)
        );
        self::assertFalse(
            ExperienceParticipation::hasDistinctOtherPlayer([], 3)
        );
        self::assertFalse(
            ExperienceParticipation::hasDistinctOtherPlayer(['players' => []], 3)
        );
        self::assertFalse(
            ExperienceParticipation::hasDistinctOtherPlayer(
                ['players' => 'not-an-array'],
                3
            )
        );
        self::assertFalse(
            ExperienceParticipation::hasDistinctOtherPlayer(
                ['players' => ['bare-string', ['user_id' => 0], ['user_id' => null]]],
                3
            )
        );
    }

    public function testJsdosEndIsScopedToViewerAndExperience(): void
    {
        $db = new PDO('sqlite::memory:');

        $db->exec("
            CREATE TABLE door_sessions (
                session_id TEXT,
                user_id INTEGER,
                door_id TEXT,
                door_type TEXT,
                ended_at TEXT
            )
        ");

        $db->exec("
            INSERT INTO door_sessions
                (session_id, user_id, door_id, door_type, ended_at)
            VALUES
                ('mine', 3, 'doom', 'jsdos', NULL),
                ('other-game', 3, 'quake', 'jsdos', NULL),
                ('other-user', 4, 'doom', 'jsdos', NULL)
        ");

        $service = new ExperienceParticipation($db);

        self::assertTrue(
            $service->end(
                [
                    'backend' => [
                        'type' => 'jsdos',
                        'id' => 'doom',
                    ],
                ],
                ['user_id' => 3],
                [
                    'user_id' => 3,
                    'session_id' => 'mine',
                ]
            )
        );

        self::assertSame(
            1,
            (int)$db->query("
                SELECT COUNT(*)
                  FROM door_sessions
                 WHERE session_id = 'mine'
                   AND ended_at IS NOT NULL
            ")->fetchColumn()
        );

        self::assertSame(
            2,
            (int)$db->query("
                SELECT COUNT(*)
                  FROM door_sessions
                 WHERE ended_at IS NULL
            ")->fetchColumn()
        );
    }

    public function testWebDoorEndIsScopedToViewerAndExperience(): void
    {
        $db = new PDO('sqlite::memory:');

        $db->exec("
            CREATE TABLE webdoor_sessions (
                session_id TEXT,
                user_id INTEGER,
                game_id TEXT,
                ended_at TEXT
            )
        ");

        $db->exec("
            INSERT INTO webdoor_sessions
                (session_id, user_id, game_id, ended_at)
            VALUES
                ('mine', 3, 'blackjack', NULL),
                ('other-game', 3, 'asterion', NULL),
                ('other-user', 4, 'blackjack', NULL)
        ");

        $service = new ExperienceParticipation($db);

        self::assertTrue(
            $service->end(
                [
                    'backend' => [
                        'type' => 'web',
                        'id' => 'blackjack',
                    ],
                ],
                ['user_id' => 3],
                [
                    'user_id' => 3,
                    'session_id' => 'mine',
                ]
            )
        );

        self::assertSame(
            1,
            (int)$db->query("
                SELECT COUNT(*)
                  FROM webdoor_sessions
                 WHERE session_id = 'mine'
                   AND ended_at IS NOT NULL
            ")->fetchColumn()
        );

        self::assertSame(
            2,
            (int)$db->query("
                SELECT COUNT(*)
                  FROM webdoor_sessions
                 WHERE ended_at IS NULL
            ")->fetchColumn()
        );
    }

    public function testUnsupportedBackendDoesNotAdvertiseEnd(): void
    {
        $experience = [
            'backend' => [
                'type' => 'future-backend',
                'id' => 'example',
            ],
        ];

        self::assertFalse(
            ExperienceParticipation::canEnd($experience)
        );

        self::assertSame(
            [
                'play' => false,
                'return' => false,
                'end' => false,
            ],
            ExperienceParticipation::viewerActions(
                $experience,
                [
                    'user_id' => 3,
                    'session_id' => 'future-session',
                ]
            )
        );
    }

    public function testViewerCannotEndAnotherUsersParticipation(): void
    {
        $db = new \PDO('sqlite::memory:');

        $db->exec("
            CREATE TABLE webdoor_sessions (
                session_id TEXT,
                user_id INTEGER,
                game_id TEXT,
                ended_at TEXT
            )
        ");

        $db->exec("
            INSERT INTO webdoor_sessions
                (session_id, user_id, game_id, ended_at)
            VALUES
                ('other-user', 4, 'blackjack', NULL)
        ");

        $service = new ExperienceParticipation($db);

        self::assertFalse(
            $service->end(
                [
                    'backend' => [
                        'type' => 'web',
                        'id' => 'blackjack',
                    ],
                ],
                ['user_id' => 3],
                [
                    'user_id' => 4,
                    'session_id' => 'other-user',
                ]
            )
        );

        self::assertSame(
            1,
            (int)$db->query("
                SELECT COUNT(*)
                  FROM webdoor_sessions
                 WHERE session_id = 'other-user'
                   AND ended_at IS NULL
            ")->fetchColumn()
        );
    }

}
