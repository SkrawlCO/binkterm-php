<?php

declare(strict_types=1);

require_once __DIR__ . '/../../telnet/src/OutputSink.php';
require_once __DIR__ . '/../../telnet/src/SocketSink.php';
require_once __DIR__ . '/../../telnet/src/BufferSink.php';
require_once __DIR__ . '/../../telnet/src/GlyphPolicy.php';
require_once __DIR__ . '/../../telnet/src/TerminalCapabilities.php';
require_once __DIR__ . '/../../telnet/src/TerminalRenderContext.php';
require_once __DIR__ . '/../../telnet/src/TelnetUtils.php';
require_once __DIR__ . '/../../telnet/src/TerminalBoxRenderer.php';
require_once __DIR__ . '/../../telnet/src/BbsSession.php';
require_once __DIR__ . '/../../telnet/src/TerminalLineEditor.php';
require_once __DIR__ . '/../../telnet/src/TerminalLineHistory.php';
require_once __DIR__ . '/../../telnet/src/TerminalShellInterface.php';
require_once __DIR__ . '/../../telnet/src/TuiShell.php';
require_once __DIR__ . '/../../telnet/src/LineShell.php';

use BinktermPHP\I18n\Translator;
use BinktermPHP\TelnetServer\BbsSession;
use BinktermPHP\TelnetServer\LineShell;
use BinktermPHP\TelnetServer\SocketSink;
use BinktermPHP\TelnetServer\TerminalCapabilities;
use BinktermPHP\TelnetServer\TerminalRenderContext;
use BinktermPHP\TelnetServer\TuiShell;
use BinktermPHP\Terminal\Presentation\Directory;
use BinktermPHP\Terminal\Presentation\DirectoryRow;
use BinktermPHP\Terminal\Presentation\DirectorySection;
use PHPUnit\Framework\TestCase;

/**
 * Terminal Experience Unification, Part 2 — the shell contract. Drives the real
 * {@see TuiShell} / {@see LineShell} `showDirectory()` over a paired socket with
 * a real {@see BbsSession} input pipeline: keystrokes in, selection payload out.
 * The navigation/render/resize behaviour is the proven selectable-list path;
 * these assert the composition wrapper and the index -> value mapping.
 */
final class ShellDirectoryTest extends TestCase
{
    /** @var resource */
    private $srv;
    /** @var resource */
    private $cli;
    private BbsSession $bbs;
    private array $state;

    protected function setUp(): void
    {
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

        $this->state = [
            'input_echo' => true, 'cols' => 80, 'rows' => 24, 'locale' => 'en', 'pushback' => '',
            'last_activity' => time(), 'idle_warned' => false,
            'idle_warning_timeout' => 300, 'idle_disconnect_timeout' => 420,
        ];
    }

    protected function tearDown(): void
    {
        @fclose($this->srv);
        @fclose($this->cli);
    }

    private function client(string $bytes): void
    {
        fwrite($this->cli, $bytes);
        fflush($this->cli);
    }

    private function serverOutput(): string
    {
        $out = '';
        while (($chunk = @fread($this->cli, 8192)) !== false && $chunk !== '') {
            $out .= $chunk;
        }

        return $out;
    }

    private function directory(): Directory
    {
        return new Directory(
            'Crossroads',
            'Where people, games, and worlds meet.',
            [
                new DirectorySection('', [
                    new DirectoryRow('Live Now', '2 callers in 1 Experience', null, 'live_now'),
                    new DirectoryRow('Your Places', 'You have no active places right now.', null, 'your_places'),
                ], true),
                new DirectorySection('Curated Experiences', [
                    new DirectoryRow('ascii-royale', 'Last player standing.', 'Multiplayer', 'ascii-royale'),
                ]),
                new DirectorySection('Game Hall', [
                    new DirectoryRow('Legend of the Red Dragon', 'Fantasy RPG.', 'Multiplayer', 'lord'),
                ]),
            ],
            ['Recently in the Crossroads', 'Bard played LORD - 47m ago'],
        );
    }

    // ===== TuiShell =====

    public function testTuiEnterSelectsTheFirstRowAndReturnsItsPayload(): void
    {
        $this->client("\r");
        $result = (new TuiShell($this->bbs))->showDirectory($this->srv, $this->state, $this->directory());

        self::assertSame('select', $result['action']);
        self::assertSame('live_now', $result['value']);
        self::assertSame(0, $result['index']);
    }

    public function testTuiArrowDownThenEnterSelectsAcrossASectionBoundary(): void
    {
        // rows: 0 Live Now, 1 Your Places, 2 ascii-royale, 3 LORD
        $this->client("\033[B\033[B\r");
        $result = (new TuiShell($this->bbs))->showDirectory($this->srv, $this->state, $this->directory());

        self::assertSame('select', $result['action']);
        self::assertSame('ascii-royale', $result['value']);
        self::assertSame(2, $result['index']);
    }

    public function testTuiQuitReturnsBack(): void
    {
        $this->client('q');
        $result = (new TuiShell($this->bbs))->showDirectory($this->srv, $this->state, $this->directory());

        self::assertSame('back', $result['action']);
        self::assertNull($result['value']);
        self::assertSame(-1, $result['index']);
    }

    public function testTuiRendersTheMastheadTaglineHeadingsAndContextBlock(): void
    {
        $this->client("\r");
        (new TuiShell($this->bbs))->showDirectory($this->srv, $this->state, $this->directory());

        $out = preg_replace('/\033\[[0-9;?]*[A-Za-z]/', '', $this->serverOutput()) ?? '';
        self::assertStringContainsString('Crossroads', $out);
        self::assertStringContainsString('Where people, games, and worlds meet.', $out);
        self::assertStringContainsString('CURATED EXPERIENCES', $out);
        self::assertStringContainsString('GAME HALL', $out);
        self::assertStringContainsString('Recently in the Crossroads', $out);
        self::assertStringContainsString('Last player standing.', $out, 'row description is shown');
    }

    // ===== LineShell parity =====

    public function testLineShellSelectsByNumberAndReturnsThePayload(): void
    {
        $this->state['term_shell_mode'] = 'line';
        $this->client("3\r\n");
        $result = (new LineShell($this->bbs))->showDirectory($this->srv, $this->state, $this->directory());

        self::assertSame('select', $result['action']);
        self::assertSame('ascii-royale', $result['value']);
        self::assertSame(2, $result['index']);
    }

    public function testLineShellQuitReturnsBack(): void
    {
        $this->state['term_shell_mode'] = 'line';
        $this->client("q\r\n");
        $result = (new LineShell($this->bbs))->showDirectory($this->srv, $this->state, $this->directory());

        self::assertSame('back', $result['action']);
        self::assertNull($result['value']);
    }

    public function testLineShellRendersHeadingsAndContext(): void
    {
        $this->state['term_shell_mode'] = 'line';
        $this->client("q\r\n");
        (new LineShell($this->bbs))->showDirectory($this->srv, $this->state, $this->directory());

        $out = preg_replace('/\033\[[0-9;?]*[A-Za-z]/', '', $this->serverOutput()) ?? '';
        self::assertStringContainsString('CURATED EXPERIENCES', $out);
        self::assertStringContainsString('Recently in the Crossroads', $out);
        self::assertStringContainsString('Fantasy RPG.', $out);
    }
}
