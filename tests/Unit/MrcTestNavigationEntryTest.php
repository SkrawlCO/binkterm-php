<?php

declare(strict_types=1);

require_once __DIR__ . '/../../telnet/src/OutputSink.php';
require_once __DIR__ . '/../../telnet/src/BufferSink.php';
require_once __DIR__ . '/../../telnet/src/GlyphPolicy.php';
require_once __DIR__ . '/../../telnet/src/TerminalCapabilities.php';
require_once __DIR__ . '/../../telnet/src/TerminalRenderContext.php';

use BinktermPHP\Terminal\Navigation\AccessContext;
use BinktermPHP\Terminal\Navigation\NavigationConfig;
use BinktermPHP\Terminal\Navigation\NavigationItem;
use BinktermPHP\Terminal\Navigation\NavigationNode;
use BinktermPHP\Terminal\Navigation\TerminalActionCatalog;
use PHPUnit\Framework\TestCase;

/**
 * M1C-2 admin-only "MRC Test" human-test navigation entry.
 *
 * These tests exercise the REAL live declarative config
 * (config/terminal_navigation.json) plus the real TerminalActionCatalog
 * registry -- not a synthetic fixture -- because the whole point of this
 * slice is proving the entry is wired into production's actual navigation
 * source of truth, gated end-to-end on admin, without disturbing any
 * existing hotkey (most importantly "M" = Messages).
 *
 * This is a TEMPORARY human-test entry for M1C-2. Not final M1D placement.
 */
final class MrcTestNavigationEntryTest extends TestCase
{
    private static function rootNode(): NavigationNode
    {
        $result = NavigationConfig::load();
        self::assertTrue(
            $result->isOk(),
            'live config/terminal_navigation.json must validate clean: ' . $result->errorSummary()
        );

        return $result->definition()->root();
    }

    private static function itemById(NavigationNode $node, string $id): NavigationItem
    {
        foreach ($node->items as $item) {
            if ($item->id === $id) {
                return $item;
            }
        }
        self::fail("no item with id \"{$id}\" on node \"{$node->id}\"");
    }

    private static function itemByHotkey(NavigationNode $node, string $hotkey): ?NavigationItem
    {
        foreach ($node->items as $item) {
            if ($item->hotkey === $hotkey) {
                return $item;
            }
        }

        return null;
    }

    private static function context(bool $admin): AccessContext
    {
        return new AccessContext(
            authenticated: true,
            admin: $admin,
            guest: false,
            featureResolver: fn (string $name) => true,
            actionResolver: fn (string $id) => TerminalActionCatalog::defaultRegistry()->has($id),
            capabilities: ['color' => true],
        );
    }

    // 1. admin sees MRC Test ==================================================

    public function testAdminSeesMrcTestItemOnRootMenu(): void
    {
        $item = self::itemById(self::rootNode(), 'mrc_test');

        self::assertTrue($item->access->evaluate(self::context(true)), 'admin must see the mrc_test item');
        self::assertSame('r', $item->hotkey);
        self::assertSame('MRC Test', $item->labelFallback);
        self::assertTrue($item->isAction());
        self::assertFalse($item->isSubmenu());
    }

    // 2. non-admin does not ===================================================

    public function testNonAdminDoesNotSeeMrcTestItem(): void
    {
        $item = self::itemById(self::rootNode(), 'mrc_test');

        self::assertFalse($item->access->evaluate(self::context(false)), 'non-admin must not see the mrc_test item');
    }

    // 3. R resolves to mrc_test only for admin ================================

    public function testHotkeyRResolvesToMrcTestItemAndIsGatedOnAdmin(): void
    {
        $root = self::rootNode();
        $item = self::itemByHotkey($root, 'r');

        self::assertNotNull($item, 'hotkey "r" must resolve to an item on the root menu');
        self::assertSame('mrc_test', $item->id);

        // The item exists at the "r" hotkey regardless of caller, but its own
        // access gate is what a real screen builder uses to decide whether it
        // is ever shown/selectable to a non-admin caller.
        self::assertTrue($item->access->evaluate(self::context(true)));
        self::assertFalse($item->access->evaluate(self::context(false)));
    }

