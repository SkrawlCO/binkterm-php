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
 * Composition:
 *   - the content is a bounded column (max {@see CONTENT_MAX} cols) with a
 *     capped left margin, so wide terminals get a designed column rather than
 *     edge-to-edge text or a tiny block lost mid-screen;
 *   - a header band ("── Title ─────") plus an optional tagline (the node
 *     description);
 *   - items, grouped into bold uppercase sections when the screen carries >= 2
 *     distinct `presentation.group` hints, otherwise a flat list;
 *   - item descriptions: rendered inline under each item when the terminal is
 *     tall enough for the whole block, otherwise collapsed to a single roaming
 *     status line above the footer hints that tracks the highlighted item;
 *   - a footer rule + truthful key hints that follows the content (with an
 *     adaptive top margin positioning the block in the upper-middle) rather
 *     than being pinned to the last row.
 *
 * On a terminal too short for the whole block the top margin collapses and the
 * item list is clipped from the bottom (header, status line and footer stay
 * visible), with a "… more" marker.
 */
final class NavigationScreenRenderer
{
    /** Widest the content column is ever drawn, regardless of terminal width. */
    public const CONTENT_MAX = 78;

    /** Never indent the content column further than this from the left edge. */
    private const MAX_LEFT_PAD = 8;

    /** Cap on the adaptive top margin so a tall terminal is not mostly blank. */
    private const MAX_TOP_MARGIN = 8;

    /** Section headings: bold, no colour (renders as plain text when colour is off). */
    private const SECTION_HEADING = "\033[1m";

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

        $ctx->write($clear ? "\033[2J\033[H" : "\033[H");

        foreach ($this->composeLines($ctx, $screen, $cols, $rows, $showHotkeys, $cursor) as $line) {
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
        $hglyph = $glyphs['h'] ?? '-';
        $utf8   = $ctx->effectiveCharset() === 'utf8';

        $contentWidth = min(max(20, $cols - 4), self::CONTENT_MAX);
        $pad          = str_repeat(' ', min(self::MAX_LEFT_PAD, max(0, intdiv($cols - $contentWidth, 2))));

        $header = $this->headerBlock($ctx, $screen, $contentWidth, $pad, $hglyph);
        $footer = [
            $pad . $ctx->colorize(
                $ctx->encodeForTerminal(str_repeat($hglyph, $contentWidth)),
                self::EMPHASIS_COLOR['muted']
            ),
            $pad . $ctx->colorize($ctx->encodeForTerminal($this->footerHints($screen)), self::EMPHASIS_COLOR['muted']),
        ];

        $hasDescriptions = $this->anyItemHasDescription($screen);

        // Prefer the richer layout — every item annotated inline — and fall back
        // to a compact list plus one roaming status line when the rich block
        // would not fit the terminal height.
        $richBody = $this->bodyBlock($ctx, $screen, $contentWidth, $pad, $showHotkeys, $cursor, true);
        $useRich  = $hasDescriptions
            && (count($header) + count($richBody) + count($footer) + 1) <= $rows;

        if ($useRich) {
            $body       = $richBody;
            $footerZone = $footer;
        } else {
            $body       = $this->bodyBlock($ctx, $screen, $contentWidth, $pad, $showHotkeys, $cursor, false);
            $footerZone = $footer;
            if ($hasDescriptions) {
                $statusText = $this->statusLine($screen, $contentWidth - 4, $cursor);
                if ($statusText !== '') {
                    $marker     = ($utf8 ? "\u{25B8}" : '>') . ' ';
                    $status     = $pad . '  ' . $ctx->encodeForTerminal($marker . $statusText);
                    $footerZone = [$footer[0], $status, $footer[1]];
                }
            }
        }

        $fixed       = count($header) + count($footerZone);
        $blockHeight = $fixed + count($body);

        if ($blockHeight >= $rows) {
            // Not enough room — collapse the margin, clip the list from the bottom.
            $maxBody = max(1, $rows - $fixed - 1);
            if (count($body) > $maxBody) {
                $body   = array_slice($body, 0, $maxBody);
                $body[] = $pad . '  ' . $ctx->colorize(
                    $ctx->encodeForTerminal($this->moreMarker($glyphs)),
                    self::EMPHASIS_COLOR['muted']
                );
            }
            $topMargin = 0;
        } else {
            // Position the block in the upper-middle; clean space below.
            $topMargin = max(1, min(self::MAX_TOP_MARGIN, intdiv($rows - $blockHeight, 3)));
        }

        return [
            ...array_fill(0, $topMargin, ''),
            ...$header,
            ...$body,
            ...$footerZone,
        ];
    }

