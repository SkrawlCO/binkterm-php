<?php

namespace BinktermPHP\TelnetServer;

use BinktermPHP\AppearanceConfig;
use BinktermPHP\I18n\Translator;

/**
 * TerminalRenderContext — the single source of render inputs and the single
 * write target for terminal rendering.
 *
 * One instance exists per session. It is a mutable *holder*: geometry mutates
 * in place (high frequency, from NAWS); {@see TerminalCapabilities} is swapped
 * as a unit when a fact resolves; style profile / locale / border style are
 * settable. The {@see OutputSink} is fixed for the context's lifetime (a socket
 * does not become a buffer).
 *
 * Because it carries no socket lifecycle, no session/auth state, no user
 * identity, no navigation state, and no service clients, the same context type
 * backs three consumers: the live session (with a {@see SocketSink}), render
 * tests, and the future sysop preview (both with a {@see BufferSink}).
 *
 * MUST NOT be added to this class:
 *   - auth / session lifecycle, session id, cookies
 *   - user identity / user row / is_admin / access (ACS) context
 *   - the `$state` array wholesale (only derived render values are copied in)
 *   - API / service clients, a database connection, `apiRequest()`
 *   - input reading (readKey*, readRawChar), key normalisation
 *   - navigation / menu / handler state, door / bridge state
 *   - Telnet/SSH negotiation
 *   - socket open/close/reconfigure (the sink holds a reference only)
 *
 * F1 note on {@see t()}: it is exactly param-driven for byte-parity with the
 * historical `BbsSession::t()` — a caller either passes a locale or gets the
 * translator default. The stored {@see $locale} is exposed via {@see locale()}
 * for future renderers but is intentionally not consulted by `t()` yet.
 */
final class TerminalRenderContext
{
    private OutputSink $sink;
    private TerminalCapabilities $capabilities;
    private int $cols;
    private int $rows;

    /** Effective charset actually used for encoding/glyphs: 'utf8' | 'cp437' | 'ascii'. */
    private string $effectiveCharset;

    /** Effective ANSI-colour flag actually used by {@see colorize()}. */
    private bool $colorEnabled;

    /**
     * Drives {@see t()} transliteration. Historically tracked separately from
     * the effective charset in `BbsSession` (a CP437 session can still be in
     * ASCII text mode), so it is a distinct field here.
     */
    private bool $asciiTextMode;

    /** The merged terminal style profile (colours per widget section). */
    private array $styleProfile;

    /** Locale string (e.g. 'en'). Not consulted by {@see t()} in F1 — see class note. */
    private string $locale;

    private Translator $translator;

    /**
     * Configured border style, or null to read it live from
     * {@see AppearanceConfig::getTermBorderStyle()} on each call (the live
     * default; a preview passes an explicit value).
     */
    private ?string $borderStyle;

    public function __construct(
        OutputSink $sink,
        TerminalCapabilities $capabilities,
        int $cols,
        int $rows,
        string $effectiveCharset,
        bool $colorEnabled,
        bool $asciiTextMode,
        array $styleProfile,
        string $locale,
        Translator $translator,
        ?string $borderStyle = null
    ) {
        $this->sink             = $sink;
        $this->capabilities     = $capabilities;
        $this->cols             = $cols;
        $this->rows             = $rows;
        $this->effectiveCharset = self::normalizeCharset($effectiveCharset);
        $this->colorEnabled     = $colorEnabled;
        $this->asciiTextMode    = $asciiTextMode;
        $this->styleProfile     = $styleProfile;
        $this->locale           = $locale;
        $this->translator       = $translator;
        $this->borderStyle      = $borderStyle;
    }

    // ===== render inputs (read) =====

    public function sink(): OutputSink { return $this->sink; }

    public function capabilities(): TerminalCapabilities { return $this->capabilities; }

    public function cols(): int { return $this->cols; }

    public function rows(): int { return $this->rows; }

    /**
     * Effective row count for selector-style full-screen widgets that anchor a
     * status/input line to the last row.
     *
     * SyncTERM keeps its own local bottom status line even when the negotiated
     * height includes that row, so one row is reserved for it. This is the
     * canonical implementation of the concept `TelnetUtils::getSelectorRows()`
     * computes from `$state` today; the two agree byte-for-byte and existing
     * call sites are migrated opportunistically (see the render-seam migration
     * rule).
     */
    public function selectorRows(): int
    {
        $reserve = $this->capabilities->isSyncTerm() ? 1 : 0;

        return max(1, $this->rows - $reserve);
    }

    public function effectiveCharset(): string { return $this->effectiveCharset; }

