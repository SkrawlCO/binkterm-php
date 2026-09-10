<?php

declare(strict_types=1);

use BinktermPHP\Database;
use BinktermPHP\DoorSessionManager;
use BinktermPHP\Security\ActiveSessionService;
use PHPUnit\Framework\TestCase;

/**
 * ActiveSessionService — Slice B (admin listing, opaque references, admin kick).
 *
 * DB-backed; every test seeds inside a transaction that is always rolled back,
 * and skips when no database is configured.
 */
final class ActiveSessionServiceAdminTest extends TestCase
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
        $stmt->execute(["kickb_$s", password_hash('x', PASSWORD_DEFAULT), "Kick B $s"]);
        return (int) $stmt->fetch(\PDO::FETCH_ASSOC)['id'];
    }

    private function newSession(int $userId, string $service = 'telnet', string $when = 'NOW()'): string
    {
        $sid = 'kickb_' . bin2hex(random_bytes(16));
        $stmt = $this->pdo->prepare(
            "INSERT INTO user_sessions (session_id, user_id, expires_at, service, created_at, last_activity, activity)
             VALUES (?, ?, NOW() + INTERVAL '1 hour', ?, $when, $when, 'testing')"
        );
        $stmt->execute([$sid, $userId, $service]);
        return $sid;
    }

    private function expiredSession(int $userId): string
    {
        $sid = 'kickb_exp_' . bin2hex(random_bytes(12));
        $stmt = $this->pdo->prepare(
            "INSERT INTO user_sessions (session_id, user_id, expires_at, service, created_at, last_activity)
             VALUES (?, ?, NOW() - INTERVAL '1 minute', 'web', NOW() - INTERVAL '2 hours', NOW() - INTERVAL '2 hours')"
        );
        $stmt->execute([$sid, $userId]);
        return $sid;
    }

    private function seedDoorSession(string $authSessionId): string
    {
        $dsid = 'doorb_' . bin2hex(random_bytes(12));
        $stmt = $this->pdo->prepare(
            "INSERT INTO door_sessions (session_id, expires_at, auth_session_id, ended_at)
             VALUES (?, NOW() + INTERVAL '1 hour', ?, NULL)"
        );
        $stmt->execute([$dsid, $authSessionId]);
        return $dsid;
    }

    private function kickEvents(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT payload::text AS payload, user_id FROM sse_events
             WHERE event_type = 'session.kick' AND user_id = ? ORDER BY id ASC"
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function svc(?DoorSessionManager $doors = null): ActiveSessionService
    {
        return new ActiveSessionService($this->pdo, null, $doors);
    }

    // ---- listActive -----------------------------------------------------

    public function testListActiveReturnsOnlyTheSafeFieldSetAndNeverTheSessionId(): void
    {
        $this->newSession($this->userA, 'web');
        $rows = $this->svc()->listActive($this->userA);

        self::assertNotEmpty($rows);
        $keys = array_keys($rows[0]);
        sort($keys);
        self::assertSame(
            ['activity', 'created_at', 'ip_address', 'is_online', 'last_activity', 'ref', 'service', 'user_id', 'username'],
            $keys
        );
        self::assertArrayNotHasKey('session_id', $rows[0]);
        self::assertArrayNotHasKey('id', $rows[0]);
        self::assertArrayNotHasKey('public_activity', $rows[0]);
        self::assertArrayNotHasKey('expires_at', $rows[0]);
        self::assertArrayNotHasKey('user_agent', $rows[0]);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $rows[0]['ref']);
    }

    public function testListActiveScopesToOneUser(): void
    {
        $this->newSession($this->userA);
        $this->newSession($this->userB);

        $forA = $this->svc()->listActive($this->userA);
        self::assertCount(1, $forA);
        self::assertSame($this->userA, $forA[0]['user_id']);

        $all = $this->svc()->listActive(null);
        self::assertGreaterThanOrEqual(2, count($all));
    }

    public function testListActiveExcludesExpiredButKeepsIdleUnexpired(): void
    {
        $this->expiredSession($this->userA);
        $this->newSession($this->userA, 'telnet', "NOW() - INTERVAL '40 minutes'"); // idle but not expired

        $rows = $this->svc()->listActive($this->userA);
        self::assertCount(1, $rows, 'expired excluded, idle-but-live kept');
        self::assertFalse($rows[0]['is_online'], 'idle 40m -> not "online"');
    }

    // ---- opaque references --------------------------------------------

    public function testSessionRefIsDeterministicOneWayAndDistinct(): void
    {
        $sid = $this->newSession($this->userA);
        $r1 = ActiveSessionService::sessionRef($sid);
        $r2 = ActiveSessionService::sessionRef($sid);
        self::assertSame($r1, $r2);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $r1);
        self::assertStringNotContainsString(substr($sid, 0, 8), $r1, 'ref does not embed the token');
        self::assertNotSame($r1, ActiveSessionService::sessionRef($sid . 'x'));
    }

    public function testRefsDistinguishSiblingSessionsOfOneUser(): void
    {
        $s1 = $this->newSession($this->userA, 'web');
        $s2 = $this->newSession($this->userA, 'telnet');
        $rows = $this->svc()->listActive($this->userA);
        self::assertCount(2, $rows);
        self::assertNotSame($rows[0]['ref'], $rows[1]['ref']);

        self::assertSame($s1, $this->svc()->resolveSessionRef($this->userA, ActiveSessionService::sessionRef($s1)));
        self::assertSame($s2, $this->svc()->resolveSessionRef($this->userA, ActiveSessionService::sessionRef($s2)));
    }

    public function testRefFromOneUserCannotResolveAgainstAnother(): void
    {
        $sidB = $this->newSession($this->userB);
        $refB = ActiveSessionService::sessionRef($sidB);
        $this->newSession($this->userA);

        self::assertNull($this->svc()->resolveSessionRef($this->userA, $refB), 'B\'s ref does not match any of A\'s sessions');
    }

    public function testInvalidRefShapeFailsClosed(): void
    {
        $this->newSession($this->userA);
        foreach (['', 'nothex', 'ABCDEF', str_repeat('a', 15), str_repeat('a', 17), '../etc'] as $bad) {
            self::assertNull($this->svc()->resolveSessionRef($this->userA, $bad));
        }
    }

    public function testUnknownButWellFormedRefFailsClosed(): void
    {
        $this->newSession($this->userA);
        self::assertNull($this->svc()->resolveSessionRef($this->userA, str_repeat('0', 16)));
    }

    // ---- admin kick --------------------------------------------------

    public function testAdminKickViaResolvedRefDeletesExactlyOneSessionAndEmitsAdminRevoked(): void
    {
        $target = $this->newSession($this->userA, 'telnet');
        $sibling = $this->newSession($this->userA, 'web');

        $ref = ActiveSessionService::sessionRef($target);
        $sid = $this->svc()->resolveSessionRef($this->userA, $ref);
        self::assertSame($target, $sid);

        self::assertTrue($this->svc()->revokeSession($sid, $this->userA, ActiveSessionService::CODE_ADMIN_REVOKED));

        $stmt = $this->pdo->prepare('SELECT session_id FROM user_sessions WHERE user_id = ?');
        $stmt->execute([$this->userA]);
        self::assertSame([$sibling], $stmt->fetchAll(\PDO::FETCH_COLUMN), 'only the target was removed');

        $events = $this->kickEvents($this->userA);
        self::assertCount(1, $events);
        self::assertSame((string) $this->userA, (string) $events[0]['user_id']);
        $payload = json_decode($events[0]['payload'], true);
        self::assertSame($target, $payload['session_id']);
        self::assertSame('admin_revoked', $payload['code']);
    }

    public function testAdminKickOwnershipAssertionRejectsAWrongTargetUser(): void
    {
        $sidB = $this->newSession($this->userB);
        // Simulate a route that resolved the wrong user id but the right session.
        self::assertFalse($this->svc()->revokeSession($sidB, $this->userA, ActiveSessionService::CODE_ADMIN_REVOKED));

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM user_sessions WHERE session_id = ?');
        $stmt->execute([$sidB]);
        self::assertSame(1, (int) $stmt->fetchColumn(), 'session untouched');
        self::assertSame([], $this->kickEvents($this->userB), 'no event on a refused admin revoke');
    }

    public function testAdminKickOfNonexistentSessionFailsSafely(): void
    {
        self::assertFalse($this->svc()->revokeSession('kickb_missing', $this->userA, ActiveSessionService::CODE_ADMIN_REVOKED));
        self::assertSame([], $this->kickEvents($this->userA));
    }

    public function testAdminKickCascadesTheAttachedDoorSession(): void
    {
        $sid = $this->newSession($this->userA);
        $dsid = $this->seedDoorSession($sid);
        $this->seedDoorSession('kickb_unrelated');

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

        self::assertTrue($this->svc($fakeDoors)->revokeSession($sid, $this->userA, ActiveSessionService::CODE_ADMIN_REVOKED));
        self::assertSame([$dsid], $fakeDoors->ended);
    }

    public function testAdminRevokeAllScopesToTargetUserAndUsesAdminCode(): void
    {
        $a1 = $this->newSession($this->userA, 'web');
        $a2 = $this->newSession($this->userA, 'telnet');
        $this->newSession($this->userB, 'web');

        self::assertSame(2, $this->svc()->revokeAllForUser($this->userA, ActiveSessionService::CODE_ADMIN_REVOKED));

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM user_sessions WHERE user_id = ?');
        $stmt->execute([$this->userA]);
        self::assertSame(0, (int) $stmt->fetchColumn());
        $stmt->execute([$this->userB]);
        self::assertSame(1, (int) $stmt->fetchColumn(), 'other user untouched');

        $events = $this->kickEvents($this->userA);
        self::assertCount(2, $events);
        foreach ($events as $e) {
            self::assertSame('admin_revoked', json_decode($e['payload'], true)['code']);
        }
        self::assertSame([], $this->kickEvents($this->userB));
    }

    public function testAdminCodeIsAcceptedByNormalization(): void
    {
        // A bogus code still collapses to 'revoked' (Slice A behaviour preserved).
        $sid = $this->newSession($this->userA);
        self::assertTrue($this->svc()->revokeSession($sid, $this->userA, 'totally made up'));
        self::assertSame('revoked', json_decode($this->kickEvents($this->userA)[0]['payload'], true)['code']);
    }
}
