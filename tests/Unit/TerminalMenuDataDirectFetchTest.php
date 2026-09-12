<?php

declare(strict_types=1);

require_once __DIR__ . '/Support/TestDatabase.php';

use BinktermPHP\Auth;
use BinktermPHP\Crossroads\TerminalDashboardSignal;
use BinktermPHP\Database;
use BinktermPHP\DashboardStatsService;
use BinktermPHP\EchoareaManager;
use BinktermPHP\Terminal\TerminalMenuData;
use BinktermPHP\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * D-LAT-2 semantic-equivalence cover: the terminal Echomail area picker and the
 * main-menu badge counts stopped round-tripping `GET /api/echoareas` and
 * `GET /api/dashboard/stats` (out to the public site URL / Cloudflare) and now
 * call the same shared services the routes delegate to
 * ({@see EchoareaManager::listForUser()}, {@see DashboardStatsService},
 * {@see TerminalDashboardSignal}).
 *
 * These pin that the network-free paths return exactly what the routes would
 * have — same echoareas, same filtering/visibility, same stats keys — wrapped
 * in the {@see \BinktermPHP\TelnetServer\TelnetUtils::apiRequest()} envelope so
 * the call sites are untouched. Read-only against the live database.
 */
final class TerminalMenuDataDirectFetchTest extends TestCase
{
    private const UID = 3; // Skrawl — a long-standing non-admin-agnostic account

    private static ?\PDO $db = null;
    private static ?array $user = null;

    public static function setUpBeforeClass(): void
    {
        try {
            self::$db = TestDatabase::pdo();
        } catch (\Throwable $e) {
            self::$db = null;

            return;
        }
        // Install the same isolated PDO into the singleton BEFORE any test
        // constructs EchoareaManager/TerminalMenuData/etc below, which
        // internally call Database::getInstance().
        Database::setInstanceForTesting(self::$db);

        $stmt = self::$db->prepare(
            'SELECT id AS user_id, username, real_name, is_admin FROM users WHERE id = ?'
        );
        $stmt->execute([self::UID]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::$user = $row ?: null;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            Database::resetInstanceForTesting();
        }
    }

    protected function setUp(): void
    {
        if (self::$db === null || self::$user === null) {
            self::markTestSkipped('database / test user not available');
        }
    }

    /** A TerminalMenuData whose session resolution is a fixed fake. */
    private function menuData(?array $user): TerminalMenuData
    {
        $auth = new class($user) extends Auth {
            private ?array $user;
            public function __construct(?array $user)
            {
                parent::__construct();
                $this->user = $user;
            }
            public function validateSession($sessionId, ?string $ipAddress = null)
            {
                return $sessionId === 'valid' ? $this->user : false;
            }
        };

        return new TerminalMenuData($auth);
    }

    // ---- EchoareaManager::listForUser() ------------------------------------

    public function testListForUserReturnsEnrichedEchoareaRows(): void
    {
        $areas = (new EchoareaManager())->listForUser(self::$user, ['filter' => 'active']);

        self::assertNotEmpty($areas, 'test DB should have at least one active echoarea');
        foreach ($areas as $a) {
            self::assertArrayHasKey('tag', $a);
            self::assertArrayHasKey('description', $a);
            self::assertArrayHasKey('message_count', $a);
            self::assertArrayHasKey('unread_count', $a);
            self::assertArrayHasKey('subscribed', $a);
            self::assertArrayHasKey('effective_posting_name_policy', $a);
            self::assertContains($a['effective_posting_name_policy'], ['real_name', 'username']);
            self::assertArrayHasKey('lovlynet_metadata', $a);
            self::assertArrayHasKey('interest_ids', $a);
            self::assertTrue((bool)$a['is_active']);
        }
    }

    public function testSubscribedOnlyIsASubsetAndEveryRowIsSubscribed(): void
    {
        $mgr = new EchoareaManager();
        $all = $mgr->listForUser(self::$user, ['filter' => 'active', 'subscribed_only' => false]);
        $sub = $mgr->listForUser(self::$user, ['filter' => 'active', 'subscribed_only' => true]);

        $allTags = array_column($all, 'tag');
        self::assertLessThanOrEqual(count($all), count($sub));
        foreach ($sub as $row) {
            self::assertContains($row['tag'], $allTags);
            self::assertNotEmpty($row['subscribed'], 'subscribed_only row must be an active subscription');
        }
    }

