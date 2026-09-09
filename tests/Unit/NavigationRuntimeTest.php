<?php

declare(strict_types=1);

require_once __DIR__ . '/../../telnet/src/OutputSink.php';
require_once __DIR__ . '/../../telnet/src/BufferSink.php';
require_once __DIR__ . '/../../telnet/src/GlyphPolicy.php';
require_once __DIR__ . '/../../telnet/src/TerminalCapabilities.php';
require_once __DIR__ . '/../../telnet/src/TerminalRenderContext.php';

use BinktermPHP\I18n\Translator;
use BinktermPHP\TelnetServer\BufferSink;
use BinktermPHP\TelnetServer\TerminalCapabilities;
use BinktermPHP\TelnetServer\TerminalRenderContext;
use BinktermPHP\Terminal\Navigation\AccessContext;
use BinktermPHP\Terminal\Navigation\NavigationDefinition;
use BinktermPHP\Terminal\Navigation\NavigationRuntime;
use BinktermPHP\Terminal\Navigation\NavigationScreenBuilder;
use BinktermPHP\Terminal\Navigation\NavigationScreenModel;
use BinktermPHP\Terminal\Navigation\NavigationScreenRenderer;
use BinktermPHP\Terminal\Navigation\TerminalActionCatalog;
use PHPUnit\Framework\TestCase;

/**
 * R5F + R5G — the interactive navigation runtime: hotkeys, arrow/lightbar,
 * nested Back/Home, action invocation, geometry reflow, clean exit.
 */
final class NavigationRuntimeTest extends TestCase
{
    /** @var array<int,string> */
    private array $invoked = [];
    /** @var array<int,string> */
    private array $rendered = [];

    private function nestedDefinition(): NavigationDefinition
    {
        return NavigationDefinition::fromArray([
            'schema' => 1, 'id' => 'demo', 'root' => 'main',
            'nodes' => [
                ['id' => 'main', 'label_fallback' => 'Main', 'items' => [
                    ['id' => 'messages', 'label_fallback' => 'Messages', 'hotkey' => 'm', 'submenu' => 'messages'],
                    ['id' => 'chat', 'label_fallback' => 'Chat', 'hotkey' => 'c', 'action' => 'localchat'],
                    ['id' => 'quit', 'label_fallback' => 'Quit', 'hotkey' => 'q', 'action' => 'quit'],
                ]],
                ['id' => 'messages', 'label_fallback' => 'Messages', 'items' => [
                    ['id' => 'netmail', 'label_fallback' => 'Netmail', 'hotkey' => 'n', 'action' => 'netmail'],
                    ['id' => 'echomail', 'label_fallback' => 'Echomail', 'hotkey' => 'e', 'action' => 'echomail'],
                    ['id' => 'more', 'label_fallback' => 'Advanced', 'hotkey' => 'a', 'submenu' => 'advanced'],
                ]],
                ['id' => 'advanced', 'label_fallback' => 'Advanced', 'items' => [
                    ['id' => 'settings', 'label_fallback' => 'Settings', 'hotkey' => 's', 'action' => 'settings'],
                ]],
            ],
        ]);
    }

    /**
     * @param array<int,string> $tokens
     */
    private function drive(NavigationDefinition $def, array $tokens, ?TerminalRenderContext $ctx = null): string
    {
        $this->invoked  = [];
        $this->rendered = [];

        $registry = TerminalActionCatalog::defaultRegistry();
        foreach ($registry->ids() as $id) {
            $registry->bind($id, function ($context, array $params) use ($id) {
                $this->invoked[] = $id;
            });
        }

        $builder = new NavigationScreenBuilder(
            $registry,
            fn (?string $k, string $fallback, string $l) => $fallback,
        );

        $ctx ??= $this->context(80, 24);
        $access = new AccessContext(true, false, false, fn () => true, fn () => true, ['color' => true]);

        $queue = $tokens;
        $readToken = function () use (&$queue): array {
            if ($queue === []) {
                return [null, false, true]; // disconnect once the script is exhausted
            }

            return [array_shift($queue), false, false];
        };

        $runtime = new NavigationRuntime($def, $registry, $builder, new NavigationScreenRenderer());

        return $runtime->run(
            $readToken,
            $ctx,
            $access,
            'INVOKE_CTX',
            'en',
            function (NavigationScreenModel $screen) {
                $this->rendered[] = $screen->title;
            },
        );
    }

