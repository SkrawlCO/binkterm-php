<?php

declare(strict_types=1);

use BinktermPHP\Auth;
use BinktermPHP\GameCatalog;
use BinktermPHP\LeasedWebDoorStorage;
use BinktermPHP\WebDoorController;
use PHPUnit\Framework\TestCase;

/** Real PostgreSQL tests. Requires an explicit disposable database, never app DB defaults. */
final class TathamProgressTest extends TestCase
{
    private PDO $db;

    public static function connection(): PDO
    {
        $dsn = getenv('TATHAM_TEST_DSN');
        if (!$dsn || !str_contains($dsn, 'dbname=tatham_slice1')) {
            throw new RuntimeException('Explicit disposable tatham_slice1 database required');
        }
        return new PDO($dsn, 'postgres', 'slice1-test-only', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    protected function setUp(): void
    {
        $this->db = self::connection();
        self::assertSame('tatham_slice1', $this->db->query('SELECT current_database()')->fetchColumn());
        $this->db->exec('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY)');
        $this->db->exec('INSERT INTO users VALUES (1), (2) ON CONFLICT DO NOTHING');
        $this->db->exec("CREATE TABLE IF NOT EXISTS webdoor_storage (
            id SERIAL PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            game_id VARCHAR(64) NOT NULL, slot INTEGER NOT NULL DEFAULT 0,
            data JSONB NOT NULL DEFAULT '{}', metadata JSONB NOT NULL DEFAULT '{}',
            saved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE(user_id, game_id, slot))");
        $this->db->exec('TRUNCATE webdoor_storage');
        $_GET = [];
        unset($_SERVER['HTTP_REFERER']);
    }

    protected function tearDown(): void
    {
        $_GET = [];
        unset($_SERVER['HTTP_REFERER']);
        http_response_code(200);
    }

    private function raw(): array
    {
        return $this->db->query('SELECT * FROM webdoor_storage ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function testOpaquePayloadCallerIsolationAndRevision(): void
    {
        $a = new LeasedWebDoorStorage($this->db, 1);
        $b = new LeasedWebDoorStorage($this->db, 2);
        self::assertNull($a->read());
        $lease = $a->acquire();
        self::assertTrue($lease['success']);
        self::assertSame(0, $lease['revision']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $lease['owner_token']);
        self::assertNull($b->read());
        $payload = "SAVEFILE:41:Simon Tatham's Portable Puzzle Collection\nVERSION :1:1\nGAME    :8:Light Up\nPARAMS  :10:7x7b20s4d0\nCPARAMS :10:7x7b20s4d0\nDESC    :17:cBd0c1hBe2h1c0d0c\nNSTATES :1:2\nSTATEPOS:1:2\nMOVE    :4:L0,0\n";
        $data = ['payload' => $payload, 'transport_text' => "quotes \" apostrophe ' backslash \\ slash /\r\n\t", 'nested' => ['n' => null, 'b' => false, 'f' => 1.0, 'list' => [1, '1']]];
        $written = $a->write($lease['owner_token'], $lease['attempt_id'], 0, $data);
        self::assertTrue($written['success']);
        self::assertSame(1, $written['revision']);
        self::assertEquals($data, $a->read()['data']);
        self::assertSame($payload, $a->read()['data']['payload']);
        self::assertSame(hash('sha256', $payload), hash('sha256', $a->read()['data']['payload']));
        self::assertSame($data['transport_text'], $a->read()['data']['transport_text']);
        self::assertSame(1.0, $a->read()['data']['nested']['f']);
        self::assertArrayNotHasKey('owner_token', $a->read());
        self::assertStringNotContainsString($lease['owner_token'], json_encode($this->raw()));
        $before = $this->raw();
        self::assertFalse($b->write($lease['owner_token'], $lease['attempt_id'], 1, [])['success']);
        self::assertSame($before, $this->raw());
        self::assertFalse($a->write($lease['owner_token'], $lease['attempt_id'], 0, [])['success']);
        self::assertSame($before, $this->raw());
        self::assertFalse($a->write($lease['owner_token'], 'wrong-attempt', 1, [])['success']);
        self::assertSame($before, $this->raw());
        $bLease = $b->acquire();
        self::assertTrue($bLease['success']);
        self::assertNotSame($lease['attempt_id'], $bLease['attempt_id']);
        self::assertSame($payload, $a->read()['data']['payload']);
    }

    public function testExpiryRenewalAndFormerOwnerCannotAffectSuccessor(): void
    {
        $store = new LeasedWebDoorStorage($this->db, 1);
        $a = $store->acquire();
        $before = $this->raw();
        self::assertFalse($store->acquire()['success']);
        self::assertSame($before, $this->raw());
        self::assertTrue($store->renew($a['owner_token'])['success']);
        self::assertSame(0, $store->read()['revision']);
        $this->db->exec("UPDATE webdoor_storage SET metadata = jsonb_set(metadata, '{lease_expires_at}', to_jsonb(EXTRACT(EPOCH FROM clock_timestamp()) - 1))");
        $expired = $this->raw();
        self::assertFalse($store->renew($a['owner_token'])['success']);
        self::assertFalse($store->write($a['owner_token'], $a['attempt_id'], 0, [])['success']);
        self::assertSame($expired, $this->raw());
        $b = $store->acquire();
        self::assertTrue($b['success']);
        self::assertNotSame($a['owner_token'], $b['owner_token']);
        self::assertSame($a['attempt_id'], $b['attempt_id']);
        $successor = $this->raw();
        self::assertFalse($store->release($a['owner_token'])['success']);
        self::assertFalse($store->write($a['owner_token'], $a['attempt_id'], 0, [])['success']);
        self::assertSame($successor, $this->raw());
        self::assertTrue($store->write($b['owner_token'], $b['attempt_id'], 0, ['payload' => 'successor'])['success']);
        self::assertTrue($store->release($b['owner_token'])['success']);
        self::assertTrue($store->acquire()['success']);
        self::assertSame(1, $store->read()['revision']);
    }

    /** Force two processes to wait before racing first-row INSERT, not sequential calls. */
    public function testConcurrentFirstAcquisitionHasExactlyOneWinner(): void
    {
        $this->db->beginTransaction();
        $this->db->exec('LOCK TABLE webdoor_storage IN ACCESS EXCLUSIVE MODE');
        $jobs = [];
        try {
            foreach ([1, 2] as $n) {
                $jobs[] = $this->worker('acquire');
            }
            $deadline = microtime(true) + 5;
            do {
                // PostgreSQL caches statistics within this lock-holding transaction.
                $this->db->query('SELECT pg_stat_clear_snapshot()');
                $waiting = (int)$this->db->query("SELECT count(*) FROM pg_stat_activity WHERE datname = current_database() AND application_name = 'tatham-acquire-test' AND wait_event_type = 'Lock'")->fetchColumn();
                if ($waiting === 2) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            self::assertSame(2, $waiting, 'Both writers must be blocked concurrently');
        } finally {
            $this->db->commit();
        }
        $results = array_map(fn (array $job): array => $this->finish($job), $jobs);
        self::assertCount(1, array_filter($results, fn ($r) => $r['success']));
        self::assertCount(1, array_filter($results, fn ($r) => !$r['success']));
        self::assertCount(1, $this->raw());
        self::assertSame(0, (new LeasedWebDoorStorage($this->db, 1))->read()['revision']);
    }

    public function testGenericControllerAndSdkCannotBypassReservedKey(): void
    {
        $store = new LeasedWebDoorStorage($this->db, 1);
        $lease = $store->acquire();
        $before = $this->raw();
        $auth = $this->createMock(Auth::class);
        $auth->method('getCurrentUser')->willReturn(['user_id' => 1, 'id' => 1]);
        $controller = new WebDoorController($this->db, $auth, $this->createMock(GameCatalog::class));
        $_GET['game_id'] = 'tatham';
        foreach (['saveGame', 'deleteSave'] as $method) {
            $r = $controller->$method(0);
            self::assertFalse($r['success']);
            self::assertSame('errors.webdoor.game_unavailable', $r['error_code']);
            self::assertSame(409, http_response_code());
        }
        unset($_GET['game_id']);
        $_SERVER['HTTP_REFERER'] = 'https://example.test/webdoors/tatham/index.html';
        self::assertFalse($controller->saveGame(0)['success']);
        self::assertFalse($controller->deleteSave(0)['success']);
        $sdk = $this->finish($this->worker('sdk', 'tatham'));
        self::assertSame(409, $sdk['status']);
        self::assertSame('errors.webdoor.game_unavailable', $sdk['body']['error_code']);
        self::assertSame($before, $this->raw());
        $_GET['game_id'] = 'ordinary';
        self::assertTrue($controller->saveGame(0)['success']);
        self::assertSame([], $controller->loadSave(0)['data']);
        $sdk = $this->finish($this->worker('sdk', 'ordinary'));
        self::assertTrue($sdk['success']);
        self::assertSame(['payload' => "ordinary\n\\\""], $controller->loadSave(0)['data']);
        self::assertTrue($controller->deleteSave(0)['success']);
        self::assertNull($controller->loadSave(0));
        self::assertSame($before, $this->raw());
    }

    public function testBreakLockNamespaceIsReservedAndIsolated(): void
    {
        $tatham = new LeasedWebDoorStorage($this->db, 1);
        $breaklock = new LeasedWebDoorStorage($this->db, 1, 'breaklock');
        $a = $tatham->acquire(); $b = $breaklock->acquire();
        self::assertTrue($a['success']); self::assertTrue($b['success']);
        self::assertTrue($breaklock->write($b['owner_token'], $b['attempt_id'], 0, ['snapshot' => 'breaklock'])['success']);
        self::assertSame([], $tatham->read()['data']);
        self::assertFalse($breaklock->write($a['owner_token'], $b['attempt_id'], 1, [])['success']);
        self::assertTrue(LeasedWebDoorStorage::isReserved('tatham'));
        self::assertTrue(LeasedWebDoorStorage::isReserved('breaklock'));
        self::assertFalse(LeasedWebDoorStorage::isReserved('ordinary'));
        $auth = $this->createMock(Auth::class);
        $auth->method('getCurrentUser')->willReturn(['user_id' => 1, 'id' => 1]);
        $controller = new WebDoorController($this->db, $auth, $this->createMock(GameCatalog::class));
        $_GET['game_id'] = 'breaklock';
        self::assertFalse($controller->saveGame(0)['success']);
        self::assertFalse($controller->deleteSave(0)['success']);
        self::assertSame(409, $this->finish($this->worker('sdk', 'breaklock'))['status']);
        self::assertSame(['snapshot' => 'breaklock'], $breaklock->read()['data']);
        self::assertSame([0, 0], array_map('intval', array_column($this->raw(), 'slot')));
        $this->expectException(InvalidArgumentException::class);
        new LeasedWebDoorStorage($this->db, 1, 'ordinary');
    }

    public function testOrdinaryPuzzlesIsolationAndReservedProtection(): void
    {
        $ordinary = new LeasedWebDoorStorage($this->db, 1, 'ordinary-puzzles');
        $lease = $ordinary->acquire();
        self::assertTrue($lease['success']);
        self::assertTrue($ordinary->write($lease['owner_token'], $lease['attempt_id'], 0, ['puzzleId' => 'e9c2882a25e2'])['success']);
        foreach (['tatham', 'breaklock'] as $namespace) {
            $other = new LeasedWebDoorStorage($this->db, 1, $namespace);
            self::assertNull($other->read());
            $otherLease = $other->acquire();
            self::assertFalse($other->write($lease['owner_token'], $otherLease['attempt_id'], 0, [])['success']);
            self::assertFalse($ordinary->write($otherLease['owner_token'], $lease['attempt_id'], 1, [])['success']);
            self::assertSame([], $other->read()['data']);
        }
        self::assertNull((new LeasedWebDoorStorage($this->db, 2, 'ordinary-puzzles'))->read());
        self::assertTrue(LeasedWebDoorStorage::isReserved('ordinary-puzzles'));
        self::assertSame(409, $this->finish($this->worker('sdk', 'ordinary-puzzles'))['status']);
        $auth = $this->createMock(Auth::class);
        $auth->method('getCurrentUser')->willReturn(['user_id' => 1, 'id' => 1]);
        $controller = new WebDoorController($this->db, $auth, $this->createMock(GameCatalog::class));
        $_GET['game_id'] = 'ordinary-puzzles';
        self::assertFalse($controller->saveGame(0)['success']);
        self::assertFalse($controller->deleteSave(0)['success']);
        self::assertSame(['puzzleId' => 'e9c2882a25e2'], $ordinary->read()['data']);
    }

    private function worker(string $mode, string $key = ''): array
    {
        $root = dirname(__DIR__, 2);
        $code = 'require ' . var_export($root . '/vendor/autoload.php', true) . '; require ' . var_export(__FILE__, true) . '; TathamProgressTest::runWorker(' . var_export($mode, true) . ', ' . var_export($key, true) . ');';
        $process = proc_open([PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        return [$process, $pipes];
    }

    private function finish(array $job): array
    {
        [$process, $pipes] = $job;
        stream_set_timeout($pipes[1], 8);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stderr);
        return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    }

    public static function runWorker(string $mode, string $key): void
    {
        $db = self::connection();
        $db->exec("SET statement_timeout = '8s'");
        if ($mode === 'acquire') {
            $db->exec("SET application_name = 'tatham-acquire-test'");
            echo json_encode((new LeasedWebDoorStorage($db, 1))->acquire());
            return;
        }
        // Execute the actual SDK storageSave body with isolated auth/DB/response
        // collaborators; avoid SDK bootstrap opening the configured production DB.
        $source = file_get_contents(dirname(__DIR__, 2) . '/public_html/webdoors/_doorsdk/php/helpers.php');
        $start = strpos($source, 'function storageSave(');
        $end = strpos($source, '/**', $start);
        $body = substr($source, $start, $end - $start);
        eval('namespace WebDoorSDK; function getCurrentUser(){return ["id"=>1];} function getDatabase(){return \\TathamProgressTest::connection();} function jsonResponse($body,$status=200){throw new \\RuntimeException(json_encode(["body"=>$body,"status"=>$status]));} ' . $body);
        try {
            \WebDoorSDK\storageSave($key, ['payload' => "ordinary\n\\\""]);
            echo json_encode(['success' => true]);
        } catch (RuntimeException $error) {
            echo $error->getMessage();
        }
    }
}
