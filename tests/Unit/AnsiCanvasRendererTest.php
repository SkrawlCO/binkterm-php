<?php
/**
 * PHPUnit tests for AnsiCanvasRenderer: resolving cursor-positioned ANSI art
 * onto an off-screen grid and serialising it back to SGR-only display lines.
 *
 * This is a rendering primitive only -- no message-reader integration, no
 * art-detection heuristic, no config toggle. See TerminalTextSanitizerTest
 * for the companion POLICY_POSITIONING coverage that feeds this renderer's
 * input.
 */

require_once __DIR__ . '/../../telnet/src/AnsiCanvasRenderer.php';

use BinktermPHP\TerminalTextSanitizer;
use BinktermPHP\TelnetServer\AnsiCanvasRenderer;
use PHPUnit\Framework\TestCase;

class AnsiCanvasRendererTest extends TestCase
{
    /** Strip SGR codes, leaving only the visible text of a rendered line. */
    private static function visible(string $line): string
    {
        return preg_replace('/\x1b\[[0-9;]*m/', '', $line);
    }

    /** @param string[] $lines */
    private static function visibleAll(array $lines): array
    {
        return array_map(self::visible(...), $lines);
    }

    public function testEmptyInputRendersOneEmptyLine(): void
    {
        $this->assertSame([''], AnsiCanvasRenderer::render('', 80));
    }

    public function testPlainTextPassesThroughUnstyled(): void
    {
        // Every serialised line always carries an SGR prefix (even the
        // default state) and a trailing reset; only the visible text is
        // asserted here.
        $this->assertSame(['hello'], self::visibleAll(AnsiCanvasRenderer::render('hello', 80)));
    }

    /**
     * Two lines positioned out of source order (row 2 written before row 1)
     * must resolve to the correct spatial order in the output -- this is the
     * entire point of the canvas: a positioned byte stream is not read
     * top-to-bottom, but the *result* must be.
     */
    public function testAbsolutePositioningResolvesToCorrectSpatialOrder(): void
    {
        $ansi = "\x1b[2;1Hsecond\x1b[1;1Hfirst";
        $this->assertSame(
            ['first', 'second'],
            self::visibleAll(AnsiCanvasRenderer::render($ansi, 80))
        );
    }

    public function testCursorForwardPlacesTextAtTheRightColumn(): void
    {
        // Move to column 6 (1-indexed) then write; the first 5 cells stay blank.
        $out = AnsiCanvasRenderer::render("\x1b[1;6Hx", 10);
        $this->assertCount(1, $out);
        $visible = preg_replace('/\x1b\[[0-9;]*m/', '', $out[0]);
        $this->assertSame('     x', $visible);
    }

    public function testEraseDisplayClearsPreviouslyWrittenCells(): void
    {
        $ansi = "\x1b[1;1Hkeep-me\x1b[2;1Herase-me\x1b[2;1H\x1b[0J";
        $out = AnsiCanvasRenderer::render($ansi, 80);
        $this->assertSame(['keep-me'], self::visibleAll($out));
    }

    public function testSgrColourStateSurvivesAndResetsPerLine(): void
    {
        $out = AnsiCanvasRenderer::render("\x1b[31mred\x1b[1;1H", 80);
        $this->assertCount(1, $out);
        // fg=31 (red) is applied to the whole run, so the emitted SGR
        // includes code 31 alongside the reset "0" every line starts with.
        $this->assertMatchesRegularExpression('/\x1b\[0;31mred/', $out[0]);
        // Every serialised line is terminated with a full reset.
        $this->assertStringEndsWith("\x1b[0m", $out[0]);
    }

    /**
     * Final renderer output must never contain an absolute cursor-positioning
     * sequence or any other non-SGR control sequence, regardless of how much
     * positioning the input used -- the canvas resolves it away.
     */
    public function testOutputContainsNoPositioningOrOtherControlSequences(): void
    {
        $ansi = "\x1b[2;1Hb\x1b[1;1Ha\x1b[0;32m\x1b[3;1H\x1b[Kc\x1b[s\x1b[u";
        $out = AnsiCanvasRenderer::render($ansi, 80);

        foreach ($out as $line) {
            $this->assertSame(
                0,
                preg_match('/\x1b\[[0-9;]*[A-HJKSTdfsu]/', $line),
                'no absolute positioning/erase/save-restore sequence survives'
            );
            $this->assertSame(
                0,
                preg_match('/\x1b(?!\[[0-9;]*m)/', $line),
                'no non-SGR escape sequence survives'
            );
        }
    }

    /**
     * End-to-end: TerminalTextSanitizer::sanitize(..., POLICY_POSITIONING)
     * feeding AnsiCanvasRenderer, on a small synthetic specimen combining
     * absolute positioning with an OSC injection attempt. The injection must
     * be gone before the canvas ever sees it, and the canvas's own output
     * must still carry only SGR/text.
     */
    public function testSanitizerPositioningPolicyFeedsCanvasSafely(): void
    {
        $raw = "\x1b]0;pwned\x07\x1b[2;1Hworld\x1b[1;1Hhello ";
        $sanitized = TerminalTextSanitizer::sanitize($raw, TerminalTextSanitizer::POLICY_POSITIONING);

        $this->assertStringNotContainsString("\x1b]", $sanitized, 'OSC injection stripped before the canvas sees it');

        $out = AnsiCanvasRenderer::render($sanitized, 80);

        $this->assertSame(['hello', 'world'], self::visibleAll($out));
        foreach ($out as $line) {
            $this->assertSame(0, preg_match('/\x1b\[[0-9;]*[A-HJKSTdfsu]/', $line));
        }
    }
}
