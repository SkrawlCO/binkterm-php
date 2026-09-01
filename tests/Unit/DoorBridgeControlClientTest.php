<?php

declare(strict_types=1);

use BinktermPHP\DoorBridgeControlClient;
use PHPUnit\Framework\TestCase;

final class DoorBridgeControlClientTest extends TestCase
{
    private string $socketPath = '';

    protected function tearDown(): void
    {
        if ($this->socketPath !== '' && file_exists($this->socketPath)) {
            unlink($this->socketPath);
        }
    }

    public function testExactSessionIdentityIsSentAndConfirmed(): void
    {
        [$pid, $capturePath] = $this->serveOnce(['success' => true]);

        $result = (new DoorBridgeControlClient($this->socketPath, 2.0))
            ->terminate('door_9_node1_proof', 'token-proof');

        pcntl_waitpid($pid, $status);
        self::assertTrue($result['success']);
        $request = json_decode((string)file_get_contents($capturePath), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('terminate_session', $request['action']);
        self::assertSame('door_9_node1_proof', $request['session_id']);
        self::assertSame('token-proof', $request['ws_token']);
        unlink($capturePath);
    }

    public function testUnconfirmedTerminationFailsClosed(): void
    {
        [$pid, $capturePath] = $this->serveOnce([
            'success' => false,
            'error' => 'Runtime termination was not confirmed',
        ]);

        $result = (new DoorBridgeControlClient($this->socketPath, 2.0))
            ->terminate('door_9_node1_proof', 'token-proof');

        pcntl_waitpid($pid, $status);
        self::assertFalse($result['success']);
        self::assertSame('Runtime termination was not confirmed', $result['error']);
        unlink($capturePath);
    }

    public function testUnavailableControlSocketFailsClosed(): void
    {
        $this->socketPath = sys_get_temp_dir() . '/missing-door-control-' . bin2hex(random_bytes(8));
        $result = (new DoorBridgeControlClient($this->socketPath, 0.1))
            ->terminate('door_9_node1_proof', 'token-proof');

        self::assertFalse($result['success']);
    }

    /** @return array{int,string} */
    private function serveOnce(array $response): array
    {
        self::assertTrue(function_exists('pcntl_fork'));
        $suffix = bin2hex(random_bytes(8));
        $this->socketPath = sys_get_temp_dir() . '/door-control-' . $suffix . '.sock';
        $capturePath = sys_get_temp_dir() . '/door-control-' . $suffix . '.json';
        $server = stream_socket_server('unix://' . $this->socketPath, $errno, $errstr);
        self::assertIsResource($server, $errstr);

        $pid = pcntl_fork();
        self::assertGreaterThanOrEqual(0, $pid);
        if ($pid === 0) {
            $client = stream_socket_accept($server, 2);
            if (is_resource($client)) {
                $request = fgets($client, 8193);
                file_put_contents($capturePath, trim((string)$request));
                fwrite($client, json_encode($response, JSON_THROW_ON_ERROR) . "\n");
                fclose($client);
            }
            fclose($server);
            exit(0);
        }

        fclose($server);
        return [$pid, $capturePath];
    }
}
