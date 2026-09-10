<?php

namespace BinktermPHP\Terminal\Presentation;

use BinktermPHP\TelnetServer\TerminalRenderContext;

/**
 * Composes a {@see DenseList} into the *flat* selectable-list contract that
 * {@see \BinktermPHP\TelnetServer\TerminalShellInterface::showSelectableList()}
 * already renders through
 * {@see \BinktermPHP\TelnetServer\TelnetUtils::runSelectableList()} — one
 * pre-formatted display string per row, one screen row per item, no wrapping,
 * no per-row detail line.
 *
 * This is the dense-list half of the Terminal Experience Unification primitive.
 * It shares the M1 vocabulary and the pure geometry helpers ({@see TextBlock})
 * with {@see DirectoryView}, but NOT its composition: an arrival-space directory
 * spends rows on a masthead band, a tagline and grouped section headings; a
 * dense list spends exactly two chrome lines — a location identity line with a
 * right-aligned page indicator, and an optional compact context line — so the
 * grid keeps its density.
 *
 * Placement is delegated: this only produces the title string, the header
 * line(s) and the row strings. The identity line is left un-colourised (the
 * shell colours the whole title); the context line and the row number chrome
 * are colourised here through {@see TerminalRenderContext::colorize()} (a no-op
 * on a mono terminal) and every visible glyph is charset-encoded through
 * {@see TerminalRenderContext::encodeForTerminal()}.
 */
final class DenseListView
{
    /** Row number — cyan bold, matching the established list-number convention. */
    private const NUM_SGR = "\033[36m\033[1m";

    /** The ")" after the row number — blue, matching the same convention. */
    private const PAREN_SGR = "\033[34m";

    /** Compact context line — dim, matching DirectoryView's muted treatment. */
    private const MUTED_SGR = "\033[2m";

    /** Widest the identity line is ever drawn. */
    private const IDENTITY_MAX = 78;

    /** Smallest a flexible column is allowed to collapse to before clipping. */
    private const MIN_FLEX = 8;

    /**
     * @param array<string,mixed> $opts reserved for future presentation options
     * @return array{title:string, headerLines:array<int,string>, rows:array<int,string>, values:array<int,mixed>}
     *   title:       identity line + right-aligned page indicator (shell colours it)
     *   headerLines: 0 or 1 compact context lines, pre-colourised, rendered under the title
     *   rows:        flat pre-formatted display strings for the flat selectable list
     *   values:      parallel array of each row's opaque payload, by page index
     */
    public static function compose(DenseList $list, TerminalRenderContext $ctx, array $opts = []): array
    {
        $cols = max(20, $ctx->cols());
        $utf8 = $ctx->effectiveCharset() === 'utf8';

        return [
            'title'       => self::identityLine($list, $ctx, $cols, $utf8),
            'headerLines' => self::contextLines($list, $ctx, $cols),
            'rows'        => self::rows($list, $ctx, $cols),
            'values'      => array_map(static fn(DenseListRow $r) => $r->value, $list->rows),
        ];
    }

    private static function identityLine(DenseList $list, TerminalRenderContext $ctx, int $cols, bool $utf8): string
    {
        $sep = $utf8 ? " \u{203A} " : ' > ';
        $left = implode($sep, array_merge(
            array_map('trim', $list->crumbs),
            [trim($list->location)]
        ));

        $right = $ctx->t(
            'ui.terminalserver.list.dense_page_indicator',
            'Page {page}/{total}',
            ['page' => $list->page, 'total' => max(1, $list->totalPages)]
        );

        $width = min($cols - 1, self::IDENTITY_MAX);

        if (mb_strlen($left, 'UTF-8') + 2 + mb_strlen($right, 'UTF-8') <= $width) {
            $line = TextBlock::padRight($left, $width - mb_strlen($right, 'UTF-8')) . $right;
        } else {
            // Too cramped for the indicator — keep the identity, drop the tail.
            $line = TextBlock::ellipsize($left, $width);
        }

        return $ctx->encodeForTerminal($line);
    }

    /** @return array<int,string> */
    private static function contextLines(DenseList $list, TerminalRenderContext $ctx, int $cols): array
    {
        $context = $list->context !== null ? trim($list->context) : '';
        if ($context === '') {
            return [];
        }

        return [$ctx->colorize(
            $ctx->encodeForTerminal(TextBlock::ellipsize($context, max(8, $cols - 2))),
            self::MUTED_SGR
        )];
    }

    /** Trailing annotation ("42 new") — cyan bold, matching the number chrome. */
    private const TRAILING_SGR = "\033[36m\033[1m";

    /** @return array<int,string> */
    private static function rows(DenseList $list, TerminalRenderContext $ctx, int $cols): array
    {
        $usable  = max(20, $cols - 1);
        $widths  = self::gridWidths($list, $usable);
        $trailW  = self::trailingWidth($list);

        $out = [];
        foreach ($list->rows as $i => $row) {
            $out[] = self::formatRow($list, $ctx, $row, $i, $widths, $trailW, false);
        }

        return $out;
    }

