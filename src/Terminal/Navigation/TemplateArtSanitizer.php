<?php

namespace BinktermPHP\Terminal\Navigation;

/**
 * Prepares a trusted sysop ANSI template for use as an absolute-positioned
 * presentation backdrop behind declarative navigation content.
 *
 * A template is decoration, not a terminal-control program. The allowed
 * capability is deliberately narrow: printable text and SGR (colour / text
 * style) only. Everything that could move the cursor, scroll or erase the
 * screen, change modes, drive the window title / clipboard, or reflect input
 * is stripped — otherwise a template could fight the renderer's own
 * positioning or attack the reader's terminal.
 *
 * The escape-stripping policy here is intentionally the same whitelist as the
 * message-body path: keep `ESC [ ... m`, drop OSC / DCS / SOS / PM / APC, drop
 * every non-SGR CSI, drop other escape sequences, drop C0/C1 control bytes
 * except TAB / CR / LF. {@see stripControl()} is the single place that policy
 * lives, so a later consolidation onto a shared sanitizer is a one-method
 * change.
 *
 * Template art follows the house convention (see telnet/screens/*.ans): it is
 * authored in CP437 with a possible trailing SAUCE / EOF record. This class
 * truncates at the DOS EOF byte and converts to the caller's effective charset
 * — mirroring AppearanceConfig::getLoginScreenAnsi().
 */
final class TemplateArtSanitizer
{
    /**
     * @param string $raw      the template file bytes
     * @param string $charset  the terminal's effective charset: 'utf8', 'cp437'
     *                         or 'ascii'
     * @return string safe, newline-normalised (\n) text in $charset, or '' when
     *                the template cannot be represented safely in that charset
     *                (the caller then falls back to the flowing renderer)
     */
    public static function sanitize(string $raw, string $charset): string
    {
        if ($raw === '') {
            return '';
        }

        // 1. Drop a trailing SAUCE / EOF record (DOS 0x1A delimiter).
        $eof = strpos($raw, "\x1A");
        if ($eof !== false) {
            $raw = substr($raw, 0, $eof);
        }

        // 2. Strip every control sequence except SGR, and disallowed control
        //    bytes. Byte-safe: ESC and the CSI grammar are all ASCII, so this
        //    is correct whether $raw is CP437 or UTF-8.
        $raw = self::stripControl($raw);

        // 3. Normalise line endings to \n; the renderer positions each line.
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);

        // 4. Represent it in the terminal's charset.
        return self::toCharset($raw, $charset);
    }

    /**
     * The escape / control-byte whitelist. SGR is kept; everything else goes.
     *
     * This is the same policy the terminal message-body read path applies to
     * untrusted FTN content; keeping it in one method means a future move to a
     * shared sanitizer class is a single delegation.
     */
    private static function stripControl(string $text): string
    {
        // Split on well-formed SGR sequences and keep them; scrub the rest.
        $parts = preg_split('/(\x1b\[[0-9;:]*m)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return self::scrub($text);
        }

        $out = '';
        foreach ($parts as $i => $part) {
            $out .= ($i % 2 === 1) ? $part : self::scrub($part);
        }

        return $out;
    }

    private static function scrub(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        // OSC: ESC ] ... (BEL | ST) — window title, clipboard (OSC 52), links.
        $text = preg_replace('/\x1b\][^\x07\x1b]*(?:\x07|\x1b\\\\)?/', '', $text) ?? $text;
        // DCS / SOS / PM / APC: ESC (P|X|^|_) ... ST.
        $text = preg_replace('/\x1b[PX^_][^\x1b]*(?:\x1b\\\\)?/', '', $text) ?? $text;
        // Any CSI that survived the SGR split: cursor moves, erase, scroll,
        // mode changes, device queries, and malformed / unterminated ones.
        $text = preg_replace('/\x1b\[[0-9;:?<>=]*[ -\/]*[@-~]?/', '', $text) ?? $text;
        // Character-set designation: ESC ( B , ESC ) 0 , ...
        $text = preg_replace('/\x1b[()*+\-.\/][0-9A-Za-z]/', '', $text) ?? $text;
        // Any other escape (ESC c, ESC 7, ESC =, ...) and a stray trailing ESC.
        $text = preg_replace('/\x1b[\x20-\x7e]?/', '', $text) ?? $text;
        // C0 control bytes except TAB (09), LF (0A), CR (0D); plus DEL (7F).
        $text = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $text) ?? $text;
        // UTF-8-encoded C1 range (U+0080..U+009F); 0x9B is an alternate CSI.
        $text = preg_replace('/\xc2[\x80-\x9f]/', '', $text) ?? $text;

        return $text;
    }

    private static function toCharset(string $text, string $charset): string
    {
        $isUtf8 = $text === '' || mb_check_encoding($text, 'UTF-8');

        if ($charset === 'utf8') {
            if ($isUtf8) {
                return $text;
            }

            return @iconv('CP437', 'UTF-8//TRANSLIT//IGNORE', $text)
                ?: (@mb_convert_encoding($text, 'UTF-8', 'CP437') ?: '');
        }

        if ($charset === 'cp437') {
            if (!$isUtf8) {
                return $text; // already CP437 bytes
            }

            return @iconv('UTF-8', 'CP437//TRANSLIT//IGNORE', $text) ?: '';
        }

        // ascii / anything else: block art does not survive; signal fallback.
        return '';
    }
}
