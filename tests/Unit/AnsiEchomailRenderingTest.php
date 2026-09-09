<?php

declare(strict_types=1);

require_once __DIR__ . '/../../telnet/src/OutputSink.php';
require_once __DIR__ . '/../../telnet/src/BufferSink.php';
require_once __DIR__ . '/../../telnet/src/SocketSink.php';
require_once __DIR__ . '/../../telnet/src/GlyphPolicy.php';
require_once __DIR__ . '/../../telnet/src/TerminalCapabilities.php';
require_once __DIR__ . '/../../telnet/src/TerminalRenderContext.php';
require_once __DIR__ . '/../../telnet/src/TelnetUtils.php';
require_once __DIR__ . '/../../telnet/src/TerminalMarkupRenderer.php';
require_once __DIR__ . '/../../telnet/src/TerminalLineEditor.php';
require_once __DIR__ . '/../../telnet/src/TerminalLineHistory.php';
require_once __DIR__ . '/../../telnet/src/TerminalBoxRenderer.php';
require_once __DIR__ . '/../../telnet/src/BbsSession.php';

use BinktermPHP\I18n\Translator;
use BinktermPHP\TelnetServer\BbsSession;
use BinktermPHP\TelnetServer\BufferSink;
use BinktermPHP\TelnetServer\TelnetUtils;
use BinktermPHP\TelnetServer\TerminalCapabilities;
use BinktermPHP\TelnetServer\TerminalMarkupRenderer;
use BinktermPHP\TelnetServer\TerminalRenderContext;
use PHPUnit\Framework\TestCase;

/**
 * Regression for the ANSI Echomail rendering failure (specimen: ANN.DOORS@anet
 * "[ANSI] A-Net Game Server ...", id 6488).
 *
 * The stored body was well-formed — real ESC bytes, SGR-only, CP437 block glyphs
 * stored as UTF-8. The viewer destroyed it: byte-oriented wordwrap(cut:true)
 * bisected multi-byte glyphs into invalid UTF-8 lines, then per-line
 * iconv('UTF-8','CP437//IGNORE') aborted (returns false) and the byte-strip
 * fallback deleted every ESC, leaving literal "[0;33m" fragments. Only CP437 /
 * ASCII terminals were affected.
 */
final class AnsiEchomailRenderingTest extends TestCase
{
    /** U+2584 LOWER HALF BLOCK, as stored (UTF-8). */
    private const BLK = "\u{2584}";

    private function session(string $charset): BbsSession
    {
        $conn = fopen('php://temp', 'r+');
        $bbs  = new BbsSession($conn, 'http://127.0.0.1', false, false, false, false);
        $caps = TerminalCapabilities::unknown();
        $ctx  = new TerminalRenderContext(
            new BufferSink(), $caps, 80, 24, $charset, true, $charset === 'ascii', [], 'en', new Translator()
        );
        foreach (['renderContext' => $ctx, 'capabilities' => $caps] as $p => $v) {
            $r = new \ReflectionProperty($bbs, $p);
            $r->setAccessible(true);
            $r->setValue($bbs, $v);
        }

        return $bbs;
    }

    /** One line of SGR + block glyphs, no spaces, wider than the wrap width. */
    private function artLine(int $glyphs): string
    {
        return "\033[0;33m" . str_repeat(self::BLK, $glyphs) . "\033[1;43m"
             . str_repeat(self::BLK, $glyphs) . "\033[0m";
    }

    // ===== wrapTextLines =====

    public function testWrapNeverBisectsAMultibyteGlyphOrAnEscapeSequence(): void
    {
        $lines = TelnetUtils::wrapTextLines($this->artLine(60), 40);

        self::assertGreaterThan(1, count($lines), 'the long line wrapped');
        foreach ($lines as $i => $line) {
            self::assertTrue(mb_check_encoding($line, 'UTF-8'), "line {$i} is valid UTF-8");
            // No dangling ESC (an escape sequence split across the wrap point).
            self::assertDoesNotMatchRegularExpression('/\033$/', $line, "line {$i} has no dangling ESC");
            self::assertDoesNotMatchRegularExpression('/\033\[[0-9;]*$/', $line, "line {$i} has no truncated CSI");
        }
    }

