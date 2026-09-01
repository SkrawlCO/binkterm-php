<?php

declare(strict_types=1);

namespace BinktermPHP;

/**
 * Generic runtime for native terminal.mode=line Experiences.
 *
 * The browser bridge supplies already line-buffered input on $input. This
 * runtime validates the trusted native-door manifest, connects to its private
 * TCP service, invokes the optional PHP relay adapter, and copies data in both
 * directions until either side closes.
 */
final class LineRelayRuntime
{
    private NativeDoorManager $doors;
    /** @var \Closure(string,int):resource */
    private \Closure $connector;

    /** @param callable(string,int):resource|null $connector */
    public function __construct(
        ?NativeDoorManager $doors = null,
        ?callable $connector = null
    ) {
        $this->doors = $doors ?? new NativeDoorManager();
        $this->connector = $connector !== null
            ? \Closure::fromCallable($connector)
            : static function (string $host, int $port) {
                $target = str_contains($host, ':') && $host[0] !== '[' ? "[{$host}]" : $host;
                $errno = 0;
                $errstr = '';
                $backend = @stream_socket_client("tcp://{$target}:{$port}", $errno, $errstr, 5);
                if ($backend === false) {
                    throw new \RuntimeException("Unable to connect to line relay backend: {$errstr}", $errno);
                }
                return $backend;
            };
    }

    /** @param resource $input @param resource $output */
    public function run(
        string $doorId,
        int $userId,
        string $sessionId,
        $input,
        $output
    ): int {
        if ($userId <= 0 || $sessionId === '' || !$this->validDoorId($doorId)) {
            throw new \InvalidArgumentException('Invalid line-relay session identity');
        }

        $door = $this->doors->getDoor($doorId);
        if (!is_array($door) || strtolower((string)($door['terminal_mode'] ?? '')) !== 'line') {
            throw new \RuntimeException('Native door is not a terminal.mode=line Experience');
        }

        $host = trim((string)($door['relay_host'] ?? ''));
        $port = (int)($door['relay_port'] ?? 0);
        if ($host === '' || $port < 1 || $port > 65535) {
            throw new \RuntimeException('Line relay requires a valid relay_host and relay_port');
        }

        $adapterClass = trim((string)($door['relay_adapter_class'] ?? ''));
        if ($adapterClass !== '' && !class_exists($adapterClass)) {
            throw new \RuntimeException('Configured line-relay adapter class is unavailable');
        }
        if ($adapterClass !== '' && !is_callable([$adapterClass, 'handshake'])) {
            throw new \RuntimeException('Configured line-relay adapter has no handshake method');
        }

        $backend = ($this->connector)($host, $port);
        if (!is_resource($backend)) {
            throw new \RuntimeException('Line relay connector did not return a stream');
        }

        $state = ['user_id' => $userId];
        $context = ['session_id' => $sessionId, 'door_id' => $doorId];

        try {
            if ($adapterClass !== '') {
                $adapterClass::handshake($output, $backend, $state, $context);
            }

            stream_set_blocking($input, false);
            stream_set_blocking($backend, false);

            while (is_resource($backend) && !feof($backend)) {
                $read = [$input, $backend];
                $write = $except = null;
                $ready = @stream_select($read, $write, $except, 0, 100000);
                if ($ready === false) {
                    return 1;
                }
                if ($ready === 0) {
                    continue;
                }

                foreach ($read as $stream) {
                    $chunk = @fread($stream, 4096);
                    if ($chunk === false || ($chunk === '' && feof($stream))) {
                        return 0;
                    }
                    if ($chunk === '') {
                        continue;
                    }

                    if ($stream === $input) {
                        if (@fwrite($backend, $chunk) === false) {
                            return 1;
                        }
                        continue;
                    }

                    if (@fwrite($output, $chunk) === false) {
                        return 1;
                    }
                    @fflush($output);
                    if ($adapterClass !== '' && is_callable([$adapterClass, 'onOutput'])) {
                        $adapterClass::onOutput($chunk, $state, $context);
                    }
                }
            }
        } finally {
            if (is_resource($backend)) {
                fclose($backend);
            }
        }

        return 0;
    }

    private function validDoorId(string $doorId): bool
    {
        return $doorId !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $doorId) === 1;
    }
}