    private function context(int $cols, int $rows): TerminalRenderContext
    {
        return new TerminalRenderContext(
            new BufferSink(),
            TerminalCapabilities::unknown()->withColorSupport(TerminalCapabilities::COLOR_ANSI),
            $cols,
            $rows,
            'utf8',
            true,
            false,
            [],
            'en',
            new Translator(),
        );
    }

    // ===== tests =====

    public function testHotkeySelectsAnActionAndReturnsToTheSameScreen(): void
    {
        $exit = $this->drive($this->nestedDefinition(), ['CHAR:c', 'CHAR:q']);

        self::assertSame(NavigationRuntime::EXIT_QUIT, $exit);
        self::assertSame(['localchat'], $this->invoked);
    }

    public function testSubmenuNavigationAndBack(): void
    {
        $exit = $this->drive($this->nestedDefinition(), [
            'CHAR:m',   // into Messages
            'CHAR:n',   // invoke netmail
            'LEFT',     // back to Main
            'CHAR:q',
        ]);

        self::assertSame(NavigationRuntime::EXIT_QUIT, $exit);
        self::assertSame(['netmail'], $this->invoked);
        self::assertContains('Messages', $this->rendered);
        self::assertContains('Main', $this->rendered);
        self::assertSame('Main', $this->rendered[count($this->rendered) - 1], 'last render before quit is the root again');
    }

    public function testHomeJumpsToRootFromDepth(): void
    {
        $exit = $this->drive($this->nestedDefinition(), [
            'CHAR:m',   // Messages
            'CHAR:a',   // Advanced
            'CHAR:h',   // Home -> Main
            'CHAR:q',
        ]);

        self::assertSame(NavigationRuntime::EXIT_QUIT, $exit);
        self::assertContains('Advanced', $this->rendered);
        self::assertSame('Main', $this->rendered[count($this->rendered) - 1]);
    }

    public function testArrowKeysAndEnterDriveTheLightbar(): void
    {
        // main selectable order: [messages, chat, quit]
        $exit = $this->drive($this->nestedDefinition(), [
            'DOWN', 'DOWN', 'ENTER', // -> quit
        ]);

        self::assertSame(NavigationRuntime::EXIT_QUIT, $exit);
        self::assertSame([], $this->invoked, 'quit terminates, never invoked as a bound action');
    }

    public function testEnterOnASubmenuDescends(): void
    {
        $exit = $this->drive($this->nestedDefinition(), [
            'ENTER',      // messages (cursor 0)
            'DOWN',       // echomail
            'ENTER',      // invoke echomail
            'LEFT',       // back to Main (Q is not a submenu quit key)
            'CHAR:q',     // Main's [Q] Quit item -> terminate
        ]);

        self::assertSame(NavigationRuntime::EXIT_QUIT, $exit);
        self::assertSame(['echomail'], $this->invoked);
    }

    public function testBackAtRootEndsTheLoopCleanly(): void
    {
        $exit = $this->drive($this->nestedDefinition(), ['LEFT']);
        self::assertSame(NavigationRuntime::EXIT_BACK_ROOT, $exit);
    }

    public function testDisconnectIsReportedNotSwallowed(): void
    {
        // empty script -> readToken returns disconnect immediately
        $exit = $this->drive($this->nestedDefinition(), []);
        self::assertSame(NavigationRuntime::EXIT_DISCONNECT, $exit);
    }

