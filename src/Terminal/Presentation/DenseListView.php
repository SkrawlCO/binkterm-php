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

    /** @return array<int,string> */
    private static function rows(DenseList $list, TerminalRenderContext $ctx, int $cols): array
    {
        $usable = max(20, $cols - 1);

        $prefixWidth = 0;
        foreach ($list->rows as $row) {
            if ($row->prefix !== null && $row->prefix !== '') {
                $prefixWidth = max($prefixWidth, mb_strlen($row->prefix, 'UTF-8') + 1);
            }
        }

        // Chrome: leading space + "NN) " (4) + optional badge column.
        $overhead = 1 + 4 + $prefixWidth;
        $gaps = max(0, count($list->columns) - 1);
        $widths = self::fitColumns($list->columns, $usable - $overhead - $gaps);

        $out = [];
        foreach ($list->rows as $i => $row) {
            $cells = [];
            foreach ($list->columns as $ci => $col) {
                $w = $widths[$ci];
                $text = TextBlock::ellipsize((string)($row->cells[$col->key] ?? ''), $w);
                if (!$col->isFlexible()) {
                    $text = $col->align === DenseListColumn::ALIGN_RIGHT
                        ? self::padLeft($text, $w)
                        : TextBlock::padRight($text, $w);
                }
                $cells[] = $ctx->encodeForTerminal($text);
            }

            $prefix = $ctx->colorize(sprintf('%2d', $i + 1), self::NUM_SGR)
                . $ctx->colorize(')', self::PAREN_SGR) . ' ';

            if ($row->prefix !== null && $row->prefix !== '') {
                $badge = $row->prefixSgr !== null
                    ? $ctx->colorize($row->prefix, $row->prefixSgr)
                    : $row->prefix;
                $prefix .= $badge . ' ';
            }

            $out[] = ' ' . $prefix . implode(' ', $cells);
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
