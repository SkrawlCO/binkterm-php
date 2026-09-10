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
require_once __DIR__ . '/../../telnet/src/TerminalShellFactory.php';
require_once __DIR__ . '/../../telnet/src/MailUtils.php';
require_once __DIR__ . '/../../telnet/src/FileHandler.php';

use BinktermPHP\I18n\Translator;
use BinktermPHP\TelnetServer\BbsSession;
use BinktermPHP\TelnetServer\FileHandler;
use BinktermPHP\TelnetServer\LineShell;
use BinktermPHP\TelnetServer\SocketSink;
use BinktermPHP\TelnetServer\TerminalCapabilities;
use BinktermPHP\TelnetServer\TerminalRenderContext;
use BinktermPHP\TelnetServer\TuiShell;
use PHPUnit\Framework\TestCase;

/**
 * Terminal Experience Unification M3 — File Areas is the second consumer of the
 * dense-list primitive (after Echomail Areas / M2). Drives the real (private)
 * {@see FileHandler::pickFileArea()} over a paired socket with a real
 * {@see BbsSession} input pipeline: keystrokes in, selection out.
 *
 * These assert the new presentation (location identity, compact context, page
 * indicator, tag/description/file-count grid) and that every selection / paging
 * / back semantic is unchanged from the pre-M3 flat list.
 */
final class FileAreaDenseListTest extends TestCase
{
    /** @var resource */
    private $srv;
    /** @var resource */
    private $cli;
    private BbsSession $bbs;
    private TerminalRenderContext $ctx;
    private FileHandler $handler;

    protected function setUp(): void
    {
        [$this->srv, $this->cli] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        stream_set_blocking($this->srv, false);
        stream_set_blocking($this->cli, false);

        $this->bbs = new BbsSession($this->srv, 'http://127.0.0.1', false, false, false, false);
        $caps = TerminalCapabilities::unknown()
            ->withCharsetSupport(TerminalCapabilities::CHARSET_UTF8)
            ->withColorSupport(TerminalCapabilities::COLOR_ANSI);
        $this->ctx = new TerminalRenderContext(
            new SocketSink($this->srv), $caps, 80, 24, 'utf8', true, false, [], 'en', new Translator()
        );
        foreach (['renderContext' => $this->ctx, 'capabilities' => $caps] as $p => $v) {
            $r = new \ReflectionProperty($this->bbs, $p);
            $r->setAccessible(true);
            $r->setValue($this->bbs, $v);
        }

        $this->handler = new FileHandler($this->bbs, 'http://127.0.0.1');
    }

    protected function tearDown(): void
    {
        @fclose($this->srv);
        @fclose($this->cli);
    }

