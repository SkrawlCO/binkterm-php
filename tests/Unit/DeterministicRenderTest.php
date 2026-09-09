<?php

declare(strict_types=1);

require_once __DIR__ . '/Support/TerminalRenderHarness.php';
require_once __DIR__ . '/../../telnet/src/TelnetUtils.php';
require_once __DIR__ . '/../../telnet/src/TerminalBoxRenderer.php';
require_once __DIR__ . '/../../telnet/src/BbsSession.php';

use BinktermPHP\Tests\Support\TerminalRenderHarness;
use BinktermPHP\TelnetServer\BbsSession;
use BinktermPHP\TelnetServer\TelnetUtils;
use BinktermPHP\TelnetServer\TerminalBoxRenderer;
use BinktermPHP\TelnetServer\TerminalRenderContext;
use PHPUnit\Framework\TestCase;

/**
 * F2 — deterministic render foundation.
 *
 * Exercises real render helpers through the harness at every
 * {geometry} x {charset} x {colour} cell, with no socket / session / DB, and
 * asserts geometry-safe layout, glyph + charset fallback, and colour behaviour.
 */
final class DeterministicRenderTest extends TestCase
{
    /**
     * Drive TerminalBoxRenderer::renderBox against a harness context by
     * injecting the context into a minimally-constructed BbsSession (the box
     * renderer takes a BbsSession; the render accessors delegate to the
     * context).
     */
    private function renderBox(TerminalRenderContext $ctx, string $title, array $lines): string
    {
        $conn = fopen('php://temp', 'r+');
        $session = new BbsSession($conn, 'http://127.0.0.1', false, false, false, false);

        $rc = new \ReflectionProperty($session, 'renderContext');
        $rc->setAccessible(true);
        $rc->setValue($session, $ctx);
        $cp = new \ReflectionProperty($session, 'capabilities');
        $cp->setAccessible(true);
        $cp->setValue($session, $ctx->capabilities());

        // Keep the process-global colour flag consistent with the context so the
        // TelnetUtils static path inside renderBox matches.
        TelnetUtils::setAnsiColorEnabled($ctx->isColorEnabled());

        $state = ['cols' => $ctx->cols(), 'rows' => $ctx->rows()];
        (new TerminalBoxRenderer($session))->renderBox($conn, $state, $title, $lines);

        $sink = $ctx->sink();
        // renderBox writes through the context sink; also anything that slipped
        // to the raw resource (should be nothing).
        rewind($conn);
        $direct = stream_get_contents($conn);
        fclose($conn);

        self::assertSame('', $direct, 'nothing should bypass the context sink');

        return method_exists($sink, 'getBytes') ? $sink->getBytes() : '';
    }

    protected function tearDown(): void
    {
        TelnetUtils::setAnsiColorEnabled(true);
    }

    /**
     * renderBox() must not emit anything to the PHP error log — it once carried
     * a stray `error_log('DEBUG topBorder …')` that fired on every box render.
     */
    public function testRenderBoxEmitsNothingToTheErrorLog(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'renderbox_errlog_');
        $previous = ini_get('error_log');
        ini_set('error_log', $logFile);

        try {
            $ctx = TerminalRenderHarness::at(80, 24)->charset('cp437')->color(true)->context();
            $this->renderBox($ctx, 'Café → Status', ['line one', str_repeat('overflow ', 30)]);
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }

        $contents = (string) file_get_contents($logFile);
        @unlink($logFile);

