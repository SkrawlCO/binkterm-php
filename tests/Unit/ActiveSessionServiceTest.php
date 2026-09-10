<?php

declare(strict_types=1);

use BinktermPHP\Database;
use BinktermPHP\DoorSessionManager;
use BinktermPHP\Realtime\BinkStream;
use BinktermPHP\Security\ActiveSessionService;
use PHPUnit\Framework\TestCase;

/**
 * ActiveSessionService — Slice A.
 *
 * DB-backed; every test seeds inside a transaction that is always rolled back,
 * and the suite skips when no database is configured (matching
 * FileSearchServiceTest / UnifiedNewscanServiceTest).
 */
final class ActiveSessionServiceTest extends TestCase
{
    private \PDO $pdo;
    private int $userA;
    private int $userB;

    protected function setUp(): void
    {
        try {
            $this->pdo = Database::getInstance()->getPdo();
            $this->pdo->query('SELECT 1');
        } catch (\Throwable $e) {
            try {
                $this->pdo = Database::reconnect()->getPdo();
                $this->pdo->query('SELECT 1');
            } catch (\Throwable $e2) {
                self::markTestSkipped('database not available: ' . $e2->getMessage());
            }
        }

        $this->pdo->beginTransaction();
        $this->userA = $this->newUser();
        $this->userB = $this->newUser();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function newUser(): int
    {
        $s = strtolower(bin2hex(random_bytes(6)));
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (username, password_hash, real_name, is_active)
             VALUES (?, ?, ?, TRUE) RETURNING id'
        );
        $stmt->execute(["kick_$s", password_hash('x', PASSWORD_DEFAULT), "Kick Test $s"]);
        return (int) $stmt->fetch(\PDO::FETCH_ASSOC)['id'];
    }

    private function newSession(int $userId, string $service = 'telnet'): string
    {
        $sid = 'kicktest_' . bin2hex(random_bytes(16));
        $stmt = $this->pdo->prepare(
            "INSERT INTO user_sessions (session_id, user_id, expires_at, service, last_activity)
             VALUES (?, ?, NOW() + INTERVAL '1 hour', ?, NOW())"
        );
        $stmt->execute([$sid, $userId, $service]);
        return $sid;
    }

    private function seedDoorSession(string $authSessionId): string
    {
        $dsid = 'doortest_' . bin2hex(random_bytes(12));
        $stmt = $this->pdo->prepare(
            "INSERT INTO door_sessions (session_id, expires_at, auth_session_id, ended_at)
             VALUES (?, NOW() + INTERVAL '1 hour', ?, NULL)"
        );
        $stmt->execute([$dsid, $authSessionId]);
        return $dsid;
    }

