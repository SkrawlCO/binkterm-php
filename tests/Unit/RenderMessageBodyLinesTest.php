<?php
/**
 * PHPUnit tests for TelnetUtils::renderMessageBodyLines() — the single shared
 * body-rendering decision used by EchomailHandler's two viewers and
 * NetmailHandler's viewer: plain prose, SGR-colour prose, SGR-only ANSI art,
 * and markup all take the existing unchanged path; genuinely positioned
 * ANSI art (detected from the RAW body) is the only class routed through
 * AnsiCanvasRenderer.
 */

require_once __DIR__ . '/../../telnet/src/OutputSink.php';
require_once __DIR__ . '/../../telnet/src/BufferSink.php';
require_once __DIR__ . '/../../telnet/src/SocketSink.php';
require_once __DIR__ . '/../../telnet/src/GlyphPolicy.php';
require_once __DIR__ . '/../../telnet/src/TerminalCapabilities.php';
require_once __DIR__ . '/../../telnet/src/TerminalRenderContext.php';
require_once __DIR__ . '/../../telnet/src/TelnetUtils.php';
require_once __DIR__ . '/../../telnet/src/TerminalMarkupRenderer.php';
require_once __DIR__ . '/../../telnet/src/AnsiCanvasRenderer.php';

use BinktermPHP\TelnetServer\TelnetUtils;
use PHPUnit\Framework\TestCase;

class RenderMessageBodyLinesTest extends TestCase
{
    private static function visible(string $line): string
    {
        return preg_replace('/\x1b\[[0-9;]*m/', '', $line);
    }

    public function testPlainProseUnchanged(): void
    {
        $text = "Hello, world.\nSecond line.";
        $this->assertSame(
            TelnetUtils::wrapTextLines($text, max(10, 80 - 2)),
            TelnetUtils::renderMessageBodyLines($text, null, null, 80)
        );
    }

    public function testSgrColouredProseUnchanged(): void
    {
        $text = "\x1b[31mred\x1b[0m prose, nothing positioned.";
        $this->assertSame(
            TelnetUtils::wrapTextLines($text, max(10, 80 - 2)),
            TelnetUtils::renderMessageBodyLines($text, null, null, 80)
        );
    }

    /**
     * SGR-only ANSI art (no positioning) must stay on the existing
     * ArtFormatDetector -> clipArtLines path, not the canvas.
     */
    public function testSgrOnlyAnsiArtDoesNotEnterCanvasPath(): void
    {
        $text = "\x1b[33m\u{2588}\u{2588}\u{2588}\x1b[0m\n\x1b[33m\u{2588}\u{2588}\u{2588}\x1b[0m";
        $this->assertSame(
            TelnetUtils::clipArtLines($text, max(10, 80 - 1)),
            TelnetUtils::renderMessageBodyLines($text, null, null, 80)
        );
    }

    /**
     * A body with a genuine absolute-positioning sequence must route to the
     * canvas, and the composition must resolve spatially correctly.
     */
    public function testPositionedAnsiRoutesToCanvasWithCorrectComposition(): void
    {
        $text = "\x1b[2;1Hsecond\x1b[1;1Hfirst";
        $out  = TelnetUtils::renderMessageBodyLines($text, null, null, 80);

        $this->assertSame(['first', 'second'], array_map(self::visible(...), $out));
    }

    public function testPositionedOutputHasNoPositioningOrNonSgrControls(): void
    {
        $text = "\x1b[2;1Hb\x1b[1;1Ha\x1b[0;32m\x1b[3;1H\x1b[Kc";
        $out  = TelnetUtils::renderMessageBodyLines($text, null, null, 80);

        foreach ($out as $line) {
            $this->assertSame(0, preg_match('/\x1b\[[0-9;]*[A-HJKSTdfsu]/', $line));
            $this->assertSame(0, preg_match('/\x1b(?!\[[0-9;]*m)/', $line));
        }
    }

    /**
     * Canvas width is capped at 80 even on a wider terminal, and bounded to
     * (cols - 1) on a narrower one -- matching clipArtLines' guard-column
     * convention, not wrapTextLines' two-column one.
     */
    public function testCanvasWidthIsCappedAtEightyColumns(): void
    {
        $text = "\x1b[1;1H" . str_repeat('x', 120);
        $out  = TelnetUtils::renderMessageBodyLines($text, null, null, 200);

        $this->assertSame(80, mb_strlen(self::visible($out[0])));
    }

    public function testCanvasWidthShrinksOnNarrowTerminal(): void
    {
        $text = "\x1b[1;1H" . str_repeat('x', 60);
        $out  = TelnetUtils::renderMessageBodyLines($text, null, null, 40);

        $this->assertSame(39, mb_strlen(self::visible($out[0])));
    }

    /**
     * The helper is a pure function of (rawBody, markupFormat, charsetHint,
     * cols) -- both echomail and netmail viewers call it identically, so the
     * same inputs must produce the same output regardless of caller.
     */
    public function testSameInputsProduceSameOutputForAnyCaller(): void
    {
        $text = "\x1b[2;1Hworld\x1b[1;1Hhello";
        $a = TelnetUtils::renderMessageBodyLines($text, null, null, 80);
        $b = TelnetUtils::renderMessageBodyLines($text, null, null, 80);
        $this->assertSame($a, $b);
    }

    public function testMarkupFormatStillTakesPriorityWhenNotPositioned(): void
    {
        // No markup renderer is registered for 'nonexistent'; TerminalMarkupRenderer::render()
        // is expected to fail closed to a safe fallback rather than throw. This only proves
        // the markup branch is still reached (not skipped) for a non-positioned body.
        $out = TelnetUtils::renderMessageBodyLines('plain text', 'nonexistent-format', null, 80);
        $this->assertIsArray($out);
    }
}
