<?php

namespace BinktermPHP\Terminal\Presentation;

use BinktermPHP\TelnetServer\TerminalRenderContext;

/**
 * Composes a {@see Directory} into the structured selectable-list contract that
 * {@see \BinktermPHP\TelnetServer\TuiShell::chooseFromList()} and
 * {@see \BinktermPHP\TelnetServer\LineShell::chooseFromList()} already consume
 * ({@see \BinktermPHP\TelnetServer\TelnetUtils::runSelectableStructuredList()}).
 *
 * This is the reusable seam of the "Terminal Experience Unification" primitive:
 * it layers a location masthead, a tagline, an ambient context block and
 * uppercase section headings — the front door's presentation vocabulary — onto
 * a legacy list, WITHOUT touching the proven list navigation/render/resize
 * behaviour and without forcing the screen into a NavigationScreenModel.
 *
 * All rendering placement is delegated: this only produces the row array and
 * the title string. Section headings and preamble lines are colour-aware via
 * {@see TerminalRenderContext::colorize()} (a no-op when colour is disabled, so
 * a mono terminal still gets a plain uppercase heading), and every visible glyph
 * is charset-encoded through {@see TerminalRenderContext::encodeForTerminal()}.
 */
final class DirectoryView
{
    /** Masthead identity + section headings — cyan bold / bold, matching NavigationScreenRenderer. */
    private const HEADING_SGR = "\033[1m";

    /** Tagline + ambient context — dim. */
    private const MUTED_SGR = "\033[2m";

    /** Widest the masthead rule is ever drawn. */
    private const BAND_MAX = 60;

    /**
     * @param array<string,mixed> $opts reserved for future presentation options
     * @return array{title:string, items:array<int,array<string,mixed>>, values:array<int,mixed>}
     *   title:  plain (uncoloured) masthead band — the shell colours it
     *   items:  structured rows for chooseFromList()
     *   values: parallel array of each row's opaque payload, by flat index
     */
    public static function compose(Directory $directory, TerminalRenderContext $ctx, array $opts = []): array
    {
        $cols = max(20, $ctx->cols());
        $utf8 = $ctx->effectiveCharset() === 'utf8';
        $glyphs = $ctx->lineDrawingChars();
        $hchar = $glyphs['h'] ?? '-';

        // --- Masthead band: "-- Crossroads --------------------" -------------
        $lead = str_repeat($hchar, 2) . ' ';
        $loc = trim($directory->location);
        $bandWidth = min(max(20, $cols - 2), self::BAND_MAX);
        $tailLen = max(3, $bandWidth - mb_strlen($lead, 'UTF-8') - mb_strlen($loc, 'UTF-8') - 1);
        $title = $ctx->encodeForTerminal($lead . $loc . ' ' . str_repeat($hchar, $tailLen));

        // --- Row-0 preamble: tagline ---------------------------------------
        $preamble = [];
        if ($directory->tagline !== null && trim($directory->tagline) !== '') {
            $preamble[] = $ctx->colorize(
                $ctx->encodeForTerminal(TextBlock::ellipsize(trim($directory->tagline), max(8, $cols - 4))),
                self::MUTED_SGR
            );
            $preamble[] = '';
        }

        // --- Ambient context block (recent activity, etc.) -----------------
        // Rendered above the first titled section; falls back to row 0 when no
        // section carries a heading.
        $context = [];
        foreach ($directory->contextLines as $line) {
            $line = (string) $line;
            $context[] = $line === ''
                ? ''
                : $ctx->colorize($ctx->encodeForTerminal(TextBlock::ellipsize($line, max(8, $cols - 4))), self::MUTED_SGR);
        }
        if ($context !== []) {
            $context[] = '';
        }

        $items = [];
        $values = [];
        $firstRowOverall = true;
        $contextPending = $context;

        // Detail column: one secondary line per destination, clipped so the
        // structured renderer never wraps a description into prose. Compact
        // sections fold the description onto the primary line instead.
        $detailWidth = max(24, $cols - 10);
        $inlineWidth = max(24, $cols - 8);

        foreach ($directory->sections as $section) {
            $sectionTitle = trim($section->title);
            $firstInSection = true;

            foreach ($section->rows as $row) {
                $desc = self::oneLine($row->description);

                if ($section->compact) {
                    $item = [
                        'label' => self::rowLabel($row, $ctx, $desc, $inlineWidth),
                        'detail' => '',
                    ];
                } else {
                    $item = [
                        'label' => self::rowLabel($row, $ctx, null, 0),
                        'detail' => $desc === '' ? '' : $ctx->encodeForTerminal(self::clipDescription($desc, $detailWidth)),
                    ];
                }

                $sectionBeforeLines = $firstRowOverall ? $preamble : [];

                if ($firstInSection && $sectionTitle !== '') {
                    if ($contextPending !== []) {
                        $sectionBeforeLines = array_merge($sectionBeforeLines, $contextPending);
                        $contextPending = [];
                    }
                    $item['section_before'] = $ctx->colorize(
                        $ctx->encodeForTerminal(mb_strtoupper($sectionTitle, 'UTF-8')),
                        self::HEADING_SGR
                    );
                }

                if ($sectionBeforeLines !== []) {
                    $item['section_before_lines'] = $sectionBeforeLines;
                }

                $items[] = $item;
                $values[] = $row->value;
                $firstRowOverall = false;
                $firstInSection = false;
            }
        }

        // Context block with no titled section after it (or no rows at all):
        // attach it to row 0 so it is never silently dropped.
        if ($contextPending !== [] && isset($items[0])) {
            $items[0]['section_before_lines'] = array_merge(
                $items[0]['section_before_lines'] ?? [],
                $contextPending
            );
        }

        return ['title' => $title, 'items' => $items, 'values' => $values];
    }

