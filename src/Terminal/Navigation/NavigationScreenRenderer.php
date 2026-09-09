<?php

namespace BinktermPHP\Terminal\Navigation;

use BinktermPHP\TelnetServer\TerminalRenderContext;

/**
 * Renders a {@see NavigationScreenModel} to a {@see TerminalRenderContext}.
 *
 * This is the single rendering path for declarative navigation screens — the
 * live session, the deterministic tests, and the sysop preview all go through
 * it. It differs between those only in the sink and the capability/geometry
 * carried by the context; layout maths, ANSI-aware clipping, glyph and charset
 * handling, and colour application are identical because they all come from the
 * context.
 *
 * The screen is drawn top-anchored and clipped from the bottom so the title and
 * orientation line are never scrolled away on a short terminal; a footer status
 * line is always anchored to the last usable row.
 */
final class NavigationScreenRenderer
{
    private const EMPHASIS_COLOR = [
        'primary' => "\033[36m\033[1m", // cyan bold
        'muted'   => "\033[2m",         // dim
        'danger'  => "\033[31m",        // red
        'normal'  => '',
    ];

    /**
     * @param array<string,mixed> $opts  'clear' (bool, default true),
     *                                   'show_hotkeys' (bool, default true),
     *                                   'cursor' (int|null, index into the visible
     *                                             selectable items, for a lightbar)
     */
    public function render(TerminalRenderContext $ctx, NavigationScreenModel $screen, array $opts = []): void
    {
        $clear       = $opts['clear'] ?? true;
        $showHotkeys = $opts['show_hotkeys'] ?? true;
        $cursor      = $opts['cursor'] ?? null;

        $cols = max(20, $ctx->cols());
        $rows = max(6, $ctx->selectorRows());

        if ($clear) {
            $ctx->write("\033[2J\033[H");
        } else {
            $ctx->write("\033[H");
        }

        $lines = $this->composeLines($ctx, $screen, $cols, $rows, $showHotkeys, $cursor);
        foreach ($lines as $line) {
            $ctx->writeLine($this->clip($line, $cols));
        }
    }

    /**
     * Compose the full screen as an ordered list of display lines. Exposed so
     * tests and previews can assert on structure without a sink.
     *
     * @return array<int,string>
     */
    public function composeLines(
        TerminalRenderContext $ctx,
        NavigationScreenModel $screen,
        int $cols,
        int $rows,
        bool $showHotkeys = true,
        ?int $cursor = null
    ): array {
        $glyphs = $ctx->lineDrawingChars();
        $rule   = str_repeat($glyphs['h'] ?? '-', $cols);

        $header = [];
        $header[] = $ctx->colorize($this->center($screen->title, $cols), self::EMPHASIS_COLOR['primary']);
        $header[] = $ctx->encodeForTerminal($rule);
        $orientation = $this->orientationLine($screen);
        if ($orientation !== '') {
            $header[] = $ctx->colorize($orientation, self::EMPHASIS_COLOR['muted']);
        }
        if ($screen->description !== null && $screen->description !== '') {
            $header[] = $screen->description;
        }
        $header[] = '';

        $footer = [
            $ctx->encodeForTerminal($rule),
            $ctx->colorize($this->footerHints($screen), self::EMPHASIS_COLOR['muted']),
        ];

        $bodyBudget = max(1, $rows - count($header) - count($footer));
        $body       = $this->itemLines($ctx, $screen, $showHotkeys, $cursor);

        $clippedFromBottom = false;
        if (count($body) > $bodyBudget) {
            $body = array_slice($body, 0, $bodyBudget - 1);
            $clippedFromBottom = true;
        }
        if ($clippedFromBottom) {
            $body[] = $ctx->colorize('  ' . $this->moreMarker($glyphs), self::EMPHASIS_COLOR['muted']);
        }
        while (count($body) < $bodyBudget) {
            $body[] = '';
        }

        return [...$header, ...$body, ...$footer];
    }

