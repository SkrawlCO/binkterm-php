<?php

declare(strict_types=1);

namespace BinktermPHP\Tests\Support;

require_once __DIR__ . '/../../../telnet/src/OutputSink.php';
require_once __DIR__ . '/../../../telnet/src/BufferSink.php';
require_once __DIR__ . '/../../../telnet/src/SocketSink.php';
require_once __DIR__ . '/../../../telnet/src/GlyphPolicy.php';
require_once __DIR__ . '/../../../telnet/src/TerminalCapabilities.php';
require_once __DIR__ . '/../../../telnet/src/TerminalRenderContext.php';

use BinktermPHP\I18n\Translator;
use BinktermPHP\TelnetServer\BufferSink;
use BinktermPHP\TelnetServer\TerminalCapabilities;
use BinktermPHP\TelnetServer\TerminalRenderContext;

/**
 * TerminalRenderHarness — deterministic terminal render fixtures for tests.
 *
 * Builds a {@see TerminalRenderContext} backed by a {@see BufferSink} at an
 * arbitrary geometry / charset / colour / style / locale, with NO live socket,
 * NO BbsSession, NO authentication, NO database, and NO API calls. This is the
 * substrate the future sysop preview and every deterministic render test share.
 *
 * Usage:
 *
 *   $h   = TerminalRenderHarness::at(132, 51)->charset('cp437')->mono();
 *   $ctx = $h->context();
 *   // ... render into $ctx ...
 *   $bytes = $h->bytes();
 *
 * Or expand a matrix:
 *
 *   foreach (TerminalRenderHarness::standardMatrix() as $name => $h) { ... }
 */
final class TerminalRenderHarness
{
    public const GEOMETRIES = [
        '80x24'  => [80, 24],
        '132x36' => [132, 36],
        '132x51' => [132, 51],
    ];

    public const CHARSETS = ['utf8', 'cp437', 'ascii'];

    private int $cols;
    private int $rows;
    private string $charset = 'utf8';
    private bool $color = true;
    private ?bool $asciiTextMode = null;
    private ?string $clientType = null;
    private array $styleProfile = [];
    private string $locale = 'en';
    private string $borderStyle = 'classic';
    private bool $sixel = false;

    private ?BufferSink $sink = null;
    private ?TerminalRenderContext $context = null;

    private function __construct(int $cols, int $rows)
    {
        $this->cols = $cols;
        $this->rows = $rows;
    }

    public static function at(int $cols, int $rows): self
    {
        return new self($cols, $rows);
    }

    /** @param string $geometry one of the GEOMETRIES keys (e.g. '132x36') */
    public static function geometry(string $geometry): self
    {
        [$c, $r] = self::GEOMETRIES[$geometry] ?? [80, 24];

        return new self($c, $r);
    }

    public function charset(string $charset): self
    {
        $this->charset = $charset;
        $this->context = null;

        return $this;
    }

    public function color(bool $on = true): self
    {
        $this->color = $on;
        $this->context = null;

        return $this;
    }

    public function mono(): self
    {
        return $this->color(false);
    }

    public function asciiTextMode(bool $on): self
    {
        $this->asciiTextMode = $on;
        $this->context = null;

        return $this;
    }

    public function clientType(?string $type): self
    {
        $this->clientType = $type;
        $this->context = null;

        return $this;
    }

    public function syncterm(): self
    {
        return $this->clientType('SYNCTERM');
    }

    public function style(array $profile): self
    {
        $this->styleProfile = $profile;
        $this->context = null;

        return $this;
    }

    public function locale(string $locale): self
    {
        $this->locale = $locale;
        $this->context = null;

        return $this;
    }

    public function borderStyle(string $style): self
    {
        $this->borderStyle = $style;
        $this->context = null;

        return $this;
    }

    public function sixel(bool $on = true): self
    {
        $this->sixel = $on;
        $this->context = null;

        return $this;
    }

    public function sink(): BufferSink
    {
        $this->context();

        return $this->sink;
    }

    public function bytes(): string
    {
        return $this->sink()->getBytes();
    }

    public function capabilities(): TerminalCapabilities
    {
        $caps = TerminalCapabilities::unknown()->withSixel($this->sixel);
        if ($this->clientType !== null) {
            $caps = $caps->withClientType($this->clientType);
        }
        if ($this->charset === 'ascii') {
            $caps = $caps->withCharsetSupport(TerminalCapabilities::CHARSET_ASCII_ONLY);
        } elseif ($this->charset === 'utf8') {
            $caps = $caps->withCharsetSupport(TerminalCapabilities::CHARSET_UTF8);
        }
        $caps = $caps->withColorSupport(
            $this->color ? TerminalCapabilities::COLOR_ANSI : TerminalCapabilities::COLOR_NONE
        );

        return $caps;
    }

    public function context(): TerminalRenderContext
    {
        if ($this->context !== null) {
            return $this->context;
        }

        $this->sink = new BufferSink();
        $this->context = new TerminalRenderContext(
            $this->sink,
            $this->capabilities(),
            $this->cols,
            $this->rows,
            $this->charset,
            $this->color,
            $this->asciiTextMode ?? ($this->charset === 'ascii'),
            $this->styleProfile,
            $this->locale,
            new Translator(),
            $this->borderStyle
        );

        return $this->context;
    }

    /**
     * The standard {geometry} x {charset} x {colour} matrix used across the
     * deterministic render tests (3 x 3 x 2 = 18 harnesses).
     *
     * @return array<string,self>
     */
    public static function standardMatrix(): array
    {
        $out = [];
        foreach (array_keys(self::GEOMETRIES) as $geo) {
            foreach (self::CHARSETS as $cs) {
                foreach ([true, false] as $color) {
                    $key = "{$geo}/{$cs}/" . ($color ? 'color' : 'mono');
                    $out[$key] = self::geometry($geo)->charset($cs)->color($color);
                }
            }
        }

        return $out;
    }

    // ===== measurement helpers (test assertions) =====

    /**
     * Visible display width of a line: ANSI SGR/CSI sequences stripped, then
     * multibyte-aware length. Matches how the box renderers measure content.
     */
    public static function visibleWidth(string $line): int
    {
        $plain = preg_replace('/\033\[[0-9;?]*[A-Za-z]/', '', $line) ?? $line;
        $plain = str_replace(["\r", "\n"], '', $plain);

        return mb_strlen($plain, 'UTF-8');
    }

    /**
     * Split rendered bytes into displayable lines (CRLF-delimited), with cursor
     * positioning and clear sequences removed so width checks see only content.
     *
     * @return array<int,string>
     */
    public static function contentLines(string $bytes): array
    {
        $stripped = preg_replace('/\033\[\??[0-9;]*[A-Za-z]/', '', $bytes) ?? $bytes;
        $stripped = str_replace("\r", '', $stripped);

        return explode("\n", $stripped);
    }
}
