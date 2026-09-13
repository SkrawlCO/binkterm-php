<?php

namespace BinktermPHP\Terminal\Presentation;

/**
 * OSC 8 terminal hyperlink wrapping — an L33TEST-authored presentation
 * primitive, NOT a content sanitizer and NOT a generic escape passthrough.
 *
 * This is the only place OSC 8 may be constructed. Callers must only ever
 * pass a URL the feature itself already treats as admin-controlled/approved
 * application data (e.g. an approved BBS Directory entry's `website` field)
 * — never anything sourced from echomail/netmail/user-generated message
 * content. Even so, this helper never assumes that provenance alone makes a
 * string safe to embed in an OSC control string: it does its own strict,
 * allowlist-only validation and rejects (rather than repairs) anything that
 * does not cleanly qualify.
 *
 * Wire shape (see the de facto spec:
 * https://gist.github.com/egmontkob/eb114294efbcd5adb1944c9f3cb5feda):
 *
 *   ESC ] 8 ; ; <url> ST <label> ESC ] 8 ; ; ST
 *
 * ST (string terminator) is ESC \ (0x1B 0x5C), the form the spec prefers
 * over the legacy BEL terminator. The closer is always emitted alongside
 * the opener — never one without the other.
 *
 * A client without OSC 8 support renders the returned string identically
 * whether wrapping succeeded or was rejected: an OSC…ST control string is
 * swallowed by any VT/ANSI-family terminal parser (the same mechanism that
 * already lets {@see \BinktermPHP\TelnetServer\BbsSession::setTerminalTitle()}
 * emit an OSC 0 title to every caller today), leaving only the visible
 * label on screen.
 */
final class TerminalHyperlink
{
    private const ST = "\033\\";

    /** Only these schemes may ever appear inside an OSC 8 URL field. */
    private const ALLOWED_SCHEMES = ['http', 'https'];

    /**
     * Wrap $label as an OSC 8 hyperlink to $url when — and only when — $url
     * passes strict validation. On any rejection this returns the label
     * alone (control bytes stripped), so the caller always gets a safe,
     * ordinary display string whether or not the link was accepted, and the
     * visible text is never different between the two cases.
     */
    public static function wrap(string $url, string $label): string
    {
        $cleanLabel = self::stripControlBytes($label);

        if (!self::isSafeUrl($url)) {
            return $cleanLabel;
        }

        return "\033]8;;{$url}" . self::ST . $cleanLabel . "\033]8;;" . self::ST;
    }

    /**
     * As {@see wrap()}, but only when the resulting wrapped string is
     * provably short enough that a plain, OSC-unaware word-wrapper (raw byte
     * counting, no escape-sequence awareness — e.g. the line shell's
     * `TelnetUtils::writeWrapped()`) could never need to break a line
     * containing it, which would otherwise risk splitting the escape
     * sequence mid-stream. $maxWidth is the full visible-column budget the
     * caller already knows is available for this rendered line (this method
     * accounts for the fixed OSC 8 overhead and the URL appearing twice —
     * once as the link target, once as the visible label — on top of it).
     *
     * Falls back to the identical plain label (control bytes stripped) when
     * the budget can't be proven safe, or when $url fails {@see wrap()}'s
     * own validation — never a partially-safe or best-effort compromise.
     */
    public static function wrapIfFits(string $url, string $label, int $maxWidth): string
    {
        $cleanLabel = self::stripControlBytes($label);

        if (!self::isSafeUrl($url)) {
            return $cleanLabel;
        }

        // Fixed overhead: two "\033]8;;" openers (6 bytes each) + two ST
        // "\033\\" terminators (2 bytes each) = 16, plus the URL counted
        // twice (link target + visible label).
        $wrappedWidth = 16 + (2 * mb_strlen($url));
        if ($wrappedWidth > $maxWidth) {
            return $cleanLabel;
        }

        return self::wrap($url, $cleanLabel);
    }

    /**
     * Strict allowlist validation: a well-formed absolute http/https URL
     * with no control bytes anywhere in the string. Never attempts to
     * repair, normalize, or partially accept a questionable URL — anything
     * not cleanly valid is rejected outright.
     */
    private static function isSafeUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || self::hasControlBytes($url)) {
            return false;
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }
        if (!in_array(strtolower($parts['scheme']), self::ALLOWED_SCHEMES, true)) {
            return false;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    private static function hasControlBytes(string $s): bool
    {
        return preg_match('/[\x00-\x1F\x7F]/', $s) === 1;
    }

    private static function stripControlBytes(string $s): string
    {
        return preg_replace('/[\x00-\x1F\x7F]/', '', $s) ?? '';
    }
}
