<?php

declare(strict_types=1);

require_once __DIR__ . '/../../telnet/src/OutputSink.php';
require_once __DIR__ . '/../../telnet/src/BufferSink.php';
require_once __DIR__ . '/../../telnet/src/SocketSink.php';
require_once __DIR__ . '/../../telnet/src/GlyphPolicy.php';
require_once __DIR__ . '/../../telnet/src/TerminalCapabilities.php';
require_once __DIR__ . '/../../telnet/src/TerminalRenderContext.php';
require_once __DIR__ . '/../../telnet/src/TelnetUtils.php';
require_once __DIR__ . '/../../telnet/src/TerminalBoxRenderer.php';
require_once __DIR__ . '/../../telnet/src/BbsSession.php';
require_once __DIR__ . '/../../telnet/src/DeclarativeMenuBridge.php';
require_once __DIR__ . '/../../telnet/src/MailUtils.php';
require_once __DIR__ . '/../../telnet/src/EchomailHandler.php';

use BinktermPHP\I18n\Translator;
use BinktermPHP\TelnetServer\BbsSession;
use BinktermPHP\TelnetServer\BufferSink;
use BinktermPHP\TelnetServer\DeclarativeMenuBridge;
use BinktermPHP\TelnetServer\TerminalCapabilities;
use BinktermPHP\TelnetServer\TerminalRenderContext;
use BinktermPHP\Terminal\Navigation\NavigationConfig;
use PHPUnit\Framework\TestCase;

/**
 * R5F + R5J — the BbsSession <-> declarative navigation glue, and that a session
 * with the feature off is untouched.
 */
final class DeclarativeMenuBridgeTest extends TestCase
{
    private array $envBackup = [];

