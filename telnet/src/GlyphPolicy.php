<?php

namespace BinktermPHP\TelnetServer;

/**
 * GlyphPolicy — resolves the box-drawing glyph set for a given effective
 * character set and a configured border style.
 *
 * This is the pure formatting logic previously inlined in
 * `BbsSession::getLineDrawingChars()` / `resolveEffectiveBorderStyle()` /
 * `borderGlyphs()`, extracted verbatim so a {@see TerminalRenderContext} — and
 * therefore off-session render tests and previews — can obtain frame glyphs
 * without a live session.
 *
 * Rules (unchanged from the historical behaviour):
 *   - An ASCII terminal always gets the ASCII glyph set, regardless of the
 *     configured style.
 *   - On a CP437 terminal, styles that require UTF-8-only codepoints degrade:
 *     `heavy` -> `classic`, `rounded` -> `single`.
 *   - On a UTF-8 terminal, the configured style is used as-is.
 */
final class GlyphPolicy
{
    /**
     * @param string $charset          Effective charset: 'utf8', 'cp437', or 'ascii'.
     * @param string $configuredStyle  The sysop-configured border style name.
     * @return array{h:string,h_bold:string,v:string,tl:string,tr:string,bl:string,br:string,l_tee:string,r_tee:string,shadow_char:string}
     */
    public static function forCharsetAndStyle(string $charset, string $configuredStyle): array
    {
        if ($charset === 'ascii') {
            return self::glyphs('ascii');
        }

        return self::glyphs(self::resolveStyle($configuredStyle, $charset));
    }

    /**
     * UTF-8-only styles fall back on CP437 terminals; on UTF-8 the style is
     * used unchanged.
     */
    private static function resolveStyle(string $style, string $charset): string
    {
        if ($charset === 'utf8') {
            return $style;
        }

        // cp437: heavy and rounded require UTF-8 codepoints not in the CP437 set
        return match ($style) {
            'heavy'   => 'classic',
            'rounded' => 'single',
            default   => $style,
        };
    }

    /**
     * @return array{h:string,h_bold:string,v:string,tl:string,tr:string,bl:string,br:string,l_tee:string,r_tee:string,shadow_char:string}
     */
    private static function glyphs(string $style): array
    {
        return match ($style) {
            'double' => [
                'h' => '═', 'h_bold' => '═', 'v' => '║',
                'tl' => '╔', 'tr' => '╗', 'bl' => '╚', 'br' => '╝',
                'l_tee' => '╠', 'r_tee' => '╣', 'shadow_char' => '',
            ],
            'single' => [
                'h' => '─', 'h_bold' => '─', 'v' => '│',
                'tl' => '┌', 'tr' => '┐', 'bl' => '└', 'br' => '┘',
                'l_tee' => '├', 'r_tee' => '┤', 'shadow_char' => '',
            ],
            'heavy' => [
                'h' => '━', 'h_bold' => '━', 'v' => '┃',
                'tl' => '┏', 'tr' => '┓', 'bl' => '┗', 'br' => '┛',
                'l_tee' => '┣', 'r_tee' => '┫', 'shadow_char' => '',
            ],
            'rounded' => [
                'h' => '─', 'h_bold' => '─', 'v' => '│',
                'tl' => '╭', 'tr' => '╮', 'bl' => '╰', 'br' => '╯',
                'l_tee' => '├', 'r_tee' => '┤', 'shadow_char' => '',
            ],
            'minimal' => [
                'h' => '─', 'h_bold' => '─', 'v' => ' ',
                'tl' => '─', 'tr' => '─', 'bl' => '─', 'br' => '─',
                'l_tee' => '─', 'r_tee' => '─', 'shadow_char' => '',
            ],
            'mixed' => [
                'h' => '═', 'h_bold' => '═', 'v' => '│',
                'tl' => '╒', 'tr' => '╕', 'bl' => '╘', 'br' => '╛',
                'l_tee' => '╞', 'r_tee' => '╡', 'shadow_char' => '',
            ],
            'shadow' => [
                'h' => '─', 'h_bold' => '═', 'v' => '│',
                'tl' => '╔', 'tr' => '╗', 'bl' => '╚', 'br' => '╝',
                'l_tee' => '╠', 'r_tee' => '╣', 'shadow_char' => '▒',
            ],
            'ascii' => [
                'h' => '-', 'h_bold' => '=', 'v' => '|',
                'tl' => '+', 'tr' => '+', 'bl' => '+', 'br' => '+',
                'l_tee' => '+', 'r_tee' => '+', 'shadow_char' => '',
            ],
            default => [ // 'classic' — double corners/tees, single sides and divider
                'h' => '─', 'h_bold' => '═', 'v' => '│',
                'tl' => '╔', 'tr' => '╗', 'bl' => '╚', 'br' => '╝',
                'l_tee' => '╠', 'r_tee' => '╣', 'shadow_char' => '',
            ],
        };
    }
}
