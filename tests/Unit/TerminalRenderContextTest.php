<?php

declare(strict_types=1);

require_once __DIR__ . '/../../telnet/src/OutputSink.php';
require_once __DIR__ . '/../../telnet/src/BufferSink.php';
require_once __DIR__ . '/../../telnet/src/SocketSink.php';
require_once __DIR__ . '/../../telnet/src/GlyphPolicy.php';
require_once __DIR__ . '/../../telnet/src/TerminalCapabilities.php';
require_once __DIR__ . '/../../telnet/src/TerminalRenderContext.php';

use BinktermPHP\I18n\Translator;
use BinktermPHP\TelnetServer\BufferSink;
use BinktermPHP\TelnetServer\TerminalCapabilities;
use BinktermPHP\TelnetServer\TerminalRenderContext;
use PHPUnit\Framework\TestCase;

/**
 * F1 — the socket-independent render context.
 *
 * Every test here constructs a context with NO socket, NO BbsSession, NO auth,
 * NO database — proving the F1 contract is sufficient for off-session rendering
 * (the future preview + the deterministic render harness).
 */
final class TerminalRenderContextTest extends TestCase
{
    private function ctx(
        string $charset = 'utf8',
        bool $color = true,
        bool $asciiMode = false,
        int $cols = 80,
        int $rows = 24,
        ?string $borderStyle = 'classic',
        string $locale = 'en'
    ): array {
        $sink = new BufferSink();
        $ctx = new TerminalRenderContext(
            $sink,
            TerminalCapabilities::unknown(),
            $cols,
            $rows,
            $charset,
            $color,
            $asciiMode,
            [],
            $locale,
            new Translator(),
            $borderStyle
        );

        return [$ctx, $sink];
    }

    public function testConstructsWithNoSocketOrSession(): void
    {
        [$ctx] = $this->ctx();
        self::assertSame(80, $ctx->cols());
        self::assertSame(24, $ctx->rows());
        self::assertSame('utf8', $ctx->effectiveCharset());
        self::assertTrue($ctx->isColorEnabled());
        self::assertInstanceOf(BufferSink::class, $ctx->sink());
        self::assertInstanceOf(TerminalCapabilities::class, $ctx->capabilities());
    }

    public function testInvalidCharsetNormalisesToAscii(): void
    {
        [$ctx] = $this->ctx('latin-1');
        self::assertSame('ascii', $ctx->effectiveCharset());
    }

    public function testWriteAndWriteLineGoToTheSink(): void
    {
        [$ctx, $sink] = $this->ctx();
        $ctx->write("a");
        $ctx->writeLine("b");
        self::assertSame("ab\r\n", $sink->getBytes());
    }

    public function testColorizeRespectsEffectiveColorFlag(): void
    {
        [$on] = $this->ctx(color: true);
        self::assertSame("\033[31mX\033[0m", $on->colorize('X', "\033[31m"));

        [$off] = $this->ctx(color: false);
        self::assertSame('X', $off->colorize('X', "\033[31m"));

        // Live toggle.
        $off->setColorEnabled(true);
        self::assertSame("\033[31mX\033[0m", $off->colorize('X', "\033[31m"));
    }

    public function testEncodeForTerminalUtf8Passthrough(): void
    {
        [$ctx] = $this->ctx('utf8');
        $s = "arrows \xe2\x86\x90\xe2\x86\x92 box \xe2\x94\x8c\xe2\x94\x80 accent \xc3\xa9";
        self::assertSame($s, $ctx->encodeForTerminal($s));
    }

    public function testEncodeForTerminalCp437Transliterates(): void
    {
        [$ctx] = $this->ctx('cp437');
        // Box-drawing chars exist in CP437; the left arrow does not and translits/drops.
        $out = $ctx->encodeForTerminal("\xe2\x94\x8c\xe2\x94\x80\xe2\x94\x90"); // ┌─┐
        self::assertNotSame('', $out);
        self::assertDoesNotMatchRegularExpression('/[\xE2]/', $out, 'no leftover UTF-8 lead bytes');
        // Pure ASCII is untouched.
        self::assertSame('plain text 123', $ctx->encodeForTerminal('plain text 123'));
    }