    public function testNonAdminNeverSeesSysopOnlyAreas(): void
    {
        $nonAdmin = ['user_id' => self::UID, 'username' => 'probe', 'real_name' => 'Probe', 'is_admin' => false];
        $areas = (new EchoareaManager())->listForUser($nonAdmin, ['filter' => 'all']);
        foreach ($areas as $a) {
            self::assertEmpty($a['is_sysop_only'] ?? false, 'sysop-only area leaked to a non-admin');
        }
    }

    // ---- TerminalMenuData::echoareas() -----------------------------------

    public function testEchoareasEnvelopeMatchesListForUser(): void
    {
        $envelope = $this->menuData(self::$user)->echoareas('valid', false, 'active');

        self::assertSame(200, $envelope['status']);
        self::assertNull($envelope['error']);
        self::assertArrayHasKey('echoareas', $envelope['data']);

        $direct = (new EchoareaManager())->listForUser(self::$user, ['filter' => 'active', 'subscribed_only' => false]);
        self::assertSame(
            array_column($direct, 'tag'),
            array_column($envelope['data']['echoareas'], 'tag')
        );
    }

    public function testEchoareasUnauthenticatedSessionReturns401(): void
    {
        $envelope = $this->menuData(self::$user)->echoareas('bogus', true);

        self::assertSame(401, $envelope['status']);
        self::assertSame([], $envelope['data']);
        self::assertSame('unauthenticated', $envelope['error']);
    }

    // ---- TerminalMenuData::dashboardStats() ----------------------------

    public function testDashboardStatsEnvelopeCarriesTheServiceKeysPlusCrossroads(): void
    {
        $envelope = $this->menuData(self::$user)->dashboardStats('valid');

        self::assertSame(200, $envelope['status']);
        self::assertNull($envelope['error']);

        $data = $envelope['data'];
        foreach (['unread_netmail', 'new_echomail', 'online_count', 'unread_bulletins', 'total_netmail'] as $key) {
            self::assertArrayHasKey($key, $data);
        }
        // The route appends this key unconditionally (null or an array).
        self::assertArrayHasKey('crossroads', $data);
        self::assertTrue($data['crossroads'] === null || is_array($data['crossroads']));

        // Same reduction the route now calls.
        self::assertSame(
            TerminalDashboardSignal::compose(self::$user),
            $data['crossroads']
        );
    }

    public function testDashboardStatsUnauthenticatedSessionReturns401(): void
    {
        $envelope = $this->menuData(self::$user)->dashboardStats('bogus');

        self::assertSame(401, $envelope['status']);
        self::assertSame([], $envelope['data']);
        self::assertSame('unauthenticated', $envelope['error']);
    }

    // ---- call sites no longer touch the two self-HTTP endpoints ----------

    public function testTerminalCallSitesNoLongerInvokeTheSelfHttpEndpoints(): void
    {
        $echomail = file_get_contents(__DIR__ . '/../../telnet/src/EchomailHandler.php');
        $mailUtils = file_get_contents(__DIR__ . '/../../telnet/src/MailUtils.php');

        self::assertStringNotContainsString("apiRequest(\$this->apiBase, 'GET', '/api/echoareas", $echomail);
        self::assertStringNotContainsString("'/api/dashboard/stats'", $mailUtils);
        // The direct-service seam is present.
        self::assertStringContainsString('TerminalMenuData', $echomail);
        self::assertStringContainsString('TerminalMenuData', $mailUtils);
    }

    public function testEchoareasRouteDelegatesToTheSharedService(): void
    {
        $routes = file_get_contents(__DIR__ . '/../../routes/api-routes.php');
        self::assertStringContainsString('->listForUser($user, [', $routes);
        self::assertStringContainsString('TerminalDashboardSignal::compose($user)', $routes);
    }
}
