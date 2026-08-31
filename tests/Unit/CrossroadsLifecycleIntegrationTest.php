<?php

declare(strict_types=1);

use BinktermPHP\Auth;
use BinktermPHP\ExperienceParticipation;
use BinktermPHP\ExperiencePresence;
use BinktermPHP\ExperiencePresentation;
use BinktermPHP\ExperienceState;
use BinktermPHP\GameCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Deterministic proof of the managed-Experience lifecycle shared by web and
 * terminal Crossroads surfaces.
 *
 * The in-memory launch helper is the only substituted boundary: it represents
 * the external JS-DOS player creating or resuming its managed session row. All
 * lifecycle reads, viewer ownership, presentation actions, termination, and
 * presence transitions use production services and production table shapes.
 */
final class CrossroadsLifecycleIntegrationTest extends TestCase
{
    public function testManagedExperienceLifecycleAcrossWebAndTerminal(): void
    {
        $db = $this->createDatabase();
        $experience = $this->experience();
        $catalog = new CrossroadsLifecycleCatalog([$experience['id'] => $experience]);
        $auth = $this->databaseAuth($db);

        $viewer = $auth->authenticateCredentials('traveler', 'correct horse');
        self::assertIsArray($viewer, 'Production credential authentication must succeed.');
        self::assertSame(10, (int)$viewer['id']);

        $authSessionId = 'auth-viewer';
        $db->prepare("
            INSERT INTO user_sessions (
                session_id, user_id, expires_at, last_activity,
                activity, public_activity, service
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $authSessionId,
            10,
            '2099-01-01 00:00:00',
            gmdate('Y-m-d H:i:s'),
            'BBS',
            null,
            'web',
        ]);

        $modelUser = ['user_id' => 10, 'username' => 'traveler', 'is_admin' => false];
        self::assertArrayHasKey('proof-door', $catalog->getEnabledGames($modelUser, 'web'));
        self::assertArrayHasKey('proof-door', $catalog->getEnabledGames($modelUser, 'terminal'));

        $stateService = new ExperienceState($db, $catalog);
        $initialState = $stateService->getExperienceState('proof-door', $modelUser, 'web');
        self::assertNotNull($initialState);
        self::assertNull(ExperienceParticipation::findViewerPlayer($initialState, 10));

        $initialView = ExperiencePresentation::build($experience, 'web', $initialState, null);
        self::assertSame('play', $initialView['actions']['primary']);
        self::assertTrue($initialView['actions']['play']);
        self::assertFalse($initialView['actions']['return']);

        $sessionId = $this->launchOrReturn($db, 10, 'proof-door', $authSessionId);
        (new ExperiencePresence($auth))->enter($authSessionId, $experience);

        $activeState = $stateService->getExperienceState('proof-door', $modelUser, 'web');
        self::assertNotNull($activeState);
        $viewerPlayer = ExperienceParticipation::findViewerPlayer($activeState, 10);
        self::assertNotNull($viewerPlayer);
        self::assertSame($sessionId, $viewerPlayer['session_id']);
        self::assertSame('Playing Proof Door', $viewerPlayer['presence']);

        // Crossroads arrival semantics: the viewer is in Your Places, while
        // the pre-existing second caller makes the same Experience Live Now.
        self::assertTrue(ExperienceParticipation::hasDistinctOtherPlayer($activeState, 10));
        self::assertSame(2, $activeState['session_count']);
        self::assertSame(2, $activeState['player_count']);

        $activeView = ExperiencePresentation::build(
            $experience,
            'web',
            $activeState,
            $viewerPlayer
        );
        self::assertSame('return', $activeView['actions']['primary']);
        self::assertTrue($activeView['actions']['return']);
        self::assertTrue($activeView['actions']['end_participation']);
        self::assertTrue($activeView['capacity']['at_capacity']);
        self::assertFalse($activeView['viewer']['blocked_by_capacity']);

        // Return must reuse the active session instead of creating another
        // participation row or consuming another capacity slot.
        self::assertSame(
            $sessionId,
            $this->launchOrReturn($db, 10, 'proof-door', $authSessionId)
        );
        self::assertSame(1, $this->activeViewerSessionCount($db));

        // Terminal consumes the same authorized state and action semantics;
        // no interactive telnet daemon is required for this service boundary.
        $terminalState = $stateService->getExperienceState(
            'proof-door',
            $modelUser,
            'terminal'
        );
        self::assertNotNull($terminalState);
        $terminalPlayer = ExperienceParticipation::findViewerPlayer($terminalState, 10);
        self::assertNotNull($terminalPlayer);
        $terminalView = ExperiencePresentation::build(
            $experience,
            'telnet',
            $terminalState,
            $terminalPlayer
        );
        self::assertSame('return', $terminalView['actions']['primary']);

        $participation = new ExperienceParticipation($db);
        self::assertTrue($participation->end($experience, $modelUser, $viewerPlayer));
        (new ExperiencePresence($auth))->leave($authSessionId);

        $endedState = $stateService->getExperienceState('proof-door', $modelUser, 'web');
        self::assertNotNull($endedState);
        self::assertNull(ExperienceParticipation::findViewerPlayer($endedState, 10));
        self::assertSame(1, $endedState['session_count'], 'The other caller remains active.');
        self::assertSame(1, $endedState['player_count']);

        $endedView = ExperiencePresentation::build($experience, 'web', $endedState, null);
        self::assertSame('play', $endedView['actions']['primary']);
        self::assertTrue($endedView['actions']['play']);
        self::assertFalse($endedView['capacity']['at_capacity']);
        self::assertSame(0, $this->activeViewerSessionCount($db));

        $session = $db->query("
            SELECT activity, public_activity
              FROM user_sessions
             WHERE session_id = 'auth-viewer'
        ")->fetch(PDO::FETCH_ASSOC);
        self::assertSame('BBS', $session['activity']);
        self::assertNull($session['public_activity']);
    }

    private function createDatabase(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->sqliteCreateFunction('NOW', static fn(): string => gmdate('Y-m-d H:i:s'));

        $db->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                username TEXT,
                real_name TEXT,
                email TEXT,
                is_admin INTEGER,
                manage_hub_point INTEGER,
                password_hash TEXT,
                created_at TEXT,
                last_login TEXT,
                location TEXT,
                fidonet_address TEXT,
                is_active INTEGER
            );

            CREATE TABLE user_sessions (
                session_id TEXT PRIMARY KEY,
                user_id INTEGER,
                expires_at TEXT,
                last_activity TEXT,
                activity TEXT,
                public_activity TEXT,
                service TEXT
            );

            CREATE TABLE door_sessions (
                session_id TEXT PRIMARY KEY,
                user_id INTEGER,
                door_id TEXT,
                door_type TEXT,
                node_number INTEGER,
                started_at TEXT,
                ended_at TEXT,
                expires_at TEXT,
                auth_session_id TEXT
            );

            CREATE TABLE webdoor_sessions (
                session_id TEXT PRIMARY KEY,
                user_id INTEGER,
                game_id TEXT,
                created_at TEXT,
                ended_at TEXT,
                expires_at TEXT
            );
        ");

        $insertUser = $db->prepare("
            INSERT INTO users (
                id, username, real_name, email, is_admin, manage_hub_point,
                password_hash, created_at, last_login, location,
                fidonet_address, is_active
            ) VALUES (?, ?, ?, ?, 0, 0, ?, ?, NULL, ?, NULL, 1)
        ");
        $insertUser->execute([
            10,
            'traveler',
            'Crossroads Traveler',
            'traveler@example.invalid',
            password_hash('correct horse', PASSWORD_DEFAULT),
            '2026-08-31 00:00:00',
            'Test Junction',
        ]);
        $insertUser->execute([
            20,
            'neighbor',
            'Crossroads Neighbor',
            'neighbor@example.invalid',
            password_hash('battery staple', PASSWORD_DEFAULT),
            '2026-08-31 00:00:00',
            'Test Junction',
        ]);

        $db->exec("
            INSERT INTO door_sessions (
                session_id, user_id, door_id, door_type, node_number,
                started_at, ended_at, expires_at, auth_session_id
            ) VALUES (
                'other-session', 20, 'proof-door', 'jsdos', NULL,
                '2026-08-31 00:00:00', NULL, '2099-01-01 00:00:00', NULL
            )
        ");

        return $db;
    }

    private function databaseAuth(PDO $db): CrossroadsLifecycleAuth
    {
        $reflection = new ReflectionClass(CrossroadsLifecycleAuth::class);
        /** @var CrossroadsLifecycleAuth $auth */
        $auth = $reflection->newInstanceWithoutConstructor();
        $property = new ReflectionProperty(Auth::class, 'db');
        $property->setValue($auth, $db);
        $auth->useDatabase($db);

        return $auth;
    }

    /**
     * Deterministic substitute for the external player/process boundary.
     */
    private function launchOrReturn(
        PDO $db,
        int $userId,
        string $experienceId,
        string $authSessionId
    ): string {
        $existing = $db->prepare("
            SELECT session_id
              FROM door_sessions
             WHERE user_id = ?
               AND door_id = ?
               AND door_type = 'jsdos'
               AND ended_at IS NULL
               AND expires_at > ?
             ORDER BY started_at DESC
             LIMIT 1
        ");
        $existing->execute([$userId, $experienceId, gmdate('Y-m-d H:i:s')]);
        $sessionId = $existing->fetchColumn();

        if (is_string($sessionId) && $sessionId !== '') {
            return $sessionId;
        }

        $sessionId = 'proof-session-' . $userId;
        $insert = $db->prepare("
            INSERT INTO door_sessions (
                session_id, user_id, door_id, door_type, node_number,
                started_at, ended_at, expires_at, auth_session_id
            ) VALUES (?, ?, ?, 'jsdos', NULL, ?, NULL, ?, ?)
        ");
        $insert->execute([
            $sessionId,
            $userId,
            $experienceId,
            gmdate('Y-m-d H:i:s'),
            '2099-01-01 00:00:00',
            $authSessionId,
        ]);

        return $sessionId;
    }

    private function activeViewerSessionCount(PDO $db): int
    {
        return (int)$db->query("
            SELECT COUNT(*)
              FROM door_sessions
             WHERE user_id = 10
               AND door_id = 'proof-door'
               AND ended_at IS NULL
        ")->fetchColumn();
    }

    /** @return array<string,mixed> */
    private function experience(): array
    {
        return [
            'id' => 'proof-door',
            'name' => 'Proof Door',
            'description' => 'A deterministic Crossroads lifecycle fixture.',
            'category' => 'game',
            'backend' => ['type' => 'jsdos', 'id' => 'proof-door'],
            'capabilities' => ['multiplayer' => true],
            'policy' => [
                'enabled' => true,
                'credit_cost' => 0,
            ],
            'capacity' => ['max_sessions' => 2],
            'surfaces' => ['web' => 'full', 'telnet' => 'full'],
        ];
    }
}

final class CrossroadsLifecycleCatalog extends GameCatalog
{
    /** @param array<string,array<string,mixed>> $experiences */
    public function __construct(private array $experiences)
    {
    }

    public function getEnabledGames(?array $user = null, string $surface = 'web'): array
    {
        return $user === null ? [] : $this->experiences;
    }
}

final class CrossroadsLifecycleAuth extends Auth
{
    private PDO $lifecycleDb;

    public function useDatabase(PDO $db): void
    {
        $this->lifecycleDb = $db;
    }

    public function updateSessionActivity(
        string $sessionId,
        string $activity,
        ?string $ipAddress = null
    ): void {
        $stmt = $this->lifecycleDb->prepare("
            UPDATE user_sessions
               SET last_activity = ?, activity = ?
             WHERE session_id = ?
        ");
        $stmt->execute([gmdate('Y-m-d H:i:s'), $activity, $sessionId]);
    }

    public function updateSessionPublicActivity(string $sessionId, ?string $activity): void
    {
        $stmt = $this->lifecycleDb->prepare("
            UPDATE user_sessions
               SET public_activity = ?
             WHERE session_id = ?
        ");
        $stmt->execute([$activity, $sessionId]);
    }
}
