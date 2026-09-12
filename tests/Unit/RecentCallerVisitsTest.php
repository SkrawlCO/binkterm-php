<?php

declare(strict_types=1);

require_once __DIR__ . '/Support/TestDatabase.php';

use BinktermPHP\Auth;
use BinktermPHP\Database;
use BinktermPHP\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/** Real PostgreSQL queries against transaction-local shadow tables, never caller data. */
final class RecentCallerVisitsTest extends TestCase
{
    private PDO $db;
    private Auth $auth;

    protected function setUp(): void
    {
        $this->db = TestDatabase::pdo();
        // Install the same isolated PDO into the singleton BEFORE constructing
        // Auth below, which internally calls Database::getInstance().
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
        $this->auth = new Auth();
        $hash = password_hash('caller-test-password', PASSWORD_BCRYPT, ['cost' => 4]);
        $stmt = $this->db->prepare('INSERT INTO users (id, username, real_name, password_hash, is_active, is_system) VALUES (?, ?, ?, ?, TRUE, FALSE)');
        for ($i = 1; $i <= 10; $i++) {
            $stmt->execute([$i, 'caller' . $i, 'Caller ' . $i, $hash]);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->inTransaction()) $this->db->rollBack();
        Database::resetInstanceForTesting();
    }

    private function visit(int $id = 1): mixed
    {
        return $this->db->query('SELECT last_caller_visit_at FROM users WHERE id = ' . $id)->fetchColumn();
    }

    public function testNormalWebTelnetAndSshLoginsRecordArrival(): void
    {
        foreach (['web', 'telnet', 'ssh'] as $i => $service) {
            $session = $this->auth->login('caller' . ($i + 1), 'caller-test-password', $service);
            self::assertIsString($session);
            self::assertNotNull($this->visit($i + 1));
        }
    }

    public function testCredentialsAloneAndDebugLowLevelCreationDoNotRecord(): void
    {
        self::assertIsArray($this->auth->authenticateCredentials('caller1', 'caller-test-password'));
        $session = $this->auth->createSessionForConnection(1, 'telnet');
        self::assertIsArray($this->auth->validateSession($session));
        self::assertNull($this->visit());
        self::assertSame([], $this->auth->getRecentCallerVisits());
    }

    public function testValidationAndBackgroundActivityCannotRefreshDurableArrival(): void
    {
        $session = $this->auth->login('caller1', 'caller-test-password');
        $this->db->exec("UPDATE users SET last_caller_visit_at = NOW() - INTERVAL '2 days' WHERE id = 1");
        $before = $this->visit();
        $this->auth->validateSession($session);
        $this->auth->updateSessionActivity($session, 'background refresh');
        self::assertSame($before, $this->visit());
        self::assertTrue($this->auth->getRecentCallerVisits()[0]['is_online']);
    }

    public function testTrustedReturnRecorderUpdatesOldArrivalAndCoalescesWrites(): void
    {
        $this->db->exec("UPDATE users SET last_caller_visit_at = NOW() - INTERVAL '2 days' WHERE id = 1");
        $before = $this->visit();
        self::assertTrue($this->auth->recordCallerVisit(1));
        self::assertNotSame($before, $this->visit());
        $this->db->exec("UPDATE users SET last_caller_visit_at = NOW() - INTERVAL '5 minutes' WHERE id = 1");
        $recent = $this->visit();
        $this->auth->recordCallerVisit(1);
        self::assertSame($recent, $this->visit());
    }

    public function testLogoutSurvivesAndOnlineComesOnlyFromUnexpiredRecentSessions(): void
    {
        $session = $this->auth->login('caller1', 'caller-test-password');
        self::assertTrue($this->auth->getRecentCallerVisits()[0]['is_online']);
        $before = $this->visit();
        $this->auth->logout($session);
        self::assertSame($before, $this->visit());
        $rows = $this->auth->getRecentCallerVisits();
        self::assertCount(1, $rows);
        self::assertFalse($rows[0]['is_online']);
        $session = $this->auth->createSessionForConnection(1, 'ssh');
        $this->db->exec("UPDATE user_sessions SET expires_at = NOW() - INTERVAL '1 minute'");
        self::assertFalse($this->auth->getRecentCallerVisits()[0]['is_online']);
        $this->auth->cleanExpiredSessions();
        self::assertCount(1, $this->auth->getRecentCallerVisits());
    }

    public function testBoundWindowUniquePeopleAndPublicFieldAllowlist(): void
    {
        $this->db->exec("UPDATE users SET last_caller_visit_at = NOW() - id * INTERVAL '1 hour'");
        $this->auth->createSessionForConnection(1, 'web', '192.0.2.123', 'private-agent');
        $this->auth->createSessionForConnection(1, 'ssh');
        $rows = $this->auth->getRecentCallerVisits(999);
        self::assertCount(6, $rows);
        self::assertSame('caller1', $rows[0]['username']);
        self::assertSame(['username', 'last_caller_visit_at', 'is_online'], array_keys($rows[0]));
        self::assertCount(2, $this->auth->getRecentCallerVisits(2));
        $this->db->exec("UPDATE users SET is_system = TRUE WHERE id = 1");
        $this->db->exec("UPDATE users SET is_active = FALSE WHERE id = 2");
        $this->db->exec("UPDATE users SET last_caller_visit_at = NOW() - INTERVAL '8 days' WHERE id >= 3");
        self::assertSame([], $this->auth->getRecentCallerVisits());
    }

    public function testPendingMigrationDoesNotBreakLoginOrPresentation(): void
    {
        $this->db->exec('ALTER TABLE pg_temp.users DROP COLUMN last_caller_visit_at');
        self::assertIsString($this->auth->login('caller1', 'caller-test-password'));
        self::assertFalse($this->auth->recordCallerVisit(1));
        self::assertSame([], $this->auth->getRecentCallerVisits());
    }

    public function testReturnRouteUsesAuthenticatedIdentityAndStandardCsrfGuard(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/routes/api-routes.php');
        preg_match("~SimpleRouter::post\\('/caller-visit', function\\(\\) \\{(.*?)\\n    \\}\\);~s", $source, $match);
        self::assertNotEmpty($match[1]);
        self::assertStringContainsString('RouteHelper::requireAuth()', $match[1]);
        self::assertStringContainsString("\$user['user_id']", $match[1]);
        self::assertStringNotContainsString('requireAdmin', $match[1]);
        self::assertStringNotContainsString('php://input', $match[1]);
    }
}
