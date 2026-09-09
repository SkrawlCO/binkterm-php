<?php

namespace BinktermPHP\Terminal\Navigation;

/**
 * Turns a {@see NavigationNode} plus an {@see AccessContext} into a resolved
 * {@see NavigationScreenModel}: labels translated, access decided, disabled
 * state computed, orientation filled in.
 *
 * Access-hidden items are dropped entirely (their hotkeys go with them, so
 * nothing dangles). Items whose `enabled` is false, or whose bound action is
 * unavailable, are kept but marked non-selectable so the caller can see the
 * option exists.
 */
final class NavigationScreenBuilder
{
    /**
     * @param callable(?string,string,string):string $translate (key, fallback, locale) -> text
     */
    public function __construct(
        private readonly ActionRegistry $actions,
        private readonly mixed $translate,
    ) {
    }

    public function build(
        NavigationDefinition $definition,
        AccessContext $ctx,
        NavigationPath $path,
        string $locale = 'en'
    ): NavigationScreenModel {
        $node  = $definition->node($path->currentId());
        $items = [];

        foreach ($node->items as $item) {
            $screenItem = $this->resolveItem($definition, $item, $ctx, $locale);
            if ($screenItem !== null) {
                $items[] = $screenItem;
            }
        }

        return new NavigationScreenModel(
            nodeId: $node->id,
            title: $this->text($node->labelKey, $node->labelFallback, $locale),
            description: $node->descriptionFallback !== null
                ? $this->text($node->descriptionKey, $node->descriptionFallback, $locale)
                : null,
            items: $items,
            path: $path,
            backAvailable: !$path->isRoot(),
            homeAvailable: $path->depth() > 2,
            presentation: $node->presentation,
        );
    }

    private function resolveItem(
        NavigationDefinition $definition,
        NavigationItem $item,
        AccessContext $ctx,
        string $locale
    ): ?NavigationScreenItem {
        // Access gate — hidden items are simply absent.
        if (!$item->access->evaluate($ctx)) {
            return null;
        }

        $enabled        = $item->enabled;
        $disabledReason = $enabled ? null : 'disabled';

        if ($item->isAction()) {
            $actionId = $item->action->actionId;
            if (!$this->actions->has($actionId)) {
                // A definition that passed validation should never hit this, but
                // fail safe: hide rather than render a broken row.
                return null;
            }
            if (!$this->actions->isAvailable($actionId, $ctx)) {
                return null;
            }
            $kind   = NavigationScreenItem::KIND_ACTION;
            $target = null;
            $action = $item->action;
        } else {
            if (!$definition->hasNode($item->submenu)) {
                return null;
            }
            $targetNode = $definition->node($item->submenu);
            if (!$targetNode->access->evaluate($ctx)) {
                return null;
            }
            $kind   = NavigationScreenItem::KIND_SUBMENU;
            $target = $item->submenu;
            $action = null;
        }

        return new NavigationScreenItem(
            id: $item->id,
            label: $this->text($item->labelKey, $item->labelFallback, $locale),
            description: $item->descriptionFallback !== null
                ? $this->text($item->descriptionKey, $item->descriptionFallback, $locale)
                : null,
            hotkey: $item->hotkey,
            emphasis: $item->presentation->emphasis,
            enabled: $enabled,
            disabledReason: $disabledReason,
            kind: $kind,
            targetNodeId: $target,
            action: $action,
            glyph: $item->presentation->glyph,
        );
    }

    /** Resolve a label the same way item/node titles are resolved. */
    public function translateLabel(?string $key, string $fallback, string $locale = 'en'): string
    {
        return $this->text($key, $fallback, $locale);
    }

    private function text(?string $key, string $fallback, string $locale): string
    {
        return ($this->translate)($key, $fallback, $locale);
    }
}
