<?php

/**
 * Local, deterministic regression test for the timeout-recorded-as-success
 * defect tracked in docs/checkpoints/BinkP_FidoAgora_InboundHang_2026-09-13.md
 * (S3).
 *
 * Confirmed pre-fix contract: BinkpSession::processSession()'s main
 * "wait for session termination" loop can end four different ways:
 *
 *   1. the peer-close-first grace period completing (clean)         -> sets STATE_TERMINATED
 *   2. the peer closing after both sides exchanged M_EOB (clean)    -> sets STATE_TERMINATED
 *   3. the hard EOB/inactivity timeout expiring (abnormal)          -> breaks WITHOUT setting STATE_TERMINATED
 *   4. the peer closing before the EOB exchange completed (abnormal)-> breaks WITHOUT setting STATE_TERMINATED
 *
 * Pre-fix, the method returned `true` unconditionally after this loop
 * (unless an exception was thrown), so outcomes 3 and 4 were logged as
 * "Session ended (final state: N)" but still reported success to every
 * caller (BinkpClient::connect(), BinkpServer's answerer path) and to the
 * session log (`endSession('success')`) - exactly the defect responsible
 * for a 300-second, zero-byte, authenticated-but-stalled session on the
 * incident date being recorded as `success` in `binkp_session_log`.
 *
 * Fixed contract: the return value is now keyed off the actual final
 * state - `true` only when `STATE_TERMINATED` was genuinely reached (paths
 * 1-2 above), `false` for anything else (paths 3-4), matching what the
 * WARNING-level log line already said.
 *
 * This test exercises path 3 (the hard EOB/inactivity timeout) end-to-end
 * with a real (but held-open, unresponsive) mock peer via
 * socket_create_pair(AF_UNIX, ...) + pcntl_fork() - no real network, no
 * production config, no external peer. Clean-path regression coverage
 * (outcomes 1-2 still correctly returning true) is provided by the
 * existing BinkpOriginatorReceiveWindowIncidentTest Cases A and B, which
 * this change does not alter (re-run as collateral, still green). The
 * exception path (`catch (\Exception $e) { ...; return false; }`) is
 * untouched by this diff and is not re-tested here.
 *
 * NOTE ON TIMING: processSession() floors its EOB/inactivity timeout at
 * `max(30, (int) $config->getBinkpTimeout())` - this floor is existing,
 * unmodified production behavior (see "Do NOT change the configured
 * timeout value" in the S3 instructions), so this test's mock peer must
 * stay silent for at least that long and genuinely takes ~30s wall time.
 * That is still bounded and deterministic, and nowhere near the original
 * incident's 300-second hang.
 */

use BinktermPHP\Binkp\Protocol\BinkpSession;
use PHPUnit\Framework\TestCase;

final class BinkpSessionAbnormalTimeoutTest extends TestCase
{
    protected function setUp(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl extension required to drive the mock peer process');
        }
    }

    private function makeConfigStub(int $binkpTimeout, string $inboundPath, string $outboundPath): object
    {
        return new class ($binkpTimeout, $inboundPath, $outboundPath) {
            public function __construct(
                private int $timeout,
                private string $inbound,
                private string $outbound
            ) {
            }
            public function getBinkpTimeout()
            {
                return $this->timeout;
            }
            public function getInboundPath()
            {
                return $this->inbound;
            }
            public function getOutboundPath()
            {
                return $this->outbound;
            }
            public function getPreserveSentPackets()
            {
                return false;
            }
        };
    }

    private static function silentLogger(): object
    {
        return new class {
            public array $lines = [];
            public function log($level, $message, $context = [])
            {
                $this->lines[] = "[{$level}] {$message}";
            }
        };
    }

    /** @return array{0:\Socket,1:\Socket} */
    private static function makeSocketPair(): array
    {
        $pair = [];
        $ok = socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        self::assertTrue($ok, 'socket_create_pair() failed (in-process, no network involved)');
        return $pair;
    }

    /**
     * Forks a mock peer that authenticates nothing, sends nothing, and never
     * closes its end - it simply holds the connection open and silent long
     * enough to outlast our session's hard timeout, so our side is forced to
     * give up on its own via the EOB/inactivity timeout rather than seeing a
     * clean close or any frame at all.
     */
    private function forkSilentPeer(\Socket $peerSocketEnd, int $holdSeconds): int
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            self::fail('pcntl_fork() failed');
        }
        if ($pid === 0) {
            // Child: silent peer. Keep the fd open and idle; never write, never close.
            sleep($holdSeconds);
            exit(0);
        }
        return $pid;
    }

    public function testHardEobInactivityTimeoutIsReportedAsFailureNotSuccess(): void
    {
        [$sessionEnd, $peerEnd] = self::makeSocketPair();
        $sessionStream = socket_export_stream($sessionEnd);
        stream_set_blocking($sessionStream, true);
        stream_set_timeout($sessionStream, 40);

        $inbound = sys_get_temp_dir() . '/binkp_abnormal_timeout_inbound_' . bin2hex(random_bytes(4));
        $outbound = sys_get_temp_dir() . '/binkp_abnormal_timeout_outbound_' . bin2hex(random_bytes(4));
        mkdir($inbound, 0777, true);
        mkdir($outbound, 0777, true);

        // Held open well past the ~30s floor so it's still silent when our
        // side gives up; killed explicitly afterward rather than waited out.
        $childPid = $this->forkSilentPeer($peerEnd, 40);

        // The configured timeout value itself (1) is deliberately tiny to
        // prove this test does NOT depend on a large configured value - the
        // production max(30, ...) floor in processSession() is what actually
        // governs the wait, unmodified by this fix.
        $config = $this->makeConfigStub(1, $inbound, $outbound);
        $logger = self::silentLogger();

        $session = new BinkpSession($sessionStream, true /* originator */, $config);
        $session->setLogger($logger);

        $start = microtime(true);
        $result = $session->processSession();
        $elapsed = microtime(true) - $start;

        posix_kill($childPid, SIGKILL);
        pcntl_waitpid($childPid, $status);
        fclose($sessionStream);
        foreach (glob($inbound . '/*') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($outbound . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($inbound);
        @rmdir($outbound);

        self::assertFalse(
            $result,
            'processSession() must NOT report success for a session that never reached clean termination - ' .
            'this is the exact defect that let a 300-second, zero-byte, stalled session be recorded as `success`'
        );

        self::assertGreaterThanOrEqual(
            29.0,
            $elapsed,
            'the hard EOB/inactivity timeout floor (max(30, ...) in processSession()) must actually be honored, not bypassed'
        );
        self::assertLessThan(
            60.0,
            $elapsed,
            'must be bounded well under the original 300-second incident duration, not hang indefinitely'
        );

        $sawTimeoutWarning = false;
        $sawAbnormalEnd = false;
        foreach ($logger->lines as $line) {
            if (str_contains($line, 'timeout')) {
                $sawTimeoutWarning = true;
            }
            if (str_contains($line, 'Session ended abnormally')) {
                $sawAbnormalEnd = true;
            }
        }
        self::assertTrue($sawTimeoutWarning, 'the EOB/inactivity timeout must still be logged (unchanged diagnostic)');
        self::assertTrue($sawAbnormalEnd, 'the final outcome must be logged distinctly from "Session completed successfully"');
    }
}
