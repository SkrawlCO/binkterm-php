<?php

declare(strict_types=1);

use BinktermPHP\Config;
use BinktermPHP\DoorSessionManager;
use PHPUnit\Framework\TestCase;

/**
 * Service-level proof of the door-session admission contract, independent of the
 * HTTP layer:
 *
 *   1. a door cannot exceed its configured `max_nodes`, even when two launches
 *      overlap inside the admission critical section, and
 *   2. two overlapping launches never receive the same active `node_number`.
 *
 * Overlap is forced by holding the production admission mutex
 * (pg_advisory_lock(DoorSessionManager::ADMISSION_LOCK_KEY)) while both
 * DoorSessionManager::startSession() calls are already running in separate
 * processes, then releasing it and observing the outcome.
 */
final class DoorSessionManagerAdmissionTest extends TestCase
{
    private ?PDO $adminDb = null;
    private string $schema = '';
    private string $fixtureRoot = '';

    protected function tearDown(): void
    {
        if ($this->adminDb !== null) {
            $this->adminDb->exec('SELECT pg_advisory_unlock_all()');
            if ($this->schema !== '') {
                $this->adminDb->exec('DROP SCHEMA IF EXISTS ' . $this->schema . ' CASCADE');
            }
        }
        $this->adminDb = null;

        if ($this->fixtureRoot !== '' && is_dir($this->fixtureRoot)) {
            $this->removeFixtureTree($this->fixtureRoot);
        }
    }

    public function testSequentialLaunchAtCapacityThrowsDoorCapacityException(): void
    {
        $this->createIsolatedDatabase();
        $this->createDoorFixtures(['slot-a' => 1]);

        $first = $this->runStart('slot-a', 10);
        self::assertTrue($first['ok'], json_encode($first));
        self::assertSame(1, (int)$first['session']['node']);

        $second = $this->runStart('slot-a', 11);
        self::assertFalse($second['ok'], json_encode($second));
        self::assertSame('BinktermPHP\\DoorCapacityException', $second['error_class'], json_encode($second));
        self::assertSame('slot-a', $second['door_id']);
        self::assertSame(1, $second['max_nodes']);
        self::assertSame(1, $second['active']);

        self::assertSame(1, $this->activeCount('slot-a'));
    }

    public function testConcurrentLaunchesForOneDoorAdmitExactlyOne(): void
    {
        $this->createIsolatedDatabase();
        $this->createDoorFixtures(['slot-a' => 1]);

        [$results, $waiters] = $this->raceStarts([
            ['slot-a', 10],
            ['slot-a', 11],
        ]);

        self::assertSame(2, $waiters, 'both launches must block on the admission mutex simultaneously');

        $ok = array_values(array_filter($results, static fn(array $r): bool => $r['ok']));
        $rejected = array_values(array_filter($results, static fn(array $r): bool => !$r['ok']));

        self::assertCount(1, $ok, json_encode($results));
        self::assertCount(1, $rejected, json_encode($results));
        self::assertSame('BinktermPHP\\DoorCapacityException', $rejected[0]['error_class'], json_encode($results));
        self::assertSame(1, $rejected[0]['max_nodes']);

        self::assertSame(1, $this->activeCount('slot-a'));
        self::assertSame(1, (int)$ok[0]['session']['node']);
    }