    public function testGeometryChangeBetweenRendersReflows(): void
    {
        $ctx = $this->context(80, 24);

        $registry = TerminalActionCatalog::defaultRegistry();
        foreach ($registry->ids() as $id) {
            $registry->bind($id, fn () => null);
        }
        $builder = new NavigationScreenBuilder($registry, fn (?string $k, string $f, string $l) => $f);
        $renderer = new NavigationScreenRenderer();
        $runtime = new NavigationRuntime($this->nestedDefinition(), $registry, $builder, $renderer);

        $sink = $ctx->sink();
        $step = 0;
        $readToken = function () use (&$step, $ctx): array {
            $step++;
            if ($step === 1) {
                $ctx->setGeometry(132, 40); // resize before the second render
                return ['', true, false]; // timeout -> re-render
            }

            return ['CHAR:q', false, false];
        };

        $runtime->run($readToken, $ctx, new AccessContext(true, false, false, fn () => true, fn () => true, []), null);

        $bytes = $sink->getBytes();
        // The second render must contain a rule as wide as the new geometry.
        self::assertMatchesRegularExpression('/-{120,}|\xE2\x94\x80{120,}|.{120}/', preg_replace('/\033\[[0-9;]*m/', '', $bytes));
    }

    public function testUnknownKeysAreIgnored(): void
    {
        $exit = $this->drive($this->nestedDefinition(), ['CHAR:z', 'CHAR:9', 'TAB', 'CHAR:q']);
        self::assertSame(NavigationRuntime::EXIT_QUIT, $exit);
        self::assertSame([], $this->invoked);
    }

    // ===== Q-ownership regression (live-acceptance bug) =====================
    //
    // Live SyncTerm test: R5 root -> Games & Experiences -> Crossroads child
    // screen; pressing Q on a child screen (where Q is that screen's own Back)
    // logged the caller off the BBS. Two causes:
    //   1. a delegated legacy screen's key reader leaves a look-ahead byte in
    //      the shared input buffer; when it returns, R5 consumed that stray
    //      byte as one of its own keystrokes;
    //   2. R5 treated Q as a global quit on every screen, including submenus.

    /**
     * Full-control harness: a token queue R5 reads from, plus per-action
     * closures that can push "leaked" tokens back onto the queue (simulating a
     * delegated screen's key-reader look-ahead), plus the same $onActionBoundary
     * discard hook the real bridge installs.
     *
     * @param array<int,string>                    $tokens
     * @param array<string,callable(array):void>   $actionBehaviour  id => fn(&$queue)
     */
    private function driveWithDelegation(
        NavigationDefinition $def,
        array $tokens,
        array $actionBehaviour = [],
        bool $installBoundaryHook = true
    ): string {
        $this->invoked = [];
        $this->rendered = [];

        $queue = $tokens;
        $registry = TerminalActionCatalog::defaultRegistry();
        foreach ($registry->ids() as $id) {
            $behaviour = $actionBehaviour[$id] ?? null;
            $registry->bind($id, function () use ($id, $behaviour, &$queue) {
                $this->invoked[] = $id;
                if ($behaviour !== null) {
                    $behaviour($queue);
                }
            });
        }

        $builder = new NavigationScreenBuilder($registry, fn (?string $k, string $f, string $l) => $f);
        $ctx = $this->context(80, 24);
        $access = new AccessContext(true, false, false, fn () => true, fn () => true, ['color' => true]);

        $readToken = function () use (&$queue): array {
            return $queue === [] ? [null, false, true] : [array_shift($queue), false, false];
        };
        // Real bridge behaviour: discard input the delegated screen left behind.
        $onActionBoundary = $installBoundaryHook
            ? function () use (&$queue): void { $queue = []; }
            : null;

        $runtime = new NavigationRuntime($def, $registry, $builder, new NavigationScreenRenderer());

        return $runtime->run(
            $readToken,
            $ctx,
            $access,
            null,
            'en',
            function (NavigationScreenModel $s) { $this->rendered[] = $s->title; },
            $onActionBoundary,
        );
    }

    public function testStrayInputLeftByADelegatedActionDoesNotLeakIntoR5(): void
    {
        // main: [c] chat (delegated), [q] Log Off (quit). The delegated action
        // "leaks" a Q (as the real showScrollablePanel look-ahead would), then a
        // legitimate DOWN follows.
        $exit = $this->driveWithDelegation(
            $this->nestedDefinition(),
            ['CHAR:c'],
            ['localchat' => function (array &$queue): void {
                array_unshift($queue, 'CHAR:q', 'DOWN'); // stray Q, then a real key
            }],
        );

        // The stray Q was discarded at the action boundary; the session is alive
        // and eventually ends only because the scripted queue drained.
        self::assertSame(NavigationRuntime::EXIT_DISCONNECT, $exit);
        self::assertSame(['localchat'], $this->invoked);
        // Redrew the R5 screen after the action returned (resume).
        self::assertContains('Main', $this->rendered);
    }