    public function testWrapPreservesEverySgrSequence(): void
    {
        $src   = implode("\n", [$this->artLine(50), $this->artLine(30), 'plain tail line']);
        $before = preg_match_all('/\033\[[0-9;]*m/', $src);
        $after  = preg_match_all('/\033\[[0-9;]*m/', implode("\n", TelnetUtils::wrapTextLines($src, 38)));

        self::assertSame($before, $after, 'no SGR sequence lost to wrapping');
    }

    public function testWrapVisibleWidthIgnoresAnsiAndCountsCodepoints(): void
    {
        foreach (TelnetUtils::wrapTextLines($this->artLine(60), 40) as $line) {
            $visible = preg_replace('/\033\[[0-9;?]*[ -\/]*[@-~]/', '', $line);
            self::assertLessThanOrEqual(40, mb_strlen($visible), 'visible width within bound');
        }
    }

    /**
     * @dataProvider asciiWrapCases
     */
    public function testAsciiWrappingStaysIdenticalToTheHistoricalWordwrap(string $text, int $width): void
    {
        $old = $text === '' ? [''] : explode("\n", wordwrap($text, $width, "\n", true));
        self::assertSame($old, TelnetUtils::wrapTextLines($text, $width));
    }

    public static function asciiWrapCases(): array
    {
        return [
            'sentence'     => ['The quick brown fox jumps over the lazy dog and keeps on running past noon', 40],
            'long word'    => ['antidisestablishmentarianism supercalifragilisticexpialidocious pneumonoultramicroscopicsilicovolcanoconiosis', 30],
            'short'        => ['under the limit', 40],
            'empty'        => ['', 40],
            'many singles' => ['a b c d e f g h i j k l m n o p q r s t u v w x y z 1 2 3 4 5 6 7 8 9 0', 20],
        ];
    }

    // ===== encodeForTerminal / convertToCp437 =====

    public function testCp437EncodeKeepsAnsiWhenAWrappedLineIsInvalidUtf8(): void
    {
        // Simulate the historical damage: a line ending in a truncated glyph.
        $broken = "\033[0;33m" . str_repeat(self::BLK, 4) . "\033[1;43m" . substr(self::BLK, 0, 2);

        $out = $this->session('cp437')->encodeForTerminal($broken);

        self::assertSame(2, substr_count($out, "\033"), 'both SGR escapes survive the conversion');
        self::assertStringContainsString("\033[0;33m", $out);
        self::assertStringContainsString("\033[1;43m", $out);
    }

    public function testCp437EncodeOfWellFormedArtKeepsAllEscapesAndConvertsGlyphs(): void
    {
        $lines = TelnetUtils::wrapTextLines($this->artLine(60), 78);
        $bbs   = $this->session('cp437');

        $encoded = implode("\n", array_map(fn (string $l): string => $bbs->encodeForTerminal($l), $lines));

        self::assertSame(
            preg_match_all('/\033\[[0-9;]*m/', implode("\n", $lines)),
            preg_match_all('/\033\[[0-9;]*m/', $encoded),
            'CP437 conversion keeps every SGR sequence'
        );
        self::assertStringContainsString("\xdc", $encoded, 'U+2584 became the CP437 lower-half-block byte 0xDC');
        self::assertStringNotContainsString('[0;33m ', $encoded); // no literal-parameter leakage
    }

    // ===== stripNonDisplayAnsi (safety) =====

    public function testStripNonDisplayAnsiKeepsSgrButRemovesControlSequences(): void
    {
        $hostile = "\033[2J\033[H"                    // clear + home
                 . "\033]0;pwned\007"                 // OSC window title
                 . "\033]52;c;Zm9v\007"               // OSC 52 clipboard
                 . "\033[0;33mVISIBLE\033[0m"         // legit SGR — keep
                 . "\033[6n"                          // DSR cursor query
                 . "\033Pq~~~\033\\"                  // DCS / sixel
                 . "\033[?1049h";                     // alt screen

        $safe = TerminalMarkupRenderer::stripNonDisplayAnsi($hostile);

        self::assertSame("\033[0;33mVISIBLE\033[0m", $safe);
    }

