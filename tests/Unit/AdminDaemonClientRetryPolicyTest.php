<?php

/**
 * Local, in-process regression test for the RPC blind-retry defect tracked in
 * docs/checkpoints/BinkP_FidoAgora_InboundHang_2026-09-13.md (S2).
 *
 * Confirmed mechanism: AdminDaemonClient::sendCommand() retried ANY command
 * up to twice after an ambiguous failure (a read timeout or dropped
 * connection after the command line was already written). For a
 * side-effecting, non-idempotent command like `binkp_poll_sync` - which
 * causes the admin daemon to spawn a real outbound BinkP session - the retry
 * opened a second RPC connection and re-sent the identical command, causing
 * the admin daemon to spawn a SECOND, independent poll for the same uplink
 * while the first (unaware the client gave up on it) kept running. Combined
 * with BinkpClient's host-lock timeout bypass (see
 * BinkpHostLockStrictnessTest), this produced the observed duplicate dials.
 *
 * This test uses a real local TCP server (no external traffic, no BinkP
 * protocol, 127.0.0.1 only) that mimics the admin daemon's line-based auth +
 * command protocol just enough to authenticate, receive a command line, and
 * then close the connection WITHOUT responding - the exact "ambiguous
 * failure after dispatch" scenario. It runs in a forked child so the parent
 * (the actual AdminDaemonClient under test) can make its normal blocking
 * calls; the child records every command line it receives to a receipt file
 * the parent inspects after the child exits.
 *
 * No real BinkP session, no real admin daemon, no sleeps anywhere near the
 * production timeout values - the server closes immediately on receiving the
 * command, so the client's fgets() returns false (EOF) at once regardless of
 * whatever read timeout is configured.
 */

use BinktermPHP\Admin\AdminDaemonClient;
use PHPUnit\Framework\TestCase;

final class AdminDaemonClientRetryPolicyTest extends TestCase
{
    private string $receiptFile;

    protected function setUp(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl extension not available');
        }
        $this->receiptFile = sys_get_temp_dir() . '/admin_daemon_retry_test_' . bin2hex(random_bytes(4)) . '.log';
    }

    protected function tearDown(): void
    {
        @unlink($this->receiptFile);
    }

    /**
     * Starts a fake admin-daemon TCP server in a forked child. The child:
     *  - accepts exactly one connection at a time, in a loop, until $maxConnections
     *    connections have been handled (each sendCommand() attempt opens a
     *    fresh connection, so this bounds how many attempts we allow the
     *    server side to observe before the child exits)
     *  - completes the auth handshake successfully
     *  - reads the command line, appends its `cmd` value to the receipt file
     *  - closes the connection without writing a response (ambiguous failure)
     *
     * @return array{0:string,1:int} [socketTarget, childPid]
     */
    private function startFakeServer(int $maxConnections): array
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($server, "failed to start fake server: {$errstr} ({$errno})");
        $name = stream_socket_get_name($server, false);
        [$host, $port] = explode(':', $name);
        $socketTarget = "tcp://127.0.0.1:{$port}";

        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid, 'fork failed');

        if ($pid === 0) {
            // Child: fake server.
            for ($i = 0; $i < $maxConnections; $i++) {
                $client = @stream_socket_accept($server, 5);
                if ($client === false) {
                    break;
                }

                $authLine = fgets($client);
                if ($authLine !== false) {
                    fwrite($client, json_encode(['ok' => true]) . "\n");
                }

                $cmdLine = fgets($client);
                if ($cmdLine !== false) {
                    $payload = json_decode(trim($cmdLine), true);
                    $cmd = is_array($payload) ? ($payload['cmd'] ?? 'unknown') : 'unparseable';
                    file_put_contents($this->receiptFile, $cmd . "\n", FILE_APPEND | LOCK_EX);
                }

                // Ambiguous failure: close without ever writing a command response.
                fclose($client);
            }
            fclose($server);
            exit(0);
        }

        // Parent.
        fclose($server);
        return [$socketTarget, $pid];
    }

    public function testNonRetryableCommandIsDispatchedExactlyOnceAfterAmbiguousFailure(): void
    {
        // binkp_poll_sync (via binkPollSync()) is the confirmed non-idempotent
        // command - allow the fake server to accept up to 2 connections so a
        // regression (a reintroduced retry) would actually be observable
        // rather than just refused by the server.
        [$socketTarget, $pid] = $this->startFakeServer(2);

        $client = new AdminDaemonClient($socketTarget, 'test-secret-not-real');

        $threw = false;
        $start = microtime(true);
        try {
            $client->binkPollSync('999:1/1');
        } catch (\RuntimeException $e) {
            $threw = true;
        }
        $elapsed = microtime(true) - $start;

        pcntl_waitpid($pid, $status);

        self::assertTrue($threw, 'an ambiguous failure must still surface as a thrown failure to the caller');
        self::assertLessThan(5.0, $elapsed, 'no retry means this must fail fast, not wait out any read timeout');

        $receivedCommands = $this->readReceipts();
        self::assertSame(
            ['binkp_poll_sync'],
            $receivedCommands,
            'binkp_poll_sync must be dispatched exactly once - a second entry would mean the client redialed the admin daemon and caused a duplicate outbound poll spawn'
        );
    }

    public function testRetryableCommandStillRetriesOnceAsBefore(): void
    {
        // Contrast case: an ordinary (retryable=true, the default) command
        // is unaffected by this change and keeps its original at-most-2-attempts
        // behavior, proving the fix is scoped to binkp_poll_sync and not a
        // blanket removal of retry semantics for every admin RPC.
        [$socketTarget, $pid] = $this->startFakeServer(2);

        $client = new AdminDaemonClient($socketTarget, 'test-secret-not-real');

        $threw = false;
        try {
            $client->processPackets(); // retryable=true (default), not touched by this fix
        } catch (\RuntimeException $e) {
            $threw = true;
        }

        pcntl_waitpid($pid, $status);

        self::assertTrue($threw);
        self::assertSame(
            ['process_packets', 'process_packets'],
            $this->readReceipts(),
            'an unmodified, default-retryable command should still be dispatched twice on ambiguous failure, unchanged from before this fix'
        );
    }

    /** @return string[] */
    private function readReceipts(): array
    {
        if (!file_exists($this->receiptFile)) {
            return [];
        }
        $lines = file($this->receiptFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return $lines === false ? [] : $lines;
    }
}
