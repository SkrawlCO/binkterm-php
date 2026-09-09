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
            'CHAR:q',
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
}