    /**
     * @return array<int,string>
     */
    private function itemLines(
        TerminalRenderContext $ctx,
        NavigationScreenModel $screen,
        bool $showHotkeys,
        ?int $cursor
    ): array {
        $out           = [];
        $selectableSeen = 0;
        foreach ($screen->items as $item) {
            $isCursor = false;
            if ($item->isSelectable()) {
                $isCursor = $cursor !== null && $selectableSeen === $cursor;
                $selectableSeen++;
            }

            $prefix = '';
            if ($showHotkeys && $item->hotkey !== null) {
                $prefix = '[' . mb_strtoupper($item->hotkey) . '] ';
            } elseif ($showHotkeys) {
                $prefix = '    ';
            }

            $label = $item->label;
            if ($item->isSubmenu()) {
                $label .= ' ' . ($ctx->lineDrawingChars()['r_tee'] ?? '>');
            }
            if (!$item->isSelectable()) {
                $label .= '  (' . ($item->disabledReason ?? 'unavailable') . ')';
            }

            $marker = $isCursor ? '> ' : '  ';
            $text   = $ctx->encodeForTerminal($marker . $prefix . $label);

            if ($isCursor) {
                $out[] = $ctx->colorize($text, "\033[7m"); // reverse video lightbar
                continue;
            }
            $color = self::EMPHASIS_COLOR[$item->isSelectable() ? $item->emphasis : 'muted'] ?? '';
            $out[] = $color !== '' ? $ctx->colorize($text, $color) : $text;
        }

        return $out;
    }

    private function orientationLine(NavigationScreenModel $screen): string
    {
        if ($screen->path->isRoot()) {
            return '';
        }

        return $screen->path->crumb(' > ');
    }

    private function footerHints(NavigationScreenModel $screen): string
    {
        $parts = [];
        $selectable = $screen->selectableItems();
        if ($selectable !== []) {
            $parts[] = 'Select an option';
        }
        if ($screen->backAvailable) {
            // "B" is only a Back affordance when the screen does not bind it to
            // an item; otherwise advertise the non-conflicting Back keys only.
            $parts[] = ($screen->bindsHotkey('b') ? 'Left/Esc' : 'B/Left') . ' Back';
        }
        // "H Home" only when the screen does not bind H to an item. When it does,
        // the Home affordance is left to the terminal's Home key (unconditional
        // in the runtime) rather than a conflicting shortcut.
        if ($screen->homeAvailable && !$screen->bindsHotkey('h')) {
            $parts[] = 'H Home';
        }
        // "Q" is only a hint when THIS screen actually binds it (an explicit
        // quit / log-off item). It is not a universal key, so screens without a
        // 'q' item never advertise one.
        $quitItem = $screen->itemForHotkey('q');
        if ($quitItem !== null) {
            $parts[] = 'Q ' . $quitItem->label;
        }

        return ' ' . implode('   ', $parts);
    }

    private function moreMarker(array $glyphs): string
    {
        return trim(str_repeat($glyphs['h'] ?? '.', 3)) . ' more';
    }

    private function center(string $text, int $width): string
    {
        $len = mb_strlen($text);
        if ($len >= $width) {
            return $text;
        }
        $pad = intdiv($width - $len, 2);

        return str_repeat(' ', $pad) . $text;
    }

    /** ANSI-aware, multibyte-aware clip to a visible column width. */
    private function clip(string $line, int $width): string
    {
        $plain = preg_replace('/\033\[[0-9;]*m/', '', $line) ?? $line;
        if (mb_strlen($plain, 'UTF-8') <= $width) {
            return $line;
        }

        // Walk the string, counting visible characters and passing SGR through.
        $tokens = preg_split('/(\033\[[0-9;]*m)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $out       = '';
        $visible   = 0;
        $hadColour = false;
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            if ($token[0] === "\033") {
                $out .= $token;
                $hadColour = true;
                continue;
            }
            foreach (mb_str_split($token, 1, 'UTF-8') as $ch) {
                if ($visible >= $width) {
                    return $out . ($hadColour ? "\033[0m" : '');
                }
                $out .= $ch;
                $visible++;
            }
        }

        return $out . ($hadColour ? "\033[0m" : '');
    }
}
