<?php

declare(strict_types=1);

use BinktermPHP\Config;
use PHPUnit\Framework\TestCase;

/**
 * HTTP-level proof of the existing Crossroads WebDoor lifecycle.
 *
 * A private PostgreSQL schema and a short-lived PHP HTTP server isolate the
 * proof from the live application data. Wordle is used because its production
 * WebDoor session endpoint is itself the complete launch boundary: no external
 * game process or remote service needs to be replaced.
 */
final class CrossroadsHttpLifecycleTest extends TestCase
{
    /** @var resource|null */
    private $server = null;

    private ?PDO $adminDb = null;
    private string $schema = '';
    private string $baseUrl = '';
    private string $serverLog = '';

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
            $this->server = null;
        }

        if ($this->adminDb !== null && $this->schema !== '') {
            $this->adminDb->exec('DROP SCHEMA IF EXISTS ' . $this->schema . ' CASCADE');
        }

        if ($this->serverLog !== '' && is_file($this->serverLog)) {
            unlink($this->serverLog);
        }
    }

    public function testAuthenticatedHttpLifecycleUsesCsrfAndReusesSession(): void
    {
        $this->startIsolatedApplication();

        $login = $this->request('POST', '/api/auth/login', [
            'username' => 'httptraveler',
            'password' => 'correct horse',
            'remember' => false,
        ]);
        self::assertSame(200, $login['status'], $login['body']);
        self::assertTrue($login['json']['success'] ?? false);
        $csrf = (string)($login['json']['csrf_token'] ?? '');
        self::assertNotSame('', $csrf);
        $cookie = $this->sessionCookie($login['headers']);

        // The authenticated state route is the HTTP detail/discovery boundary:
        // it authorizes Wordle through GameCatalog before returning lobby state.
        $initial = $this->request('GET', '/api/experiences/wordle/state', null, $cookie);
        self::assertSame(200, $initial['status'], $initial['body']);
        self::assertFalse($initial['json']['viewer']['participating']);
        self::assertTrue($initial['json']['viewer']['actions']['play']);
        self::assertFalse($initial['json']['viewer']['actions']['return']);
        self::assertSame('play', $initial['json']['presentation']['actions']['primary']);

        $launch = $this->request(
            'GET',
            '/api/webdoor/session?game_id=wordle',
            null,
            $cookie
        );
        self::assertSame(200, $launch['status'], $launch['body']);
        $sessionId = (string)($launch['json']['session_id'] ?? '');
        self::assertNotSame('', $sessionId);

        $active = $this->request('GET', '/api/experiences/wordle/state', null, $cookie);
        self::assertSame(200, $active['status'], $active['body']);
        self::assertTrue($active['json']['viewer']['participating']);
        self::assertSame($sessionId, $active['json']['viewer']['session_id']);
        self::assertTrue($active['json']['viewer']['actions']['return']);
        self::assertTrue($active['json']['viewer']['actions']['end']);
        self::assertSame('return', $active['json']['presentation']['actions']['primary']);
        self::assertSame(1, $active['json']['state']['session_count']);

        $return = $this->request(
            'GET',
            '/api/webdoor/session?game_id=wordle',
            null,
            $cookie
        );
        self::assertSame(200, $return['status'], $return['body']);
        self::assertSame($sessionId, $return['json']['session_id']);
        self::assertSame(1, $this->activeSessionCount());

        // The normalized Experience mutation must reject the authenticated
        // cookie without its matching per-user CSRF token.
        $rejectedEnd = $this->request(
            'POST',
            '/api/experiences/wordle/end',
            [],
            $cookie
        );
        self::assertSame(403, $rejectedEnd['status'], $rejectedEnd['body']);
        self::assertSame('errors.auth.invalid_csrf_token', $rejectedEnd['json']['error_code']);
        self::assertSame(1, $this->activeSessionCount());

        $ended = $this->request(
            'POST',
            '/api/experiences/wordle/end',
            [],
            $cookie,
            ['X-CSRF-TOKEN: ' . $csrf]
        );
        self::assertSame(200, $ended['status'], $ended['body']);
        self::assertTrue($ended['json']['success'] ?? false);
        self::assertFalse($ended['json']['participating'] ?? true);
        self::assertSame(0, $this->activeSessionCount());

        $final = $this->request('GET', '/api/experiences/wordle/state', null, $cookie);
        self::assertSame(200, $final['status'], $final['body']);
        self::assertFalse($final['json']['viewer']['participating']);
        self::assertTrue($final['json']['viewer']['actions']['play']);
        self::assertFalse($final['json']['viewer']['actions']['return']);
        self::assertSame('play', $final['json']['presentation']['actions']['primary']);
        self::assertSame(0, $final['json']['state']['session_count']);
    }

    private function startIsolatedApplication(): void
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

        $this->schema = 'crossroads_http_' . bin2hex(random_bytes(6));
        $this->adminDb->exec('CREATE SCHEMA ' . $this->schema);
        $this->adminDb->exec('SET search_path TO ' . $this->schema);
        $this->createSchema($this->adminDb);

        $port = $this->freePort();
        $this->baseUrl = 'http://127.0.0.1:' . $port;
        $this->serverLog = tempnam(sys_get_temp_dir(), 'crossroads-http-') ?: '';
        self::assertNotSame('', $this->serverLog);

        $environment = getenv();
        self::assertIsArray($environment);
        $environment['PGOPTIONS'] = '-c search_path=' . $this->schema;
        $environment['PERF_LOG_ENABLED'] = 'false';

        $command = [
            PHP_BINARY,
            '-S',
            '127.0.0.1:' . $port,
            '-t',
            dirname(__DIR__, 2) . '/public_html',
            dirname(__DIR__, 2) . '/public_html/index.php',
        ];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $this->serverLog, 'a'],
            2 => ['file', $this->serverLog, 'a'],
        ];
        $this->server = proc_open(
            $command,
            $descriptors,
            $pipes,
            dirname(__DIR__, 2),
            $environment
        );
        self::assertIsResource($this->server);
        fclose($pipes[0]);

        $deadline = microtime(true) + 5.0;
        do {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
            if (is_resource($socket)) {
                fclose($socket);
                return;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        self::fail('HTTP test server did not start: ' . $this->serverOutput());
    }

    private function createSchema(PDO $db): void
    {
        $db->exec("
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
                ip_address VARCHAR(64),
                user_agent TEXT,
                last_activity TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                activity VARCHAR(255),
                public_activity VARCHAR(255),
                service VARCHAR(20)
            );

            CREATE TABLE users_meta (
                user_id BIGINT NOT NULL REFERENCES users(id),
                keyname VARCHAR(255) NOT NULL,
                valname TEXT,
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                PRIMARY KEY (user_id, keyname)
            );

            CREATE TABLE webdoor_sessions (
                id BIGSERIAL PRIMARY KEY,
                session_id VARCHAR(128) UNIQUE NOT NULL,
                user_id BIGINT NOT NULL REFERENCES users(id),
                game_id VARCHAR(100) NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                expires_at TIMESTAMPTZ NOT NULL,
                ended_at TIMESTAMPTZ,
                playtime_seconds INTEGER NOT NULL DEFAULT 0
            );

            -- Full column set matching the real door_sessions schema (base
            -- migration + later ALTERs). The app bootstrap runs
            -- DoorSessionManager::cleanExpiredSessions() on ~5% of every request
            -- (public_html/index.php), which UPDATEs exit_status here, so a
            -- partial fixture makes unrelated requests randomly 500. Mirrors the
            -- door_sessions fixture in DoorSessionRuntimeHandoffTest /
            -- DoorSessionManagerAdmissionTest / CrossroadsManagedDoorConcurrencyTest.
            CREATE TABLE door_sessions (
                id BIGSERIAL PRIMARY KEY,
                session_id VARCHAR(128) UNIQUE NOT NULL,
                user_id BIGINT NOT NULL REFERENCES users(id),
                door_id VARCHAR(100) NOT NULL,
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

            CREATE TABLE user_activity_log (
                id BIGSERIAL PRIMARY KEY,
                user_id BIGINT REFERENCES users(id),
                activity_type_id INTEGER NOT NULL,
                object_id BIGINT,
                object_name VARCHAR(255),
                meta JSONB,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            );

            CREATE TABLE chat_rooms (
                id BIGSERIAL PRIMARY KEY,
                name VARCHAR(255) UNIQUE NOT NULL,
                is_active BOOLEAN NOT NULL DEFAULT TRUE
            );
        ");

        $insert = $db->prepare("
            INSERT INTO users (username, real_name, email, password_hash)
            VALUES (?, ?, ?, ?)
        ");
        $insert->execute([
            'httptraveler',
            'HTTP Traveler',
            'http@example.invalid',
            password_hash('correct horse', PASSWORD_DEFAULT),
        ]);
    }

    /**
     * @param array<string,mixed>|null $json
     * @param string[] $extraHeaders
     * @return array{status:int,headers:string[],body:string,json:array<string,mixed>}
     */
    private function request(
        string $method,
        string $path,
        ?array $json = null,
        string $cookie = '',
        array $extraHeaders = []
    ): array {
        $headers = array_merge(['Accept: application/json'], $extraHeaders);
        if ($cookie !== '') {
            $headers[] = 'Cookie: ' . $cookie;
        }

        $options = [
            'method' => $method,
            'ignore_errors' => true,
            'timeout' => 10,
            'header' => implode("\r\n", $headers),
        ];
        if ($json !== null) {
            $options['header'] .= "\r\nContent-Type: application/json";
            $options['content'] = json_encode($json, JSON_THROW_ON_ERROR);
        }

        $body = file_get_contents(
            $this->baseUrl . $path,
            false,
            stream_context_create(['http' => $options])
        );
        $responseHeaders = $http_response_header ?? [];
        $statusLine = (string)($responseHeaders[0] ?? '');
        preg_match('/\s(\d{3})\s/', $statusLine, $match);
        $decoded = json_decode((string)$body, true);

        return [
            'status' => (int)($match[1] ?? 0),
            'headers' => $responseHeaders,
            'body' => (string)$body . "\n" . $this->serverOutput(),
            'json' => is_array($decoded) ? $decoded : [],
        ];
    }

    /** @param string[] $headers */
    private function sessionCookie(array $headers): string
    {
        foreach ($headers as $header) {
            if (preg_match('/^Set-Cookie:\s*(binktermphp_session=[^;]+)/i', $header, $match)) {
                return $match[1];
            }
        }

        self::fail('Login response did not set a session cookie.');
    }

    private function activeSessionCount(): int
    {
        $stmt = $this->adminDb->query("
            SELECT COUNT(*)
              FROM webdoor_sessions
             WHERE game_id = 'wordle'
               AND ended_at IS NULL
        ");

        return (int)$stmt->fetchColumn();
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
        return $this->serverLog !== '' && is_file($this->serverLog)
            ? (string)file_get_contents($this->serverLog)
            : '';
    }
}