    public function testWithoutTheBoundaryHookTheStrayQWouldHaveQuit(): void
    {
        // Same scenario, boundary hook NOT installed -> the leaked Q reaches the
        // root, which has an explicit Log Off item, and terminates the session.
        // This documents exactly what the hook prevents.
        $exit = $this->driveWithDelegation(
            $this->nestedDefinition(),
            ['CHAR:c'],
            ['localchat' => function (array &$queue): void {
                array_unshift($queue, 'CHAR:q');
            }],
            installBoundaryHook: false,
        );

        self::assertSame(NavigationRuntime::EXIT_QUIT, $exit);
    }

    public function testQIsNotAGlobalQuitKeyOnAnR5Submenu(): void
    {
        // main -> [m] messages submenu (no 'q' item). Press Q there: must be a
        // no-op, NOT a session quit. Then Left backs out, then Q on root quits.
        $exit = $this->drive($this->nestedDefinition(), [
            'CHAR:m',   // into Messages
            'CHAR:q',   // Q on a submenu with no q item -> no-op
            'CHAR:q',   // still a no-op
            'LEFT',     // back to Main
            'CHAR:q',   // Main has [Q] Quit -> terminates
        ]);

        self::assertSame(NavigationRuntime::EXIT_QUIT, $exit);
        self::assertSame([], $this->invoked, 'Q on the submenu did nothing');
        // We rendered Messages, went back to Main, and only then quit.
        self::assertContains('Messages', $this->rendered);
        self::assertSame('Main', $this->rendered[count($this->rendered) - 1]);
    }

    public function testQOnTheRootStillTriggersAnExplicitLogOffItem(): void
    {
        $exit = $this->drive($this->nestedDefinition(), ['CHAR:q']);
        self::assertSame(NavigationRuntime::EXIT_QUIT, $exit);
    }

    public function testSubmenuBackViaLeftEscAndBWhenNoItemBindsThem(): void
    {
        foreach (['LEFT', 'ESC', 'CHAR:b'] as $backKey) {
            $exit = $this->drive($this->nestedDefinition(), ['CHAR:m', $backKey, 'CHAR:q']);
            self::assertSame(NavigationRuntime::EXIT_QUIT, $exit, "back key {$backKey}");
            // Rendered Messages then Main again.
            self::assertSame('Messages', $this->rendered[1] ?? null, "back key {$backKey}: entered submenu");
            self::assertSame('Main', $this->rendered[count($this->rendered) - 1], "back key {$backKey}: returned to root");
        }
    }

    // ===== shortcut-alias vs explicit item-hotkey precedence ================
    //
    // Live: the Explore submenu has "[B] BBS Directory", but the footer still
    // said "B/Left Back" and B was expected to be Back. An explicit item hotkey
    // must win over the framework's convenience alias, and the footer must not
    // advertise the conflicting alias.

    /** A submenu that binds B and H to items, mirroring the live "Explore" node. */
    private function collidingDefinition(): NavigationDefinition
    {
        return NavigationDefinition::fromArray([
            'schema' => 1, 'id' => 'collide', 'root' => 'main',
            'nodes' => [
                ['id' => 'main', 'label_fallback' => 'Main', 'items' => [
                    ['id' => 'explore', 'label_fallback' => 'Explore', 'hotkey' => 'x', 'submenu' => 'explore'],
                    ['id' => 'quit', 'label_fallback' => 'Log Off', 'hotkey' => 'q', 'action' => 'quit'],
                ]],
                ['id' => 'explore', 'label_fallback' => 'Explore', 'items' => [
                    ['id' => 'bbslist', 'label_fallback' => 'BBS Directory', 'hotkey' => 'b', 'action' => 'bbslist'],
                    ['id' => 'sub', 'label_fallback' => 'Deeper', 'hotkey' => 'd', 'submenu' => 'deeper'],
                ]],
                ['id' => 'deeper', 'label_fallback' => 'Deeper', 'items' => [
                    ['id' => 'homeitem', 'label_fallback' => 'Homestead', 'hotkey' => 'h', 'action' => 'settings'],
                ]],
            ],
        ]);
    }

