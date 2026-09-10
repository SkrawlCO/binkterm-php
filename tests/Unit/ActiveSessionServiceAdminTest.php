<?php

declare(strict_types=1);

use BinktermPHP\Database;
use BinktermPHP\DoorBridgeControlClient;
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

    private function seedDoorSession(
        string $authSessionId,
        ?string $wsToken = null,
        ?int $dosboxPid = null
    ): string {
        $dsid = 'doorb_' . bin2hex(random_bytes(12));
        $stmt = $this->pdo->prepare(
            "INSERT INTO door_sessions (session_id, expires_at, auth_session_id, ended_at, ws_token, dosbox_pid)
             VALUES (?, NOW() + INTERVAL '1 hour', ?, NULL, ?, ?)"
        );
        $stmt->execute([$dsid, $authSessionId, $wsToken, $dosboxPid]);
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

    private function svc(
        ?DoorSessionManager $doors = null,
        ?DoorBridgeControlClient $bridge = null
    ): ActiveSessionService {
        return new ActiveSessionService($this->pdo, null, $doors, $bridge);
    }

    /**
     * Recording fake for {@see DoorBridgeControlClient} — mirrors the anonymous
     * fake-DoorSessionManager pattern used elsewhere in this suite. Never opens
     * a socket. $trace (when given) receives an ordered marker so a test can
     * assert bridge-termination happens before endSession() cleanup.
     */
    private function fakeBridge(array $response = ['success' => true], ?\ArrayObject $trace = null): DoorBridgeControlClient
    {
        return new class ($response, $trace) extends DoorBridgeControlClient {
            /** @var list<array{session_id:string,ws_token:string}> */
            public array $calls = [];
            private array $response;
            private ?\ArrayObject $trace;
            public function __construct(array $response, ?\ArrayObject $trace)
            {
                $this->response = $response;
                $this->trace = $trace;
            }
            public function terminate(string $sessionId, string $wsToken): array
            {
                $this->calls[] = ['session_id' => $sessionId, 'ws_token' => $wsToken];
                if ($this->trace !== null) {
                    $this->trace->append('bridge:' . $sessionId);
                }
                return $this->response;
            }
        };
    }

    /**
     * Recording fake for {@see DoorSessionManager::endSession()}. $trace (when
     * given) receives an ordered marker.
     */
    private function fakeDoors(?\ArrayObject $trace = null, bool $throw = false): DoorSessionManager
    {
        return new class ($trace, $throw) extends DoorSessionManager {
            /** @var list<array{session_id:string,confirmed:bool}> */
            public array $ended = [];
            private ?\ArrayObject $trace;
            private bool $throw;
            public function __construct(?\ArrayObject $trace, bool $throw)
            {
                $this->trace = $trace;
                $this->throw = $throw;
            }
            public function endSession(string $sessionId, bool $runtimeTerminationConfirmed = false): bool
            {
                $this->ended[] = ['session_id' => $sessionId, 'confirmed' => $runtimeTerminationConfirmed];
                if ($this->trace !== null) {
                    $this->trace->append('endSession:' . $sessionId);
                }
                if ($this->throw) {
                    throw new \RuntimeException('endSession blew up');
                }
                return true;
            }
        };
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

    // ---- Acceptance C: forced door-runtime termination via the bridge -----

    public function testCascadeAsksBridgeToTerminateTheManagedRuntimeWithExactIdentity(): void
    {
        $sid = $this->newSession($this->userA, 'telnet');
        $dsid = $this->seedDoorSession($sid, 'wstok-lord-abc', 1270249);
        // A sibling door session with no managed runtime must not reach the bridge.
        $this->seedDoorSession('kickb_unrelated_auth', null, null);

        $bridge = $this->fakeBridge(['success' => true]);
        $doors = $this->fakeDoors();

        self::assertTrue(
            $this->svc($doors, $bridge)->revokeSession($sid, $this->userA, ActiveSessionService::CODE_ADMIN_REVOKED)
        );

        self::assertSame(
            [['session_id' => $dsid, 'ws_token' => 'wstok-lord-abc']],
            $bridge->calls,
            'exactly the attached managed door session is terminated through the bridge'
        );
    }

    public function testEndSessionCleanupStillRunsAndIsToldTheRuntimeWasConfirmed(): void
    {
        $sid = $this->newSession($this->userA);
        $dsid = $this->seedDoorSession($sid, 'wstok-1', 999001);

        $bridge = $this->fakeBridge(['success' => true]);
        $doors = $this->fakeDoors();

        self::assertTrue($this->svc($doors, $bridge)->revokeSession($sid, $this->userA));

        self::assertSame(
            [['session_id' => $dsid, 'confirmed' => true]],
            $doors->ended,
            'endSession still runs and is told the bridge already confirmed the kill'
        );
    }

    public function testBridgeTerminationHappensBeforeEndSessionCleanup(): void
    {
        $sid = $this->newSession($this->userA);
        $dsid = $this->seedDoorSession($sid, 'wstok-order', 999002);

        $trace = new \ArrayObject();
        $bridge = $this->fakeBridge(['success' => true], $trace);
        $doors = $this->fakeDoors($trace);

        self::assertTrue($this->svc($doors, $bridge)->revokeSession($sid, $this->userA));

        self::assertSame(
            ['bridge:' . $dsid, 'endSession:' . $dsid],
            $trace->getArrayCopy(),
            'the bridge kill must precede endSession(), which would otherwise stamp ended_at and void authorization'
        );
    }

    public function testBridgeFailureDoesNotBlockRevocationKickOrCleanup(): void
    {
        $sid = $this->newSession($this->userA, 'telnet');
        $dsid = $this->seedDoorSession($sid, 'wstok-fail', 999003);

        $bridge = $this->fakeBridge(['success' => false, 'error' => 'Door bridge control socket is unavailable']);
        $doors = $this->fakeDoors();

        self::assertTrue(
            $this->svc($doors, $bridge)->revokeSession($sid, $this->userA, ActiveSessionService::CODE_ADMIN_REVOKED),
            'the auth session is revoked even when the bridge cannot be reached'
        );

        // auth session gone
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM user_sessions WHERE session_id = ?');
        $stmt->execute([$sid]);
        self::assertSame(0, (int) $stmt->fetchColumn());

        // kick still emitted
        $events = $this->kickEvents($this->userA);
        self::assertCount(1, $events);
        self::assertSame('admin_revoked', json_decode($events[0]['payload'], true)['code']);

        // cleanup still attempted, and NOT falsely told the runtime was confirmed
        self::assertSame(
            [['session_id' => $dsid, 'confirmed' => false]],
            $doors->ended,
            'endSession still runs, but is not told the bridge confirmed a kill it did not confirm'
        );
    }

    public function testBridgeExceptionIsContainedAndRevocationStillCompletes(): void
    {
        $sid = $this->newSession($this->userA);
        $dsid = $this->seedDoorSession($sid, 'wstok-throw', 999004);

        $bridge = new class extends DoorBridgeControlClient {
            public function __construct()
            {
            }
            public function terminate(string $sessionId, string $wsToken): array
            {
                throw new \RuntimeException('socket exploded');
            }
        };

        self::assertTrue($this->svc($this->fakeDoors(), $bridge)->revokeSession($sid, $this->userA));

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM user_sessions WHERE session_id = ?');
        $stmt->execute([$sid]);
        self::assertSame(0, (int) $stmt->fetchColumn());
        self::assertCount(1, $this->kickEvents($this->userA));
    }

    public function testCascadeWithoutManagedRuntimeNeverContactsTheBridge(): void
    {
        $sid = $this->newSession($this->userA);
        // Caller-owned door session (e.g. Telnet line relay): ws_token present
        // but no recorded runtime pid -> bridge must not be called.
        $dsid = $this->seedDoorSession($sid, 'wstok-linerelay', null);

        $bridge = $this->fakeBridge(['success' => true]);
        $doors = $this->fakeDoors();

        self::assertTrue($this->svc($doors, $bridge)->revokeSession($sid, $this->userA));

        self::assertSame([], $bridge->calls, 'no managed runtime -> no bridge control request');
        self::assertSame(
            [['session_id' => $dsid, 'confirmed' => false]],
            $doors->ended
        );
    }

    public function testSiblingAndUnrelatedDoorSessionsAreUntouched(): void
    {
        $target = $this->newSession($this->userA, 'telnet');
        $other = $this->newSession($this->userA, 'web');

        $targetDoor = $this->seedDoorSession($target, 'wstok-target', 999005);
        $siblingDoor = $this->seedDoorSession($other, 'wstok-sibling', 999006);
        $unrelatedDoor = $this->seedDoorSession('kickb_no_such_auth', 'wstok-unrelated', 999007);

        $bridge = $this->fakeBridge(['success' => true]);
        $doors = $this->fakeDoors();

        self::assertTrue(
            $this->svc($doors, $bridge)->revokeSession($target, $this->userA, ActiveSessionService::CODE_ADMIN_REVOKED)
        );

        self::assertSame(
            [['session_id' => $targetDoor, 'ws_token' => 'wstok-target']],
            $bridge->calls,
            'only the door session attached to the revoked auth session is terminated'
        );
        self::assertSame(
            [$targetDoor],
            array_column($doors->ended, 'session_id'),
            'endSession is called only for the attached door session; sibling and unrelated rows are never selected'
        );
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