    public function testEncodeForTerminalAsciiStrips(): void
    {
        [$ctx] = $this->ctx('ascii');
        $out = $ctx->encodeForTerminal("caf\xc3\xa9 \xe2\x86\x92");
        self::assertMatchesRegularExpression('/^[\x20-\x7E]*$/', $out, '7-bit only');
        self::assertStringContainsString('caf', $out);
        self::assertSame('untouched', $ctx->encodeForTerminal('untouched'));
    }

    public function testLineDrawingCharsHonoursInjectedStyleAndCharsetFallback(): void
    {
        [$utf8Heavy] = $this->ctx('utf8', borderStyle: 'heavy');
        self::assertSame('━', $utf8Heavy->lineDrawingChars()['h']);

        [$cp437Heavy] = $this->ctx('cp437', borderStyle: 'heavy');
        self::assertSame('─', $cp437Heavy->lineDrawingChars()['h'], 'heavy -> classic on cp437');

        [$asciiDouble] = $this->ctx('ascii', borderStyle: 'double');
        self::assertSame('|', $asciiDouble->lineDrawingChars()['v'], 'ascii terminal -> ascii glyphs');
    }

    public function testTIsParamDrivenAndTransliteratesInAsciiMode(): void
    {
        $key = 'ui.terminalserver.__f1_nonexistent_key__';

        [$normal] = $this->ctx(asciiMode: false);
        self::assertSame("Caf\xc3\xa9 3", $normal->t($key, "Caf\xc3\xa9 {n}", ['n' => 3]));

        [$ascii] = $this->ctx(asciiMode: true);
        $out = $ascii->t($key, "Caf\xc3\xa9 {n}", ['n' => 3]);
        self::assertMatchesRegularExpression('/^Caf.? 3$/', $out);
        self::assertMatchesRegularExpression('/^[\x20-\x7E]*$/', $out, '7-bit only in ascii text mode');
    }

    public function testMutatorsUpdateInPlace(): void
    {
        [$ctx] = $this->ctx();
        $ctx->setGeometry(132, 51);
        self::assertSame(132, $ctx->cols());
        self::assertSame(51, $ctx->rows());

        $ctx->setEffectiveCharset('cp437');
        self::assertSame('cp437', $ctx->effectiveCharset());

        $ctx->setAsciiTextMode(true);
        self::assertTrue($ctx->isAsciiTextMode());

        $ctx->setStyleProfile(['list' => ['title' => "\033[36m"]]);
        self::assertSame("\033[36m", $ctx->styleProfile()['list']['title']);

        $ctx->setLocale('fr');
        self::assertSame('fr', $ctx->locale());

        $newCaps = TerminalCapabilities::forProfile('syncterm-utf8');
        $ctx->setCapabilities($newCaps);
        self::assertTrue($ctx->capabilities()->isSyncTerm());
    }

    public function testPreviewConstructionProof(): void
    {
        // Proof 1 — SyncTerm-like, 80x24, UTF-8, colour.
        $caps = TerminalCapabilities::forProfile('syncterm-utf8');
        $sink1 = new BufferSink();
        $p1 = new TerminalRenderContext($sink1, $caps, 80, 24, 'utf8', true, false, [], 'en', new Translator(), 'classic');
        $p1->write($p1->colorize($p1->encodeForTerminal("hi \xe2\x94\x8c"), "\033[36m"));
        self::assertStringContainsString("\033[36m", $sink1->getBytes());

        // Proof 2 — generic terminal, 132x51, CP437, colour.
        $sink2 = new BufferSink();
        $p2 = new TerminalRenderContext(
            $sink2,
            TerminalCapabilities::forProfile('generic'),
            132,
            51,
            'cp437',
            true,
            false,
            [],
            'en',
            new Translator(),
            'classic'
        );
        self::assertSame(132, $p2->cols());
        self::assertSame('cp437', $p2->effectiveCharset());
        self::assertIsArray($p2->lineDrawingChars());
    }
}