    public function isColorEnabled(): bool { return $this->colorEnabled; }

    public function isAsciiTextMode(): bool { return $this->asciiTextMode; }

    public function styleProfile(): array { return $this->styleProfile; }

    public function locale(): string { return $this->locale; }

    // ===== mutation (session owner only, at the defined sync points) =====

    public function setGeometry(int $cols, int $rows): void
    {
        $this->cols = $cols;
        $this->rows = $rows;
    }

    public function setCapabilities(TerminalCapabilities $capabilities): void
    {
        $this->capabilities = $capabilities;
    }

    public function setEffectiveCharset(string $charset): void
    {
        $this->effectiveCharset = self::normalizeCharset($charset);
    }

    public function setColorEnabled(bool $enabled): void
    {
        $this->colorEnabled = $enabled;
    }

    public function setAsciiTextMode(bool $enabled): void
    {
        $this->asciiTextMode = $enabled;
    }

    public function setStyleProfile(array $styleProfile): void
    {
        $this->styleProfile = $styleProfile;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    public function setBorderStyle(?string $borderStyle): void
    {
        $this->borderStyle = $borderStyle;
    }

    // ===== render operations =====

    public function write(string $bytes): void
    {
        $this->sink->write($bytes);
    }

    public function writeLine(string $text = ''): void
    {
        $this->sink->write($text . "\r\n");
    }

    /**
     * Wrap text in an ANSI colour sequence, honouring the effective colour flag.
     * Byte-identical to the historical `BbsSession::colorize()` /
     * `TelnetUtils::colorize()`.
     */
    public function colorize(string $text, string $color): string
    {
        if (!$this->colorEnabled) {
            return $text;
        }

        return $color . $text . "\033[0m";
    }

    /**
     * Encode a UTF-8 string for the effective terminal character set.
     * Verbatim behaviour of the historical `BbsSession::encodeForTerminal()`.
     */
    public function encodeForTerminal(string $text): string
    {
        return match ($this->effectiveCharset) {
            'utf8'  => $text,
            'cp437' => self::convertToCp437($text),
            default => self::normalizeAscii($text),
        };
    }

    /**
     * Box-drawing glyphs for the effective charset and the configured border
     * style. When no border style was injected, the sysop-configured value is
     * read live (cached inside {@see AppearanceConfig}).
     *
     * @return array{h:string,h_bold:string,v:string,tl:string,tr:string,bl:string,br:string,l_tee:string,r_tee:string,shadow_char:string}
     */
    public function lineDrawingChars(): array
    {
        $style = $this->borderStyle ?? AppearanceConfig::getTermBorderStyle();

        return GlyphPolicy::forCharsetAndStyle($this->effectiveCharset, $style);
    }

    /**
     * Translate a terminal server UI string. Param-driven for exact byte-parity
     * with the historical `BbsSession::t()`: an explicit locale wins, otherwise
     * the translator default is used (the stored locale is NOT substituted).
     */
    public function t(string $key, string $fallback, array $params = [], string $locale = ''): string
    {
        $result = $this->translator->translate(
            $key,
            $params,
            $locale !== '' ? $locale : null,
            ['terminalserver']
        );

        if ($result === $key) {
            foreach ($params as $k => $v) {
                $fallback = str_replace('{' . $k . '}', (string)$v, $fallback);
            }

            return $this->asciiTextMode ? self::normalizeAscii($fallback) : $fallback;
        }

        return $this->asciiTextMode ? self::normalizeAscii($result) : $result;
    }

    // ===== internal (moved verbatim from BbsSession) =====

    private static function normalizeCharset(string $charset): string
    {
        return match ($charset) {
            'utf8', 'cp437', 'ascii' => $charset,
            default => 'ascii',
        };
    }

    /**
     * Convert a UTF-8 string to CP437, transliterating where possible.
     */
    private static function convertToCp437(string $text): string
    {
        if (!preg_match('/[^\x20-\x7E\r\n\t]/', $text)) {
            return $text;
        }
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'CP437//TRANSLIT//IGNORE', $text);
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }
        return preg_replace('/[^\x20-\x7E\r\n\t]/', '', $text) ?? $text;
    }

    /**
     * Transliterate to 7-bit ASCII for terminals that do not render UTF-8 reliably.
     */
    private static function normalizeAscii(string $text): string
    {
        if (!preg_match('/[^\x20-\x7E]/', $text)) {
            return $text;
        }
        if (function_exists('iconv')) {
            $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if (is_string($ascii) && $ascii !== '') {
                return $ascii;
            }
        }
        return preg_replace('/[^\x20-\x7E]/', '', $text) ?? $text;
    }
}
