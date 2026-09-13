<?php

declare(strict_types=1);

use BinktermPHP\Terminal\Presentation\TerminalHyperlink;
use PHPUnit\Framework\TestCase;

/**
 * {@see TerminalHyperlink} — the only place OSC 8 hyperlinks may be
 * constructed in L33TEST. Pure/off-session: no BbsSession, no shell, no
 * database. Verifies the exact byte shape proven in the SyncTERM v1.9rc4
 * human acceptance test, the strict allowlist that keeps this from ever
 * becoming a generic escape passthrough, and that rejection always degrades
 * to the identical plain label a caller would see without OSC 8 at all.
 */
final class TerminalHyperlinkTest extends TestCase
{
    private const ST = "\033\\";

    public function testValidHttpsUrlProducesTheCanonicalOsc8Wrapper(): void
    {
        $result = TerminalHyperlink::wrap('https://example.com/binktermphp', 'https://example.com/binktermphp');

        self::assertSame(
            "\033]8;;https://example.com/binktermphp" . self::ST
            . 'https://example.com/binktermphp'
            . "\033]8;;" . self::ST,
            $result
        );
    }

    public function testValidHttpUrlIsAlsoAccepted(): void
    {
        $result = TerminalHyperlink::wrap('http://example.com', 'http://example.com');

        self::assertStringStartsWith("\033]8;;http://example.com" . self::ST, $result);
        self::assertStringEndsWith("\033]8;;" . self::ST, $result);
    }

    public function testOpenerShape(): void
    {
        $result = TerminalHyperlink::wrap('https://example.com', 'label');

        // ESC ] 8 ; ; <url> ST — empty params field between the two `;`.
        self::assertStringStartsWith("\033]8;;https://example.com\033\\", $result);
    }

    public function testCloserIsAlwaysPresentAndWellFormed(): void
    {
        $result = TerminalHyperlink::wrap('https://example.com', 'label');

        // Closer: ESC ] 8 ; ; ST — empty url field, immediately after the label.
        self::assertStringEndsWith("label\033]8;;\033\\", $result);
    }

    public function testVisibleLabelTextIsUnchangedByWrapping(): void
    {
        $label  = 'Open BinkTermPHP project page';
        $result = TerminalHyperlink::wrap('https://github.com/awehttam/binkterm-php', $label);

        self::assertStringContainsString($label, $result);
    }

    public function testNoOscStateLeaksPastTheLabel(): void
    {
        $result = TerminalHyperlink::wrap('https://example.com', 'label');

        // Exactly two OSC 8 introducers: one opener, one closer. Nothing
        // trails after the closer's ST.
        self::assertSame(2, substr_count($result, "\033]8;;"));
        self::assertTrue(str_ends_with($result, "\033]8;;" . self::ST));
    }

    public function testControlBytesInUrlAreRejectedEntirely(): void
    {
        // An embedded ESC in the URL field could break out of the OSC
        // control string; the whole link must be refused, not sanitized.
        $malicious = "https://example.com/\033]0;evil\007";
        $result    = TerminalHyperlink::wrap($malicious, 'Website');

        self::assertStringNotContainsString("\033]8;;", $result);
        self::assertSame('Website', $result);
    }

    public function testControlBytesInLabelAreStrippedNotRejected(): void
    {
        $result = TerminalHyperlink::wrap('https://example.com', "Evil\033]0;pwn\007Label");

        // The raw ESC/BEL control bytes are gone (no smuggled second OSC
        // sequence survives inside the label), but the link itself is still
        // accepted — only the label's control bytes are removed, not the
        // whole link rejected outright the way an unsafe URL would be.
        self::assertStringNotContainsString("\033]0;", $result);
        self::assertStringNotContainsString("\007", $result);
        self::assertStringContainsString("\033]8;;https://example.com", $result);
        self::assertStringContainsString(']0;pwnLabel', $result); // literal chars survive; only control bytes strip
    }

    /** @dataProvider unsafeUrlProvider */
    public function testMalformedOrUnsafeUrlNeverProducesOsc8(string $url): void
    {
        $result = TerminalHyperlink::wrap($url, 'Website');

        self::assertStringNotContainsString("\033]8;;", $result, "rejected for: {$url}");
        self::assertSame('Website', $result);
    }

    public static function unsafeUrlProvider(): array
    {
        return [
            'javascript scheme'   => ['javascript:alert(1)'],
            'data scheme'         => ['data:text/html,<script>alert(1)</script>'],
            'file scheme'         => ['file:///etc/passwd'],
            'no scheme'           => ['example.com'],
            'empty string'        => [''],
            'whitespace only'     => ['   '],
            'ftp scheme'          => ['ftp://example.com'],
            'missing host'        => ['https://'],
            'scheme case tricks'  => ['jAvAsCrIpT:alert(1)'],
        ];
    }

    public function testWrapIfFitsWrapsWhenBudgetIsAmple(): void
    {
        $url = 'https://example.com';
        // 16 fixed overhead + 2*20 (url twice) = 56; give it plenty of room.
        $result = TerminalHyperlink::wrapIfFits($url, $url, 100);

        self::assertStringContainsString("\033]8;;{$url}" . self::ST, $result);
        self::assertStringEndsWith("\033]8;;" . self::ST, $result);
    }

    public function testWrapIfFitsFallsBackToPlainLabelWhenBudgetTooNarrow(): void
    {
        $url = 'https://example.com/a/reasonably/long/path/segment';
        // Deliberately too small for 16 + 2*strlen($url).
        $result = TerminalHyperlink::wrapIfFits($url, $url, 10);

        self::assertSame($url, $result);
        self::assertStringNotContainsString("\033]8;;", $result);
    }

    public function testWrapIfFitsBoundaryIsExact(): void
    {
        $url    = 'http://a.co'; // 11 chars
        $needed = 16 + (2 * mb_strlen($url)); // = 38

        self::assertStringNotContainsString("\033]8;;", TerminalHyperlink::wrapIfFits($url, $url, $needed - 1));
        self::assertStringContainsString("\033]8;;", TerminalHyperlink::wrapIfFits($url, $url, $needed));
    }

    public function testWrapIfFitsStillRejectsUnsafeUrlRegardlessOfWidth(): void
    {
        $result = TerminalHyperlink::wrapIfFits('javascript:alert(1)', 'Website', 1000);

        self::assertSame('Website', $result);
    }

    public function testUnsupportedClientVisibleLabelIsOrdinaryTextByConstruction(): void
    {
        // The whole point of OSC 8: a client with no OSC 8 support parses the
        // OSC…ST control string (same class already proven safe in
        // production via OSC 0 terminal-title sequences) and swallows it,
        // leaving only the plain label bytes on screen — this asserts the
        // label itself carries no OSC framing of its own that a non-OSC-8
        // parser could misrender.
        $label  = 'https://example.com';
        $result = TerminalHyperlink::wrap($label, $label);

        $withoutOsc8 = preg_replace('/\033\]8;;[^\033]*\033\\\\/', '', $result);
        self::assertSame($label, $withoutOsc8);
    }
}
