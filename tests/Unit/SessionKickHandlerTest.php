<?php

declare(strict_types=1);

require_once __DIR__ . '/../../telnet/src/TerminalEventHandlerInterface.php';
require_once __DIR__ . '/../../telnet/src/SessionKickHandler.php';

use BinktermPHP\TelnetServer\SessionKickHandler;
use PHPUnit\Framework\TestCase;

/**
 * Session-kick handler — Slice A. Pure, no DB or socket.
 */
final class SessionKickHandlerTest extends TestCase
{
    public function testMatchingSessionIdWithKnownCodeTerminates(): void
    {
        $h = new SessionKickHandler('sess-abc');
        $h->handleTerminalEvent('session.kick', ['session_id' => 'sess-abc', 'code' => 'revoked'], 1);
        self::assertSame('revoked', $h->takeTerminationCode());
    }

    public function testCodeIsReturnedOnceThenCleared(): void
    {
        $h = new SessionKickHandler('sess-abc');
        $h->handleTerminalEvent('session.kick', ['session_id' => 'sess-abc', 'code' => 'revoked_all'], 1);
        self::assertSame('revoked_all', $h->takeTerminationCode());
        self::assertNull($h->takeTerminationCode());
    }

    public function testDifferentSessionIdForSameUserIsIgnored(): void
    {
        $h = new SessionKickHandler('sess-abc');
        $h->handleTerminalEvent('session.kick', ['session_id' => 'sess-other', 'code' => 'revoked'], 1);
        self::assertNull($h->takeTerminationCode());
    }

    public function testUnrelatedEventTypeIsIgnored(): void
    {
        $h = new SessionKickHandler('sess-abc');
        $h->handleTerminalEvent('chat_message', ['session_id' => 'sess-abc'], 1);
        $h->handleTerminalEvent('dashboard_stats', ['session_id' => 'sess-abc', 'code' => 'revoked'], 2);
        self::assertNull($h->takeTerminationCode());
    }

    public function testUnknownOrAbsentCodeCollapsesToGenericAndNeverEchoesText(): void
    {
        foreach (
            [
                ['session_id' => 'sess-abc', 'code' => 'you are a spammer, goodbye'],
                ['session_id' => 'sess-abc', 'code' => ''],
                ['session_id' => 'sess-abc'],
                ['session_id' => 'sess-abc', 'code' => '<script>alert(1)</script>'],
            ] as $payload
        ) {
            $h = new SessionKickHandler('sess-abc');
            $h->handleTerminalEvent('session.kick', $payload, 1);
            self::assertSame(SessionKickHandler::CODE_GENERIC, $h->takeTerminationCode());
        }
    }

    public function testMissingSessionIdIsIgnored(): void
    {
        $h = new SessionKickHandler('sess-abc');
        $h->handleTerminalEvent('session.kick', ['code' => 'revoked'], 1);
        self::assertNull($h->takeTerminationCode());
    }

    public function testAdminRevokedCodePassesThroughTheAllowList(): void
    {
        $h = new SessionKickHandler('sess-abc');
        $h->handleTerminalEvent('session.kick', ['session_id' => 'sess-abc', 'code' => 'admin_revoked'], 1);
        self::assertSame('admin_revoked', $h->takeTerminationCode());
    }
}
