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
 *
 * It is also the content composer for {@see ThemedNavigationRenderer}: that
 * decorator asks {@see composeRegions()} for the MENU and FOOTER blocks and
 * positions them inside a trusted template. All layout, grouping, clipping,
 * glyph and colour logic stays here — the themed renderer owns presentation
 * placement only.
 */
final class NavigationScreenRenderer implements NavigationRenderer
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

        // One screen, one write: paint the whole frame atomically rather than
        // line by line (which can tear on a high-latency link).
        $ctx->beginFrame();
        try {
            $ctx->write($clear ? "\033[2J\033[H" : "\033[H");

            foreach ($this->composeLines($ctx, $screen, $cols, $rows, $showHotkeys, $cursor) as $line) {
                $ctx->writeLine($this->clip($line, $cols));
            }
        } finally {
            $ctx->endFrame();
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

    /** Themed directory row — where the description column starts (0-based). */
    private const DIR_DESC_COL = 19;

    /** Themed directory row — width reserved for a right-aligned live badge. */
    private const DIR_BADGE_W = 10;

    /**
     * Compose the MENU and FOOTER blocks for a themed layout.
     *
     * The themed layout is a *directory*, not a vertical application menu: each
     * destination is one full-width row — hotkey, an uppercase name, its
     * purpose in a description column, and any live badge right-aligned — under
     * a short section sign. The selected row is a full-width bar. Identity and
     * the "place" framing come from the template around it, so this drops the
     * flowing renderer's title band and roaming status line.
     *
     * Returns two fixed-size blocks — exactly `$menuHeight` x `$menuWidth` and
     * `$footerHeight` x `$footerWidth` visible cells, every cell painted, each
     * line reset-prefixed so it does not inherit SGR from the template beneath.
     *
     * @param array<string,mixed> $opts 'show_hotkeys' (bool), 'cursor' (int|null)
     * @return array{menu:array<int,string>,footer:array<int,string>}
     */
    public function composeRegions(
        TerminalRenderContext $ctx,
        NavigationScreenModel $screen,
        int $menuWidth,
        int $menuHeight,
        int $footerWidth,
        int $footerHeight,
        array $opts = []
    ): array {
        $showHotkeys = $opts['show_hotkeys'] ?? true;
        $cursor      = $opts['cursor'] ?? null;

        $menuWidth    = max(8, $menuWidth);
        $menuHeight   = max(1, $menuHeight);
        $footerWidth  = max(8, $footerWidth);
        $footerHeight = max(1, $footerHeight);

        $glyphs = $ctx->lineDrawingChars();

        // --- MENU block: crumb (submenus only) + directory --------------------
        $lines = [];
        if (!$screen->path->isRoot()) {
            $lines[] = $ctx->colorize(
                $ctx->encodeForTerminal($screen->path->crumb(' ' . ($ctx->effectiveCharset() === 'utf8' ? "\u{203A}" : '>') . ' ')),
                self::EMPHASIS_COLOR['muted']
            );
            $lines[] = '';
        }
        foreach ($this->directoryBlock($ctx, $screen, $menuWidth, $showHotkeys, $cursor) as $line) {
            $lines[] = $line;
        }

        if (count($lines) > $menuHeight) {
            $lines   = array_slice($lines, 0, max(1, $menuHeight - 1));
            $lines[] = $ctx->colorize(
                $ctx->encodeForTerminal($this->moreMarker($glyphs)),
                self::EMPHASIS_COLOR['muted']
            );
        }

        $menu = $this->fitBlock($lines, $menuWidth, $menuHeight);

        // Root caller context uses the existing ambient slot. Elsewhere retain
        // badge context; key hints keep their own line. No queries here.
        $hints   = $ctx->colorize($ctx->encodeForTerminal($this->footerHints($screen)), self::EMPHASIS_COLOR['muted']);
        $ambient = $screen->ambient ?? $this->ambientActivityLine($screen);

        if ($footerHeight >= 2) {
            $footerLines = [
                $ambient !== ''
                    ? $ctx->colorize($ctx->encodeForTerminal($ambient), self::EMPHASIS_COLOR['muted'])
                    : '',
                $hints,
            ];
        } else {
            $footerLines = [$hints];
        }

        $footer = $this->fitBlock($footerLines, $footerWidth, $footerHeight);

        return ['menu' => $menu, 'footer' => $footer];
    }

    /**
     * Schema-2 semantic content, composed only from the resolved view model.
     * Navigation and key hints must fit in full; informational text may clip.
     * Returns terminal-encoded blocks keyed by semantic region name.
     *
     * @return array<string,array<int,string>>
     */
    public function composeSemanticRegions(
        TerminalRenderContext $ctx,
        NavigationScreenModel $screen,
        NavigationThemeGeometry $geo,
        array $opts = []
    ): array {
        $menu = $geo->menu();
        $cursor = $opts['cursor'] ?? 0;
        $menuLines = $this->directoryBlock($ctx, $screen, $menu->width, $opts['show_hotkeys'] ?? true, $cursor, true);
        if (!$screen->path->isRoot()) {
            array_unshift($menuLines, $ctx->encodeForTerminal($screen->path->crumb(' > ')), '');
        }

        $selected = $screen->selectableItems()[$cursor] ?? null;
        $description = [
            $selected?->label ?? $screen->title,
            $selected?->description ?? $screen->description ?? '',
        ];
        $status = [$screen->ambient ?? '', trim($this->ambientActivityLine($screen))];
        $plainBlocks = [
            'DESCRIPTION' => $description,
            'STATUS' => $status,
            'FOOTER' => [$this->footerHints($screen)],
        ];
        $blocks = ['MENU' => $this->fitSemanticBlock($ctx, $menuLines, $menu, true)];
        foreach ($plainBlocks as $name => $lines) {
            // Model text is data, never a source of terminal control sequences.
            $encoded = array_map(function (string $line) use ($ctx): string {
                $line = preg_replace('/[\x00-\x1f\x7f-\x9f]/u', ' ', $line) ?? '';
                return $ctx->encodeForTerminal($line);
            }, $lines);
            $region = $geo->region($name);
            if ($region === null) {
                throw new \UnexpectedValueException("Missing {$name} region");
            }
            $blocks[$name] = $this->fitSemanticBlock($ctx, $encoded, $region, $name === 'FOOTER');
        }
        return $blocks;
    }

    /** Shared semantic-region fitter; content is encoded for the supplied context. */
    public function fitSemanticBlock(
        TerminalRenderContext $ctx,
        array $lines,
        NavigationThemeRegion $region,
        bool $mustFit
    ): array {
        $utf8 = array_map(static function (string $line) use ($ctx): string {
            if ($ctx->effectiveCharset() === 'cp437') {
                $line = iconv('CP437', 'UTF-8', $line) ?: '';
            }
            // Keep composer SGR, but never allow model strings to position the
            // terminal or introduce additional display rows inside a region.
            return str_replace(["\n", "\t"], ' ', TemplateArtSanitizer::sanitize($line, 'utf8'));
        }, $lines);
        if ($mustFit) {
            if (count($utf8) > $region->height) {
                throw new \LengthException("{$region->name} cannot fit its content height");
            }
            foreach ($utf8 as $line) {
                $plain = preg_replace('/\033\[[0-9;]*m/', '', $line) ?? $line;
                if (mb_strlen($plain, 'UTF-8') > $region->width) {
                    throw new \LengthException("{$region->name} cannot fit its content width");
                }
            }
        }
        return array_map(fn (string $line): string => $ctx->encodeForTerminal($line),
            $this->fitBlock($utf8, $region->width, $region->height));
    }

    /**
     * The themed directory: sectioned, one row per destination.
     *
     * @return array<int,string>
     */
    private function directoryBlock(
        TerminalRenderContext $ctx,
        NavigationScreenModel $screen,
        int $width,
        bool $showHotkeys,
        ?int $cursor,
        bool $compact = false
    ): array {
        $utf8   = $ctx->effectiveCharset() === 'utf8';
        $subGlyph = $utf8 ? "\u{203A}" : '>';

        $selectableIndex = [];
        foreach ($screen->selectableItems() as $i => $it) {
            $selectableIndex[$it->id] = $i;
        }

        // Group order (first-seen); ungrouped items fall to a trailing block.
        $groups = [];
        foreach ($screen->items as $it) {
            $g = ($it->group !== null && trim($it->group) !== '') ? $it->group : '';
            if (!in_array($g, $groups, true)) {
                $groups[] = $g;
            }
        }

        $descCol = min(self::DIR_DESC_COL, max(10, $width - 24));
        $badgeW  = self::DIR_BADGE_W;
        $descW   = max(6, $width - $descCol - $badgeW - 1);

        $out   = [];
        $first = true;
        foreach ($groups as $group) {
            $members = array_values(array_filter(
                $screen->items,
                static fn (NavigationScreenItem $it) => (($it->group !== null && trim($it->group) !== '') ? $it->group : '') === $group
            ));
            if ($members === []) {
                continue;
            }

            if (!$first) {
                $out[] = '';
            }
            $first = false;

            if ($group !== '') {
                $out[] = $ctx->colorize($ctx->encodeForTerminal(mb_strtoupper($group)), self::SECTION_HEADING)
                    . ' ' . $ctx->colorize($ctx->encodeForTerminal(str_repeat($utf8 ? "\u{2500}" : '-', 6)), self::EMPHASIS_COLOR['muted']);
            }

            foreach ($members as $it) {
                $out[] = $this->directoryRow(
                    $ctx,
                    $it,
                    $width,
                    $descCol,
                    $descW,
                    $badgeW,
                    $showHotkeys,
                    $subGlyph,
                    $cursor !== null && isset($selectableIndex[$it->id]) && $selectableIndex[$it->id] === $cursor,
                    $compact
                );
            }
        }

        return $out;
    }

    private function directoryRow(
        TerminalRenderContext $ctx,
        NavigationScreenItem $it,
        int $width,
        int $descCol,
        int $descW,
        int $badgeW,
        bool $showHotkeys,
        string $subGlyph,
        bool $isCursor,
        bool $compact = false
    ): string {
        $key = '';
        if ($showHotkeys && $it->hotkey !== null) {
            $key = '[' . mb_strtoupper($it->hotkey) . '] ';
        } elseif ($showHotkeys) {
            $key = '    ';
        }

        $name = mb_strtoupper($it->label);
        if ($it->isSubmenu()) {
            $name .= ' ' . $subGlyph;
        }
        if (!$it->isSelectable()) {
            $name .= ' (' . ($it->disabledReason ?? 'unavailable') . ')';
        }

        if ($compact) {
            $text = '  ' . $key . $name;
            if (mb_strlen($text, 'UTF-8') > $width) {
                throw new \LengthException('MENU cannot fit a navigation label');
            }
            return $ctx->colorize(
                $ctx->encodeForTerminal($this->padVisible($text, $width)),
                $isCursor ? "\033[7m" : (self::EMPHASIS_COLOR[$it->isSelectable() ? $it->emphasis : 'muted'] ?? '')
            );
        }

        // Name segment: exactly $descCol visible cells.
        $nameSeg = $this->padVisible('  ' . $key . $name, $descCol);

        // Tail segment: description + gap + right-aligned badge, exactly
        // ($width - $descCol) visible cells.
        $desc  = $this->clipVisible($it->description !== null ? trim($it->description) : '', $descW);
        $badge = ($it->isSelectable() && $it->annotation !== null && trim($it->annotation) !== '')
            ? $this->clipVisible(trim($it->annotation), $badgeW)
            : '';
        $badgePad = str_repeat(' ', max(0, $badgeW - mb_strlen($badge, 'UTF-8')));
        $tailSeg  = $this->padVisible($desc, $descW) . ' ' . $badgePad . $badge;
        $tailSeg  = $this->padVisible($tailSeg, $width - $descCol);

        if ($isCursor) {
            return $ctx->colorize($ctx->encodeForTerminal($nameSeg . $tailSeg), "\033[7m");
        }

        $emph = self::EMPHASIS_COLOR[$it->isSelectable() ? $it->emphasis : 'muted'] ?? '';
        $encName = $ctx->encodeForTerminal($nameSeg);

        return ($emph !== '' ? $ctx->colorize($encName, $emph) : $encName)
            . $ctx->colorize($ctx->encodeForTerminal($tailSeg), self::EMPHASIS_COLOR['muted']);
    }

    /**
     * "3 people online · 2 in experiences" — assembled only from badge
     * annotations already on the screen model, so it costs nothing. Empty when
     * the front door is quiet.
     */
    private function ambientActivityLine(NavigationScreenModel $screen): string
    {
        $parts = [];
        foreach ($screen->items as $it) {
            if ($it->annotation !== null && trim($it->annotation) !== '') {
                $parts[] = trim($it->label) . ' ' . trim($it->annotation);
            }
        }
        if ($parts === []) {
            return '';
        }

        return '  ' . implode('   ' . "\u{00B7}" . '   ', $parts);
    }

    private function clipVisible(string $s, int $width): string
    {
        // Extracted verbatim to Terminal\Presentation\TextBlock so this renderer
        // and DirectoryView truncate identically. Output is unchanged.
        return \BinktermPHP\Terminal\Presentation\TextBlock::ellipsize($s, $width);
    }

    private function padVisible(string $s, int $width): string
    {
        return \BinktermPHP\Terminal\Presentation\TextBlock::padRight($s, $width);
    }

    /**
     * Force $lines into exactly $height rows of exactly $width visible cells:
     * clip over-wide lines, right-pad short ones with spaces, pad the block with
     * blank rows. Each row is prefixed with a reset so it cannot inherit SGR
     * from whatever the themed renderer painted underneath.
     *
     * @param array<int,string> $lines
     * @return array<int,string>
     */
    private function fitBlock(array $lines, int $width, int $height): array
    {
        $out = [];
        for ($i = 0; $i < $height; $i++) {
            $line    = $lines[$i] ?? '';
            $clipped = $this->clip($line, $width);
            $visible = mb_strlen(preg_replace('/\033\[[0-9;]*m/', '', $clipped) ?? $clipped, 'UTF-8');
            $padding = str_repeat(' ', max(0, $width - $visible));
            $out[]   = "\033[0m" . $clipped . "\033[0m" . $padding;
        }

        return $out;
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

        $out[] = $screen->ambient !== null
            ? $pad . $ctx->colorize($ctx->encodeForTerminal($this->clipVisible($screen->ambient, $width)), self::EMPHASIS_COLOR['muted'])
            : '';

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