    private function state(int $cols = 80, int $rows = 24): array
    {
        return [
            'input_echo' => true, 'cols' => $cols, 'rows' => $rows, 'locale' => 'en', 'pushback' => '',
            'user_id' => 7, 'last_activity' => time(), 'idle_warned' => false,
            'idle_warning_timeout' => 300, 'idle_disconnect_timeout' => 420,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function areas(int $n = 3): array
    {
        $seed = [
            ['id' => 1, 'tag' => 'GENERAL',  'description' => 'General uploads',       'file_count' => 12],
            ['id' => 2, 'tag' => 'UTILS',    'description' => 'System utilities',      'file_count' => 3],
            ['id' => 3, 'tag' => 'DOORS',    'description' => 'Door game archives',    'file_count' => 47],
        ];
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = $seed[$i] ?? [
                'id' => $i + 10, 'tag' => 'AREA' . $i, 'description' => 'Area ' . $i, 'file_count' => $i,
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
        int $perPage = 17,
        $shell = null,
        ?array $stateOverride = null
    ): array {
        fwrite($this->cli, $keys);
        fflush($this->cli);

        $state = $stateOverride ?? $this->state();
        $this->ctx->setGeometry((int)$state['cols'], (int)$state['rows']);
        $shell ??= new TuiShell($this->bbs);

        $m = new \ReflectionMethod(FileHandler::class, 'pickFileArea');
        $m->setAccessible(true);

        $args = [$this->srv, &$state, $areas, 1, $perPage, $shell];
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

    /** @return string[] visible screen lines, ANSI stripped, CR removed */
    private static function lines(string $s): array
    {
        return explode("\n", str_replace("\r", '', self::plain($s)));
    }

    public function testScreenShowsLocationIdentityContextAndPageIndicator(): void
    {
        $r = $this->pick('q', $this->areas(3));
        $plain = self::plain($r['output']);

        self::assertStringContainsString('Files', $plain);
        self::assertStringContainsString('File Areas', $plain);
        self::assertStringContainsString('Page 1/1', $plain);
        // context line: 3 areas, 12 + 3 + 47 = 62 files
        self::assertStringContainsString('3 areas - 62 files', $plain);
        self::assertSame('quit', $r['result']['action']);
    }

    public function testAreaTagDescriptionAndFileCountStayVisible(): void
    {
        $plain = self::plain($this->pick('q', $this->areas(3))['output']);

        self::assertStringContainsString('GENERAL', $plain);
        self::assertStringContainsString('General uploads', $plain);
        self::assertStringContainsString('12 file(s)', $plain);
        self::assertStringContainsString('DOORS', $plain);
        self::assertStringContainsString('Door game archives', $plain);
        self::assertStringContainsString('47 file(s)', $plain);
    }

    public function testFileCountsAreRightAligned(): void
    {
        $out = $this->pick('q', $this->areas(3))['output'];
        $rows = array_values(array_filter(
            self::lines($out),
            static fn(string $l): bool => (bool)preg_match('/^\s*\d+\)\s.*file\(s\)/', $l)
        ));
        self::assertGreaterThanOrEqual(3, count($rows));
        // The right-aligned file-count cell ends at the same column in each row.
        $ends = array_map(
            static fn(string $l): int => mb_strpos($l, 'file(s)', 0, 'UTF-8') + 7,
            $rows
        );
        self::assertCount(1, array_unique($ends), 'file-count cells share a right edge');
    }

    public function testEnterSelectsTheHighlightedArea(): void
    {
        $r = $this->pick("\r", $this->areas(3));

        self::assertSame('select', $r['result']['action']);
        self::assertSame('GENERAL', $r['result']['area']['tag']);
        self::assertSame(1, $r['result']['area']['id']);
    }

    public function testArrowDownThenEnterSelectsTheSecondArea(): void
    {
        $r = $this->pick("\033[B\r", $this->areas(3));

        self::assertSame('select', $r['result']['action']);
        self::assertSame('UTILS', $r['result']['area']['tag']);
    }

    public function testNumericJumpStillSelectsByRowNumber(): void
    {
        // Menu key reader eats a CR immediately after a printable; space the keys.
        $r = $this->pick("3\n\r", $this->areas(3));

        self::assertSame('select', $r['result']['action']);
        self::assertSame('DOORS', $r['result']['area']['tag']);
    }

    public function testRightArrowPagesForwardAndKeepsIndicatorCoherent(): void
    {
        $r = $this->pick("\033[C", $this->areas(40), 17);

        self::assertSame('redraw', $r['result']['action']);
        self::assertSame(2, $r['result']['page']);
        // 40 areas / 17 per page = 3 pages.
        self::assertStringContainsString('Page 1/3', self::plain($r['output']));
    }

    public function testQuitReturnsToCaller(): void
    {
        self::assertSame('quit', $this->pick('q', $this->areas(3))['result']['action']);
    }

    public function testNoHorizontalOverflowAt80Columns(): void
    {
        $wide = $this->areas(2);
        $wide[0]['description'] = str_repeat('a very long file area description ', 8);
        $wide[0]['tag'] = 'AN_EXTREMELY_LONG_FILE_AREA_TAG_THAT_OVERFLOWS';
        $wide[0]['file_count'] = 999999;

        foreach (self::lines($this->pick('q', $wide)['output']) as $line) {
            self::assertLessThanOrEqual(80, mb_strlen($line, 'UTF-8'), "line within 80: {$line}");
        }
    }

    public function testNoHorizontalOverflowAtNarrowWidth(): void
    {
        $wide = $this->areas(2);
        $wide[0]['description'] = str_repeat('long ', 30);
        $wide[0]['tag'] = 'VERY_LONG_TAG_NAME_HERE';

        $out = $this->pick('q', $wide, 17, null, $this->state(40, 24))['output'];
        foreach (self::lines($out) as $line) {
            self::assertLessThanOrEqual(40, mb_strlen($line, 'UTF-8'), "line within 40: {$line}");
        }
    }

    public function testLongTagAndDescriptionClipWithEllipsisNoWrap(): void
    {
        $wide = $this->areas(1);
        $wide[0]['tag'] = 'SUPERCALIFRAGILISTIC_AREA_TAG';
        $wide[0]['description'] = 'This description is far too long to fit inside the flexible column at eighty columns and must be clipped';

        $out = $this->pick('q', $wide)['output'];
        $lines = self::lines($out);

        // Exactly one selectable row for the single area (no wrap onto a 2nd line).
        $rowLines = array_values(array_filter(
            $lines,
            static fn(string $l): bool => (bool)preg_match('/^\s*1\)\s/', $l)
        ));
        self::assertCount(1, $rowLines);
        self::assertStringContainsString("\u{2026}", $rowLines[0], 'clipped with an ellipsis');
    }

    public function testDensityAtLeast15AreaRowsAt80x24(): void
    {
        $out = $this->pick('q', $this->areas(20), 17)['output'];
        $rowLines = array_filter(
            self::lines($out),
            static fn(string $l): bool => (bool)preg_match('/^\s*\d+\)\s/', $l)
        );
        self::assertGreaterThanOrEqual(15, count($rowLines), 'at least 15 area rows visible at 80x24');
    }

    public function testExpandedGeometryGainsWidthAndRows(): void
    {
        // A description that fits the flexible column at 132 but not at 80.
        $longDesc = 'Door game archives, utilities, art packs and text files collected over many years of the board';
        $mk = function (int $n) use ($longDesc): array {
            $a = $this->areas($n);
            $a[0]['description'] = $longDesc;
            return $a;
        };

        // perPage mirrors the production formula (rows - 7).
        $narrow = self::plain($this->pick('q', $mk(30), 24 - 7, null, $this->state(80, 24))['output']);
        $wide   = self::plain($this->pick('q', $mk(30), 51 - 7, null, $this->state(132, 51))['output']);

        self::assertGreaterThan(
            preg_match_all('/^\s*\d+\)\s/m', $narrow),
            preg_match_all('/^\s*\d+\)\s/m', $wide),
            'more area rows at 132x51'
        );

        // The long description is clipped at 80 and shown in full at 132.
        self::assertStringNotContainsString($longDesc, $narrow);
        self::assertStringContainsString($longDesc, $wide);

        $widest = 0;
        foreach (self::lines($wide) as $l) {
            $widest = max($widest, mb_strlen(rtrim($l), 'UTF-8'));
        }
        self::assertGreaterThan(80, $widest, 'uses the extra width');
        self::assertLessThanOrEqual(132, $widest);
    }

    public function testLineShellParitySelectsByNumberAndShowsContext(): void
    {
        $state = $this->state();
        $state['term_shell_mode'] = 'line';

        $r = $this->pick("2\r\n", $this->areas(3), 17, new LineShell($this->bbs), $state);

        self::assertSame('select', $r['result']['action']);
        self::assertSame('UTILS', $r['result']['area']['tag']);
        self::assertStringContainsString('3 areas - 62 files', self::plain($r['output']));
    }

    public function testLegacyFlatFallbackWhenNoRenderContext(): void
    {
        // Null the render context: the pre-auth / mono pathway must still render
        // the historical flat row + cyan title, and selection still works.
        $r = new \ReflectionProperty($this->bbs, 'renderContext');
        $r->setAccessible(true);
        $r->setValue($this->bbs, null);

        $res = $this->pick("\r", $this->areas(3));
        self::assertSame('select', $res['result']['action']);
        self::assertSame('GENERAL', $res['result']['area']['tag']);
        $plain = self::plain($res['output']);
        self::assertStringContainsString('File Areas (page 1/1):', $plain);
        self::assertStringContainsString('GENERAL', $plain);
    }
}
