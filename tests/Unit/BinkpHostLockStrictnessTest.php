<?php

/**
 * Local, deterministic regression test for the host-lock-bypass defect
 * tracked in docs/checkpoints/BinkP_FidoAgora_InboundHang_2026-09-13.md (S2).
 *
 * Confirmed pre-fix behavior: BinkpClient::acquireHostLock() waited up to
 * 90 seconds for the flock() on a per-hostname:port lock file, and if that
 * wait expired it logged a warning and returned a handle that connect()
 * treated as "proceed without the lock" - dialing the remote host anyway
 * while another session to the same physical host was still active. That is
 * exactly the collision commit 000a9eb08 introduced the lock to prevent.
 *
 * Fixed behavior: a failure to acquire the lock (for any reason - contention
 * timeout, or the lock file being unavailable) returns null, and
 * connect() now treats null as "must not dial" - throwing
 * HostLockBusyException before any network code runs.
 *
 * This test exercises the private acquireHostLock() method directly via
 * reflection, using two separate file handles from within this one PHP
 * process to stand in for two independent processes contending for the same
 * lock path - flock() locks are associated with the open file description,
 * not the process, so this reproduces genuine OS-level lock contention
 * without pcntl_fork() or any real network I/O. Only a short, test-supplied
 * timeout is used (never the production 90s default), so this runs in
 * well under a second.
 */

use BinktermPHP\Binkp\Protocol\BinkpClient;
use PHPUnit\Framework\TestCase;

final class BinkpHostLockStrictnessTest extends TestCase
{
    private function invokeAcquire(BinkpClient $client, string $hostname, int $port, int $timeoutSeconds)
    {
        $method = new \ReflectionMethod($client, 'acquireHostLock');
        $method->setAccessible(true);
        return $method->invoke($client, $hostname, $port, $timeoutSeconds);
    }

    private function invokeRelease(BinkpClient $client, $handle, string $hostname, int $port): void
    {
        $method = new \ReflectionMethod($client, 'releaseHostLock');
        $method->setAccessible(true);
        $method->invoke($client, $handle, $hostname, $port);
    }

    public function testSecondContenderCannotAcquireWhileFirstHoldsItAndDoesNotBlockPastItsTimeout(): void
    {
        // Unique per test run so parallel/previous runs never collide.
        $hostname = 'test-hostlock-' . bin2hex(random_bytes(4)) . '.invalid';
        $port = 24554;

        $clientA = new BinkpClient();
        $clientB = new BinkpClient();

        $lockA = $this->invokeAcquire($clientA, $hostname, $port, 5);
        self::assertNotNull($lockA, 'the first contender must acquire an uncontended lock');

        $start = microtime(true);
        $lockB = $this->invokeAcquire($clientB, $hostname, $port, 1); // 1s bounded wait
        $elapsed = microtime(true) - $start;

        self::assertNull(
            $lockB,
            'a second contender must NOT acquire the lock while the first still holds it - null is the ' .
            '"do not dial" signal that BinkpClient::connect() now treats as a hard refusal, never a green light'
        );
        // acquireHostLock()'s wait loop compares whole-second time()
        // boundaries every 250ms, so a 1s requested timeout can legitimately
        // resolve in anywhere from one 250ms tick (if the wall clock's
        // integer-second boundary had already ticked over by the time of
        // the first check) up to just over 1s. The invariant that matters is
        // the upper bound - it must not hang past its requested timeout.
        self::assertLessThan(3.0, $elapsed, 'the wait must be bounded to the requested timeout, not hang indefinitely');

        $this->invokeRelease($clientA, $lockA, $hostname, $port);

        // After release, a later attempt must be able to acquire it again -
        // this proves lock release is correct and the earlier failure was
        // genuine contention, not a broken/stuck lock file.
        $clientC = new BinkpClient();
        $lockC = $this->invokeAcquire($clientC, $hostname, $port, 5);
        self::assertNotNull($lockC, 'once the first contender releases, a later attempt must be able to acquire the lock');
        $this->invokeRelease($clientC, $lockC, $hostname, $port);
    }

    /**
     * connect()'s wiring of the null-lock path to HostLockBusyException is
     * verified by source inspection rather than a live end-to-end test here:
     * connect()'s only call into acquireHostLock() uses the hardcoded 90s
     * default timeout (not overridable per-call), so a real end-to-end test
     * through connect() would have to wait out that full 90s to observe the
     * timeout path - not appropriate for this suite ("keep timeout in
     * milliseconds/seconds"). The acquireHostLock() contract itself (null
     * means "do not dial", proven above) plus the connect() diff:
     *
     *   $hostLock = $this->acquireHostLock($hostname, $port);
     *   if ($hostLock === null) {
     *       throw new HostLockBusyException(...);
     *   }
     *
     * ...is a direct, unconditional check with no other code path between
     * acquiring the lock and the throw - there is nothing left to race or
     * fall through.
     */
}
