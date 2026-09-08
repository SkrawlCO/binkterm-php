<?php

declare(strict_types=1);

require_once __DIR__ . '/../../telnet/src/GlyphPolicy.php';

use BinktermPHP\TelnetServer\GlyphPolicy;
use PHPUnit\Framework\TestCase;

/**
 * F1 — box-drawing glyph resolution (extracted verbatim from BbsSession).
 */
final class GlyphPolicyTest extends TestCase
{
    public function testAsciiTerminalAlwaysGetsAsciiGlyphs(): void
    {
        foreach (['classic', 'double', 'heavy', 'rounded', 'shadow', 'minimal', 'mixed'] as $style) {
            $g = GlyphPolicy::forCharsetAndStyle('ascii', $style);
            self::assertSame('-', $g['h'], "style {$style}");
            self::assertSame('|', $g['v']);
            self::assertSame('+', $g['tl']);
            self::assertSame('', $g['shadow_char']);
        }
    }

    public function testUtf8UsesConfiguredStyleUnchanged(): void
    {
        self::assertSame('━', GlyphPolicy::forCharsetAndStyle('utf8', 'heavy')['h']);
        self::assertSame('╭', GlyphPolicy::forCharsetAndStyle('utf8', 'rounded')['tl']);
        self::assertSame('▒', GlyphPolicy::forCharsetAndStyle('utf8', 'shadow')['shadow_char']);
    }

    public function testCp437FallsBackHeavyToClassicAndRoundedToSingle(): void
    {
        // heavy -> classic
        $heavy = GlyphPolicy::forCharsetAndStyle('cp437', 'heavy');
        self::assertSame('─', $heavy['h']);
        self::assertSame('═', $heavy['h_bold']);
        self::assertSame('╔', $heavy['tl']);

        // rounded -> single
        $rounded = GlyphPolicy::forCharsetAndStyle('cp437', 'rounded');
        self::assertSame('│', $rounded['v']);
        self::assertSame('┌', $rounded['tl']);
    }

    public function testCp437KeepsOtherStyles(): void
    {
        self::assertSame('═', GlyphPolicy::forCharsetAndStyle('cp437', 'double')['h']);
        self::assertSame('╒', GlyphPolicy::forCharsetAndStyle('cp437', 'mixed')['tl']);
    }

    public function testUnknownStyleFallsToClassic(): void
    {
        $g = GlyphPolicy::forCharsetAndStyle('utf8', 'no-such-style');
        self::assertSame('─', $g['h']);
        self::assertSame('═', $g['h_bold']);
        self::assertSame('╔', $g['tl']);
        self::assertSame('│', $g['v']);
    }

    public function testGlyphSetShapeIsComplete(): void
    {
        $keys = ['h', 'h_bold', 'v', 'tl', 'tr', 'bl', 'br', 'l_tee', 'r_tee', 'shadow_char'];
        foreach (['utf8', 'cp437', 'ascii'] as $cs) {
            $g = GlyphPolicy::forCharsetAndStyle($cs, 'classic');
            foreach ($keys as $k) {
                self::assertArrayHasKey($k, $g, "{$cs}/{$k}");
            }
        }
    }
}
