<?php

declare(strict_types=1);

require_once __DIR__ . '/../../telnet/src/OutputSink.php';
require_once __DIR__ . '/../../telnet/src/SocketSink.php';
require_once __DIR__ . '/../../telnet/src/BufferSink.php';
require_once __DIR__ . '/../../telnet/src/GlyphPolicy.php';
require_once __DIR__ . '/../../telnet/src/TerminalCapabilities.php';
require_once __DIR__ . '/../../telnet/src/TerminalRenderContext.php';
require_once __DIR__ . '/../../telnet/src/TelnetUtils.php';
require_once __DIR__ . '/../../telnet/src/TerminalMarkupRenderer.php';
require_once __DIR__ . '/../../telnet/src/TerminalBoxRenderer.php';
require_once __DIR__ . '/../../telnet/src/BbsSession.php';
require_once __DIR__ . '/../../telnet/src/TerminalLineEditor.php';
require_once __DIR__ . '/../../telnet/src/TerminalLineHistory.php';
require_once __DIR__ . '/../../telnet/src/TerminalShellInterface.php';
require_once __DIR__ . '/../../telnet/src/TuiShell.php';
require_once __DIR__ . '/../../telnet/src/LineShell.php';
require_once __DIR__ . '/../../telnet/src/MailUtils.php';
require_once __DIR__ . '/../../telnet/src/TerminalMessageService.php';
require_once __DIR__ . '/../../telnet/src/NetmailHandler.php';
require_once __DIR__ . '/../../telnet/src/EchomailHandler.php';

use BinktermPHP\I18n\Translator;
use BinktermPHP\TelnetServer\BbsSession;
use BinktermPHP\TelnetServer\EchomailHandler;
use BinktermPHP\TelnetServer\LineShell;
use BinktermPHP\TelnetServer\SocketSink;
use BinktermPHP\TelnetServer\TerminalCapabilities;
use BinktermPHP\TelnetServer\TerminalRenderContext;
use BinktermPHP\TelnetServer\TuiShell;
use PHPUnit\Framework\TestCase;

/**
 * Terminal Experience Unification M2, Part 3 — Echomail Areas is the first
 * consumer of the dense-list primitive. Drives the real (private)
 * {@see EchomailHandler::pickEchoarea()} over a paired socket with a real
 * {@see BbsSession} input pipeline: keystrokes in, selection out.
 *
 * The list navigation is the proven flat selectable-list key loop; these assert
 * the new presentation (location identity, compact context, page indicator) and
 * that every selection / paging / extra-key semantic is unchanged.
 */
final class EchoareaDenseListTest extends TestCase
{
    /** @var resource */
    private $srv;
    /** @var resource */
    private $cli;
    private BbsSession $bbs;
    private EchomailHandler $handler;

    private mixed $themeEnvBackup = null;

    protected function setUp(): void
    {
        // These pin the *non-themed* dense-list presentation. Point the theme
        // loader at an empty temp dir so a deployed echoareas surface
        // (config/terminal_theme_echoareas.json) does not swap in the authored
        // M2 frame under the tests (that path is covered by
        // EchoareaBrowserCompositionTest).
        $this->themeEnvBackup = $_ENV['TERMINAL_NAV_THEME_CONFIG'] ?? null;
        $_ENV['TERMINAL_NAV_THEME_CONFIG'] = sys_get_temp_dir() . '/echoarea-densetest-none-' . uniqid('', true) . '.json';
        \BinktermPHP\Terminal\Navigation\NavigationThemeConfig::reset();

        [$this->srv, $this->cli] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        stream_set_blocking($this->srv, false);
        stream_set_blocking($this->cli, false);

        $this->bbs = new BbsSession($this->srv, 'http://127.0.0.1', false, false, false, false);
        $caps = TerminalCapabilities::unknown()
            ->withCharsetSupport(TerminalCapabilities::CHARSET_UTF8)
            ->withColorSupport(TerminalCapabilities::COLOR_ANSI);
        $ctx = new TerminalRenderContext(
            new SocketSink($this->srv), $caps, 80, 24, 'utf8', true, false, [], 'en', new Translator()
        );
        foreach (['renderContext' => $ctx, 'capabilities' => $caps] as $p => $v) {
            $r = new \ReflectionProperty($this->bbs, $p);
            $r->setAccessible(true);
            $r->setValue($this->bbs, $v);
        }

        $this->handler = new EchomailHandler($this->bbs, 'http://127.0.0.1');
    }