    // 4. M still resolves to Messages ==========================================

    public function testMHotkeyStillResolvesToMessages(): void
    {
        $item = self::itemByHotkey(self::rootNode(), 'm');

        self::assertNotNull($item, 'hotkey "m" must still exist on the root menu');
        self::assertSame('messages', $item->id);
        self::assertSame('Messages', $item->labelFallback);
        self::assertTrue($item->isSubmenu());
        self::assertSame('messages', $item->submenu);
    }

    // 5. existing C/L/X/M/P/S/Q behaviour unchanged ===========================

    public function testExistingTopLevelHotkeysAreUnchanged(): void
    {
        $root = self::rootNode();

        $expected = [
            'c' => 'crossroads',
            'l' => 'library',
            'x' => 'explore',
            'm' => 'messages',
            'p' => 'people',
            's' => 'settings',
            'q' => 'logoff',
        ];

        foreach ($expected as $hotkey => $expectedId) {
            $item = self::itemByHotkey($root, $hotkey);
            self::assertNotNull($item, "hotkey \"{$hotkey}\" must resolve to an item");
            self::assertSame($expectedId, $item->id, "hotkey \"{$hotkey}\" must still resolve to \"{$expectedId}\"");
        }

        // And none of those pre-existing items were switched to admin-only.
        foreach (['crossroads', 'library', 'explore', 'messages', 'people', 'settings', 'logoff'] as $id) {
            $item = self::itemById($root, $id);
            self::assertTrue(
                $item->access->evaluate(self::context(false)) || $id === 'crossroads',
                "\"{$id}\" access must not have been narrowed to admin-only"
            );
        }
    }

    // 6. action registry/binding resolves mrc_test ============================

    public function testActionRegistryResolvesMrcTestAsAdminGated(): void
    {
        $registry = TerminalActionCatalog::defaultRegistry();

        self::assertTrue($registry->has('mrc_test'), 'the "mrc_test" action must be registered in TerminalActionCatalog');

        $action = $registry->get('mrc_test');
        self::assertTrue($action->availability->evaluate(self::context(true)), 'mrc_test action must be available to admin');
        self::assertFalse($action->availability->evaluate(self::context(false)), 'mrc_test action must NOT be available to non-admin');
        self::assertFalse($action->terminates, 'mrc_test must not be a terminating action like quit');
    }

    // 7. invoking the action reaches MrcChatHandler participation path ========

    public function testMrcTestActionIsWiredToMrcChatHandlerParticipate(): void
    {
        $source = file_get_contents(__DIR__ . '/../../telnet/src/BbsSession.php');
        self::assertNotFalse($source);

        // The declarative-nav $handlers map must bind 'mrc_test' to a closure
        // that constructs MrcChatHandler and calls ->participate(...) -- the
        // same real participation lifecycle M1C-1/M1C-2 already cover, not a
        // reimplementation and not the read-only ->show() viewer.
        self::assertMatchesRegularExpression(
            "/'mrc_test'\\s*=>\\s*fn\\s*\\(\\)\\s*=>\\s*\\(new MrcChatHandler\\(/",
            $source,
            'BbsSession handlers map must bind mrc_test to a new MrcChatHandler(...) closure'
        );
        self::assertMatchesRegularExpression(
            '/\)\)->participate\(\$conn,\s*\$state\)/',
            $source,
            'the mrc_test closure must call ->participate($conn, $state), not ->show(...)'
        );

        // And the class it wires up is genuinely require_once'd in both
        // daemons now that production actually invokes it.
        foreach (['telnet/telnet_daemon.php', 'ssh/ssh_daemon.php'] as $daemon) {
            $daemonSource = file_get_contents(__DIR__ . '/../../' . $daemon);
            self::assertNotFalse($daemonSource);
            self::assertStringContainsString(
                'MrcChatHandler.php',
                $daemonSource,
                "{$daemon} must require_once MrcChatHandler.php"
            );
        }
    }
}
