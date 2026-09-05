<?php

declare(strict_types=1);

use BinktermPHP\TelnetServer\BbsSession;
use PHPUnit\Framework\TestCase;

// Same include order as telnet/telnet_daemon.php (telnet/src/ classes are not
// Composer-autoloaded — see telnet/CLAUDE.md). Only what BbsSession's
// dashboard rendering path transitively touches is needed here.
require_once __DIR__ . '/../../telnet/src/TelnetUtils.php';
require_once __DIR__ . '/../../telnet/src/TerminalBoxRenderer.php';
require_once __DIR__ . '/../../telnet/src/BbsSession.php';

/**
 * Terminal main-menu Crossroads signal — pure formatter contract.
 *
 * BbsSession::composeCrossroadsSignalLine() is a pure formatter over the
 * `crossroads` field already reduced server-side by DashboardPulse::compose()
 * (see routes/api-routes.php's `GET /api/dashboard/stats`); that reduction's
 * own state-priority and dedup rules are covered by CrossroadsDashboardPulseTest.
 * Here we cover only the terminal main-menu presentation contract: which
 * states earn a line, and that the line never carries a username.
 */
final class BbsSessionCrossroadsSignalTest extends TestCase
{
    private static function translator(): callable
    {
        return static function (string $key, array $params = [], string $fallback = ''): string {
            $text = $fallback !== '' ? $fallback : $key;
            foreach ($params as $name => $value) {
                $text = str_replace('{' . $name . '}', (string)$value, $text);
            }
            return $text;
        };
    }

    public function testOthersStateRendersAggregateHeadcountWithoutUsernames(): void
    {
        $line = BbsSession::composeCrossroadsSignalLine(
            ['crossroads' => ['state' => 'others', 'count' => 3]],
            self::translator()
        );

        self::assertSame('3 people out there', $line);
    }

    public function testOthersStateWithSingleDistinctPersonUsesSingularCopy(): void
    {
        $line = BbsSession::composeCrossroadsSignalLine(
            ['crossroads' => ['state' => 'others', 'count' => 1]],
            self::translator()
        );

        self::assertSame('1 person out there', $line);
    }

    public function testOthersStateWithZeroOrMissingCountRendersNothing(): void
    {
        self::assertNull(BbsSession::composeCrossroadsSignalLine(
            ['crossroads' => ['state' => 'others', 'count' => 0]],
            self::translator()
        ));
        self::assertNull(BbsSession::composeCrossroadsSignalLine(
            ['crossroads' => ['state' => 'others']],
            self::translator()
        ));
    }

    public function testRecentSelfStateRendersHistoricalContinuity(): void
    {
        $line = BbsSession::composeCrossroadsSignalLine(
            ['crossroads' => ['state' => 'recent_self', 'experience_name' => 'MultiZork']],
            self::translator()
        );

        self::assertSame('Last in MultiZork', $line);
    }

    public function testRecentSelfStateWithoutExperienceNameRendersNothing(): void
    {
        self::assertNull(BbsSession::composeCrossroadsSignalLine(
            ['crossroads' => ['state' => 'recent_self', 'experience_name' => '']],
            self::translator()
        ));
        self::assertNull(BbsSession::composeCrossroadsSignalLine(
            ['crossroads' => ['state' => 'recent_self']],
            self::translator()
        ));
    }

    /**
     * Generic community `recent` activity is deliberately not shown at the
     * main menu — "Recently in the Crossroads" already covers it inside
     * Crossroads itself. Space at the main menu is scarce.
     */
    public function testGenericRecentStateRendersNothing(): void
    {
        self::assertNull(BbsSession::composeCrossroadsSignalLine(
            ['crossroads' => ['state' => 'recent', 'username' => 'Skrawl', 'experience_name' => 'Usurper Reborn']],
            self::translator()
        ));
    }

    public function testParticipatingStateRendersNothing(): void
    {
        self::assertNull(BbsSession::composeCrossroadsSignalLine(
            ['crossroads' => ['state' => 'participating']],
            self::translator()
        ));
    }

    public function testQuietStateRendersNothing(): void
    {
        self::assertNull(BbsSession::composeCrossroadsSignalLine(
            ['crossroads' => ['state' => 'quiet']],
            self::translator()
        ));
    }

    /**
     * A malformed or missing payload (default-degraded dashboard stats,
     * a stale terminal client, a failed API call) must never break the
     * main-menu dashboard — it simply means no signal shows.
     */
    public function testMissingOrMalformedCrossroadsPayloadRendersNothing(): void
    {
        self::assertNull(BbsSession::composeCrossroadsSignalLine([], self::translator()));
        self::assertNull(BbsSession::composeCrossroadsSignalLine(['crossroads' => null], self::translator()));
        self::assertNull(BbsSession::composeCrossroadsSignalLine(['crossroads' => 'not-an-array'], self::translator()));
        self::assertNull(BbsSession::composeCrossroadsSignalLine(['crossroads' => ['state' => 'unexpected-future-state']], self::translator()));
    }

    // ---- renderDashboardSidebar(): vertical-space invariant -----------------

    /**
     * At the exact boundary where the sidebar has room for netmail, echomail,
     * online, bulletins, and credits but NOT one more divider+row group, the
     * Crossroads row must be dropped — same as the existing bulletins/credits
     * priority-drop behavior — rather than rendered past the box's own
     * accounted budget. This is the invariant the `$rowsUsed += 2` fix (added
     * alongside the credits block) protects: without it, `$rowsUsed` under-
     * counts once credits are shown, the Crossroads admission check becomes
     * wrongly permissive, and the panel overflows its accounted row budget —
     * pushing the closing bottom border past the hard terminal-row clamp in
     * the write loop, so the box renders without ever closing.
     */
    public function testCrossroadsRowDroppedAtVerticalBudgetBoundaryKeepsBoxClosed(): void
    {
        $conn = fopen('php://memory', 'r+');
        self::assertIsResource($conn);

        $session = new BbsSession($conn, 'http://example.invalid', false, false, false, false);

        $method = new ReflectionMethod(BbsSession::class, 'renderDashboardSidebar');
        $method->setAccessible(true);

        $state = ['locale' => 'en'];
        $stats = [
            'unread_netmail'   => 0,
            'new_echomail'     => 0,
            'online_count'     => 0,
            'unread_bulletins' => 0,
            'credit_balance'   => 100, // non-null: occupies the last 2-row group before Crossroads
            'crossroads'       => ['state' => 'others', 'count' => 3],
        ];

        // panelWidth=30 -> innerWidth=28; boxStartRow=1; termRows=13, maxRow=100
        // (not binding) -> availRows = min(100,11) - 1 + 1 = 11. Netmail+echomail
        // (rowsUsed=6) + online (+2=8) + bulletins (+1=9) + credits (+2=11) exactly
        // exhausts the 11-row budget, leaving no room for Crossroads (+2=13 > 11).
        $method->invoke($session, $conn, $state, $stats, 1, 30, 1, 100, 13);

        rewind($conn);
        $output = stream_get_contents($conn);
        fclose($conn);

        self::assertStringNotContainsString('Crossroads', $output);
        self::assertStringContainsString('+' . str_repeat('-', 28) . '+', $output);
    }
}
