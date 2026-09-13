<?php

/**
 * Local, in-process reproduction + regression test for the admin-RPC
 * observability gap tracked in
 * docs/checkpoints/BinkP_FidoAgora_InboundHang_2026-09-13.md (Part B).
 *
 * Empirically confirmed root cause (verified live, read-only, no external
 * traffic, in the 2026-09-13 incident investigation): `data/logs/binkp_poll.log`
 * is owned by a different user than the one `admin_daemon.php` (and every
 * process it spawns for `binkp_poll_sync`/`binkp_poll`) runs as. When that
 * mismatched-owner process's direct file write fails, `Logger::log()`
 * correctly falls back to sending the line to the admin daemon over UDP —
 * but `AdminDaemonClient::udpLog()` only confirms the packet left the
 * sender's own socket, not that the daemon durably wrote it. Because the
 * daemon runs as that same fixed, mismatched user, its own write to the
 * identical target file fails for the identical reason — and pre-fix, that
 * failure was silently swallowed (`@file_put_contents(...)` with no check
 * of the return value), permanently losing the message with no trace
 * anywhere. This is a plain ownership/permissions problem, not a race
 * against the RPC client timing out — it reproduces even for a fast,
 * successful single-uplink poll, with no relationship to session duration.
 *
 * This test isolates AdminDaemonServer::writeLogLineOrWarn() (extracted
 * specifically to be testable without fighting Config::getLogPath()'s
 * hardcoded, real data/logs/ path) via reflection, forcing a guaranteed,
 * portable write failure by pointing it at a path that is an existing
 * directory rather than a file - this fails file_put_contents() the same
 * way regardless of which user runs the test, with no dependency on real
 * file ownership, root privileges, or the actual data/logs/ directory.
 *
 * No BinkP protocol code, scheduler, host-lock, or retry logic is touched
 * or exercised here - purely the admin-daemon-side UDP log persistence
 * fallback path.
 */

use BinktermPHP\Admin\AdminDaemonServer;
use BinktermPHP\Binkp\Logger;
use PHPUnit\Framework\TestCase;

final class AdminDaemonUdpLogFallbackTest extends TestCase
{
    private string $scratchDir;

    protected function setUp(): void
    {
        $this->scratchDir = sys_get_temp_dir() . '/admin_daemon_udp_log_test_' . bin2hex(random_bytes(4));
        mkdir($this->scratchDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->scratchDir . '/*') ?: [] as $f) {
            is_dir($f) ? rmdir($f) : @unlink($f);
        }
        @rmdir($this->scratchDir);
    }

    private static function spyLogger(): object
    {
        return new class extends Logger {
            public array $warnings = [];
            public function __construct()
            {
                // Deliberately do not call parent::__construct() - this spy
                // never touches a real log file.
            }
            public function warning($message, $context = [])
            {
                $this->warnings[] = ['message' => $message, 'context' => $context];
            }
        };
    }

    private function invokeWriteLogLineOrWarn(AdminDaemonServer $server, string $logPath, string $logFile, string $message, int $pid): void
    {
        $method = new \ReflectionMethod($server, 'writeLogLineOrWarn');
        $method->setAccessible(true);
        $method->invoke($server, $logPath, $logFile, $message, $pid);
    }

    public function testOldBehaviorWouldSilentlyDiscardAMessageWhenTheTargetIsNotWritable(): void
    {
        // Reproduces the pre-fix defect directly: a plain, unchecked
        // file_put_contents() against an unwritable target (here: a path
        // that is a directory) returns false and, absent any check, leaves
        // no trace anywhere that anything was lost.
        $unwritableTarget = $this->scratchDir . '/looks-like-a-log-file';
        mkdir($unwritableTarget); // a directory, not a file - fails to open for writing regardless of user

        $result = @file_put_contents($unwritableTarget, "lost line\n", FILE_APPEND | LOCK_EX);

        self::assertFalse($result, 'sanity: writing to a directory path must fail, proving this is a genuine write failure and not a test artifact');
    }

    public function testFixedBehaviorSurfacesTheLostMessageThroughTheDaemonsOwnLogger(): void
    {
        $unwritableTarget = $this->scratchDir . '/binkp_poll.log'; // named like the real target for realism
        mkdir($unwritableTarget); // force a guaranteed, portable write failure

        $spyLogger = self::spyLogger();
        $server = new AdminDaemonServer('tcp://127.0.0.1:0', 'test-secret-not-used', $spyLogger);

        $lostMessage = '[2026-09-13 05:15:15] [12345] [INFO] [1:154/10] Handshake completed successfully';
        $this->invokeWriteLogLineOrWarn($server, $unwritableTarget, 'binkp_poll.log', $lostMessage, 12345);

        self::assertNotEmpty($spyLogger->warnings, 'a write failure must be surfaced through the daemon\'s own logger, not silently discarded');

        $warning = $spyLogger->warnings[0];
        self::assertStringContainsString('not writable', $warning['message']);
        self::assertSame('binkp_poll.log', $warning['context']['log_file']);
        self::assertSame(12345, $warning['context']['pid']);
        self::assertSame($lostMessage, $warning['context']['lost_message'], 'the actual lost log content must be preserved, not just a generic failure notice');
    }

    public function testFixedBehaviorDoesNothingExtraWhenTheWriteSucceeds(): void
    {
        $writableTarget = $this->scratchDir . '/binkp_poll.log';

        $spyLogger = self::spyLogger();
        $server = new AdminDaemonServer('tcp://127.0.0.1:0', 'test-secret-not-used', $spyLogger);

        $message = '[2026-09-13 05:15:15] [12345] [INFO] [1:154/10] Session completed successfully';
        $this->invokeWriteLogLineOrWarn($server, $writableTarget, 'binkp_poll.log', $message, 12345);

        self::assertSame($message . "\n", file_get_contents($writableTarget), 'a successful write must still land verbatim in the target file');
        self::assertEmpty($spyLogger->warnings, 'no warning should be emitted for a successful write');
    }

    public function testFixedBehaviorDoesNotWarnWhenTheFailingTargetIsTheDaemonsOwnLogFile(): void
    {
        // $this->logger writes to admin_daemon.log. If the failing target
        // is admin_daemon.log itself, calling $this->logger->warning() here
        // would attempt that exact same failing write again and could
        // trigger another UDP-fallback round-trip back into this same
        // handler. This must be a silent no-op instead of a recursion risk.
        $unwritableTarget = $this->scratchDir . '/admin_daemon.log';
        mkdir($unwritableTarget); // force a guaranteed, portable write failure

        $spyLogger = self::spyLogger();
        $server = new AdminDaemonServer('tcp://127.0.0.1:0', 'test-secret-not-used', $spyLogger);

        $this->invokeWriteLogLineOrWarn($server, $unwritableTarget, 'admin_daemon.log', 'lost line', 12345);

        self::assertEmpty($spyLogger->warnings, 'warning through $this->logger must be skipped for admin_daemon.log itself to avoid a self-referential fallback loop');
    }
}