    protected function tearDown(): void
    {
        @fclose($this->srv);
        @fclose($this->cli);

        if ($this->themeEnvBackup === null) {
            unset($_ENV['TERMINAL_NAV_THEME_CONFIG']);
        } else {
            $_ENV['TERMINAL_NAV_THEME_CONFIG'] = $this->themeEnvBackup;
        }
        \BinktermPHP\Terminal\Navigation\NavigationThemeConfig::reset();
    }

    private function state(): array
    {
        return [
            'input_echo' => true, 'cols' => 80, 'rows' => 24, 'locale' => 'en', 'pushback' => '',
            'user_id' => 7, 'last_activity' => time(), 'idle_warned' => false,
            'idle_warning_timeout' => 300, 'idle_disconnect_timeout' => 420,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function areas(int $n = 3): array
    {
        $seed = [
            ['tag' => 'AGN_SYS',   'domain' => 'agoranet', 'description' => 'SysOp Chat',      'subscribed' => true, 'id' => 1],
            ['tag' => 'L33TSPEAK', 'domain' => 'agoranet', 'description' => 'General chatter',  'subscribed' => true, 'id' => 2],
            ['tag' => 'ANN.INTRO', 'domain' => 'anet',     'description' => 'Introductions',    'subscribed' => true, 'id' => 3],
        ];
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = $seed[$i] ?? [
                'tag' => 'AREA_' . $i, 'domain' => 'anet', 'description' => 'Area ' . $i, 'subscribed' => true, 'id' => $i + 10,
            ];
        }

        return $out;
    }

    /**
     * @param array<int,array<string,mixed>> $areas
     * @return array{result:array, output:string}
     */
    private function pick(
        string $keys,
        array $areas,
        ?string $searchFilter = null,
        bool $allAreasMode = false,
        $shell = null,
        ?array $stateOverride = null
    ): array {
        fwrite($this->cli, $keys);
        fflush($this->cli);

        $state = $stateOverride ?? $this->state();
        $shell ??= new TuiShell($this->bbs);

        $m = new \ReflectionMethod(EchomailHandler::class, 'pickEchoarea');
        $m->setAccessible(true);

        $args = [
            $this->srv,
            &$state,
            $areas,
            1,
            17,
            'Echoareas (page {page}/{total}):',
            false,
            null,
            $searchFilter,
            $allAreasMode,
            [],
            $shell,
            ['crumbs' => ['Messages'], 'location' => 'Echomail Areas'],
        ];
        $result = $m->invokeArgs($this->handler, $args);

        $out = '';
        while (($chunk = @fread($this->cli, 8192)) !== false && $chunk !== '') {
            $out .= $chunk;
        }

        return ['result' => $result, 'output' => $out];
    }

    private static function plain(string $s): string
    {
        return preg_replace('/\033\[[0-9;?]*[A-Za-z]/', '', $s) ?? $s;
    }

    public function testScreenShowsLocationIdentityContextAndPageIndicator(): void
    {
        $r = $this->pick('q', $this->areas(3));
        $plain = self::plain($r['output']);

        self::assertStringContainsString('Messages', $plain);
        self::assertStringContainsString('Echomail Areas', $plain);
        self::assertStringContainsString('Page 1/1', $plain);
        self::assertStringContainsString('Areas you follow - 3', $plain);
        self::assertSame('quit', $r['result']['action']);
    }

    public function testAreaTagNetworkAndDescriptionStayVisible(): void
    {
        $plain = self::plain($this->pick('q', $this->areas(3))['output']);

        self::assertStringContainsString('AGN_SYS', $plain);
        self::assertStringContainsString('agoranet', $plain);
        self::assertStringContainsString('SysOp Chat', $plain);
        self::assertStringContainsString('ANN.INTRO', $plain);
        self::assertStringContainsString('Introductions', $plain);
    }

    public function testEnterSelectsTheHighlightedArea(): void
    {
        $r = $this->pick("\r", $this->areas(3));

        self::assertSame('select', $r['result']['action']);
        self::assertSame('AGN_SYS', $r['result']['area']['tag']);
    }

    public function testArrowDownThenEnterSelectsTheSecondArea(): void
    {
        $r = $this->pick("\033[B\r", $this->areas(3));

        self::assertSame('select', $r['result']['action']);
        self::assertSame('L33TSPEAK', $r['result']['area']['tag']);
    }

    public function testNumericJumpStillSelectsByRowNumber(): void
    {
        // The menu key reader eats a CR that immediately follows a printable
        // (the "L<Enter>" hotkey case); a real caller's keystrokes are spaced.
        // Feed a separator between the digit and the committing Enter.
        $r = $this->pick("3\n\r", $this->areas(3));

        self::assertSame('select', $r['result']['action']);
        self::assertSame('ANN.INTRO', $r['result']['area']['tag']);
    }

    public function testExtraKeysStillRouteOnTheProvenLoop(): void
    {
        self::assertSame('search', $this->pick('s', $this->areas(3))['result']['action']);
        self::assertSame('allareas', $this->pick('a', $this->areas(3))['result']['action']);
    }

    public function testPagingIsPreserved(): void
    {
        $r = $this->pick("\033[C", $this->areas(20)); // RIGHT = next page

        self::assertSame('redraw', $r['result']['action']);
        self::assertSame(2, $r['result']['page']);
        self::assertStringContainsString('Page 1/2', self::plain($r['output']));
    }

    public function testAllAreasModeShowsBadgesAndItsOwnContextLine(): void
    {
        $areas = $this->areas(3);
        $areas[1]['subscribed'] = false;

        $plain = self::plain($this->pick('q', $areas, null, true)['output']);

        self::assertStringContainsString('All areas - 3 total', $plain);
        self::assertStringContainsString('[+]', $plain, 'subscribed badge');
        self::assertStringContainsString('[ ]', $plain, 'unsubscribed badge');
    }

    public function testFilterModeShowsAnIntentionalEmptyState(): void
    {
        $r = $this->pick('q', [], 'zzz');
        $plain = self::plain($r['output']);

        self::assertStringContainsString('Filter: zzz - 0 matching', $plain);
        self::assertStringContainsString('No areas match your search.', $plain);
        self::assertSame('quit', $r['result']['action']);
    }

    public function testNoHorizontalOverflowAt80Columns(): void
    {
        $wide = $this->areas(2);
        $wide[0]['description'] = str_repeat('long description ', 12);
        $wide[0]['tag'] = 'A_VERY_LONG_AREA_TAG_THAT_WOULD_OVERFLOW';

        $out = $this->pick('q', $wide)['output'];
        foreach (explode("\n", str_replace("\r", '', self::plain($out))) as $line) {
            self::assertLessThanOrEqual(80, mb_strlen($line, 'UTF-8'), "line within 80: {$line}");
        }
    }

    public function testLineShellParitySelectsByNumberAndShowsContext(): void
    {
        $state = $this->state();
        $state['term_shell_mode'] = 'line';

        $r = $this->pick("2\r\n", $this->areas(3), null, false, new LineShell($this->bbs), $state);

        self::assertSame('select', $r['result']['action']);
        self::assertSame('L33TSPEAK', $r['result']['area']['tag']);
        self::assertStringContainsString('Areas you follow - 3', self::plain($r['output']));
    }
}