    public function testConcurrentLaunchesAllocateDistinctNodeNumbers(): void
    {
        $this->createIsolatedDatabase();
        $this->createDoorFixtures(['slot-a' => 1, 'slot-b' => 1]);

        [$results, $waiters] = $this->raceStarts([
            ['slot-a', 10],
            ['slot-b', 11],
        ]);

        self::assertSame(2, $waiters, 'both launches must block on the admission mutex simultaneously');

        foreach ($results as $r) {
            self::assertTrue($r['ok'], json_encode($r));
        }

        $nodes = array_map(static fn(array $r): int => (int)$r['session']['node'], $results);
        self::assertCount(2, array_unique($nodes), 'overlapping launches must not share a node number: ' . json_encode($nodes));

        $activeNodes = array_map('intval', $this->adminDb
            ->query("SELECT node_number FROM door_sessions WHERE ended_at IS NULL ORDER BY node_number")
            ->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame([1, 2], $activeNodes);
    }

    public function testCapacityIsReleasedWhenSessionEnds(): void
    {
        $this->createIsolatedDatabase();
        $this->createDoorFixtures(['slot-a' => 1]);

        $first = $this->runStart('slot-a', 10);
        self::assertTrue($first['ok'], json_encode($first));

        self::assertFalse($this->runStart('slot-a', 11)['ok']);

        // End the session the same way DoorSessionManager::endSession() records
        // it in the database (the slot is freed by ended_at, not by anything the
        // admission path can see mid-flight).
        $this->adminDb->prepare(
            "UPDATE door_sessions SET ended_at = NOW(), exit_status = 'normal' WHERE session_id = ?"
        )->execute([$first['session']['session_id']]);

        $third = $this->runStart('slot-a', 11);
        self::assertTrue($third['ok'], json_encode($third));
        self::assertSame(1, $this->activeCount('slot-a'));
    }

    /**
     * Run a single startSession() in a subprocess bound to the test schema.
     *
     * @return array{ok:bool,session?:array<string,mixed>,error_class?:string,door_id?:string,max_nodes?:int,active?:int,error?:string}
     */
    private function runStart(string $doorId, int $userId): array
    {
        $handle = $this->spawnStart($doorId, $userId);
        return $this->reap($handle);
    }

    /**
     * Force genuine overlap: hold the admission advisory lock, spawn every
     * startSession() call, wait until they are all parked on that lock, release
     * it, then collect results.
     *
     * @param array<int,array{0:string,1:int}> $specs door id + user id per launch
     * @return array{0:array<int,array<string,mixed>>,1:int} results, and the peak
     *         number of launches simultaneously blocked on the admission mutex
     */
    private function raceStarts(array $specs): array
    {
        $this->adminDb
            ->query('SELECT pg_advisory_lock(' . DoorSessionManager::ADMISSION_LOCK_KEY . ')')
            ->closeCursor();

        $handles = [];
        foreach ($specs as $spec) {
            $handles[] = $this->spawnStart($spec[0], $spec[1]);
        }

        $waiters = $this->waitForAdvisoryWaiters(count($specs));

        $this->adminDb
            ->query('SELECT pg_advisory_unlock(' . DoorSessionManager::ADMISSION_LOCK_KEY . ')')
            ->closeCursor();

        $results = [];
        foreach ($handles as $handle) {
            $results[] = $this->reap($handle);
        }

        return [$results, $waiters];
    }

    /**
     * @return array{process:resource,pipes:array<int,resource>}
     */
    private function spawnStart(string $doorId, int $userId): array
    {
        $runner = $this->fixtureRoot . '/start-session.php';
        if (!is_file($runner)) {
            file_put_contents($runner, <<<'PHP'
<?php
define('BINKTERMPHP_BASEDIR', $argv[1]);
require $argv[2] . '/vendor/autoload.php';

try {
    $manager = new \BinktermPHP\DoorSessionManager(null, true);
    $session = $manager->startSession(
        (int)$argv[4],
        $argv[3],
        ['real_name' => 'Racer ' . $argv[4]],
        'native',
        'auth-' . $argv[4]
    );
    echo json_encode(['ok' => true, 'session' => $session], JSON_THROW_ON_ERROR);
} catch (\BinktermPHP\DoorCapacityException $e) {
    echo json_encode([
        'ok' => false,
        'error_class' => \BinktermPHP\DoorCapacityException::class,
        'door_id' => $e->doorId,
        'max_nodes' => $e->maxNodes,
        'active' => $e->activeSessions,
    ], JSON_THROW_ON_ERROR);
} catch (\Throwable $e) {
    echo json_encode([
        'ok' => false,
        'error_class' => get_class($e),
        'error' => $e->getMessage(),
    ], JSON_THROW_ON_ERROR);
}
PHP
            );
        }

        $environment = getenv();
        self::assertIsArray($environment);
        $environment['PGOPTIONS'] = '-c search_path=' . $this->schema;

        $process = proc_open(
            [PHP_BINARY, $runner, $this->fixtureRoot, dirname(__DIR__, 2), $doorId, (string)$userId],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2),
            $environment
        );
        self::assertIsResource($process);
        fclose($pipes[0]);

        return ['process' => $process, 'pipes' => $pipes];
    }

    /**
     * @param array{process:resource,pipes:array<int,resource>} $handle
     * @return array<string,mixed>
     */
    private function reap(array $handle): array
    {
        $stdout = stream_get_contents($handle['pipes'][1]);
        $stderr = stream_get_contents($handle['pipes'][2]);
        fclose($handle['pipes'][1]);
        fclose($handle['pipes'][2]);
        $exitCode = proc_close($handle['process']);

        self::assertSame(0, $exitCode, (string)$stderr);

        return json_decode((string)$stdout, true, 512, JSON_THROW_ON_ERROR);
    }

