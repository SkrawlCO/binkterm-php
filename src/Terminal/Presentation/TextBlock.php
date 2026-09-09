<?php

namespace BinktermPHP\Terminal\Presentation;

/**
 * Pure, transport-neutral text-geometry helpers shared by the terminal
 * presentation primitives.
 *
 * These were first written as private helpers inside
 * {@see \BinktermPHP\Terminal\Navigation\NavigationScreenRenderer}; they are
 * extracted here verbatim so the declarative navigation renderer and
 * {@see DirectoryView} compose column widths and truncation identically. The
 * bodies must stay byte-for-byte equivalent to the originals — the front-door
 * renderer delegates to them and its output is regression-pinned.
 */
final class TextBlock
{
    /**
     * Right-pad a string with spaces to exactly $width visible cells. A string
     * already at or beyond $width is returned unchanged (never truncated).
     */
    public static function padRight(string $s, int $width): string
    {
        $len = mb_strlen($s, 'UTF-8');

        return $len >= $width ? $s : $s . str_repeat(' ', $width - $len);
    }

    /**
     * Clip a string to $width visible cells, replacing the trailing cell with a
     * horizontal-ellipsis when it does not fit. Multibyte-aware. The ellipsis is
     * a UTF-8 character — call this before charset encoding, not after.
     */
    public static function ellipsize(string $s, int $width): string
    {
        if (mb_strlen($s, 'UTF-8') <= $width) {
            return $s;
        }

        return rtrim(mb_substr($s, 0, max(0, $width - 1), 'UTF-8')) . ($width > 0 ? "\u{2026}" : '');
    }
}