    public function testExplicitBHotkeyWinsOverTheBackAlias(): void
    {
        // On "explore", B must activate BBS Directory, NOT go Back.
        $exit = $this->drive($this->collidingDefinition(), [
            'CHAR:x',   // main -> explore
            'CHAR:b',   // must select BBS Directory (not Back)
            'CHAR:d',   // still on explore afterwards -> go Deeper
            'LEFT',     // back to explore
            'LEFT',     // back to main
            'CHAR:q',   // quit
        ]);

        self::assertSame(NavigationRuntime::EXIT_QUIT, $exit);
        self::assertSame(['bbslist'], $this->invoked, 'B activated the item, it did not go Back');
        // Screens rendered: Main, Explore (x2, once after the action), Deeper, Explore, Main
        self::assertContains('Deeper', $this->rendered, 'D still worked from explore, so B did not leave the screen');
        self::assertSame('Main', $this->rendered[count($this->rendered) - 1]);
    }

    public function testBackStillWorksViaLeftAndEscWhenBIsAnItemHotkey(): void
    {
        foreach (['LEFT', 'ESC'] as $backKey) {
            $exit = $this->drive($this->collidingDefinition(), ['CHAR:x', $backKey, 'CHAR:q']);
            self::assertSame(NavigationRuntime::EXIT_QUIT, $exit, "back via {$backKey}");
            self::assertSame([], $this->invoked, "back via {$backKey}: nothing activated");
            self::assertSame('Explore', $this->rendered[1] ?? null, "back via {$backKey}: entered explore");
            self::assertSame('Main', $this->rendered[count($this->rendered) - 1], "back via {$backKey}: returned to root");
        }
    }

    public function testExplicitHHotkeyWinsOverTheHomeAlias(): void
    {
        // "deeper" is at depth 3 (Home would normally be available) and binds H.
        // Pressing H must activate the item, not jump Home.
        $exit = $this->drive($this->collidingDefinition(), [
            'CHAR:x',   // -> explore
            'CHAR:d',   // -> deeper (depth 3)
            'CHAR:h',   // must activate the [H] Homestead item, not go Home
            'LEFT', 'LEFT', 'CHAR:q',
        ]);

        self::assertSame(NavigationRuntime::EXIT_QUIT, $exit);
        self::assertSame(['settings'], $this->invoked, 'H activated the item, it did not jump Home');
    }

    public function testAStrayLeakedBOnAScreenThatBindsBDoesNothing(): void
    {
        // Combined with the delegated-input-boundary + Q fixes: a stray 'b' that
        // somehow reaches a screen binding B to a *disabled* item is a no-op
        // (the letter is taken; the alias is suppressed).
        $def = NavigationDefinition::fromArray([
            'schema' => 1, 'id' => 'x', 'root' => 'main',
            'nodes' => [
                ['id' => 'main', 'label_fallback' => 'Main', 'items' => [
                    ['id' => 's', 'label_fallback' => 'Section', 'hotkey' => 's', 'submenu' => 'sub'],
                    ['id' => 'q', 'label_fallback' => 'Log Off', 'hotkey' => 'q', 'action' => 'quit'],
                ]],
                ['id' => 'sub', 'label_fallback' => 'Sub', 'items' => [
                    ['id' => 'b', 'label_fallback' => 'Broken', 'hotkey' => 'b', 'action' => 'bbslist', 'enabled' => false],
                    ['id' => 'n', 'label_fallback' => 'Netmail', 'hotkey' => 'n', 'action' => 'netmail'],
                ]],
            ],
        ]);

        $exit = $this->drive($def, ['CHAR:s', 'CHAR:b', 'CHAR:b', 'LEFT', 'CHAR:q']);

        self::assertSame(NavigationRuntime::EXIT_QUIT, $exit);
        self::assertSame([], $this->invoked, 'B on a screen binding B to a disabled item is a no-op, not Back');
        self::assertSame('Sub', $this->rendered[1] ?? null);
        self::assertSame('Main', $this->rendered[count($this->rendered) - 1]);
    }
}
