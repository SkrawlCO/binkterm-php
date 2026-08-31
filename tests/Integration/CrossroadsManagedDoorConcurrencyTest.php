<?php

declare(strict_types=1);

use BinktermPHP\Config;
use BinktermPHP\DoorSessionManager;
use PHPUnit\Framework\TestCase;

/**
 * HTTP-level proof of managed-door capacity under genuinely overlapping launches.
 */
final class CrossroadsManagedDoorConcurrencyTest extends TestCase
{
    /** @var array<int,resource> */
    private array $servers = [];
    private ?PDO $adminDb = null;
    private ?PDO $observerDb = null;
    private string $schema = '';
    private string $fixtureRoot = '';
    /** @var string[] */
    private array $serverLogs = [];

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            if (is_resource($server)) {
                proc_terminate($server);
                proc_close($server);
            }
        }

        if ($this->adminDb !== null) {
            if ($this->adminDb->inTransaction()) {
                $this->adminDb->rollBack();
            }
            // Drop any admission advisory lock still held from a failed run.
            $this->adminDb->exec('SELECT pg_advisory_unlock_all()');
            if ($this->schema !== '') {
                $this->adminDb->exec('DROP SCHEMA IF EXISTS ' . $this->schema . ' CASCADE');
            }
        }
        $this->adminDb = null;
        $this->observerDb = null;

        foreach ($this->serverLogs as $log) {
            if (is_file($log)) {
                unlink($log);
            }
        }
        if ($this->fixtureRoot !== '' && is_dir($this->fixtureRoot)) {
            $this->removeFixtureTree($this->fixtureRoot);
        }
    }

    public function testConcurrentLaunchesCannotExceedOneSlotCapacity(): void
    {
        $this->createIsolatedApplication();
        $baseUrls = [$this->startServer(), $this->startServer()];

        $first = $this->login($baseUrls[0], 'capacity-one');
        $second = $this->login($baseUrls[1], 'capacity-two');

        // Hold the production admission mutex itself. startSession() takes
        // pg_advisory_xact_lock(ADMISSION_LOCK_KEY) as the first statement of its
        // node-allocation transaction; a session-level pg_advisory_lock() on the
        // same key parks every concurrent launch at that exact boundary, before
        // any of them has evaluated per-door capacity or allocated a node.
        $this->adminDb->query(
            'SELECT pg_advisory_lock(' . DoorSessionManager::ADMISSION_LOCK_KEY . ')'
        )->closeCursor();
        $lockerPid = (int)$this->adminDb->query('SELECT pg_backend_pid()')->fetchColumn();

        $gate = $this->fixtureRoot . '/launch-gate';
        $workers = [
            $this->launchWorker($baseUrls[0], $first, $gate),
            $this->launchWorker($baseUrls[1], $second, $gate),
        ];
        touch($gate);

        $blockedNodeAllocators = $this->waitForBlockedNodeAllocators(2, $lockerPid);
        $releaseAt = microtime(true);
        $this->adminDb->query(
            'SELECT pg_advisory_unlock(' . DoorSessionManager::ADMISSION_LOCK_KEY . ')'
        )->closeCursor();

        $responses = [
            $this->finishWorker($workers[0]),
            $this->finishWorker($workers[1]),
        ];
        $rows = $this->activeProofDoorRows();

        $evidence = json_encode([
            'blocked_node_allocators' => $blockedNodeAllocators,
            'released_at' => $releaseAt,
            'responses' => $responses,
            'active_rows' => $rows,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        self::assertCount(2, $blockedNodeAllocators, $evidence);
        foreach ($blockedNodeAllocators as $blocked) {
            self::assertSame('advisory', (string)$blocked['wait_event'], $evidence);
            self::assertMatchesRegularExpression(
                '/pg_advisory_xact_lock/i',
                (string)$blocked['query'],
                $evidence
            );
        }
        self::assertLessThanOrEqual(1, count($rows), $evidence);

        $successful = array_values(array_filter(
            $responses,
            static fn(array $response): bool => $response['status'] === 200
                && ($response['json']['success'] ?? false) === true
        ));
        self::assertCount(1, $successful, $evidence);

        $rejected = array_values(array_filter(
            $responses,
            static fn(array $response): bool => $response['status'] === 503
                && ($response['json']['error_code'] ?? '') === 'errors.door.capacity_reached_detail'
        ));
        self::assertCount(1, $rejected, $evidence);
        self::assertCount(1, array_unique(array_column($rows, 'user_id')), $evidence);
        self::assertCount(1, array_unique(array_column($rows, 'node_number')), $evidence);

        $admitted = $successful[0];
        $admittedSession = (string)$admitted['json']['session']['session_id'];
        $admittedUser = (int)$rows[0]['user_id'];
        $admittedAuth = $admittedUser === 1 ? $first : $second;
        $rejectedAuth = $admittedUser === 1 ? $second : $first;
        $rejectedBaseUrl = $admittedUser === 1 ? $baseUrls[1] : $baseUrls[0];
        $admittedBaseUrl = $admittedUser === 1 ? $baseUrls[0] : $baseUrls[1];

        // Return must reuse the admitted session even while capacity is full.
        $returned = $this->request(
            $admittedBaseUrl,
            'POST',
            '/api/door/launch',
            ['door' => 'proof-native', 'surface' => 'web'],
            $admittedAuth['cookie'],
            $admittedAuth['csrf']
        );
        self::assertSame(200, $returned['status'], $returned['body']);
        self::assertSame($admittedSession, $returned['json']['session']['session_id']);
        self::assertCount(1, $this->activeProofDoorRows());

        $ended = $this->request(
            $admittedBaseUrl,
            'POST',
            '/api/experiences/proof-native/end',
            [],
            $admittedAuth['cookie'],
            $admittedAuth['csrf']
        );
        self::assertSame(200, $ended['status'], $ended['body']);
        self::assertCount(0, $this->activeProofDoorRows());

        $later = $this->request(
            $rejectedBaseUrl,
            'POST',
            '/api/door/launch',
            ['door' => 'proof-native', 'surface' => 'web'],
            $rejectedAuth['cookie'],
            $rejectedAuth['csrf']
        );
        self::assertSame(200, $later['status'], $later['body']);
        self::assertCount(1, $this->activeProofDoorRows());
    }

    /** @return array{cookie:string,csrf:string} */
    private function login(string $baseUrl, string $username): array
    {
        $response = $this->request($baseUrl, 'POST', '/api/auth/login', [
            'username' => $username,
            'password' => 'capacity password',
            'remember' => false,
        ]);
        self::assertSame(200, $response['status'], $response['body']);
        $csrf = (string)($response['json']['csrf_token'] ?? '');
        self::assertNotSame('', $csrf);

        foreach ($response['headers'] as $header) {
            if (preg_match('/^Set-Cookie:\s*(binktermphp_session=[^;]+)/i', $header, $match)) {
                return ['cookie' => $match[1], 'csrf' => $csrf];
            }
        }

        self::fail('Login response did not set a session cookie.');
    }

    /**
     * @param array{cookie:string,csrf:string} $auth
     * @return array{process:resource,pipes:array<int,resource>}
     */
    private function launchWorker(string $baseUrl, array $auth, string $gate): array
    {
        $worker = $this->fixtureRoot . '/launch-worker.php';
        if (!is_file($worker)) {
            file_put_contents($worker, <<<'PHP'
<?php
$gate = $argv[1];
while (!is_file($gate)) {
    usleep(1000);
}
$started = microtime(true);
$context = stream_context_create(['http' => [
    'method' => 'POST',
    'ignore_errors' => true,
    'timeout' => 15,
    'header' => implode("\r\n", [
        'Accept: application/json',
        'Content-Type: application/x-www-form-urlencoded',
        'Cookie: ' . $argv[3],
        'X-CSRF-TOKEN: ' . $argv[4],
    ]),
    'content' => http_build_query(['door' => 'proof-native', 'surface' => 'web']),
]]);
$body = file_get_contents($argv[2] . '/api/door/launch', false, $context);
$headers = $http_response_header ?? [];
preg_match('/\s(\d{3})\s/', (string)($headers[0] ?? ''), $match);
echo json_encode([
    'status' => (int)($match[1] ?? 0),
    'json' => json_decode((string)$body, true),
    'body' => (string)$body,
    'started_at' => $started,
    'finished_at' => microtime(true),
], JSON_THROW_ON_ERROR);
PHP
            );
        }

        $process = proc_open(
            [PHP_BINARY, $worker, $gate, $baseUrl, $auth['cookie'], $auth['csrf']],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2)
        );
        self::assertIsResource($process);
        fclose($pipes[0]);

        return ['process' => $process, 'pipes' => $pipes];
    }

    /** @param array{process:resource,pipes:array<int,resource>} $worker */
    private function finishWorker(array $worker): array
    {
        $stdout = stream_get_contents($worker['pipes'][1]);
        $stderr = stream_get_contents($worker['pipes'][2]);
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        $exitCode = proc_close($worker['process']);
        self::assertSame(0, $exitCode, (string)$stderr);

        return json_decode((string)$stdout, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<int,array<string,mixed>> */
    private function waitForBlockedNodeAllocators(int $expected, int $lockerPid): array
    {
        $deadline = microtime(true) + 10.0;
        do {
            $blocked = $this->blockedNodeAllocators($lockerPid);
            if (count($blocked) >= $expected) {
                return $blocked;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        return $blocked ?? [];
    }

    /**
     * Backends parked in `pg_advisory_xact_lock(ADMISSION_LOCK_KEY)` at the top
     * of the production node-allocation transaction, whose wait chain is
     * transitively rooted at the test's advisory-lock holder ($lockerPid).
     *
     * A match means the launch request has entered startSession()/
     * findAvailableNode() and is waiting on the admission mutex, having done no
     * capacity check and no node allocation yet. Two simultaneous matches prove
     * the requests genuinely overlapped at the admission boundary.
     *
     * pg_blocking_pids() is not transitive: the first waiter blocks directly on
     * the holder, the second queues behind the first, so a closure walk is
     * needed to attribute both to the same root.
     *
     * Observation runs on a dedicated autocommit connection ($observerDb), not
     * on the lock-holder ($adminDb): with stats_fetch_consistency = cache (the
     * PostgreSQL default), pg_stat_activity is snapshotted at the first read
     * inside a transaction and frozen for the rest of it, so polling from a
     * long-lived transaction would never see backends that connect after the
     * first poll. pg_stat_clear_snapshot() is belt-and-braces for the same
     * reason.
     *
     * @return array<int,array<string,mixed>>
     */
    private function blockedNodeAllocators(int $lockerPid): array
    {
        $this->observerDb->query('SELECT pg_stat_clear_snapshot()')->closeCursor();
        $stmt = $this->observerDb->query("
            SELECT pid,
                   wait_event_type,
                   wait_event,
                   query,
                   array_to_string(pg_blocking_pids(pid), ',') AS blocked_by
              FROM pg_stat_activity
             WHERE datname = current_database()
               AND pid <> pg_backend_pid()
               AND state = 'active'
               AND wait_event_type = 'Lock'
        ");

        $waiters = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $blockedBy = array_values(array_filter(array_map(
                'intval',
                $row['blocked_by'] === '' || $row['blocked_by'] === null
                    ? []
                    : explode(',', (string)$row['blocked_by'])
            )));
            $row['blocked_by'] = $blockedBy;
            $waiters[(int)$row['pid']] = $row;
        }

        // Transitive closure of "is blocked by" rooted at the advisory-lock holder.
        $rooted = [$lockerPid => true];
        do {
            $added = false;
            foreach ($waiters as $pid => $row) {
                if (isset($rooted[$pid])) {
                    continue;
                }
                foreach ($row['blocked_by'] as $blocker) {
                    if (isset($rooted[$blocker])) {
                        $rooted[$pid] = true;
                        $added = true;
                        break;
                    }
                }
            }
        } while ($added);

        $blocked = [];
        foreach ($waiters as $pid => $row) {
            if (!isset($rooted[$pid])) {
                continue;
            }
            if (preg_match('/pg_advisory_xact_lock/i', (string)$row['query']) !== 1) {
                continue;
            }
            $blocked[$pid] = $row;
        }
        ksort($blocked);

        return array_values($blocked);
    }

    /** @return array<int,array<string,mixed>> */
    private function activeProofDoorRows(): array
    {
        $stmt = $this->adminDb->query("
            SELECT session_id, user_id, node_number, auth_session_id,
                   started_at, expires_at, ended_at
              FROM door_sessions
             WHERE door_id = 'proof-native'
               AND ended_at IS NULL
               AND expires_at > NOW()
             ORDER BY session_id
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function createIsolatedApplication(): void
    {
        $config = Config::getDatabaseConfig();
        self::assertSame('pgsql', $config['driver']);
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $config['host'],
            $config['port'],
            $config['database']
        );
        $this->adminDb = new PDO(
            $dsn,
            $config['username'],
            $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        // Separate autocommit connection used only to observe pg_stat_activity
        // while $adminDb holds its long node-lock transaction. See
        // blockedNodeAllocators() for why this cannot share $adminDb.
        $this->observerDb = new PDO(
            $dsn,
            $config['username'],
            $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $this->schema = 'managed_capacity_' . bin2hex(random_bytes(6));
        $this->adminDb->exec('CREATE SCHEMA ' . $this->schema);
        $this->adminDb->exec('SET search_path TO ' . $this->schema);
        $this->observerDb->exec('SET search_path TO ' . $this->schema);
        $this->createSchema();
        $this->createFixtureRoot();
    }

    private function createSchema(): void
    {
        $this->adminDb->exec("
            CREATE TABLE users (
                id BIGSERIAL PRIMARY KEY,
                username VARCHAR(50) UNIQUE NOT NULL,
                real_name VARCHAR(100) UNIQUE NOT NULL,
                email VARCHAR(255),
                password_hash VARCHAR(255) NOT NULL,
                is_admin BOOLEAN NOT NULL DEFAULT FALSE,
                manage_hub_point BOOLEAN NOT NULL DEFAULT FALSE,
                is_active BOOLEAN NOT NULL DEFAULT TRUE,
                is_system BOOLEAN NOT NULL DEFAULT FALSE,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                last_login TIMESTAMPTZ,
                location VARCHAR(255),
                about_me TEXT,
                fidonet_address VARCHAR(64)
            );
            CREATE TABLE user_sessions (
                session_id VARCHAR(128) PRIMARY KEY,
                user_id BIGINT NOT NULL REFERENCES users(id),
                expires_at TIMESTAMPTZ NOT NULL,
                ip_address VARCHAR(64), user_agent TEXT,
                last_activity TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                activity VARCHAR(255), public_activity VARCHAR(255), service VARCHAR(20)
            );
            CREATE TABLE users_meta (
                user_id BIGINT NOT NULL REFERENCES users(id),
                keyname VARCHAR(255) NOT NULL, valname TEXT,
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                PRIMARY KEY (user_id, keyname)
            );
            CREATE TABLE dosbox_doors (
                id BIGSERIAL PRIMARY KEY,
                door_id VARCHAR(100) UNIQUE NOT NULL,
                name VARCHAR(100) NOT NULL, description TEXT,
                executable VARCHAR(255) NOT NULL, path VARCHAR(255) NOT NULL,
                config JSONB, enabled BOOLEAN NOT NULL DEFAULT TRUE,
                door_type VARCHAR(20), updated_at TIMESTAMPTZ DEFAULT NOW()
            );
            CREATE TABLE rlogin_doors (
                id BIGSERIAL PRIMARY KEY,
                door_id VARCHAR(100) UNIQUE NOT NULL
            );
            CREATE TABLE door_sessions (
                id BIGSERIAL PRIMARY KEY,
                session_id VARCHAR(128) UNIQUE NOT NULL,
                user_id BIGINT NOT NULL REFERENCES users(id),
                door_id VARCHAR(100) NOT NULL,
                node_number INTEGER NOT NULL,
                tcp_port INTEGER, ws_port INTEGER NOT NULL,
                ws_token VARCHAR(128), dosbox_pid INTEGER, bridge_pid INTEGER,
                session_path VARCHAR(255), user_data JSONB, door_type VARCHAR(20),
                auth_session_id VARCHAR(128),
                started_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                ended_at TIMESTAMPTZ, expires_at TIMESTAMPTZ NOT NULL,
                exit_status VARCHAR(50)
            );
            CREATE TABLE door_session_logs (
                id BIGSERIAL PRIMARY KEY,
                session_id VARCHAR(128) REFERENCES door_sessions(session_id),
                event_type VARCHAR(50) NOT NULL, event_data JSONB,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            );
            CREATE TABLE user_activity_log (
                id BIGSERIAL PRIMARY KEY, user_id BIGINT REFERENCES users(id),
                activity_type_id INTEGER NOT NULL, object_id BIGINT,
                object_name VARCHAR(255), meta JSONB,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            );
            CREATE TABLE chat_rooms (
                id BIGSERIAL PRIMARY KEY, name VARCHAR(255) UNIQUE NOT NULL,
                is_active BOOLEAN NOT NULL DEFAULT TRUE
            );
        ");

        $insert = $this->adminDb->prepare("
            INSERT INTO users (username, real_name, email, password_hash)
            VALUES (?, ?, ?, ?)
        ");
        foreach ([['capacity-one', 'Capacity One'], ['capacity-two', 'Capacity Two']] as $user) {
            $insert->execute([
                $user[0], $user[1], $user[0] . '@example.invalid',
                password_hash('capacity password', PASSWORD_DEFAULT),
            ]);
        }
        $this->adminDb->exec("
            INSERT INTO dosbox_doors
                (door_id, name, executable, path, config, enabled, door_type)
            VALUES
                ('proof-native', 'Proof Native Door', 'proof.sh',
                 'native-doors/doors/proof-native', '{\"max_sessions\":1}', TRUE, 'native');
        ");
    }

    private function createFixtureRoot(): void
    {
        $this->fixtureRoot = sys_get_temp_dir() . '/managed-capacity-' . bin2hex(random_bytes(6));
        $doorDir = $this->fixtureRoot . '/native-doors/doors/proof-native';
        mkdir($doorDir, 0700, true);
        mkdir($this->fixtureRoot . '/config', 0700, true);
        file_put_contents($doorDir . '/nativedoor.json', json_encode([
            'type' => 'nativedoor',
            'version' => '1.0',
            'game' => [
                'name' => 'Proof Native Door',
                'description' => 'Deterministic one-slot Experience.',
                'category' => 'multiplayer',
            ],
            'door' => [
                'executable' => 'proof.sh',
                'launch_command' => '/bin/sh proof.sh',
                'max_nodes' => 1,
            ],
            'config' => ['enabled' => true, 'max_sessions' => 1],
            'supports' => ['web' => true, 'telnet' => true],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        file_put_contents($this->fixtureRoot . '/config/nativedoors.json', json_encode([
            'proof-native' => [
                'enabled' => true,
                'credit_cost' => 0,
                'max_sessions' => 1,
            ],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        file_put_contents($this->fixtureRoot . '/prepend.php', '<?php define('
            . "'BINKTERMPHP_BASEDIR', " . var_export($this->fixtureRoot, true) . ');');
    }

    private function startServer(): string
    {
        $port = $this->freePort();
        $log = tempnam(sys_get_temp_dir(), 'managed-capacity-http-') ?: '';
        self::assertNotSame('', $log);
        $this->serverLogs[] = $log;
        $environment = getenv();
        self::assertIsArray($environment);
        $environment['PGOPTIONS'] = '-c search_path=' . $this->schema;
        $environment['PERF_LOG_ENABLED'] = 'false';
        $project = dirname(__DIR__, 2);
        $server = proc_open([
            PHP_BINARY,
            '-d', 'auto_prepend_file=' . $this->fixtureRoot . '/prepend.php',
            '-S', '127.0.0.1:' . $port,
            '-t', $project . '/public_html',
            $project . '/public_html/index.php',
        ], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes, $project, $environment);
        self::assertIsResource($server);
        fclose($pipes[0]);
        $this->servers[] = $server;

        $deadline = microtime(true) + 5.0;
        do {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
            if (is_resource($socket)) {
                fclose($socket);
                return 'http://127.0.0.1:' . $port;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        self::fail('HTTP test server did not start: ' . $this->serverOutput());
    }

    /** @return array{status:int,headers:string[],body:string,json:array<string,mixed>} */
    private function request(
        string $baseUrl,
        string $method,
        string $path,
        ?array $data = null,
        string $cookie = '',
        string $csrf = ''
    ): array {
        $headers = ['Accept: application/json'];
        if ($cookie !== '') {
            $headers[] = 'Cookie: ' . $cookie;
        }
        if ($csrf !== '') {
            $headers[] = 'X-CSRF-TOKEN: ' . $csrf;
        }
        $options = [
            'method' => $method,
            'ignore_errors' => true,
            'timeout' => 10,
            'header' => implode("\r\n", $headers),
        ];
        if ($data !== null) {
            if ($path === '/api/auth/login' || str_starts_with($path, '/api/experiences/')) {
                $options['header'] .= "\r\nContent-Type: application/json";
                $options['content'] = json_encode($data, JSON_THROW_ON_ERROR);
            } else {
                $options['header'] .= "\r\nContent-Type: application/x-www-form-urlencoded";
                $options['content'] = http_build_query($data);
            }
        }
        $body = file_get_contents(
            $baseUrl . $path,
            false,
            stream_context_create(['http' => $options])
        );
        $responseHeaders = $http_response_header ?? [];
        preg_match('/\s(\d{3})\s/', (string)($responseHeaders[0] ?? ''), $match);
        $decoded = json_decode((string)$body, true);
        return [
            'status' => (int)($match[1] ?? 0),
            'headers' => $responseHeaders,
            'body' => (string)$body . "\n" . $this->serverOutput(),
            'json' => is_array($decoded) ? $decoded : [],
        ];
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($socket, $error);
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        self::assertIsString($name);
        return (int)substr(strrchr($name, ':'), 1);
    }

    private function serverOutput(): string
    {
        $output = '';
        foreach ($this->serverLogs as $log) {
            $output .= is_file($log) ? (string)file_get_contents($log) : '';
        }
        return $output;
    }

    private function removeFixtureTree(string $path): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
