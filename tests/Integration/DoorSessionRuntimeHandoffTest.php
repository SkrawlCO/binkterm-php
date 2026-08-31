<?php

declare(strict_types=1);

use BinktermPHP\Config;
use PHPUnit\Framework\TestCase;

/**
 * Proves the existing database handoff between PHP session orchestration and
 * the independently running multiplexing bridge.
 */
final class DoorSessionRuntimeHandoffTest extends TestCase
{
    private ?PDO $adminDb = null;
    private string $schema = '';
    private string $fixtureRoot = '';

    protected function tearDown(): void
    {
        if ($this->adminDb !== null && $this->schema !== '') {
            $this->adminDb->exec('DROP SCHEMA IF EXISTS ' . $this->schema . ' CASCADE');
        }

        if ($this->fixtureRoot !== '' && is_dir($this->fixtureRoot)) {
            $this->removeFixtureTree($this->fixtureRoot);
        }
    }

    public function testSessionCreationStopsAtDatabaseRuntimeHandoff(): void
    {
        $this->createIsolatedDatabase();
        $this->createDoorFixture();

        $runner = $this->fixtureRoot . '/start-session.php';
        file_put_contents($runner, <<<'PHP'
<?php
define('BINKTERMPHP_BASEDIR', $argv[1]);
require $argv[2] . '/vendor/autoload.php';

$manager = new \BinktermPHP\DoorSessionManager(null, true);
$session = $manager->startSession(
    10,
    'proof-native',
    ['real_name' => 'Runtime Proof'],
    'native',
    'auth-proof'
);

echo json_encode($session, JSON_THROW_ON_ERROR);
PHP
        );

        $command = [PHP_BINARY, $runner, $this->fixtureRoot, dirname(__DIR__, 2)];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $environment = getenv();
        self::assertIsArray($environment);
        $environment['PGOPTIONS'] = '-c search_path=' . $this->schema;

        $process = proc_open(
            $command,
            $descriptors,
            $pipes,
            dirname(__DIR__, 2),
            $environment
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        self::assertSame(0, $exitCode, (string)$stderr);
        $session = json_decode((string)$stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(10, $session['user_id']);
        self::assertSame('proof-native', $session['door_id']);
        self::assertSame('Proof Native Door', $session['door_name']);
        self::assertSame(1, $session['node']);
        self::assertNotSame('', $session['ws_token']);

        $row = $this->adminDb->query("
            SELECT user_id, door_id, node_number, ws_port, ws_token,
                   user_data, door_type, auth_session_id, tcp_port,
                   dosbox_pid, bridge_pid, session_path, ended_at
              FROM door_sessions
        ")->fetch(PDO::FETCH_ASSOC);

        self::assertSame(10, (int)$row['user_id']);
        self::assertSame('proof-native', $row['door_id']);
        self::assertSame(1, (int)$row['node_number']);
        self::assertSame('native', $row['door_type']);
        self::assertSame('auth-proof', $row['auth_session_id']);
        self::assertSame($session['ws_token'], $row['ws_token']);
        self::assertNull($row['tcp_port']);
        self::assertNull($row['dosbox_pid']);
        self::assertNull($row['bridge_pid']);
        self::assertNull($row['session_path']);
        self::assertNull($row['ended_at']);

        $userData = json_decode($row['user_data'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $userData['node']);
        self::assertSame('COM1:', $userData['com_port']);

        // With no bridge/WebSocket client in this proof, PHP must not cross
        // into drop-file creation or external process launch.
        self::assertDirectoryDoesNotExist($this->fixtureRoot . '/data/run/door_sessions');
        self::assertDirectoryDoesNotExist($this->fixtureRoot . '/native-doors/drops');
        self::assertSame(1, (int)$this->adminDb->query(
            "SELECT COUNT(*) FROM door_session_logs WHERE event_type = 'created'"
        )->fetchColumn());
    }

    public function testBridgeConsumesTheCommittedTokenBeforeLaunchingRuntime(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 2) . '/scripts/dosbox-bridge/multiplexing-server.js'
        );

        $lookup = strpos($source, 'WHERE ws_token = $1 AND ended_at IS NULL');
        $launch = strpos($source, 'await this.launchEmulator(session);');
        self::assertNotFalse($lookup);
        self::assertNotFalse($launch);
        self::assertLessThan(
            $launch,
            $lookup,
            'The bridge must authenticate the committed session token before launching a runtime.'
        );
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
        $this->schema = 'door_handoff_' . bin2hex(random_bytes(6));
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

            INSERT INTO users (id, username) VALUES (10, 'runtimeproof');
            INSERT INTO dosbox_doors (
                door_id, name, executable, path, enabled, door_type
            ) VALUES (
                'proof-native', 'Proof Native Door',
                'proof.sh', 'native-doors/doors/proof-native', TRUE, 'native'
            );
        ");
    }

    private function createDoorFixture(): void
    {
        $this->fixtureRoot = sys_get_temp_dir()
            . '/door-handoff-' . bin2hex(random_bytes(6));
        $doorDir = $this->fixtureRoot . '/native-doors/doors/proof-native';
        $configDir = $this->fixtureRoot . '/config';
        mkdir($doorDir, 0700, true);
        mkdir($configDir, 0700, true);

        file_put_contents($doorDir . '/nativedoor.json', json_encode([
            'type' => 'nativedoor',
            'version' => '1.0',
            'game' => [
                'name' => 'Proof Native Door',
                'description' => 'Deterministic runtime handoff fixture.',
            ],
            'door' => [
                'executable' => 'proof.sh',
                'launch_command' => '/bin/sh proof.sh',
                'max_nodes' => 2,
            ],
            'config' => ['enabled' => true, 'max_sessions' => 2],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        file_put_contents($configDir . '/nativedoors.json', json_encode([
            'proof-native' => [
                'enabled' => true,
                'credit_cost' => 0,
                'max_sessions' => 2,
            ],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    private function removeFixtureTree(string $path): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($path);
    }
}
