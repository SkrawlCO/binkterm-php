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
 * M1D: MRC Terminal Convergence's final caller-facing "Inter-BBS Chat" entry.
 *
 * These tests exercise the REAL live declarative config
 * (config/terminal_navigation.json) plus the real TerminalActionCatalog
 * registry -- not a synthetic fixture -- because the whole point of this
 * slice is proving the final placement is wired into production's actual
 * navigation source of truth: reachable by any authenticated caller under
 * People, not admin-only under root, with the temporary "mrc_test" action
 * fully retired.
 */
final class MrcNavigationEntryTest extends TestCase
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

    private static function peopleNode(): NavigationNode
    {
        $result = NavigationConfig::load();
        self::assertTrue($result->isOk());

        return $result->definition()->node('people');
    }

    private static function itemById(NavigationNode $node, string $id): ?NavigationItem
    {
        foreach ($node->items as $item) {
            if ($item->id === $id) {
                return $item;
            }
        }

        return null;
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

    private static function context(bool $authenticated): AccessContext
    {
        return new AccessContext(
            authenticated: $authenticated,
            admin: false,
            guest: !$authenticated,
            featureResolver: fn (string $name) => true,
            actionResolver: fn (string $id) => TerminalActionCatalog::defaultRegistry()->has($id),
            capabilities: ['color' => true],
        );
    }

    // 1 & 2: the mrc action exists in the catalog; mrc_test does not.

    public function testMrcActionExistsAndMrcTestDoesNot(): void
    {
        $registry = TerminalActionCatalog::defaultRegistry();

        self::assertTrue($registry->has('mrc'), 'the final "mrc" action must be registered');
        self::assertFalse($registry->has('mrc_test'), 'the temporary "mrc_test" action must no longer exist');
    }

    // 3 & 4: an authenticated caller can access mrc; a non-authenticated (guest) caller cannot.

    public function testAuthenticatedCallerCanAccessMrcNonAuthenticatedCannot(): void
    {
        $registry = TerminalActionCatalog::defaultRegistry();
        $action = $registry->get('mrc');

        self::assertTrue($action->availability->evaluate(self::context(true)), 'an authenticated caller must be able to use mrc');
        self::assertFalse($action->availability->evaluate(self::context(false)), 'a non-authenticated caller must not be able to use mrc');
        self::assertFalse($action->terminates);
    }

    // 5: final location is the People submenu (not the root menu).

    public function testMrcItemLivesInThePeopleSubmenu(): void
    {
        $people = self::peopleNode();
        $item = self::itemById($people, 'mrc');

        self::assertNotNull($item, 'the "mrc" item must exist on the People submenu');
        self::assertSame('Inter-BBS Chat', $item->labelFallback);
        self::assertTrue($item->isAction());
        self::assertFalse($item->isSubmenu());
        self::assertTrue($item->access->evaluate(self::context(true)), 'authenticated caller must see Inter-BBS Chat');
        self::assertFalse($item->access->evaluate(self::context(false)), 'non-authenticated caller must not see Inter-BBS Chat');

        $root = self::rootNode();
        self::assertNull(self::itemById($root, 'mrc'), 'mrc must not also exist as a root item');
    }

    // 6: hotkey "i" resolves to mrc within the People submenu specifically.

    public function testHotkeyIResolvesToMrcWithinPeople(): void
    {
        $item = self::itemByHotkey(self::peopleNode(), 'i');

        self::assertNotNull($item, 'hotkey "i" must resolve to an item on the People submenu');
        self::assertSame('mrc', $item->id);
    }

    // 7: existing People hotkeys w/c/s/p are unchanged.

    public function testExistingPeopleHotkeysAreUnchanged(): void
    {
        $people = self::peopleNode();

        $expected = [
            'w' => 'whosonline',
            'c' => 'localchat',
            's' => 'shoutbox',
            'p' => 'polls',
        ];

        foreach ($expected as $hotkey => $expectedId) {
            $item = self::itemByHotkey($people, $hotkey);
            self::assertNotNull($item, "hotkey \"{$hotkey}\" must still resolve to an item");
            self::assertSame($expectedId, $item->id, "hotkey \"{$hotkey}\" must still resolve to \"{$expectedId}\"");
        }
    }

    // 8: root "M" still resolves to Messages.

    public function testRootHotkeyMStillResolvesToMessages(): void
    {
        $item = self::itemByHotkey(self::rootNode(), 'm');

        self::assertNotNull($item);
        self::assertSame('messages', $item->id);
        self::assertSame('Messages', $item->labelFallback);
    }

    // 9: root no longer has any temporary "R -- MRC Test" item, and the
    // existing top-level hotkeys are otherwise unchanged.

    public function testRootHasNoTemporaryMrcTestEntry(): void
    {
        $root = self::rootNode();

        self::assertNull(self::itemById($root, 'mrc_test'), 'the temporary mrc_test item must be gone from root');
        self::assertNull(self::itemByHotkey($root, 'r'), 'root hotkey "r" must be free again (mrc_test removed, not reassigned)');

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
    }

    // 10: BbsSession binds "mrc" to MrcChatHandler::participate(), and no
    // longer binds "mrc_test" to anything.

    public function testBbsSessionBindsMrcToMrcChatHandlerParticipate(): void
    {
        $source = file_get_contents(__DIR__ . '/../../telnet/src/BbsSession.php');
        self::assertNotFalse($source);

        self::assertMatchesRegularExpression(
            "/'mrc'\\s*=>\\s*fn\\s*\\(\\)\\s*=>\\s*\\(new MrcChatHandler\\(/",
            $source,
            'BbsSession handlers map must bind mrc to a new MrcChatHandler(...) closure'
        );
        self::assertMatchesRegularExpression(
            '/\)\)->participate\(\$conn,\s*\$state\)/',
            $source,
            'the mrc closure must call ->participate($conn, $state)'
        );
        self::assertStringNotContainsString(
            "'mrc_test'",
            $source,
            'the temporary mrc_test handler binding must be gone'
        );

        // The class it wires up is still require_once'd in both daemons.
        foreach (['telnet/telnet_daemon.php', 'ssh/ssh_daemon.php'] as $daemon) {
            $daemonSource = file_get_contents(__DIR__ . '/../../' . $daemon);
            self::assertNotFalse($daemonSource);
            self::assertStringContainsString('MrcChatHandler.php', $daemonSource, "{$daemon} must require_once MrcChatHandler.php");
        }
    }

    // 11: a normal (non-admin) authenticated caller can see Inter-BBS Chat --
    // re-asserted explicitly since this is the whole point of M1D.

    public function testNormalAuthenticatedCallerSeesInterBbsChat(): void
    {
        $item = self::itemById(self::peopleNode(), 'mrc');
        self::assertNotNull($item);

        $normalCaller = new AccessContext(
            authenticated: true,
            admin: false,
            guest: false,
            featureResolver: fn (string $name) => true,
            actionResolver: fn (string $id) => TerminalActionCatalog::defaultRegistry()->has($id),
            capabilities: ['color' => true],
        );

        self::assertTrue($item->access->evaluate($normalCaller), 'a normal, non-admin authenticated caller must see Inter-BBS Chat');
    }

    // 12: no unrelated navigation changed -- Messages submenu items and the
    // rest of People are untouched by this slice.

    public function testUnrelatedNavigationIsUnchanged(): void
    {
        $result = NavigationConfig::load();
        self::assertTrue($result->isOk());
        $messages = $result->definition()->node('messages');

        $expected = [
            'a' => 'whatsnew',
            'n' => 'netmail',
            'e' => 'echomail',
            'b' => 'bulletins',
            'k' => 'qwk',
        ];
        foreach ($expected as $hotkey => $expectedId) {
            $item = self::itemByHotkey($messages, $hotkey);
            self::assertNotNull($item, "Messages hotkey \"{$hotkey}\" must still resolve to an item");
            self::assertSame($expectedId, $item->id);
        }

        $people = self::peopleNode();
        self::assertCount(5, $people->items, 'People must have exactly the 4 pre-existing items plus mrc');
    }
}