    /**
     * A selection-following window of the grid, fitted to an authored MENU
     * region (`$width` x `$height` visible cells), for the M2 themed frame.
     * Row indices, numbers and payloads are unchanged — this is presentation
     * only. Returns exactly the fitted row strings (0..$height of them); the
     * caller pads the block.
     *
     * @return array<int,string>
     */
    public static function viewport(
        DenseList $list,
        TerminalRenderContext $ctx,
        int $width,
        int $height,
        int $selectedIndex
    ): array {
        $height = max(1, $height);
        $total  = count($list->rows);
        if ($total === 0) {
            return [];
        }
        $selectedIndex = max(0, min($selectedIndex, $total - 1));
        $first  = self::windowStart($total, $height, $selectedIndex);
        $widths = self::gridWidths($list, max(20, $width));
        $trailW = self::trailingWidth($list);

        $out = [];
        for ($i = $first; $i < $total && count($out) < $height; $i++) {
            $out[] = self::formatRow($list, $ctx, $list->rows[$i], $i, $widths, $trailW, $i === $selectedIndex, $width);
        }

        return $out;
    }

    /**
     * First row index of a selection-following window of `$height` rows over a
     * `$total`-row list: the selected row is kept visible and roughly centred,
     * without letting the window run past either list boundary.
     */
    public static function windowStart(int $total, int $height, int $selectedIndex): int
    {
        $height = max(1, $height);
        if ($total <= $height) {
            return 0;
        }
        $selectedIndex = max(0, min($selectedIndex, $total - 1));

        return max(0, min($selectedIndex - intdiv($height - 1, 2), $total - $height));
    }

    /**
     * Ellipsize to at most `$width` cells *as emitted to this terminal*.
     *
     * {@see TextBlock::ellipsize()} appends the single glyph U+2026, which
     * {@see TerminalRenderContext::encodeForTerminal()} transliterates to "..."
     * on CP437 (and ASCII) — three cells, not one. A row fitted in UTF-8 then
     * encoded to CP437 therefore overruns its region and
     * {@see NavigationScreenRenderer::fitSemanticBlock()} (mustFit) throws,
     * dropping the authored frame to fallback. On those charsets the terminator
     * is spelled out and charged its real width here.
     *
     * A value that already fits is returned untouched — no ellipsis, no
     * expansion — so on UTF-8 this is byte-identical to {@see TextBlock::ellipsize()}.
     */
    private static function ellipsizeForTerminal(string $s, int $width, TerminalRenderContext $ctx): string
    {
        if ($width <= 0) {
            return '';
        }
        if (mb_strlen($s, 'UTF-8') <= $width) {
            return $s;
        }
        if ($ctx->effectiveCharset() === 'utf8') {
            return TextBlock::ellipsize($s, $width);
        }
        if ($width <= 3) {
            return mb_substr($s, 0, $width, 'UTF-8');
        }

        return rtrim(mb_substr($s, 0, $width - 3, 'UTF-8')) . '...';
    }

    /** Widest trailing annotation across the page (+1 gap), or 0 when none. */
    private static function trailingWidth(DenseList $list): int
    {
        $w = 0;
        foreach ($list->rows as $row) {
            $t = $row->trailing !== null ? trim($row->trailing) : '';
            if ($t !== '') {
                $w = max($w, mb_strlen($t, 'UTF-8'));
            }
        }

        return $w > 0 ? $w + 1 : 0;
    }

    /** @return array<int,int> column widths for the usable content span */
    private static function gridWidths(DenseList $list, int $usable): array
    {
        $prefixWidth = 0;
        foreach ($list->rows as $row) {
            if ($row->prefix !== null && $row->prefix !== '') {
                $prefixWidth = max($prefixWidth, mb_strlen($row->prefix, 'UTF-8') + 1);
            }
        }
        // Chrome: leading space + "NN) " (4) + optional badge column + trailing.
        $overhead = 1 + 4 + $prefixWidth + self::trailingWidth($list);
        $gaps = max(0, count($list->columns) - 1);

        return self::fitColumns($list->columns, $usable - $overhead - $gaps);
    }

