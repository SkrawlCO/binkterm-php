<?php

namespace BinktermPHP\Terminal\Presentation;

use BinktermPHP\TelnetServer\TerminalRenderContext;
use BinktermPHP\Terminal\Navigation\NavigationScreenRenderer;
use BinktermPHP\Terminal\Navigation\NavigationTheme;
use BinktermPHP\Terminal\Navigation\NavigationThemeGeometry;
use BinktermPHP\Terminal\Navigation\ThemedNavigationRenderer;

/**
 * Presentation bridge from a {@see DenseList} to the shared M2 region painter —
 * the dense-list sibling of {@see ThemedDirectoryView}.
 *
 * The structured selectable-list runtime
 * ({@see \BinktermPHP\TelnetServer\TelnetUtils::runSelectableList()}) still owns
 * the whole page's row array, the selected index, paging, shortcuts, resize and
 * dispatch; its optional `frame_renderer` calls {@see tryRender()}, which either
 * paints an authored frame (returns true) or yields to the existing dense-list
 * renderer (returns false). It reads only resolved presentation data — no
 * queries, no read-state, no watermark writes.
 *
 * Unlike a Directory, density is the point here: the MENU region shows a
 * selection-following window of the grid (via {@see DenseListView::viewport()}),
 * DESCRIPTION is a single compact line for the selected row, STATUS is a small
 * block of already-authorised application text, FOOTER is the shell's real key
 * hints plus the visible range.
 */
final class ThemedDenseListView
{
    private ThemedNavigationRenderer $painter;
    private NavigationScreenRenderer $composer;

    /**
     * @param list<string>          $statusLines already-authorised application text for STATUS
     * @param callable(DenseListRow):string|null $describe selected-row detail line;
     *        defaults to "<first cell>  -  <flexible cell>"
     */
    public function __construct(
        private readonly DenseList $list,
        NavigationTheme $theme,
        private readonly array $statusLines = [],
        private readonly mixed $describe = null,
    ) {
        $this->composer = new NavigationScreenRenderer();
        $this->painter = new ThemedNavigationRenderer($this->composer, $theme);
    }

    /** False leaves the existing dense-list renderer in charge of this frame. */
    public function tryRender(TerminalRenderContext $ctx, int $selectedIndex, array $statusBar): bool
    {
        return $this->painter->tryRenderRegions(
            $ctx,
            fn (NavigationThemeGeometry $geo): array => $this->compose($ctx, $geo, $selectedIndex, $statusBar)
        );
    }

    /** @return array{mode:string,reason:?string,geometry:?string} */
    public function lastReport(): array
    {
        return $this->painter->lastReport();
    }

    /** @return array<string,list<string>> terminal-encoded fitted M2 blocks */
    private function compose(TerminalRenderContext $ctx, NavigationThemeGeometry $geo, int $selectedIndex, array $statusBar): array
    {
        $menu = $geo->menu();
        $description = $geo->region('DESCRIPTION');
        $status = $geo->region('STATUS');
        if ($description === null || $status === null) {
            throw new \UnexpectedValueException('Dense-list composition requires M2 regions');
        }

        $total = count($this->list->rows);
        $selectedIndex = $total > 0 ? max(0, min($selectedIndex, $total - 1)) : 0;

        // MENU: a selection-following window of the real grid. Row indices,
        // numbers and payloads are untouched — presentation only.
        $window = DenseListView::viewport($this->list, $ctx, $menu->width, $menu->height, $selectedIndex);
        if ($window === [] && $total > 0) {
            throw new \LengthException('MENU cannot fit a grid row');
        }

        // DESCRIPTION: one compact line for the selected row.
        $detailText = '';
        if (isset($this->list->rows[$selectedIndex])) {
            $row = $this->list->rows[$selectedIndex];
            $detailText = is_callable($this->describe)
                ? (string) ($this->describe)($row)
                : $this->defaultDescribe($row);
        }
        $detail = [$this->plain($detailText)];

        // FOOTER: the shell's real hint string plus the visible range.
        $first = $total > 0 ? DenseListView::windowStart($total, $menu->height, $selectedIndex) : 0;
        $last  = $total > 0 ? min($total - 1, $first + count($window) - 1) : -1;
        $hints = '';
        foreach ($statusBar as $segment) {
            $hints .= (string) ($segment['text'] ?? '');
        }
        if ($total > 0) {
            $hints = trim($hints) . sprintf('  [%d-%d/%d]', $first + 1, $last + 1, $total);
        } else {
            $hints = trim($hints);
        }

        $blocks = ['MENU' => $this->composer->fitSemanticBlock($ctx, $window, $menu, true)];
        foreach (['DESCRIPTION' => $detail, 'STATUS' => array_values($this->statusLines), 'FOOTER' => [$hints]] as $name => $lines) {
            $region = $geo->region($name);
            if ($region === null) {
                throw new \UnexpectedValueException("Missing {$name} region");
            }
            $encoded = array_map(fn (string $line): string => $ctx->encodeForTerminal(
                $name === 'FOOTER' ? $this->plain($line) : TextBlock::ellipsize($this->plain($line), $region->width)
            ), $lines);
            $blocks[$name] = $this->composer->fitSemanticBlock($ctx, $encoded, $region, $name === 'FOOTER');
        }

        return $blocks;
    }

    private function defaultDescribe(DenseListRow $row): string
    {
        $cells = array_values($row->cells);
        $lead = trim((string) ($cells[0] ?? ''));
        $tail = trim((string) end($cells));
        if ($lead !== '' && $tail !== '' && $tail !== $lead) {
            return $lead . '  -  ' . $tail;
        }

        return $lead !== '' ? $lead : $tail;
    }

    /** Model content cannot supply ANSI or line controls. */
    private function plain(string $text): string
    {
        return trim(preg_replace('/[\x00-\x1f\x7f-\x9f]/u', ' ', $text) ?? '');
    }
}