    public function testStripNonDisplayAnsiIsANoOpForSgrOnlyArt(): void
    {
        $art = $this->artLine(20);
        self::assertSame($art, TerminalMarkupRenderer::stripNonDisplayAnsi($art));
    }

    // ===== the mono path still drops colour =====

    public function testAsciiTerminalStillStripsColourAfterTheFix(): void
    {
        $out = $this->session('ascii')->encodeForTerminal("\033[0;33m" . str_repeat(self::BLK, 3) . "\033[0m");
        // ascii mode transliterates the glyphs; ESC may remain but there is no
        // colour rendering path — the key point is it does not crash / lose text.
        self::assertTrue(mb_check_encoding($out, 'UTF-8'));
    }

    // ===== clipArtLines — ANSI-art rows must not be reflowed =====

    /** Visible cell count of a rendered line (SGR counts zero). */
    private static function visibleWidth(string $line): int
    {
        return mb_strlen(preg_replace('/\033\[[0-9;?]*[ -\/]*[@-~]/', '', $line));
    }

    /** One physical art row: leading SGR then $cells block glyphs, no spaces. */
    private function artRow(int $cells): string
    {
        return "\033[0;33m" . str_repeat(self::BLK, $cells);
    }

    public function testClipArtDoesNotSplitA79CellRowAt80ColumnGeometry(): void
    {
        // Live case: 80-col terminal -> handler passes max(10, cols - 1) = 79.
        $rows = TelnetUtils::clipArtLines($this->artRow(79), 79);

        self::assertCount(1, $rows, 'a 79-cell art row stays a single terminal row');
        self::assertSame(79, self::visibleWidth($rows[0]), 'all 79 cells retained');
    }

    public function testClipArtPreservesEveryAuthoredLineBoundaryOneToOne(): void
    {
        $src = implode("\n", [
            $this->artRow(79),
            $this->artRow(70),
            '',
            $this->artRow(79),
            '  1 game   18 other   35 third',
        ]);

        $rows = TelnetUtils::clipArtLines($src, 79);

        self::assertCount(5, $rows, 'one output row per source line — no reflow, no inserted rows');
        self::assertSame('', $rows[2], 'a blank source line stays blank');
    }

    public function testClipArtNeverInsertsANearEmptyRow(): void
    {
        // The old wordwrap path turned a 79-cell row into [tiny remnant, rest],
        // and the tiny remnant rendered as the black band. clipArtLines must not.
        foreach (TelnetUtils::clipArtLines($this->artRow(79) . "\n" . $this->artRow(79), 78) as $row) {
            self::assertGreaterThan(1, self::visibleWidth($row), 'no near-empty remnant row');
        }
    }

    public function testClipArtClipsAnOverWideRowInsteadOfWrappingIt(): void
    {
        $rows = TelnetUtils::clipArtLines($this->artLine(80), 79); // 160 visible cells

        self::assertCount(1, $rows, 'an over-wide row is clipped to one row, never wrapped');
        self::assertLessThanOrEqual(79, self::visibleWidth($rows[0]), 'clipped to the display width');
    }

    public function testClipArtKeepsTrailingBackgroundFilledCells(): void
    {
        // Trailing spaces under an active background are real artwork, not slack.
        $row  = "\033[43mLABEL     "; // 10 visible cells, 5 trailing spaces
        $rows = TelnetUtils::clipArtLines($row, 79);

        self::assertSame($row, $rows[0], 'no rtrim of colour-filled trailing cells within width');
    }

    public function testClipArtPreservesSafeSgrAndClosesAnOpenFillOnClip(): void
    {
        $unclipped = TelnetUtils::clipArtLines($this->artLine(20), 79)[0]; // fits
        self::assertSame(
            preg_match_all('/\033\[[0-9;]*m/', $this->artLine(20)),
            preg_match_all('/\033\[[0-9;]*m/', $unclipped),
            'every SGR sequence survives when the row is not clipped'
        );

        $clipped = TelnetUtils::clipArtLines("\033[1;43m" . str_repeat(self::BLK, 200), 40)[0];
        self::assertStringEndsWith("\033[0m", $clipped, 'an open background fill is reset at the clip point');
    }

