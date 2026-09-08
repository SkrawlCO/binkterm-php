<?php

declare(strict_types=1);

require_once __DIR__ . '/../../telnet/src/TerminalCapabilities.php';

use BinktermPHP\TelnetServer\TerminalCapabilities;
use PHPUnit\Framework\TestCase;

/**
 * F1 — immutable capability value object.
 */
final class TerminalCapabilitiesTest extends TestCase
{
    public function testUnknownDefaults(): void
    {
        $c = TerminalCapabilities::unknown();

        self::assertNull($c->clientType);
        self::assertSame(TerminalCapabilities::CHARSET_UNKNOWN, $c->charsetSupport);
        self::assertSame(TerminalCapabilities::COLOR_UNKNOWN, $c->colorSupport);
        self::assertFalse($c->sixelSupported);
    }

    public function testUnknownIsTreatedAsCapable(): void
    {
        $c = TerminalCapabilities::unknown();

        // Conservative-on: UNKNOWN must not read as "incapable".
        self::assertTrue($c->assumeUtf8Capable());
        self::assertTrue($c->assumeColor());
        self::assertFalse($c->isSyncTerm());
    }

    public function testWithClientTypeNormalisesAndIsImmutable(): void
    {
        $base = TerminalCapabilities::unknown();
        $next = $base->withClientType('  syncterm  ');

        self::assertNull($base->clientType, 'original untouched');
        self::assertSame('SYNCTERM', $next->clientType);
        self::assertTrue($next->isSyncTerm());

        self::assertNull($base->withClientType('')->clientType);
        self::assertNull($base->withClientType(null)->clientType);
    }

    public function testWithCharsetAndColorSupport(): void
    {
        $c = TerminalCapabilities::unknown()
            ->withCharsetSupport(TerminalCapabilities::CHARSET_ASCII_ONLY)
            ->withColorSupport(TerminalCapabilities::COLOR_NONE);

        self::assertSame(TerminalCapabilities::CHARSET_ASCII_ONLY, $c->charsetSupport);
        self::assertFalse($c->assumeUtf8Capable());
        self::assertFalse($c->assumeColor());

        $utf8 = $c->withCharsetSupport(TerminalCapabilities::CHARSET_UTF8);
        self::assertTrue($utf8->assumeUtf8Capable());
        self::assertSame(TerminalCapabilities::CHARSET_ASCII_ONLY, $c->charsetSupport, 'immutable');
    }

    public function testWithSixel(): void
    {
        $c = TerminalCapabilities::unknown()->withSixel(true);
        self::assertTrue($c->sixelSupported);
        self::assertFalse($c->withSixel(false)->sixelSupported);
    }

    public function testInvalidSupportValuesRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TerminalCapabilities::unknown()->withCharsetSupport('latin1');
    }

    public function testInvalidColorValueRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TerminalCapabilities::unknown()->withColorSupport('256');
    }

    /** @dataProvider profileProvider */
    public function testForProfilePresets(string $name, ?string $clientType, string $charset, string $color, bool $sixel): void
    {
        $c = TerminalCapabilities::forProfile($name);

        self::assertSame($clientType, $c->clientType);
        self::assertSame($charset, $c->charsetSupport);
        self::assertSame($color, $c->colorSupport);
        self::assertSame($sixel, $c->sixelSupported);
    }

    public static function profileProvider(): array
    {
        return [
            ['syncterm-utf8',   'SYNCTERM',  TerminalCapabilities::CHARSET_UTF8,       TerminalCapabilities::COLOR_ANSI, true],
            ['netrunner-cp437', 'NETRUNNER', TerminalCapabilities::CHARSET_ASCII_ONLY, TerminalCapabilities::COLOR_ANSI, false],
            ['putty-utf8',      'XTERM',     TerminalCapabilities::CHARSET_UTF8,       TerminalCapabilities::COLOR_ANSI, false],
            ['ascii-dumb',      'DUMB',      TerminalCapabilities::CHARSET_ASCII_ONLY, TerminalCapabilities::COLOR_NONE, false],
            ['generic',         null,        TerminalCapabilities::CHARSET_UNKNOWN,    TerminalCapabilities::COLOR_UNKNOWN, false],
        ];
    }

    public function testUnknownProfileThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TerminalCapabilities::forProfile('nope');
    }
}
