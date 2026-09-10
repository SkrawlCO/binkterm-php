<?php

namespace BinktermPHP\Terminal\Presentation;

use BinktermPHP\TelnetServer\TerminalRenderContext;
use BinktermPHP\TelnetServer\TelnetUtils;
use BinktermPHP\Terminal\Navigation\NavigationScreenRenderer;
use BinktermPHP\Terminal\Navigation\NavigationTheme;
use BinktermPHP\Terminal\Navigation\NavigationThemeGeometry;
use BinktermPHP\Terminal\Navigation\ThemedNavigationRenderer;

/**
 * Presentation bridge from Directory to the existing M2 region painter.
 * The structured-list runtime still owns all input, row indices and dispatch.
 * This view uses only resolved directory text and a bounded status snapshot.
 */
final class ThemedDirectoryView
{
    private ThemedNavigationRenderer $painter;
    private NavigationScreenRenderer $composer;

    /** @param list<string> $statusLines already-authorized application text */
    public function __construct(
        private readonly Directory $directory,
        NavigationTheme $theme,
        private readonly array $statusLines = [],
    ) {
        $this->composer = new NavigationScreenRenderer();
        $this->painter = new ThemedNavigationRenderer($this->composer, $theme);
    }

    /** False leaves the existing directory renderer in charge of this frame. */
    public function tryRender(TerminalRenderContext $ctx, int $selectedIndex, array $statusBar): bool
    {
        return $this->painter->tryRenderRegions($ctx, fn (NavigationThemeGeometry $geo): array =>
            $this->compose($ctx, $geo, $selectedIndex, $statusBar));
    }

    public function lastReport(): array
    {
        return $this->painter->lastReport();
    }

    /** @return array<string,list<string>> terminal-encoded fitted M2 blocks */
    private function compose(TerminalRenderContext $ctx, NavigationThemeGeometry $geo, int $selectedIndex, array $statusBar): array
    {
        $rows = [];
        foreach ($this->directory->sections as $section) {
            foreach ($section->rows as $row) {
                $rows[] = ['row' => $row, 'group' => $section->title];
            }
        }
        if (!isset($rows[$selectedIndex])) {
            throw new \LengthException('No selected directory row');
        }
        $menu = $geo->menu();
        $description = $geo->region('DESCRIPTION');
        $status = $geo->region('STATUS');
        if ($description === null || $status === null) {
            throw new \UnexpectedValueException('Directory composition requires M2 regions');
        }

        // A presentation window over the FULL existing row list. Repeat its
        // category heading at the top when a window starts inside a section.
        // No filtering, reordering, renumbering, or separate pagination input.
        $window = [];
        $last = -1;
        for ($first = 0; $first <= $selectedIndex; $first++) {
            $window = [];
            $lastGroup = null;
            $last = $first - 1;
            for ($i = $first; $i < count($rows); $i++) {
                $group = $rows[$i]['group'];
                $heading = $group !== '' && $group !== $lastGroup;
                if (count($window) + ($heading ? 2 : 1) > $menu->height) {
                    break;
                }
                if ($heading) {
                    $window[] = $ctx->colorize($ctx->encodeForTerminal(mb_strtoupper(self::plain($group))), "\033[1;36m");
                }
                $label = sprintf('%2d) %s', $i + 1, self::plain($rows[$i]['row']->label));
                $window[] = $ctx->colorize($ctx->encodeForTerminal(TextBlock::padRight($label, $menu->width)), $i === $selectedIndex ? "\033[7m" : '');
                $lastGroup = $group;
                $last = $i;
            }
            if ($last >= $selectedIndex) {
                break;
            }
        }
        if ($last < $selectedIndex) {
            throw new \LengthException('MENU cannot fit the selected destination and category');
        }

        $selected = $rows[$selectedIndex]['row'];
        $detail = TelnetUtils::wrapTextLines(self::plain($selected->label), $description->width);
        if ($selected->badge !== null && $selected->badge !== '') {
            $detail = array_merge($detail, TelnetUtils::wrapTextLines(self::plain($selected->badge), $description->width));
        }
        $detail[] = '';
        $detail = array_merge($detail, TelnetUtils::wrapTextLines(self::plain($selected->description ?? ''), $description->width));
        if (count($detail) > $description->height) {
            $detail = array_slice($detail, 0, $description->height);
            $detail[count($detail) - 1] = TextBlock::ellipsize(rtrim(end($detail)) . ' ...', $description->width);
        }

        // Preserve the shell's actual key hints; append only the visible range.
        $hints = '';
        foreach ($statusBar as $segment) {
            $hints .= (string)($segment['text'] ?? '');
        }
        $hints = trim($hints) . sprintf('  [%d-%d/%d]', $first + 1, $last + 1, count($rows));
        $blocks = ['MENU' => $this->composer->fitSemanticBlock($ctx, $window, $menu, true)];
        foreach (['DESCRIPTION' => $detail, 'STATUS' => $this->statusLines, 'FOOTER' => [$hints]] as $name => $lines) {
            $region = $geo->region($name);
            if ($region === null) {
                throw new \UnexpectedValueException("Missing {$name} region");
            }
            $encoded = array_map(fn (string $line): string => $ctx->encodeForTerminal(
                $name === 'FOOTER' ? self::plain($line) : TextBlock::ellipsize(self::plain($line), $region->width)
            ), $lines);
            $blocks[$name] = $this->composer->fitSemanticBlock($ctx, $encoded, $region, $name === 'FOOTER');
        }
        return $blocks;
    }

    /** Model content cannot supply ANSI or line controls. */
    private static function plain(string $text): string
    {
        return trim(preg_replace('/[\x00-\x1f\x7f-\x9f]/u', ' ', $text) ?? '');
    }
}