    private function waitForAdvisoryWaiters(int $expected): int
    {
        $peak = 0;
        $deadline = microtime(true) + 10.0;
        do {
            $count = (int)$this->adminDb->query("
                SELECT COUNT(*) FROM pg_locks
                 WHERE locktype = 'advisory'
                   AND NOT granted
                   AND pid <> pg_backend_pid()
            ")->fetchColumn();
            $peak = max($peak, $count);
            if ($count >= $expected) {
                return $count;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        return $peak;
    }

    private function activeCount(string $doorId): int
    {
        $stmt = $this->adminDb->prepare("
            SELECT COUNT(*) FROM door_sessions
             WHERE door_id = ? AND ended_at IS NULL AND expires_at > NOW()
        ");
        $stmt->execute([$doorId]);

        return (int)$stmt->fetchColumn();
    }

    private function createIsolatedDatabase(): void
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
        $this->schema = 'door_admission_' . bin2hex(random_bytes(6));
        $this->adminDb->exec('CREATE SCHEMA ' . $this->schema);
        $this->adminDb->exec('SET search_path TO ' . $this->schema);
        $this->adminDb->exec("
            CREATE TABLE users (
                id BIGINT PRIMARY KEY,
                username VARCHAR(50) NOT NULL
            );

            CREATE TABLE dosbox_doors (
                door_id VARCHAR(50) PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                description TEXT,
                executable VARCHAR(255) NOT NULL,
                path VARCHAR(255) NOT NULL,
                config JSONB,
                enabled BOOLEAN NOT NULL DEFAULT TRUE,
                door_type VARCHAR(20)
            );

            CREATE TABLE door_sessions (
                id BIGSERIAL PRIMARY KEY,
                session_id VARCHAR(128) UNIQUE NOT NULL,
                user_id BIGINT NOT NULL REFERENCES users(id),
                door_id VARCHAR(50) NOT NULL REFERENCES dosbox_doors(door_id),
                node_number INTEGER NOT NULL,
                tcp_port INTEGER,
                ws_port INTEGER NOT NULL,
                ws_token VARCHAR(128),
                dosbox_pid INTEGER,
                bridge_pid INTEGER,
                session_path VARCHAR(255),
                user_data JSONB,
                door_type VARCHAR(20),
                auth_session_id VARCHAR(128),
                started_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                ended_at TIMESTAMPTZ,
                expires_at TIMESTAMPTZ NOT NULL,
                exit_status VARCHAR(50)
            );

            CREATE TABLE door_session_logs (
                id BIGSERIAL PRIMARY KEY,
                session_id VARCHAR(128) REFERENCES door_sessions(session_id),
                event_type VARCHAR(50) NOT NULL,
                event_data JSONB,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            );

            INSERT INTO users (id, username) VALUES
                (10, 'racer-ten'), (11, 'racer-eleven');
        ");
    }

    /**
     * @param array<string,int> $doors door id => max_nodes
     */
    private function createDoorFixtures(array $doors): void
    {
        $this->fixtureRoot = sys_get_temp_dir() . '/door-admission-' . bin2hex(random_bytes(6));
        mkdir($this->fixtureRoot . '/config', 0700, true);

        $configMap = [];
        foreach ($doors as $doorId => $maxNodes) {
            $doorDir = $this->fixtureRoot . '/native-doors/doors/' . $doorId;
            mkdir($doorDir, 0700, true);
            file_put_contents($doorDir . '/nativedoor.json', json_encode([
                'type' => 'nativedoor',
                'version' => '1.0',
                'game' => [
                    'name' => 'Door ' . $doorId,
                    'description' => 'Admission-contract fixture.',
                ],
                'door' => [
                    'executable' => 'proof.sh',
                    'launch_command' => '/bin/sh proof.sh',
                    'max_nodes' => $maxNodes,
                ],
                'config' => ['enabled' => true, 'max_sessions' => $maxNodes],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            $configMap[$doorId] = [
                'enabled' => true,
                'credit_cost' => 0,
                'max_sessions' => $maxNodes,
            ];

            $this->adminDb->prepare("
                INSERT INTO dosbox_doors (door_id, name, executable, path, config, enabled, door_type)
                VALUES (?, ?, 'proof.sh', ?, '{}', TRUE, 'native')
            ")->execute([$doorId, 'Door ' . $doorId, 'native-doors/doors/' . $doorId]);
        }

        file_put_contents(
            $this->fixtureRoot . '/config/nativedoors.json',
            json_encode($configMap, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
        );
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
