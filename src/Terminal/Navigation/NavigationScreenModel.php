<?php

namespace BinktermPHP\Terminal\Navigation;

/**
 * The intermediate model between a declarative definition and the terminal
 * renderer: one resolved screen. It knows nothing about sockets, sessions, or
 * services — it is constructible in tests and in the preview service, and holds
 * only resolved presentation information.
 *
 *   definition -> validation -> access/action resolution -> THIS -> renderer
 *   -> TerminalRenderContext -> OutputSink
 */
final class NavigationScreenModel
{
    /**
     * @param array<int,NavigationScreenItem> $items visible items, in order
     */
    public function __construct(
        public readonly string $nodeId,
        public readonly string $title,
        public readonly ?string $description,
        public readonly array $items,
        public readonly NavigationPath $path,
        public readonly bool $backAvailable,
        public readonly bool $homeAvailable,
        public readonly PresentationHints $presentation,
        public readonly ?string $ambient = null,
    ) {
    }

    /** @return array<int,NavigationScreenItem> */
    public function selectableItems(): array
    {
        return array_values(array_filter($this->items, static fn (NavigationScreenItem $i) => $i->isSelectable()));
    }

    /** Resolve a typed hotkey (case-insensitive) to its item, if selectable. */
    public function itemForHotkey(string $key): ?NavigationScreenItem
    {
        $key = mb_strtolower($key);
        foreach ($this->items as $item) {
            if ($item->hotkey !== null && mb_strtolower($item->hotkey) === $key && $item->isSelectable()) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Whether any item on this screen declares $key as its hotkey (whether or
     * not that item is currently selectable). Used to suppress the framework's
     * convenience navigation aliases (B = Back, H = Home) when the letter is
     * already an item's key — an explicit binding always wins.
     */
    public function bindsHotkey(string $key): bool
    {
        $key = mb_strtolower($key);
        foreach ($this->items as $item) {
            if ($item->hotkey !== null && mb_strtolower($item->hotkey) === $key) {
                return true;
            }
        }

        return false;
    }

    public function itemById(string $id): ?NavigationScreenItem
    {
        foreach ($this->items as $item) {
            if ($item->id === $id) {
                return $item;
            }
        }

        return null;
    }
}