    /**
     * @param string|null $inlineDesc when non-empty, folded onto the label
     *                                 ("Label  ·  desc") and clipped to $width
     */
    private static function rowLabel(
        DirectoryRow $row,
        TerminalRenderContext $ctx,
        ?string $inlineDesc,
        int $width
    ): string {
        $utf8 = $ctx->effectiveCharset() === 'utf8';
        $label = trim($row->label);

        if ($row->badge !== null && trim($row->badge) !== '') {
            $label .= ($utf8 ? "  \u{00B7} " : '  - ') . trim($row->badge);
        }

        if ($inlineDesc !== null && $inlineDesc !== '') {
            $joined = $label . ($utf8 ? "  \u{00B7} " : '  - ') . $inlineDesc;
            $label = TextBlock::ellipsize($joined, $width);
        }

        return $ctx->encodeForTerminal($label);
    }

    /** Collapse a possibly multi-line description to a single trimmed line. */
    private static function oneLine(?string $s): string
    {
        if ($s === null) {
            return '';
        }

        return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    }

    /**
     * Compact a one-line description to at most $width visible cells for the
     * directory's secondary row. Presentation only — the full text still lives
     * on detail/web surfaces. Prefers, in order: the whole string if it fits;
     * its first sentence if that fits; otherwise a word-boundary clip with an
     * ellipsis (never mid-word, never a hard substring).
     */
    private static function clipDescription(string $s, int $width): string
    {
        if (mb_strlen($s, 'UTF-8') <= $width) {
            return $s;
        }

        if (preg_match('/^(.{16,}?[.!?])(?:\s|$)/u', $s, $m) && mb_strlen($m[1], 'UTF-8') <= $width) {
            return $m[1];
        }

        $cut = mb_substr($s, 0, $width - 1, 'UTF-8');
        $lastSpace = mb_strrpos($cut, ' ', 0, 'UTF-8');
        if ($lastSpace !== false && $lastSpace >= (int) ($width * 0.5)) {
            $cut = mb_substr($cut, 0, $lastSpace, 'UTF-8');
        }

        return rtrim($cut) . "\u{2026}";
    }
}
