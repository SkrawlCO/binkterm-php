<?php

declare(strict_types=1);

namespace BinktermPHP;

/**
 * Server-side client for the multiplexing bridge's local control socket.
 *
 * The socket is never exposed through the web server. The bridge additionally
 * verifies the database-issued WebSocket token so a request can address only
 * the exact managed session already authorized by the HTTP endpoint.
 */
final class DoorBridgeControlClient
{
    private string $socketPath;
    private float $timeoutSeconds;

    public function __construct(?string $socketPath = null, ?float $timeoutSeconds = null)
    {
        $basePath = defined('BINKTERMPHP_BASEDIR')
            ? BINKTERMPHP_BASEDIR
            : dirname(__DIR__);
        $this->socketPath = $socketPath
            ?? $basePath . '/data/run/dosdoor-bridge-control.sock';
        $this->timeoutSeconds = $timeoutSeconds
            ?? (max(1000, (int)Config::env('DOSDOOR_CARRIER_LOSS_TIMEOUT', '5000')) + 3000) / 1000;
    }

    /**
     * @return array{success:bool,error?:string}
     */
    public function terminate(string $sessionId, string $wsToken): array
    {
        if ($sessionId === '' || $wsToken === '') {
            return ['success' => false, 'error' => 'Missing managed-session identity'];
        }

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            'unix://' . $this->socketPath,
            $errno,
            $errstr,
            $this->timeoutSeconds,
            STREAM_CLIENT_CONNECT
        );
        if ($socket === false) {
            return ['success' => false, 'error' => 'Door bridge control socket is unavailable'];
        }

        try {
            stream_set_timeout(
                $socket,
                (int)$this->timeoutSeconds,
                (int)(($this->timeoutSeconds - (int)$this->timeoutSeconds) * 1_000_000)
            );
            $payload = json_encode([
                'action' => 'terminate_session',
                'session_id' => $sessionId,
                'ws_token' => $wsToken,
            ], JSON_THROW_ON_ERROR) . "\n";

            if (@fwrite($socket, $payload) !== strlen($payload)) {
                return ['success' => false, 'error' => 'Unable to send door termination request'];
            }

            $line = @fgets($socket, 8193);
            $metadata = stream_get_meta_data($socket);
            if ($line === false || !empty($metadata['timed_out'])) {
                return ['success' => false, 'error' => 'Door runtime termination was not confirmed'];
            }

            $response = json_decode(trim($line), true);
            if (!is_array($response) || !array_key_exists('success', $response)) {
                return ['success' => false, 'error' => 'Invalid door bridge control response'];
            }

            return [
                'success' => $response['success'] === true,
                'error' => isset($response['error']) && is_string($response['error'])
                    ? $response['error']
                    : null,
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Door bridge control request failed'];
        } finally {
            fclose($socket);
        }
    }
}