    /**
     * Format one grid row. When `$fitWidth` is given the row is laid out to
     * exactly that many visible cells (for an authored MENU region); otherwise
     * it grows naturally (the flat fallback list). `$selected` renders the whole
     * row in reverse video (no inner SGR); an unselected row colourises only the
     * number chrome, the prefix badge and the trailing count.
     *
     * @param array<int,int> $widths
     */
    private static function formatRow(
        DenseList $list,
        TerminalRenderContext $ctx,
        DenseListRow $row,
        int $index,
        array $widths,
        int $trailW,
        bool $selected,
        ?int $fitWidth = null
    ): string {
        $cells = [];
        foreach ($list->columns as $ci => $col) {
            $w = $widths[$ci] ?? $col->minWidth;
            $text = self::ellipsizeForTerminal((string) ($row->cells[$col->key] ?? ''), $w, $ctx);
            if (!$col->isFlexible()) {
                $text = $col->align === DenseListColumn::ALIGN_RIGHT
                    ? self::padLeft($text, $w)
                    : TextBlock::padRight($text, $w);
            }
            $cells[] = $text;
        }

        $num    = sprintf('%2d) ', $index + 1);
        $badge  = ($row->prefix !== null && $row->prefix !== '') ? $row->prefix . ' ' : '';
        $grid   = implode(' ', $cells);
        $trail  = ($trailW > 0 && $row->trailing !== null && trim($row->trailing) !== '') ? trim($row->trailing) : '';

        // Visible layout: " " + num + badge + grid + [pad + trail]
        $left = ' ' . $num . $badge . $grid;
        if ($trail !== '') {
            $target = $fitWidth ?? (mb_strlen($left, 'UTF-8') + $trailW);
            $pad = max(1, $target - mb_strlen($left, 'UTF-8') - mb_strlen($trail, 'UTF-8'));
            $left .= str_repeat(' ', $pad) . $trail;
        }
        if ($fitWidth !== null) {
            $left = TextBlock::padRight(self::ellipsizeForTerminal($left, $fitWidth, $ctx), $fitWidth);
        }

        if ($selected) {
            return $ctx->colorize($ctx->encodeForTerminal($left), "\033[7m");
        }

        // Rebuild with colour, preserving the exact visible column layout by
        // re-cutting `$left` at known offsets.
        $emphOpen  = ($row->emphasis !== null && $row->emphasis !== '') ? $row->emphasis : '';
        $emphClose = $emphOpen !== '' ? "\033[0m" : '';

        $out = ' ';
        $pos = 1;
        $out .= $ctx->colorize(sprintf('%2d', $index + 1), self::NUM_SGR)
            . $ctx->colorize(')', self::PAREN_SGR) . ' ';
        $pos += 4;
        if ($badge !== '') {
            // Colourise the marker only; the trailing space stays plain (matches
            // the historical flat-list rendering that tests pin).
            $marker = mb_substr($left, $pos, mb_strlen((string) $row->prefix, 'UTF-8'), 'UTF-8');
            $out .= ($row->prefixSgr !== null ? $ctx->colorize($marker, $row->prefixSgr) : $ctx->encodeForTerminal($marker)) . ' ';
            $pos += mb_strlen($badge, 'UTF-8');
        }
        if ($trail !== '') {
            $mid = mb_substr($left, $pos, -mb_strlen($trail, 'UTF-8'), 'UTF-8');
            $out .= $emphOpen . $ctx->encodeForTerminal($mid) . $emphClose
                . $ctx->colorize($ctx->encodeForTerminal($trail), self::TRAILING_SGR);
        } else {
            $out .= $emphOpen . $ctx->encodeForTerminal(mb_substr($left, $pos, null, 'UTF-8')) . $emphClose;
        }

        return $out;
    }

    /**
     * Resolve each column's actual width. The flexible column takes the
     * remainder; when the remainder is below {@see MIN_FLEX} the fixed columns
     * are shrunk widest-first (never below their {@see DenseListColumn::$minWidth})
     * to make room, and only then does the flexible column clip.
     *
     * @param list<DenseListColumn> $columns
     * @return array<int,int> width by column index
     */
    private static function fitColumns(array $columns, int $budget): array
    {
        $budget = max(0, $budget);
        $widths = [];
        $flexIndex = null;
        $fixedTotal = 0;

        foreach ($columns as $i => $col) {
            if ($col->isFlexible()) {
                $flexIndex = $flexIndex ?? $i;
                $widths[$i] = 0;
                continue;
            }
            $widths[$i] = $col->width;
            $fixedTotal += $col->width;
        }

        // No column declared flexible — treat the last as the remainder column.
        if ($flexIndex === null && $columns !== []) {
            $flexIndex = array_key_last($columns);
            $fixedTotal -= $widths[$flexIndex];
            $widths[$flexIndex] = 0;
        }

        if ($flexIndex === null) {
            return $widths;
        }

        $flex = $budget - $fixedTotal;
        if ($flex < self::MIN_FLEX) {
            $deficit = self::MIN_FLEX - $flex;
            // Shrink fixed columns, widest first.
            $order = [];
            foreach ($columns as $i => $col) {
                if ($i !== $flexIndex && !$col->isFlexible()) {
                    $order[$i] = $widths[$i];
                }
            }
            arsort($order);
            foreach (array_keys($order) as $i) {
                if ($deficit <= 0) {
                    break;
                }
                $room = $widths[$i] - $columns[$i]->minWidth;
                $take = max(0, min($room, $deficit));
                $widths[$i] -= $take;
                $deficit -= $take;
            }
            $flex = $budget - array_sum(array_filter($widths, static fn($w) => $w > 0));
        }

        $widths[$flexIndex] = max(1, $flex);

        return $widths;
    }

    /** Left-pad to $width visible cells; an over-width string is returned unchanged. */
    private static function padLeft(string $s, int $width): string
    {
        $len = mb_strlen($s, 'UTF-8');

        return $len >= $width ? $s : str_repeat(' ', $width - $len) . $s;
    }
}
