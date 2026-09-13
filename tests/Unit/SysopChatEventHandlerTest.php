<?php

declare(strict_types=1);

require_once __DIR__ . '/../../telnet/src/TerminalEventHandlerInterface.php';
require_once __DIR__ . '/../../telnet/src/SysopChatEventHandler.php';

use BinktermPHP\TelnetServer\SysopChatEventHandler;
use PHPUnit\Framework\TestCase;

/**
 * SysopChatEventHandler — M1C terminal event handling. Pure, no DB or socket.
 */
final class SysopChatEventHandlerTest extends TestCase
{
    public function testAcceptedTransitionForExpectedPageIsSurfaced(): void
    {
        $h = new SysopChatEventHandler();
        $h->setExpectedPageId(42);
        $h->handleTerminalEvent('sysop_chat.accepted', ['page_id' => 42], 1);
        self::assertSame('sysop_chat.accepted', $h->takePendingTransition());
    }

    public function testTransitionIsReturnedOnceThenCleared(): void
    {
        $h = new SysopChatEventHandler();
        $h->setExpectedPageId(42);
        $h->handleTerminalEvent('sysop_chat.completed', ['page_id' => 42], 1);
        self::assertSame('sysop_chat.completed', $h->takePendingTransition());
        self::assertNull($h->takePendingTransition());
    }

    public function testEventForADifferentPageIsIgnored(): void
    {
        $h = new SysopChatEventHandler();
        $h->setExpectedPageId(42);
        $h->handleTerminalEvent('sysop_chat.accepted', ['page_id' => 999], 1);
        self::assertNull($h->takePendingTransition());
    }

    public function testEventWithNoExpectedPageIdIsIgnored(): void
    {
        $h = new SysopChatEventHandler();
        // No setExpectedPageId() call — not currently paging.
        $h->handleTerminalEvent('sysop_chat.accepted', ['page_id' => 42], 1);
        self::assertNull($h->takePendingTransition());
    }

    public function testUnrelatedEventTypeIsIgnored(): void
    {
        $h = new SysopChatEventHandler();
        $h->setExpectedPageId(42);
        $h->handleTerminalEvent('session.kick', ['session_id' => 'x'], 1);
        $h->handleTerminalEvent('dashboard_stats', [], 2);
        self::assertNull($h->takePendingTransition());
        self::assertFalse($h->takeHasNewMessage());
    }

    public function testMessageEventSetsNewMessageFlagNotTransition(): void
    {
        $h = new SysopChatEventHandler();
        $h->setExpectedPageId(42);
        $h->handleTerminalEvent('sysop_chat.message', ['page_id' => 42, 'message_id' => 7], 1);
        self::assertNull($h->takePendingTransition());
        self::assertTrue($h->takeHasNewMessage());
    }

    public function testNewMessageFlagIsReturnedOnceThenCleared(): void
    {
        $h = new SysopChatEventHandler();
        $h->setExpectedPageId(42);
        $h->handleTerminalEvent('sysop_chat.message', ['page_id' => 42], 1);
        self::assertTrue($h->takeHasNewMessage());
        self::assertFalse($h->takeHasNewMessage());
    }

    public function testMessageForADifferentPageDoesNotSetTheFlag(): void
    {
        $h = new SysopChatEventHandler();
        $h->setExpectedPageId(42);
        $h->handleTerminalEvent('sysop_chat.message', ['page_id' => 999], 1);
        self::assertFalse($h->takeHasNewMessage());
    }

    public function testSettingExpectedPageIdClearsAnyStalePendingState(): void
    {
        $h = new SysopChatEventHandler();
        $h->setExpectedPageId(42);
        $h->handleTerminalEvent('sysop_chat.accepted', ['page_id' => 42], 1);
        $h->handleTerminalEvent('sysop_chat.message', ['page_id' => 42], 2);

        // Moving to a new page (or leaving) must not leak the old page's
        // pending transition/message flag into the new context.
        $h->setExpectedPageId(43);
        self::assertNull($h->takePendingTransition());
        self::assertFalse($h->takeHasNewMessage());
    }

    public function testClearingExpectedPageIdIgnoresFurtherEvents(): void
    {
        $h = new SysopChatEventHandler();
        $h->setExpectedPageId(42);
        $h->setExpectedPageId(null);
        $h->handleTerminalEvent('sysop_chat.accepted', ['page_id' => 42], 1);
        self::assertNull($h->takePendingTransition());
    }

    public function testDeclinedExpiredAndCompletedAllSurfaceAsTransitions(): void
    {
        foreach (['sysop_chat.declined', 'sysop_chat.expired', 'sysop_chat.completed'] as $type) {
            $h = new SysopChatEventHandler();
            $h->setExpectedPageId(1);
            $h->handleTerminalEvent($type, ['page_id' => 1], 1);
            self::assertSame($type, $h->takePendingTransition());
        }
    }

    public function testRequestEventIsNotATransitionOrAMessage(): void
    {
        // sysop_chat.request is admin-only broadcast — a caller's own handler
        // should never treat it as anything actionable even if it somehow
        // arrived (defense in depth; server-side targeting already excludes it).
        $h = new SysopChatEventHandler();
        $h->setExpectedPageId(1);
        $h->handleTerminalEvent('sysop_chat.request', ['page_id' => 1], 1);
        self::assertNull($h->takePendingTransition());
        self::assertFalse($h->takeHasNewMessage());
    }
}