    public function testClipArtRowsStayValidUtf8AndWithinWidth(): void
    {
        foreach (TelnetUtils::clipArtLines($this->artLine(60), 40) as $i => $row) {
            self::assertTrue(mb_check_encoding($row, 'UTF-8'), "row {$i} valid UTF-8");
            self::assertDoesNotMatchRegularExpression('/\033\[[0-9;]*$/', $row, "row {$i} has no truncated CSI");
            self::assertLessThanOrEqual(40, self::visibleWidth($row), "row {$i} within width");
        }
    }

    public function testClipArtOutputStillConvertsCleanlyToCp437(): void
    {
        $bbs  = $this->session('cp437');
        $rows = TelnetUtils::clipArtLines($this->artRow(79), 79);
        $out  = $bbs->encodeForTerminal($rows[0]);

        self::assertStringContainsString("\033[0;33m", $out, 'SGR kept through CP437 conversion');
        self::assertStringContainsString("\xdc", $out, 'U+2584 became the CP437 lower-half-block byte 0xDC');
        self::assertStringNotContainsString('[0;33m ', $out, 'no literal-parameter leakage');
    }

    // ===== the generic ANSI-art gate =====

    public function testArtGateDetectsSgrOnlyBodyAsAnsiAndLeavesProseAlone(): void
    {
        self::assertSame(
            'ansi',
            \BinktermPHP\ArtFormatDetector::detectArtFormat($this->artLine(10), 'CP437')
        );
        self::assertNull(
            \BinktermPHP\ArtFormatDetector::detectArtFormat(
                "Just ordinary prose, long enough to wrap on any sensible terminal width, with no escape sequences at all.",
                'CP437'
            ),
            'prose without ANSI escapes is not treated as art (still word-wrapped)'
        );
    }

    public function testEchomailAndNetmailViewersBothRouteArtThroughClipArtLines(): void
    {
        foreach (['EchomailHandler.php', 'NetmailHandler.php'] as $file) {
            $src = file_get_contents(__DIR__ . '/../../telnet/src/' . $file);
            self::assertStringContainsString('ArtFormatDetector::detectArtFormat', $src, "{$file} gates on the generic detector");
            self::assertStringContainsString('TelnetUtils::clipArtLines', $src, "{$file} renders detected art without reflow");
        }
    }

    // ===== C0 control-byte sanitation (stored 0x02 must not become a glyph) =====

    public function testStripNonDisplayAnsiRemovesRawC0ControlBytes(): void
    {
        // 0x02 is the specimen's decorative bullet before each game name; on a
        // CP437 terminal a raw 0x02 renders as a smiley face.
        self::assertSame('AB', TerminalMarkupRenderer::stripNonDisplayAnsi("A\x02B"));

        $withControls = "X\x01\x02\x03\x04\x05\x06\x07\x08\x0B\x0C\x0E\x0F\x1F" . "Y";
        self::assertSame('XY', TerminalMarkupRenderer::stripNonDisplayAnsi($withControls));
    }

    public function testStripNonDisplayAnsiKeepsTabCrLfAndSgrEsc(): void
    {
        self::assertSame(
            "a\tb\r\nc\033[33md\033[0m",
            TerminalMarkupRenderer::stripNonDisplayAnsi("a\tb\r\nc\033[33md\033[0m")
        );
    }

    public function testStripNonDisplayAnsiStillRemovesCursorAndEraseAfterC0Tightening(): void
    {
        $safe = TerminalMarkupRenderer::stripNonDisplayAnsi(
            "\033[2J\033[10;5H\033[K\x02\033[0;33mKEEP\033[0m\033[6n"
        );
        self::assertSame("\033[0;33mKEEP\033[0m", $safe);
    }
}
