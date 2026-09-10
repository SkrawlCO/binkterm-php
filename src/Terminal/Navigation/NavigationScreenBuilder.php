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
     * @param (callable(string):?string)|null $badgeResolver resolves a `presentation.badge`
     *        signal name to a short live-status string (e.g. "3 online"), or null when the
     *        signal is unknown / has nothing worth showing. Only ever called for items that
     *        have already passed every access and availability gate, so it can never be used
     *        to probe a hidden item. Off-session callers (preview, tests) pass null.
     * @param (callable(string):?string)|null $ambientResolver authenticated root-only
     *        ambient text, receiving the resolved locale. No input/navigation behavior.
     * @param (callable(string,string):?array<int,string>)|null $summaryResolver
     *        per-node authored semantic STATUS/summary: it receives the node id
     *        and the resolved locale and returns already-translated plain-text
     *        lines (a pure projection of existing authoritative state), or null
     *        when the node has no authored summary. Unlike the ambient resolver
     *        it is not root-gated — an authored non-root node (e.g. Messages) is
     *        exactly its purpose. No input/navigation behaviour; performs no
     *        writes. Off-session callers (preview, tests) pass null.
     */
    public function __construct(
        private readonly ActionRegistry $actions,
        private readonly mixed $translate,
        private readonly mixed $badgeResolver = null,
        private readonly mixed $ambientResolver = null,
        private readonly mixed $summaryResolver = null,
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
            ambient: $path->isRoot() && $ctx->isAuthenticated() && is_callable($this->ambientResolver)
                ? ($this->ambientResolver)($locale) : null,
            summary: is_callable($this->summaryResolver)
                ? $this->normalizeSummary(($this->summaryResolver)($node->id, $locale)) : null,
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
            group: $item->presentation->group,
            annotation: $this->resolveBadge($item->presentation->badge, $enabled),
        );
    }

    /**
     * Resolve a `presentation.badge` signal to its live-status string. Only
     * reached for an item that has already passed every access / availability
     * gate. A disabled-but-visible item keeps its badge suppressed — a live
     * count next to an option the caller cannot take just adds noise.
     */
    private function resolveBadge(?string $signal, bool $enabled): ?string
    {
        if ($signal === null || $signal === '' || !$enabled || !is_callable($this->badgeResolver)) {
            return null;
        }

        $text = ($this->badgeResolver)($signal);
        $text = is_string($text) ? trim($text) : '';

        return $text !== '' ? $text : null;
    }

    /**
     * Coerce a summary resolver's result to a clean list of plain-text lines,
     * or null. Anything that is not a non-empty list of strings becomes null so
     * a malformed resolver simply falls back to the ambient/activity STATUS.
     *
     * @param mixed $lines
     * @return array<int,string>|null
     */
    private function normalizeSummary(mixed $lines): ?array
    {
        if (!is_array($lines) || $lines === [] || !array_is_list($lines)) {
            return null;
        }
        $clean = [];
        foreach ($lines as $line) {
            if (!is_string($line)) {
                return null;
            }
            $clean[] = $line;
        }

        return $clean;
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
