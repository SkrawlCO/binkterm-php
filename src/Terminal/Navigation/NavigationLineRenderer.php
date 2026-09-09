<?php

namespace BinktermPHP\Terminal\Navigation;

/**
 * Plain-text projection of a {@see NavigationScreenModel} for the low-capability
 * line shell: a numbered list plus a hotkey->index map, suitable for handing to
 * the existing `chooseFromList()` widget. No ANSI, no geometry maths, works on
 * any width.
 */
final class NavigationLineRenderer
{
    /**
     * @return array{
     *   title:string,
     *   items:array<int,string>,
     *   key_to_index:array<string,int>,
     *   selectable:array<int,NavigationScreenItem>,
     *   context:string
     * }
     */
    public function project(NavigationScreenModel $screen): array
    {
        $items       = [];
        $keyToIndex  = [];
        $selectable  = [];

        foreach ($screen->items as $item) {
            if (!$item->isSelectable()) {
                continue; // the line shell simply omits unavailable options
            }
            $index = count($selectable);
            $selectable[] = $item;

            $prefix = $item->hotkey !== null ? '[' . mb_strtoupper($item->hotkey) . '] ' : '';
            $suffix = $item->isSubmenu() ? ' >' : '';
            $items[] = $prefix . $item->label . $suffix;

            if ($item->hotkey !== null) {
                $keyToIndex[mb_strtolower($item->hotkey)] = $index;
            }
        }

        return [
            'title'        => $screen->title,
            'items'        => $items,
            'key_to_index' => $keyToIndex,
            'selectable'   => $selectable,
            'context'      => $screen->path->isRoot() ? '' : $screen->path->crumb(' > '),
        ];
    }
}
