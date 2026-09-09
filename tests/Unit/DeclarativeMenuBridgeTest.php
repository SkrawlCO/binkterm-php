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
        foreach (['TERMINAL_NAV_RUNTIME', 'TERMINAL_NAV_CONFIG'] as $k) {
            $this->envBackup[$k] = $_ENV[$k] ?? null;
            unset($_ENV[$k]);
        }
        NavigationConfig::reset();
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $k => $v) {
            $v === null ? $this->clearEnv($k) : $_ENV[$k] = $v;
        }
        NavigationConfig::reset();
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
