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

        foreach ($directory->sections as $section) {
            $sectionTitle = trim($section->title);
            $firstInSection = true;

            foreach ($section->rows as $row) {
                $item = [
                    'label' => self::rowLabel($row, $ctx),
                    'detail' => $row->description !== null ? trim($row->description) : '',
                ];

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

    private static function rowLabel(DirectoryRow $row, TerminalRenderContext $ctx): string
    {
        $label = trim($row->label);
        if ($row->badge !== null && trim($row->badge) !== '') {
            $sep = $ctx->effectiveCharset() === 'utf8' ? "  \u{00B7} " : '  - ';
            $label .= $sep . trim($row->badge);
        }

        return $ctx->encodeForTerminal($label);
    }
}
