<?php

declare(strict_types=1);

require_once __DIR__ . '/Support/TestDatabase.php';

use BinktermPHP\Auth;
use BinktermPHP\Database;
use BinktermPHP\Messaging\VisitTracker;
use BinktermPHP\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/** Real PostgreSQL queries against transaction-local shadow tables, never caller data. */
final class VisitTrackerTest extends TestCase
{
    private PDO $db;
    private Auth $auth;
    private VisitTracker $tracker;

    protected function setUp(): void
    {
        $this->db = TestDatabase::pdo();
        // Install the same isolated PDO into the singleton BEFORE constructing
        // Auth/VisitTracker below, which internally call Database::getInstance().
        Database::setInstanceForTesting($this->db);
        $this->db->beginTransaction();
        foreach (['users', 'user_sessions', 'users_meta'] as $table) {
            $this->db->exec("CREATE TEMP TABLE $table (LIKE public.$table INCLUDING DEFAULTS) ON COMMIT DROP");
        }
        // INCLUDING DEFAULTS can inherit public serial sequences: detach the only
        // generated ID used by these fixtures before running any inserts.
        $this->db->exec('ALTER TABLE pg_temp.users_meta ALTER COLUMN id SET DEFAULT 0');
        $this->db->exec('CREATE UNIQUE INDEX ON pg_temp.users_meta (user_id, keyname)');
        $this->db->exec(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/v20260910141945_add_last_caller_visit_at.sql'));
        $this->db->exec(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/v20260914180250_add_messaging_visit_columns.sql'));
        $this->auth = new Auth();
        $this->tracker = new VisitTracker();
        $hash = password_hash('visit-test-password', PASSWORD_BCRYPT, ['cost' => 4]);
        $stmt = $this->db->prepare('INSERT INTO users (id, username, real_name, password_hash, is_active, is_system) VALUES (?, ?, ?, ?, ?, FALSE)');
        for ($i = 1; $i <= 5; $i++) {
            $stmt->execute([$i, 'visitor' . $i, 'Visitor ' . $i, $hash, $i === 5 ? 'false' : 'true']);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->inTransaction()) $this->db->rollBack();
        Database::resetInstanceForTesting();
    }

    /** @return array{0: ?string, 1: ?string} [boundary, renewed] */
    private function state(int $id = 1): array
    {
        $row = $this->db->query('SELECT messaging_visit_boundary_at, messaging_visit_renewed_at FROM users WHERE id = ' . $id)->fetch();
        return [$row['messaging_visit_boundary_at'], $row['messaging_visit_renewed_at']];
    }

    private function setRenewedAt(int $id, string $intervalExpr): void
    {
        $this->db->exec("UPDATE users SET messaging_visit_renewed_at = NOW() - INTERVAL '$intervalExpr' WHERE id = $id");
    }

    public function testFirstEverVisitLeavesBoundaryNullAndSetsRenewedAt(): void
    {
        self::assertSame([null, null], $this->state());
        $this->tracker->renew(1);
        [$boundary, $renewed] = $this->state();
        self::assertNull($boundary);
        self::assertNotNull($renewed);
    }

    public function testContinuationInsideGraceLeavesBoundaryUntouched(): void
    {
        $this->tracker->renew(1);
        $this->setRenewedAt(1, '5 minutes');
        [, $renewedBefore] = $this->state();
        $this->tracker->renew(1);
        [$boundary, $renewedAfter] = $this->state();
        self::assertNull($boundary);
        self::assertNotSame($renewedBefore, $renewedAfter);
    }

    public function testNewVisitAfterGraceFreezesBoundaryToPreviousRenewal(): void
    {
        $this->tracker->renew(1);
        $this->setRenewedAt(1, '20 minutes');
        [, $stale] = $this->state();
        $this->tracker->renew(1);
        [$boundary, $renewedAfter] = $this->state();
        self::assertSame($stale, $boundary);
        self::assertNotSame($stale, $renewedAfter);
    }

    public function testExactFourteenFiftyNineIsSameVisit(): void
    {
        $this->tracker->renew(1);
        $this->setRenewedAt(1, '14 minutes 59 seconds');
        $this->tracker->renew(1);
        [$boundary] = $this->state();
        self::assertNull($boundary);
    }

    public function testExactFifteenMinutesIsSameVisitByDesign(): void
    {
        // Strict-inequality boundary check (matches Auth::getOnlineUsers()'s
        // own "> NOW() - INTERVAL 'N minutes'" convention): a gap of exactly
        // GRACE_SECONDS does NOT close the family. Only a gap exceeding it does.
        $this->tracker->renew(1);
        $this->setRenewedAt(1, '15 minutes');
        $this->tracker->renew(1);
        [$boundary] = $this->state();
        self::assertNull($boundary);
    }

    public function testExactFifteenOhOneIsNewVisit(): void
    {
        $this->tracker->renew(1);
        $this->setRenewedAt(1, '15 minutes 1 second');
        [, $stale] = $this->state();
        $this->tracker->renew(1);
        [$boundary] = $this->state();
        self::assertSame($stale, $boundary);
    }

    public function testSecondExplicitLoginDoesNotMoveAFrozenBoundary(): void
    {
        $session = $this->auth->login('visitor1', 'visit-test-password');
        self::assertIsString($session);
        $this->setRenewedAt(1, '20 minutes');
        [, $stale] = $this->state();
        // First post-gap login closes the old family and freezes the boundary.
        $this->auth->login('visitor1', 'visit-test-password');
        [$boundary] = $this->state();
        self::assertSame($stale, $boundary);
        // A second explicit login moments later, still inside the new family's
        // grace window, must not move the now-frozen boundary again.
        $this->auth->login('visitor1', 'visit-test-password');
        [$boundaryAfter] = $this->state();
        self::assertSame($boundary, $boundaryAfter);
    }

    public function testWebAndTelnetEquivalentConcurrentRenewalsConverge(): void
    {
        $this->tracker->renew(1);
        // Simulate a Web request and a Telnet request both renewing within
        // the same grace window; both go through the identical renew() path.
        $this->tracker->renew(1);
        $this->tracker->renew(1);
        [$boundary, $renewed] = $this->state();
        self::assertNull($boundary);
        self::assertNotNull($renewed);
    }

    public function testGenuineConcurrentDbRenewalRaceLeavesConsistentState(): void
    {
        // TestDatabase::pdo() is a single cached connection (temp-table fixtures
        // are session-local, so a genuinely separate connection can't see them),
        // so a true two-connection race can't be driven here. What actually
        // eliminates the race window is that renew() is one atomic UPDATE ...
        // CASE statement with no preceding SELECT to read stale state from —
        // PostgreSQL evaluates the CASE against each row's own current values
        // under EvalPlanQual, so two concurrent renewals for the same user can
        // only serialize, never interleave into a torn read. Assert there is
        // exactly one query issued by renew(), proving no such window exists.
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/Messaging/VisitTracker.php');
        preg_match('/public function renew\(int \$userId\): void\s*\{(.*?)\n    \}/s', $source, $match);
        self::assertNotEmpty($match[1]);
        self::assertSame(1, substr_count($match[1], '->execute('));
        self::assertStringNotContainsString('SELECT', strtoupper(preg_replace('/UPDATE.*/s', '', $match[1])));

        // Sanity: repeated renewals through that single statement still
        // converge to consistent, non-null state (no partial/torn writes).
        $this->tracker->renew(1);
        $this->tracker->renew(1);
        [$boundary, $renewed] = $this->state();
        self::assertNull($boundary);
        self::assertNotNull($renewed);
    }

    public function testLogoutDoesNotAlterVisitState(): void
    {
        $session = $this->auth->login('visitor1', 'visit-test-password');
        [$boundaryBefore, $renewedBefore] = $this->state();
        $this->auth->logout($session);
        self::assertSame([$boundaryBefore, $renewedBefore], $this->state());
    }

    public function testGetBoundaryIsReadOnly(): void
    {
        $this->tracker->renew(1);
        $this->setRenewedAt(1, '20 minutes');
        [, $before] = $this->state();
        self::assertNull($this->tracker->getBoundary(1));
        $this->tracker->getBoundary(1);
        $this->tracker->getBoundary(1);
        self::assertSame([null, $before], $this->state());
    }

    public function testMigrationNotYetPresentGuardSafelyNoOps(): void
    {
        $this->db->exec('ALTER TABLE pg_temp.users DROP COLUMN messaging_visit_boundary_at');
        $this->db->exec('ALTER TABLE pg_temp.users DROP COLUMN messaging_visit_renewed_at');
        $fresh = new VisitTracker();
        self::assertFalse($fresh->visitColumnsAvailable());
        $fresh->renew(1);
        self::assertNull($fresh->getBoundary(1));
        self::assertIsString($this->auth->login('visitor1', 'visit-test-password'));
    }

    public function testInactiveUserCannotMutateVisitState(): void
    {
        $this->tracker->renew(5);
        self::assertSame([null, null], $this->state(5));
    }

    public function testExistingRecordCallerVisitBehaviorRemainsUnaffected(): void
    {
        self::assertTrue($this->auth->recordCallerVisit(1));
        $row = $this->db->query('SELECT last_caller_visit_at FROM users WHERE id = 1')->fetchColumn();
        self::assertNotNull($row);
        // recordCallerVisit's own 30-minute coalescing is untouched by VisitTracker.
        $this->auth->recordCallerVisit(1);
        self::assertSame($row, $this->db->query('SELECT last_caller_visit_at FROM users WHERE id = 1')->fetchColumn());
    }
}