    /** session.kick rows emitted during this test, in id order. */
    private function kickEvents(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT payload::text AS payload, user_id
             FROM sse_events
             WHERE event_type = 'session.kick' AND user_id = ?
             ORDER BY id ASC"
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function sessionCount(int $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM user_sessions WHERE user_id = ?');
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    private function service(?DoorSessionManager $doors = null): ActiveSessionService
    {
        return new ActiveSessionService($this->pdo, null, $doors);
    }

    public function testOwnerCanRevokeOwnSession(): void
    {
        $sid = $this->newSession($this->userA);
        self::assertTrue($this->service()->revokeSession($sid, $this->userA));
        self::assertSame(0, $this->sessionCount($this->userA));
    }

    public function testCannotRevokeAnotherUsersSessionViaSelfService(): void
    {
        $sid = $this->newSession($this->userB);
        self::assertFalse($this->service()->revokeSession($sid, $this->userA));
        self::assertSame(1, $this->sessionCount($this->userB), 'other user session untouched');
        self::assertSame([], $this->kickEvents($this->userB), 'refused revoke emits no kick');
    }

    public function testUnknownSessionRevokesNothingAndEmitsNothing(): void
    {
        self::assertFalse($this->service()->revokeSession('kicktest_does_not_exist', $this->userA));
        self::assertSame([], $this->kickEvents($this->userA));
    }

    public function testSuccessfulRevokeRemovesTheRowAndEmitsOneTargetedKick(): void
    {
        $sid = $this->newSession($this->userA);

        self::assertTrue($this->service()->revokeSession($sid, $this->userA));

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM user_sessions WHERE session_id = ?');
        $stmt->execute([$sid]);
        self::assertSame(0, (int) $stmt->fetchColumn());

        $events = $this->kickEvents($this->userA);
        self::assertCount(1, $events);
        self::assertSame((string) $this->userA, (string) $events[0]['user_id'], 'targeted at the owner');

        $payload = json_decode($events[0]['payload'], true);
        self::assertSame($sid, $payload['session_id']);
        self::assertSame('revoked', $payload['code']);
    }

    public function testAdminOverridePathRevokesWithoutOwnershipCheck(): void
    {
        $sid = $this->newSession($this->userB);
        self::assertTrue($this->service()->revokeSession($sid, null, ActiveSessionService::CODE_REVOKED));

        $events = $this->kickEvents($this->userB);
        self::assertCount(1, $events);
        self::assertSame((string) $this->userB, (string) $events[0]['user_id']);
    }

    public function testAssociatedDoorSessionIsEndedThroughDoorSessionManager(): void
    {
        $sid = $this->newSession($this->userA);
        $dsid = $this->seedDoorSession($sid);
        $this->seedDoorSession('kicktest_unrelated_auth_session');

        $fakeDoors = new class extends DoorSessionManager {
            /** @var string[] */
            public array $ended = [];
            public function __construct()
            {
            }
            public function endSession(string $sessionId, bool $runtimeTerminationConfirmed = false): bool
            {
                $this->ended[] = $sessionId;
                return true;
            }
        };

        self::assertTrue($this->service($fakeDoors)->revokeSession($sid, $this->userA));
        self::assertSame([$dsid], $fakeDoors->ended, 'only the revoked auth session\'s door session is ended');
    }

    public function testDoorCleanupFailureDoesNotBlockRevokeOrSuppressTheKick(): void
    {
        $sid = $this->newSession($this->userA);
        $this->seedDoorSession($sid);

        $throwingDoors = new class extends DoorSessionManager {
            public function __construct()
            {
            }
            public function endSession(string $sessionId, bool $runtimeTerminationConfirmed = false): bool
            {
                throw new \RuntimeException('door bridge unreachable');
            }
        };

        self::assertTrue($this->service($throwingDoors)->revokeSession($sid, $this->userA));

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM user_sessions WHERE session_id = ?');
        $stmt->execute([$sid]);
        self::assertSame(0, (int) $stmt->fetchColumn(), 'session still removed');
        self::assertCount(1, $this->kickEvents($this->userA), 'kick still emitted');
    }

    public function testRevokeAllAffectsOnlyTheAuthenticatedUserAndEmitsPerSession(): void
    {
        $a1 = $this->newSession($this->userA, 'web');
        $a2 = $this->newSession($this->userA, 'telnet');
        $this->newSession($this->userB, 'web');

        self::assertSame(2, $this->service()->revokeAllForUser($this->userA));
        self::assertSame(0, $this->sessionCount($this->userA));
        self::assertSame(1, $this->sessionCount($this->userB), 'other user untouched');

        $events = $this->kickEvents($this->userA);
        self::assertCount(2, $events);
        $seen = [];
        foreach ($events as $e) {
            self::assertSame((string) $this->userA, (string) $e['user_id']);
            $p = json_decode($e['payload'], true);
            self::assertSame('revoked_all', $p['code']);
            $seen[] = $p['session_id'];
        }
        sort($seen);
        $expected = [$a1, $a2];
        sort($expected);
        self::assertSame($expected, $seen);
        self::assertSame([], $this->kickEvents($this->userB));
    }

    public function testEmitPayloadNeverCarriesArbitraryText(): void
    {
        $sid = $this->newSession($this->userA);
        // Even if a caller passes a bogus code, the payload code is normalised.
        self::assertTrue($this->service()->revokeSession($sid, $this->userA, 'arbitrary attacker text'));
        $payload = json_decode($this->kickEvents($this->userA)[0]['payload'], true);
        $keys = array_keys($payload);
        sort($keys);
        self::assertSame(['code', 'session_id'], $keys, 'payload carries only session_id + a normalised code');
        self::assertSame('revoked', $payload['code']);
    }
}