        self::assertSame('', trim($contents), "renderBox wrote to the error log: {$contents}");
    }

    /** @dataProvider matrixProvider */
    public function testRenderBoxIsGeometryAndCharsetSafe(string $name, string $geo, string $charset, bool $color): void
    {
        $h = TerminalRenderHarness::geometry($geo)->charset($charset)->color($color);
        $ctx = $h->context();

        $title = "Café → Status";
        $lines = [
            'short',
            "unicode \xe2\x94\x9c\xe2\x94\x80 arrow \xe2\x86\x92 accent \xc3\xa9 tail",
            str_repeat('overflow ', 40),
        ];

        $bytes = $this->renderBox($ctx, $title, $lines);
        self::assertNotSame('', $bytes, "{$name}: something rendered");

        // No rendered content line exceeds the terminal width.
        $width = $ctx->cols();
        foreach (TerminalRenderHarness::contentLines($bytes) as $line) {
            self::assertLessThanOrEqual(
                $width,
                TerminalRenderHarness::visibleWidth($line),
                "{$name}: line within {$width} cols: " . json_encode($line)
            );
        }

        // Colour behaviour. A mono session may still emit a bare reset (\e[0m /
        // \e[m) defensively after truncation — that is structural, not colour.
        // What it must NOT emit is a colour/attribute-setting SGR.
        $colourSetting = preg_replace('/\033\[0?m/', '', $bytes);
        $hasColourSgr = (bool) preg_match('/\033\[[0-9;]*[0-9][0-9;]*m/', $colourSetting);
        $hasAnySgr = (bool) preg_match('/\033\[[0-9;]*m/', $bytes);
        if ($color) {
            self::assertTrue($hasAnySgr, "{$name}: colour session emits SGR");
        } else {
            self::assertFalse($hasColourSgr, "{$name}: mono session emits no colour-setting SGR");
        }

        // Charset: an ASCII terminal must emit no bytes >= 0x80.
        if ($charset === 'ascii') {
            self::assertSame(
                0,
                preg_match('/[\x80-\xff]/', $bytes),
                "{$name}: ascii session is 7-bit clean"
            );
        }
        // A CP437 terminal must render frame glyphs as CP437 bytes, never as
        // UTF-8 box-drawing sequences.
        if ($charset === 'cp437') {
            self::assertSame(
                0,
                preg_match('/\xE2\x94|\xE2\x95/', $bytes),
                "{$name}: cp437 emits no UTF-8 box-drawing sequences"
            );
            self::assertMatchesRegularExpression(
                '/[\xB3\xC4\xBA\xCD\xC9\xBB\xC8\xBC\xDA\xBF\xC0\xD9]/',
                $bytes,
                "{$name}: cp437 uses CP437 frame bytes"
            );
        }
    }

    public static function matrixProvider(): array
    {
        $out = [];
        foreach (array_keys(TerminalRenderHarness::GEOMETRIES) as $geo) {
            foreach (TerminalRenderHarness::CHARSETS as $cs) {
                foreach ([true, false] as $color) {
                    $name = "{$geo}/{$cs}/" . ($color ? 'color' : 'mono');
                    $out[$name] = [$name, $geo, $cs, $color];
                }
            }
        }

        return $out;
    }

    public function testGlyphFallbackMatrixThroughTheContext(): void
    {
        // heavy on utf8 keeps heavy; on cp437 -> classic; on ascii -> ascii.
        self::assertSame('━', TerminalRenderHarness::at(80, 24)->charset('utf8')->borderStyle('heavy')->context()->lineDrawingChars()['h']);
        self::assertSame('─', TerminalRenderHarness::at(80, 24)->charset('cp437')->borderStyle('heavy')->context()->lineDrawingChars()['h']);
        self::assertSame('-', TerminalRenderHarness::at(80, 24)->charset('ascii')->borderStyle('heavy')->context()->lineDrawingChars()['h']);
    }

    public function testSelectorRowsMatchesLegacyTelnetUtils(): void
    {
        $legacy = new \ReflectionMethod(TelnetUtils::class, 'getSelectorRows');
        $legacy->setAccessible(true);

        foreach ([[80, 24], [132, 36], [132, 51], [80, 25]] as [$c, $r]) {
            foreach ([null, 'SYNCTERM', 'SYNCTERM 1.3', 'XTERM-256COLOR', 'NETRUNNER'] as $client) {
                $ctx = TerminalRenderHarness::at($c, $r)->clientType($client)->context();
                $legacyRows = $legacy->invoke(null, [
                    'rows' => $r,
                    'terminal_type' => (string) $client,
                ]);
                self::assertSame(
                    $legacyRows,
                    $ctx->selectorRows(),
                    "selectorRows parity {$c}x{$r} client=" . var_export($client, true)
                );
            }
        }
    }

    public function testWrapTextLinesIsGeometryDriven(): void
    {
        $text = "one two three four five six seven eight nine ten eleven twelve";
        foreach ([20, 40, 80] as $w) {
            $lines = TelnetUtils::wrapTextLines($text, $w);
            foreach ($lines as $line) {
                self::assertLessThanOrEqual($w, mb_strlen($line), "wrap within {$w}");
            }
        }
        // Empty lines preserved.
        self::assertSame(['', 'a', ''], TelnetUtils::wrapTextLines("\na\n", 20));
    }

    public function testBuildStatusBarClampsToOneLine(): void
    {
        $segments = [];
        for ($i = 0; $i < 30; $i++) {
            $segments[] = ['text' => "SEG{$i} ", 'color' => "\033[31m"];
        }
        foreach ([80, 132] as $w) {
            $bar = TelnetUtils::buildStatusBar($segments, $w);
            self::assertStringNotContainsString("\n", $bar, "status bar single line at {$w}");
            self::assertLessThanOrEqual(
                $w,
                TerminalRenderHarness::visibleWidth($bar),
                "status bar within {$w} cols"
            );
        }
    }

    public function testStandardMatrixHasEighteenCells(): void
    {
        self::assertCount(18, TerminalRenderHarness::standardMatrix());
    }
}