    /**
     * @return array<int,string>
     */
    private function headerBlock(
        TerminalRenderContext $ctx,
        NavigationScreenModel $screen,
        int $width,
        string $pad,
        string $hglyph
    ): array {
        $out = [];

        // Header band: "── Title ─────────────────"
        $title = ' ' . trim($screen->title) . ' ';
        $lead  = str_repeat($hglyph, 2);
        $tail  = str_repeat($hglyph, max(0, $width - mb_strlen($lead) - mb_strlen($title)));
        $out[] = $pad
            . $ctx->colorize($ctx->encodeForTerminal($lead), self::EMPHASIS_COLOR['muted'])
            . $ctx->colorize($ctx->encodeForTerminal($title), self::EMPHASIS_COLOR['primary'])
            . $ctx->colorize($ctx->encodeForTerminal($tail), self::EMPHASIS_COLOR['muted']);

        // Orientation crumb (submenus only).
        if (!$screen->path->isRoot()) {
            $out[] = $pad . $ctx->colorize(
                $ctx->encodeForTerminal($screen->path->crumb(' > ')),
                self::EMPHASIS_COLOR['muted']
            );
        }

        // Tagline (node description).
        if ($screen->description !== null && trim($screen->description) !== '') {
            foreach ($this->wrap(trim($screen->description), $width) as $line) {
                $out[] = $pad . $ctx->colorize($ctx->encodeForTerminal($line), self::EMPHASIS_COLOR['muted']);
            }
        }

        $out[] = '';

        return $out;
    }

    /**
     * @param bool $inlineDescriptions render each item's description on its own
     *                                 dim line directly below the item
     * @return array<int,string>
     */
    private function bodyBlock(
        TerminalRenderContext $ctx,
        NavigationScreenModel $screen,
        int $width,
        string $pad,
        bool $showHotkeys,
        ?int $cursor,
        bool $inlineDescriptions
    ): array {
        $utf8  = $ctx->effectiveCharset() === 'utf8';
        $items = $screen->items;

        // Selectable index by item identity. NavigationRuntime's cursor indexes
        // selectableItems() in definition order; grouping can reorder items for
        // display, so the highlight must be resolved by id, not by draw order.
        $selectableIndex = [];
        foreach ($screen->selectableItems() as $i => $it) {
            $selectableIndex[$it->id] = $i;
        }

        $groups = [];
        foreach ($items as $it) {
            if ($it->group !== null && $it->group !== '' && !in_array($it->group, $groups, true)) {
                $groups[] = $it->group;
            }
        }
        $useSections = count($groups) >= 2;

        $out  = [];
        $emit = function (NavigationScreenItem $it, bool $indent) use (
            &$out, $ctx, $pad, $width, $showHotkeys, $cursor, $utf8, $selectableIndex, $inlineDescriptions
        ): void {
            $isCursor = $cursor !== null
                && isset($selectableIndex[$it->id])
                && $selectableIndex[$it->id] === $cursor;

            $key = '';
            if ($showHotkeys && $it->hotkey !== null) {
                $key = '[' . mb_strtoupper($it->hotkey) . '] ';
            } elseif ($showHotkeys) {
                $key = '    ';
            }

            $label = $it->label;
            if ($it->isSubmenu()) {
                $label .= ' ' . ($utf8 ? "\u{203A}" : '>');
            }
            if (!$it->isSelectable()) {
                $label .= '  (' . ($it->disabledReason ?? 'unavailable') . ')';
            }

            // Live-context badge: a short dim suffix ("· 3 online") on a
            // selectable item. Under the lightbar it rides the reverse-video
            // row; otherwise it is dimmed apart from the label.
            $badge = ($it->isSelectable() && $it->annotation !== null && trim($it->annotation) !== '')
                ? '  ' . ($utf8 ? "\u{00B7}" : '-') . ' ' . trim($it->annotation)
                : '';

            $indentSp = $indent ? '  ' : '';
            $prefix   = ($isCursor ? '> ' : '  ') . $indentSp . $key;

            if ($isCursor) {
                $out[] = $pad . $ctx->colorize($ctx->encodeForTerminal($prefix . $label . $badge), "\033[7m");
            } else {
                $colour = self::EMPHASIS_COLOR[$it->isSelectable() ? $it->emphasis : 'muted'] ?? '';
                $main   = $ctx->encodeForTerminal($prefix . $label);
                $row    = $pad . ($colour !== '' ? $ctx->colorize($main, $colour) : $main);
                if ($badge !== '') {
                    $row .= $ctx->colorize($ctx->encodeForTerminal($badge), self::EMPHASIS_COLOR['muted']);
                }
                $out[] = $row;
            }

            if ($inlineDescriptions && $it->description !== null && trim($it->description) !== '') {
                $descIndent = '  ' . $indentSp . '    ';
                $descLine   = $this->wrap(trim($it->description), max(8, $width - mb_strlen($descIndent)))[0] ?? '';
                if ($descLine !== '') {
                    $out[] = $pad . $descIndent . $ctx->colorize(
                        $ctx->encodeForTerminal($descLine),
                        self::EMPHASIS_COLOR['muted']
                    );
                }
            }
        };

        if (!$useSections) {
            foreach ($items as $it) {
                $emit($it, false);
            }

            return $out;
        }

        $firstSection = true;
        foreach ($groups as $group) {
            if (!$firstSection) {
                $out[] = '';
            }
            $firstSection = false;
            $out[] = $pad . '  ' . $ctx->colorize(
                $ctx->encodeForTerminal(mb_strtoupper($group)),
                self::SECTION_HEADING
            );
            foreach ($items as $it) {
                if ($it->group === $group) {
                    $emit($it, true);
                }
            }
        }

        $ungrouped = array_values(array_filter(
            $items,
            static fn (NavigationScreenItem $it) => $it->group === null || $it->group === ''
        ));
        if ($ungrouped !== []) {
            $out[] = '';
            foreach ($ungrouped as $it) {
                $emit($it, true);
            }
        }

        return $out;
    }

