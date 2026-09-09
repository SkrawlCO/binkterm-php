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
use BinktermPHP\TelnetServer\TelnetUtils;
use BinktermPHP\TelnetServer\TerminalCapabilities;
use BinktermPHP\TelnetServer\TerminalRenderContext;
use BinktermPHP\TelnetServer\TuiShell;
use PHPUnit\Framework\TestCase;

/**
 * Terminal Experience Unification M2 — the dense-list primitive rides the
 * proven flat selectable-list key loop; the only renderer change is the new
 * `header_lines` option (a fixed informational block under the title). These
 * pin that: the no-option path is byte-for-byte unchanged, and header lines
 * push the grid down without a new key loop.
 */
final class DenseListSelectableListTest extends TestCase
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

    private function runList(array $options): string
    {
        fwrite($this->cli, "\r");
        fflush($this->cli);

        TelnetUtils::runSelectableList(
            $this->srv,
            $this->state,
            $this->bbs,
            "\033[36m\033[1mAreas\033[0m",
            [' 1) ALPHA     agoranet   First area', ' 2) BETA      agoranet   Second area'],
            1,
            1,
            0,
            [['text' => 'Q', 'color' => TelnetUtils::ANSI_RED], ['text' => ' Quit', 'color' => TelnetUtils::ANSI_BLUE]],
            [],
            null,
            $options,
            []
        );

        $out = '';
        while (($chunk = @fread($this->cli, 8192)) !== false && $chunk !== '') {
            $out .= $chunk;
        }

        return $out;
    }

    public function testNoHeaderLinesOptionIsByteForByteUnchanged(): void
    {
        $bare = $this->runList([]);
        $this->tearDown();
        $this->setUp();
        $empty = $this->runList(['header_lines' => []]);

        self::assertSame(bin2hex($bare), bin2hex($empty));
    }

    public function testHeaderLineRendersUnderTheTitleAndAboveTheFirstRow(): void
    {
        $out = $this->runList(['header_lines' => ["\033[2mAreas you follow - 34\033[0m"]]);
        $plain = preg_replace('/\033\[[0-9;?]*[A-Za-z]/', '', $out) ?? '';

        $titlePos  = strpos($plain, 'Areas');
        $ctxPos    = strpos($plain, 'Areas you follow - 34');
        $firstRow  = strpos($plain, 'ALPHA');

        self::assertNotFalse($ctxPos);
        self::assertNotFalse($firstRow);
        self::assertLessThan($ctxPos, $titlePos, 'title before context');
        self::assertLessThan($firstRow, $ctxPos, 'context before the first row');
    }

    public function testHeaderLinePushesTheFirstRowDownByExactlyOneScreenRow(): void
    {
        $without = $this->runList([]);
        $this->tearDown();
        $this->setUp();
        $with = $this->runList(['header_lines' => ['CONTEXT']]);

        self::assertSame(2, self::rowOf($without, 'ALPHA'), 'row 1 title, row 2 first item');
        self::assertSame(3, self::rowOf($with, 'ALPHA'), 'row 1 title, row 2 context, row 3 first item');
    }

    /**
     * The screen row a token lands on in the flat renderer's initial paint,
     * which is a sequential run of writeLine() after "\033[2J\033[H".
     */
    private static function rowOf(string $bytes, string $needle): int
    {
        $upTo = substr($bytes, 0, (int) strpos($bytes, $needle));
        $plain = preg_replace('/\033\[[0-9;?]*[A-Za-z]/', '', $upTo) ?? '';
        $plain = str_replace("\r", '', $plain);

        return substr_count($plain, "\n") + 1;
    }

    public function testLineShellRendersHeaderLinesInPlainMode(): void
    {
        $this->state['term_shell_mode'] = 'line';
        fwrite($this->cli, "Q\r\n");
        fflush($this->cli);

        (new LineShell($this->bbs))->showSelectableList(
            $this->srv,
            $this->state,
            'Areas',
            [' 1) ALPHA', ' 2) BETA'],
            1,
            1,
            0,
            [],
            [],
            null,
            ['header_lines' => ["\033[2mAreas you follow - 34\033[0m"]],
            []
        );

        $out = '';
        while (($chunk = @fread($this->cli, 8192)) !== false && $chunk !== '') {
            $out .= $chunk;
        }
        $plain = preg_replace('/\033\[[0-9;?]*[A-Za-z]/', '', $out) ?? '';

        self::assertStringContainsString('Areas you follow - 34', $plain);
        self::assertStringNotContainsString("\033[2m", $out, 'line shell strips SGR from the header line');
    }
}
