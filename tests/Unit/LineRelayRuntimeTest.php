<?php

declare(strict_types=1);

use BinktermPHP\LineRelayRuntime;
use BinktermPHP\NativeDoorManager;
use PHPUnit\Framework\TestCase;

final class LineRelayRuntimeProbeAdapter
{
    /** @var array<string,mixed> */
    public static array $state = [];
    /** @var array<string,mixed> */
    public static array $context = [];

    public static function handshake($output, $backend, array &$state, array $context): void
    {
        self::$state = $state;
        self::$context = $context;
        fwrite($output, "ready\n");
    }
}

final class LineRelayRuntimeTest extends TestCase
{
    public function testMalformedRelayConfigurationFailsClosed(): void
    {
        $runtime = new LineRelayRuntime($this->doors([
            'terminal_mode' => 'line',
            'relay_host' => '',
            'relay_port' => 0,
        ]));

        $this->expectException(RuntimeException::class);
        $runtime->run('fixture', 42, 'session-a', fopen('php://temp', 'r+'), fopen('php://temp', 'w+'));
    }

    public function testNonLineNativeDoorFailsClosed(): void
    {
        $runtime = new LineRelayRuntime($this->doors([
            'terminal_mode' => 'raw',
            'relay_host' => '127.0.0.1',
            'relay_port' => 1234,
        ]));

        $this->expectException(RuntimeException::class);
        $runtime->run('fixture', 42, 'session-a', fopen('php://temp', 'r+'), fopen('php://temp', 'w+'));
    }

    public function testAuthoritativeNumericUserIdReachesAdapterState(): void
    {
        [$runtimeSocket, $peerSocket] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $input = fopen('php://temp', 'r+');
        $output = fopen('php://temp', 'w+');
        fwrite($input, "look\n");
        rewind($input);

        $runtime = new LineRelayRuntime(
            $this->doors([
                'terminal_mode' => 'line',
                'relay_host' => 'private.example',
                'relay_port' => 43023,
                'relay_adapter_class' => LineRelayRuntimeProbeAdapter::class,
            ]),
            static fn(string $host, int $port) => $runtimeSocket
        );

        self::assertSame(0, $runtime->run('fixture', 77, 'session-proof', $input, $output));
        self::assertSame(['user_id' => 77], LineRelayRuntimeProbeAdapter::$state);
        self::assertSame(
            ['session_id' => 'session-proof', 'door_id' => 'fixture'],
            LineRelayRuntimeProbeAdapter::$context
        );
        rewind($output);
        self::assertSame("ready\n", stream_get_contents($output));
        fclose($peerSocket);
    }

    /** @param array<string,mixed> $door */
    private function doors(array $door): NativeDoorManager
    {
        return new class($door) extends NativeDoorManager {
            /** @param array<string,mixed> $door */
            public function __construct(private array $door) {}
            public function getDoor(string $doorId): ?array
            {
                return $doorId === 'fixture' ? $this->door : null;
            }
        };
    }
}