    private function anyItemHasDescription(NavigationScreenModel $screen): bool
    {
        foreach ($screen->items as $it) {
            if ($it->description !== null && trim($it->description) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * One-line "you are pointing at" text for the compact layout: the
     * highlighted item's label and description, clipped to the content width.
     */
    private function statusLine(NavigationScreenModel $screen, int $width, ?int $cursor): string
    {
        $selectable = $screen->selectableItems();
        $item       = $selectable[$cursor ?? 0] ?? ($selectable[0] ?? null);
        if ($item === null) {
            return '';
        }

        $desc = $item->description !== null ? trim($item->description) : '';
        $text = $desc !== '' ? trim($item->label) . ': ' . $desc : trim($item->label);

        return $this->wrap($text, max(8, $width))[0] ?? '';
    }

    private function footerHints(NavigationScreenModel $screen): string
    {
        $parts = [];
        if ($screen->selectableItems() !== []) {
            $parts[] = 'Select an option';
        }
        if ($screen->backAvailable) {
            $parts[] = ($screen->bindsHotkey('b') ? 'Left/Esc' : 'B/Left') . ' Back';
        }
        if ($screen->homeAvailable && !$screen->bindsHotkey('h')) {
            $parts[] = 'H Home';
        }
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

    /**
     * Word-wrap plain text to a column width (never splits a word unless it is
     * itself wider than the column).
     *
     * @return array<int,string>
     */
    private function wrap(string $text, int $width): array
    {
        $width = max(8, $width);
        $lines = [];
        foreach (preg_split('/\R/', $text) ?: [$text] as $paragraph) {
            $wrapped = wordwrap($paragraph, $width, "\n", true);
            foreach (explode("\n", $wrapped) as $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /** ANSI-aware, multibyte-aware clip to a visible column width. */
    private function clip(string $line, int $width): string
    {
        $plain = preg_replace('/\033\[[0-9;]*m/', '', $line) ?? $line;
        if (mb_strlen($plain, 'UTF-8') <= $width) {
            return $line;
        }

        $tokens    = preg_split('/(\033\[[0-9;]*m)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
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