    protected function setUp(): void
    {
        // See NavigationConfigTest::setUp — order-independent env neutralisation
        // (present-but-empty survives Config::loadEnvFile() re-population; the
        // live .env carries TERMINAL_NAV_RUNTIME=on).
        \BinktermPHP\Config::env('__nav_test_warm__');
        foreach (['TERMINAL_NAV_RUNTIME', 'TERMINAL_NAV_CONFIG'] as $k) {
            $this->envBackup[$k] = $_ENV[$k] ?? null;
            $_ENV[$k] = '';
        }
        // Isolate from a deployed live presentation theme — the bridge picks the
        // renderer via NavigationRendererFactory, and these tests assert on the
        // flowing renderer's output.
        $this->envBackup['TERMINAL_NAV_THEME_CONFIG'] = $_ENV['TERMINAL_NAV_THEME_CONFIG'] ?? null;
        $_ENV['TERMINAL_NAV_THEME_CONFIG'] = sys_get_temp_dir() . '/nav-theme-none-' . uniqid() . '.json';
        NavigationConfig::reset();
        \BinktermPHP\Terminal\Navigation\NavigationThemeConfig::reset();
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $k => $v) {
            $v === null ? $this->clearEnv($k) : $_ENV[$k] = $v;
        }
        NavigationConfig::reset();
        \BinktermPHP\Terminal\Navigation\NavigationThemeConfig::reset();
    }

    private function clearEnv(string $k): void
    {
        unset($_ENV[$k]);
    }

    private function session(): BbsSession
    {
        $conn = fopen('php://temp', 'r+');
        $session = new BbsSession($conn, 'http://127.0.0.1', false, false, false, false);

        $ctx = new TerminalRenderContext(
            new BufferSink(),
            TerminalCapabilities::unknown()->withColorSupport(TerminalCapabilities::COLOR_ANSI),
            80,
            24,
            'utf8',
            true,
            false,
            [],
            'en',
            new Translator(),
        );
        foreach (['renderContext' => $ctx, 'capabilities' => $ctx->capabilities()] as $prop => $val) {
            $r = new \ReflectionProperty($session, $prop);
            $r->setAccessible(true);
            $r->setValue($session, $val);
        }

        return $session;
    }

    public function testBridgeDoesNothingWhenTheRuntimeIsDisabled(): void
    {
        $bridge = new DeclarativeMenuBridge($this->session());
        $state  = ['username' => 'alice', 'is_admin' => false, 'locale' => 'en', 'cols' => 80, 'rows' => 24];

        $handled = $bridge->run(fopen('php://temp', 'r+'), $state, 'sess', []);

        self::assertFalse($handled, 'gate off -> legacy menu path');
    }

    public function testBridgeFallsBackWhenFlagIsOnButFileIsMissing(): void
    {
        $_ENV['TERMINAL_NAV_RUNTIME'] = 'on';
        $_ENV['TERMINAL_NAV_CONFIG']  = sys_get_temp_dir() . '/navbridge_absent_' . bin2hex(random_bytes(5)) . '.json';
        NavigationConfig::reset();

        $bridge = new DeclarativeMenuBridge($this->session());
        $state  = ['username' => 'alice', 'is_admin' => false, 'locale' => 'en', 'cols' => 80, 'rows' => 24];

        self::assertFalse(
            $bridge->run(fopen('php://temp', 'r+'), $state, 'sess', []),
            'flag on + no file -> bridge is entered, logs, and falls back to legacy'
        );
    }

    public function testBridgeCatchesARuntimeExceptionAndFallsBack(): void
    {
        $def = [
            'schema' => 1, 'id' => 'boom', 'root' => 'main',
            'nodes' => [['id' => 'main', 'label_fallback' => 'Main', 'items' => [
                ['id' => 'x', 'label_fallback' => 'Explode', 'hotkey' => 'x', 'action' => 'netmail'],
            ]]],
        ];
        $path = sys_get_temp_dir() . '/navbridge_boom_' . bin2hex(random_bytes(5)) . '.json';
        file_put_contents($path, json_encode($def));
        register_shutdown_function(static fn () => @unlink($path));

        $_ENV['TERMINAL_NAV_RUNTIME'] = 'on';
        $_ENV['TERMINAL_NAV_CONFIG']  = $path;
        NavigationConfig::reset();

        $session = $this->session();
        $bridge  = new DeclarativeMenuBridge($session);

        // A conn that yields "x" then nothing -> selects the action, whose handler throws.
        $conn = fopen('php://temp', 'r+');
        fwrite($conn, 'x');
        rewind($conn);
        $state = ['username' => 'alice', 'is_admin' => false, 'locale' => 'en', 'cols' => 80, 'rows' => 24, 'pushback' => '', 'last_activity' => time(), 'idle_warned' => false, 'idle_warning_timeout' => 300, 'idle_disconnect_timeout' => 420];

        $handlers = [
            'netmail' => new class {
                public function show($c, &$s, $sess): void { throw new \RuntimeException('handler blew up'); }
            },
        ];

        // Must not propagate — the bridge catches and returns false.
        self::assertFalse($bridge->run($conn, $state, 'sess', $handlers));
        // Screen was reset for the legacy menu.
        self::assertStringContainsString("\033[2J", $session->getRenderContext()->sink()->getBytes());
    }

    public function testBridgeRunsTheRuntimeEndToEndWhenActivated(): void
    {
        $def = [
            'schema' => 1, 'id' => 'bridge.test', 'root' => 'main',
            'nodes' => [['id' => 'main', 'label_fallback' => 'Main', 'items' => [
                ['id' => 'n', 'label_fallback' => 'Netmail', 'hotkey' => 'n', 'action' => 'netmail'],
                ['id' => 'q', 'label_fallback' => 'Quit', 'hotkey' => 'q', 'action' => 'quit'],
            ]]],
        ];
        $path = sys_get_temp_dir() . '/navbridge_' . bin2hex(random_bytes(5)) . '.json';
        file_put_contents($path, json_encode($def));
        register_shutdown_function(static fn () => @unlink($path));

        $_ENV['TERMINAL_NAV_RUNTIME'] = 'on';
        $_ENV['TERMINAL_NAV_CONFIG']  = $path;
        NavigationConfig::reset();

        $session = $this->session();
        $bridge  = new DeclarativeMenuBridge($session);

        $conn  = fopen('php://temp', 'r+'); // empty -> first readKey => disconnect
        $state = ['username' => 'alice', 'is_admin' => false, 'locale' => 'en', 'cols' => 80, 'rows' => 24, 'pushback' => '', 'last_activity' => time(), 'idle_warned' => false, 'idle_warning_timeout' => 300, 'idle_disconnect_timeout' => 420];

        $calls = [];
        $handlers = [
            'netmail' => new class($calls) {
                public function __construct(private &$calls) {}
                public function show($c, &$s, $sess): void { $this->calls[] = 'netmail'; }
            },
        ];

        $handled = $bridge->run($conn, $state, 'sess', $handlers);

        self::assertTrue($handled, 'gate on + valid file -> runtime handled the session');
        // The render context sink received a rendered menu.
        $bytes = $session->getRenderContext()->sink()->getBytes();
        self::assertStringContainsString('Main', preg_replace('/\033\[[0-9;?]*[A-Za-z]/', '', $bytes));
        self::assertStringContainsString('Netmail', preg_replace('/\033\[[0-9;?]*[A-Za-z]/', '', $bytes));
    }

    public function testInputLeftByADelegatedActionIsDiscardedNotFedToR5(): void
    {
        // Live bug: a delegated legacy screen's key reader leaves a look-ahead
        // byte in $state['pushback']; when it returns, R5 read that stray byte
        // as one of its own keystrokes and (a stray 'q') logged the caller off.
        $def = [
            'schema' => 1, 'id' => 'boundary.test', 'root' => 'main',
            'nodes' => [['id' => 'main', 'label_fallback' => 'Main', 'items' => [
                ['id' => 'n', 'label_fallback' => 'Netmail', 'hotkey' => 'n', 'action' => 'netmail'],
                ['id' => 'q', 'label_fallback' => 'Log Off', 'hotkey' => 'q', 'action' => 'quit'],
            ]]],
        ];
        $path = sys_get_temp_dir() . '/navbridge_boundary_' . bin2hex(random_bytes(5)) . '.json';
        file_put_contents($path, json_encode($def));
        register_shutdown_function(static fn () => @unlink($path));

        $_ENV['TERMINAL_NAV_RUNTIME'] = 'on';
        $_ENV['TERMINAL_NAV_CONFIG']  = $path;
        NavigationConfig::reset();

        $session = $this->session();
        $bridge  = new DeclarativeMenuBridge($session);

        $conn = fopen('php://temp', 'r+');
        fwrite($conn, 'n');   // select Netmail
        rewind($conn);
        $state = ['username' => 'alice', 'is_admin' => false, 'locale' => 'en', 'cols' => 80, 'rows' => 24, 'pushback' => '', 'last_activity' => time(), 'idle_warned' => false, 'idle_warning_timeout' => 300, 'idle_disconnect_timeout' => 420];

        $seen = [];
        $handlers = [
            // Handler behaves like a legacy screen whose reader left a stray 'q'.
            'netmail' => new class($seen) {
                public function __construct(public array &$seen) {}
                public function show($c, array &$s, $sess): void
                {
                    $this->seen[] = 'netmail';
                    $s['pushback'] = 'q'; // simulate the look-ahead leak
                }
            },
        ];

        $bridge->run($conn, $state, 'sess', $handlers);

        self::assertSame(['netmail'], $seen, 'the delegated action ran exactly once');
        self::assertSame('', $state['pushback'], 'the stray input was discarded at the action boundary');
    }

    public function testEchomailActionIsBoundViaAClosureToShowEchoareas(): void
    {
        // EchomailHandler's menu entrypoint is showEchoareas(), not show(), so
        // BbsSession maps 'echomail' to a closure. Without it the bridge's
        // method_exists($h,'show') check leaves the action unbound and its menu
        // item silently does nothing (the live bug).
        $def = [
            'schema' => 1, 'id' => 'echo.test', 'root' => 'main',
            'nodes' => [['id' => 'main', 'label_fallback' => 'Main', 'items' => [
                ['id' => 'e', 'label_fallback' => 'Echomail', 'hotkey' => 'e', 'action' => 'echomail'],
                ['id' => 'q', 'label_fallback' => 'Quit', 'hotkey' => 'q', 'action' => 'quit'],
            ]]],
        ];
        $path = sys_get_temp_dir() . '/navbridge_echo_' . bin2hex(random_bytes(5)) . '.json';
        file_put_contents($path, json_encode($def));
        register_shutdown_function(static fn () => @unlink($path));

        $_ENV['TERMINAL_NAV_RUNTIME'] = 'on';
        $_ENV['TERMINAL_NAV_CONFIG']  = $path;
        NavigationConfig::reset();

        $session = $this->session();
        $bridge  = new DeclarativeMenuBridge($session);

        $conn = fopen('php://temp', 'r+');
        fwrite($conn, 'e'); // select Echomail
        rewind($conn);
        $state = ['username' => 'alice', 'is_admin' => false, 'locale' => 'en', 'cols' => 80, 'rows' => 24, 'pushback' => '', 'last_activity' => time(), 'idle_warned' => false, 'idle_warning_timeout' => 300, 'idle_disconnect_timeout' => 420];

        // Spy standing in for EchomailHandler: only showEchoareas(), no show().
        $spy = new class {
            public array $calls = [];
            public function showEchoareas($conn, array &$state, string $session): void
            {
                $this->calls[] = ['session' => $session, 'cols' => $state['cols']];
            }
        };

        $bridge->run($conn, $state, 'sess', [
            'echomail' => fn () => $spy->showEchoareas($conn, $state, 'sess'),
        ]);

        self::assertCount(1, $spy->calls, 'the Echomail action launched showEchoareas() exactly once');
        self::assertSame('sess', $spy->calls[0]['session']);
    }

    public function testNewscanActionDispatchesFromASubmenuThroughTheRealRuntime(): void
    {
        // Mirrors the LIVE l33test.frontdoor shape: `newscan` is NOT a root item,
        // it is the first item of the `messages` submenu, reached by navigating
        // root -> [M]essages -> [A] What's New. BbsSession maps 'newscan' to a
        // closure (NewscanHandler::show), exactly as it maps 'echomail'. The
        // earlier version of this test put newscan at the root and so never
        // exercised the submenu -> action -> invoke path that failed live.
        $def = [
            'schema' => 1, 'id' => 'newscan.submenu.test', 'root' => 'root',
            'nodes' => [
                ['id' => 'root', 'label_fallback' => 'Main', 'items' => [
                    ['id' => 'messages', 'label_fallback' => 'Messages', 'hotkey' => 'm', 'submenu' => 'messages'],
                    ['id' => 'q', 'label_fallback' => 'Quit', 'hotkey' => 'q', 'action' => 'quit'],
                ]],
                ['id' => 'messages', 'label_fallback' => 'Messages', 'items' => [
                    ['id' => 'whatsnew', 'label_fallback' => "What's New", 'hotkey' => 'a', 'action' => 'newscan',
                     'description_fallback' => 'Everything new since your last visit.'],
                    ['id' => 'netmail', 'label_fallback' => 'Netmail', 'hotkey' => 'n', 'action' => 'netmail'],
                ]],
            ],
        ];
        $path = sys_get_temp_dir() . '/navbridge_newscan_' . bin2hex(random_bytes(5)) . '.json';
        file_put_contents($path, json_encode($def));
        register_shutdown_function(static fn () => @unlink($path));

        $_ENV['TERMINAL_NAV_RUNTIME'] = 'on';
        $_ENV['TERMINAL_NAV_CONFIG']  = $path;
        NavigationConfig::reset();

        $session = $this->session();
        $bridge  = new DeclarativeMenuBridge($session);

        $conn = fopen('php://temp', 'r+');
        fwrite($conn, 'ma'); // [M]essages then [A] What's New
        rewind($conn);
        $state = ['username' => 'alice', 'is_admin' => false, 'locale' => 'en', 'cols' => 80, 'rows' => 24, 'pushback' => '', 'last_activity' => time(), 'idle_warned' => false, 'idle_warning_timeout' => 300, 'idle_disconnect_timeout' => 420];

        $spy = new class {
            public int $calls = 0;
            public array $args = [];
            public function show($conn, array &$state, string $session): void
            {
                $this->calls++;
                $this->args[] = $session;
            }
        };

        // The handler map is built EXACTLY as BbsSession::handle() builds it:
        // 'newscan' => fn () => $newscanHandler->show($conn, $state, $session)
        $handled = $bridge->run($conn, $state, 'sess', [
            'newscan' => fn () => $spy->show($conn, $state, 'sess'),
            'netmail' => fn () => null,
        ]);

        self::assertTrue($handled, 'the declarative runtime ran');
        self::assertSame(1, $spy->calls, 'newscan dispatched once from inside the Messages submenu');
        self::assertSame(['sess'], $spy->args);
    }

    public function testNewscanIsARegisteredAvailableActionInAFreshRuntimeRegistry(): void
    {
        // The live-failure candidate "action registered but unavailable / not in
        // the registry the runtime uses". The bridge builds its registry from
        // TerminalActionCatalog::defaultRegistry() on every run.
        $registry = \BinktermPHP\Terminal\Navigation\TerminalActionCatalog::defaultRegistry();
        self::assertTrue($registry->has('newscan'), 'newscan is in the default action registry');
        self::assertFalse($registry->get('newscan')->terminates);

        $authed = new \BinktermPHP\Terminal\Navigation\AccessContext(
            true, false, false, static fn () => false, static fn () => false, []
        );
        self::assertTrue($registry->isAvailable('newscan', $authed), 'newscan is available to any authenticated caller');
    }

    public function testAMappedHandlerWithNoShowMethodLeavesTheActionInert(): void
    {
        // The pre-fix shape: a bare object with no show() -> not bound ->
        // selecting the item does nothing (no crash, no launch).
        $def = [
            'schema' => 1, 'id' => 'inert.test', 'root' => 'main',
            'nodes' => [['id' => 'main', 'label_fallback' => 'Main', 'items' => [
                ['id' => 'e', 'label_fallback' => 'Echomail', 'hotkey' => 'e', 'action' => 'echomail'],
                ['id' => 'q', 'label_fallback' => 'Quit', 'hotkey' => 'q', 'action' => 'quit'],
            ]]],
        ];
        $path = sys_get_temp_dir() . '/navbridge_inert_' . bin2hex(random_bytes(5)) . '.json';
        file_put_contents($path, json_encode($def));
        register_shutdown_function(static fn () => @unlink($path));

        $_ENV['TERMINAL_NAV_RUNTIME'] = 'on';
        $_ENV['TERMINAL_NAV_CONFIG']  = $path;
        NavigationConfig::reset();

        $session = $this->session();
        $conn = fopen('php://temp', 'r+');
        fwrite($conn, 'e');
        rewind($conn);
        $state = ['username' => 'alice', 'is_admin' => false, 'locale' => 'en', 'cols' => 80, 'rows' => 24, 'pushback' => '', 'last_activity' => time(), 'idle_warned' => false, 'idle_warning_timeout' => 300, 'idle_disconnect_timeout' => 420];

        $spy = new class {
            public bool $touched = false;
            public function showEchoareas(): void { $this->touched = true; }
        };

        // handled without error; the inert item just re-renders the screen.
        $handled = (new DeclarativeMenuBridge($session))->run($conn, $state, 'sess', ['echomail' => $spy]);

        self::assertTrue($handled);
        self::assertFalse($spy->touched, 'a handler with no show() is not invoked (documents why the closure is required)');
    }

    public function testEchomailHandlerStillHasNoShowMethodSoTheClosureIsRequired(): void
    {
        // Guard: if EchomailHandler ever gains show(), revisit the BbsSession
        // handler map (the closure could then be a plain object again).
        self::assertFalse(
            method_exists(\BinktermPHP\TelnetServer\EchomailHandler::class, 'show'),
            'EchomailHandler gained show() — the BbsSession echomail closure can be simplified'
        );
        self::assertTrue(method_exists(\BinktermPHP\TelnetServer\EchomailHandler::class, 'showEchoareas'));
    }

    public function testStandaloneEscTakesTheRuntimeBackFromASubmenu(): void
    {
        // Full path: a lone 0x1B through readKeyWithTimeout() -> the bridge's
        // readToken -> NavigationRuntime -> Back. (Live: "Left works, Esc does not".)
        $def = [
            'schema' => 1, 'id' => 'esc.test', 'root' => 'main',
            'nodes' => [
                ['id' => 'main', 'label_fallback' => 'Main Menu', 'items' => [
                    ['id' => 'x', 'label_fallback' => 'Explore', 'hotkey' => 'x', 'submenu' => 'explore'],
                    ['id' => 'q', 'label_fallback' => 'Log Off', 'hotkey' => 'q', 'action' => 'quit'],
                ]],
                ['id' => 'explore', 'label_fallback' => 'Explore Section', 'items' => [
                    ['id' => 'b', 'label_fallback' => 'BBS Directory', 'hotkey' => 'b', 'action' => 'bbslist'],
                ]],
            ],
        ];
        $path = sys_get_temp_dir() . '/navbridge_esc_' . bin2hex(random_bytes(5)) . '.json';
        file_put_contents($path, json_encode($def));
        register_shutdown_function(static fn () => @unlink($path));

        $_ENV['TERMINAL_NAV_RUNTIME'] = 'on';
        $_ENV['TERMINAL_NAV_CONFIG']  = $path;
        NavigationConfig::reset();

        $session = $this->session();
        $bridge  = new DeclarativeMenuBridge($session);

        $conn = fopen('php://temp', 'r+');
        fwrite($conn, "x");      // into Explore
        fwrite($conn, "\x1b");   // standalone ESC -> Back to Main
        // (stream then hits EOF -> disconnect -> loop ends)
        rewind($conn);
        $state = ['username' => 'alice', 'is_admin' => false, 'locale' => 'en', 'cols' => 80, 'rows' => 24, 'pushback' => '', 'last_activity' => time(), 'idle_warned' => false, 'idle_warning_timeout' => 300, 'idle_disconnect_timeout' => 420];

        $bridge->run($conn, $state, 'sess', []);

        $plain = preg_replace('/\033\[[0-9;?]*[A-Za-z]/', '', $session->getRenderContext()->sink()->getBytes());
        // Rendered: Main Menu, then Explore Section, then Main Menu again (ESC = Back).
        self::assertGreaterThanOrEqual(2, substr_count($plain, 'Main Menu'), 'ESC returned to the root screen');
        self::assertStringContainsString('Explore Section', $plain, 'the submenu was entered first');
        $lastMain = strrpos($plain, 'Main Menu');
        $lastExplore = strrpos($plain, 'Explore Section');
        self::assertGreaterThan($lastExplore, $lastMain, 'the final screen rendered is the root, reached via ESC');
    }

    public function testInvalidDefinitionFileFallsBackToLegacy(): void
    {
        $path = sys_get_temp_dir() . '/navbridge_bad_' . bin2hex(random_bytes(5)) . '.json';
        file_put_contents($path, json_encode([
            'schema' => 1, 'id' => 'x', 'root' => 'main',
            'nodes' => [['id' => 'main', 'label_fallback' => 'M', 'items' => [
                ['id' => 'a', 'label_fallback' => 'A', 'action' => 'totally_unknown_action'],
            ]]],
        ]));
        register_shutdown_function(static fn () => @unlink($path));

        $_ENV['TERMINAL_NAV_RUNTIME'] = 'on';
        $_ENV['TERMINAL_NAV_CONFIG']  = $path;
        NavigationConfig::reset();

        $bridge = new DeclarativeMenuBridge($this->session());
        $state  = ['username' => 'alice', 'is_admin' => false, 'locale' => 'en', 'cols' => 80, 'rows' => 24];

        self::assertFalse($bridge->run(fopen('php://temp', 'r+'), $state, 'sess', []), 'invalid file -> legacy');
    }

    public function testFeatureResolverMapsSpecialNames(): void
    {
        $bridge = new DeclarativeMenuBridge($this->session());
        $m = new \ReflectionMethod($bridge, 'featureResolver');
        $m->setAccessible(true);
        /** @var callable $resolver */
        $resolver = $m->invoke($bridge, ['is_admin' => false]);

        // 'nodelist' is always deferred to the handler (presence check) -> true here.
        self::assertTrue($resolver('nodelist'));
        // Unknown feature name -> BbsConfig::isFeatureEnabled -> false (not a real flag).
        self::assertFalse($resolver('___not_a_feature___'));
    }
}
