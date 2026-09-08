<?php

namespace BinktermPHP\TelnetServer;

/**
 * TerminalCapabilities — an immutable description of what a caller's terminal
 * client can do: negotiated facts and conservative inferences.
 *
 * This is NOT preferences, NOT geometry, and NOT effective/resolved values:
 *
 *   - user preferences (saved charset, ANSI-colour on/off, shell mode) live in
 *     the session settings and are *inputs to* computing effective values;
 *   - geometry (rows/cols) is dynamic session state — it changes on every NAWS
 *     update — and belongs in {@see TerminalRenderContext};
 *   - the *effective* charset/colour a renderer actually uses is derived from
 *     (preference ?? inference) and also lives in {@see TerminalRenderContext}.
 *
 * F1 populates only what can be established as a genuine fact: {@see clientType}
 * (from TTYPE / SSH pty-req) and {@see sixelSupported} (from a DA1 probe).
 * {@see charsetSupport} and {@see colorSupport} remain {@see CHARSET_UNKNOWN} /
 * {@see COLOR_UNKNOWN} until structured capability detection is implemented in a
 * later stage (F3); the `assume*()` helpers treat UNKNOWN as "assume capable",
 * matching the terminal server's historical conservative-on defaults.
 *
 * Instances are replaced (never mutated) via the `with*()` methods when a fact
 * resolves during a session.
 */
final class TerminalCapabilities
{
    /** The client renders UTF-8 correctly. */
    public const CHARSET_UTF8 = 'utf8';
    /** The client cannot be relied on to render UTF-8; ASCII/CP437 only. */
    public const CHARSET_ASCII_ONLY = 'ascii_only';
    /** Not yet determined. */
    public const CHARSET_UNKNOWN = 'unknown';

    /** The client honours ANSI SGR colour. */
    public const COLOR_ANSI = 'ansi';
    /** The client does not render colour (e.g. a dumb terminal). */
    public const COLOR_NONE = 'none';
    /** Not yet determined. */
    public const COLOR_UNKNOWN = 'unknown';

    /**
     * @param string|null $clientType     Normalised, upper-cased terminal type
     *                                    (TTYPE `IS` value / SSH pty-req TERM),
     *                                    e.g. "SYNCTERM", "XTERM-256COLOR".
     *                                    Null when not yet received.
     * @param string      $charsetSupport One of the CHARSET_* constants.
     * @param string      $colorSupport   One of the COLOR_* constants.
     * @param bool        $sixelSupported DA1 attribute 4 present.
     */
    private function __construct(
        public readonly ?string $clientType,
        public readonly string $charsetSupport,
        public readonly string $colorSupport,
        public readonly bool $sixelSupported,
    ) {
    }

    /**
     * The starting point for a fresh session: nothing known yet.
     */
    public static function unknown(): self
    {
        return new self(null, self::CHARSET_UNKNOWN, self::COLOR_UNKNOWN, false);
    }

    public function withClientType(?string $clientType): self
    {
        $normalized = $clientType !== null ? strtoupper(trim($clientType)) : null;
        if ($normalized === '') {
            $normalized = null;
        }

        return new self($normalized, $this->charsetSupport, $this->colorSupport, $this->sixelSupported);
    }

    public function withCharsetSupport(string $support): self
    {
        self::assertCharsetSupport($support);

        return new self($this->clientType, $support, $this->colorSupport, $this->sixelSupported);
    }

    public function withColorSupport(string $support): self
    {
        self::assertColorSupport($support);

        return new self($this->clientType, $this->charsetSupport, $support, $this->sixelSupported);
    }

    public function withSixel(bool $supported): self
    {
        return new self($this->clientType, $this->charsetSupport, $this->colorSupport, $supported);
    }

    /**
     * True when the reported client is SyncTERM. The terminal server special-
     * cases SyncTERM in a couple of places (a reserved local status row, a
     * VT320 status-line-off request).
     */
    public function isSyncTerm(): bool
    {
        return $this->clientType !== null && str_contains($this->clientType, 'SYNCTERM');
    }

    /**
     * Whether a renderer should assume UTF-8 output is safe. UNKNOWN counts as
     * "assume capable" (the historical conservative-on default).
     */
    public function assumeUtf8Capable(): bool
    {
        return $this->charsetSupport !== self::CHARSET_ASCII_ONLY;
    }

    /**
     * Whether a renderer should assume ANSI colour is honoured. UNKNOWN counts
     * as "assume capable".
     */
    public function assumeColor(): bool
    {
        return $this->colorSupport !== self::COLOR_NONE;
    }

    /**
     * Named capability presets for tests and the future sysop preview ONLY.
     *
     * These names are test/preview vocabulary — NOT a stable public API, NOT a
     * configuration surface, and no backward-compatibility obligations attach
     * to them. Do not expose them in user or sysop configuration.
     *
     * @throws \InvalidArgumentException on an unknown preset name.
     */
    public static function forProfile(string $name): self
    {
        return match ($name) {
            'syncterm-utf8'   => new self('SYNCTERM',  self::CHARSET_UTF8,       self::COLOR_ANSI, true),
            'netrunner-cp437' => new self('NETRUNNER', self::CHARSET_ASCII_ONLY, self::COLOR_ANSI, false),
            'putty-utf8'      => new self('XTERM',     self::CHARSET_UTF8,       self::COLOR_ANSI, false),
            'ascii-dumb'      => new self('DUMB',      self::CHARSET_ASCII_ONLY, self::COLOR_NONE, false),
            'generic'         => self::unknown(),
            default => throw new \InvalidArgumentException("Unknown terminal capability profile: {$name}"),
        };
    }

    private static function assertCharsetSupport(string $support): void
    {
        if (!in_array($support, [self::CHARSET_UTF8, self::CHARSET_ASCII_ONLY, self::CHARSET_UNKNOWN], true)) {
            throw new \InvalidArgumentException("Invalid charset support value: {$support}");
        }
    }

    private static function assertColorSupport(string $support): void
    {
        if (!in_array($support, [self::COLOR_ANSI, self::COLOR_NONE, self::COLOR_UNKNOWN], true)) {
            throw new \InvalidArgumentException("Invalid color support value: {$support}");
        }
    }
}
