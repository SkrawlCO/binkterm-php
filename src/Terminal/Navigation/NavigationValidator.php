<?php

namespace BinktermPHP\Terminal\Navigation;

/**
 * Semantic validation of a parsed {@see NavigationDefinition}: everything that
 * is structurally legal JSON but still wrong — unknown action ids, hotkey
 * clashes within a node, dangling submenu references, cycles, unreachable
 * nodes, and so on.
 *
 * All problems are collected (not thrown) so a sysop sees every issue at once.
 * The runtime never renders an invalid definition — {@see NavigationDefinitionLoader}
 * falls back to legacy behaviour when this reports anything.
 */
final class NavigationValidator
{
    public function __construct(private readonly ActionRegistry $actions)
    {
    }

    /**
     * @return array<int,ValidationError>
     */
    public function validate(NavigationDefinition $definition): array
    {
        $errors = [];

        foreach ($definition->nodes() as $node) {
            $this->validateNode($definition, $node, $errors);
        }

        $this->checkReachability($definition, $errors);
        $this->checkCycles($definition, $errors);

        return $errors;
    }

    /**
     * @param array<int,ValidationError> $errors
     */
    private function validateNode(NavigationDefinition $def, NavigationNode $node, array &$errors): void
    {
        $path = "nodes[{$node->id}]";

        $seenItemIds = [];
        $hotkeys     = [];

        foreach ($node->items as $index => $item) {
            $itemPath = "{$path}.items[{$index}]";

            if (isset($seenItemIds[$item->id])) {
                $errors[] = new ValidationError('duplicate_item_id', "duplicate item id \"{$item->id}\" in node \"{$node->id}\"", $itemPath);
            }
            $seenItemIds[$item->id] = true;

            if ($item->hotkey !== null) {
                $hk = mb_strtolower($item->hotkey);
                if (isset($hotkeys[$hk])) {
                    $errors[] = new ValidationError(
                        'hotkey_conflict',
                        "hotkey \"{$item->hotkey}\" is used by both \"{$hotkeys[$hk]}\" and \"{$item->id}\" in node \"{$node->id}\"",
                        $itemPath
                    );
                }
                $hotkeys[$hk] = $item->id;
            }

            if ($item->isAction()) {
                $actionId = $item->action->actionId;
                if (!$this->actions->has($actionId)) {
                    $errors[] = new ValidationError(
                        'unknown_action',
                        "item \"{$item->id}\" references unknown action \"{$actionId}\"",
                        "{$itemPath}.action"
                    );
                }
            }

            if ($item->isSubmenu()) {
                if (!$def->hasNode($item->submenu)) {
                    $errors[] = new ValidationError(
                        'unknown_submenu',
                        "item \"{$item->id}\" points at undefined node \"{$item->submenu}\"",
                        "{$itemPath}.submenu"
                    );
                } elseif ($item->submenu === $node->id) {
                    $errors[] = new ValidationError(
                        'self_submenu',
                        "item \"{$item->id}\" links node \"{$node->id}\" to itself",
                        "{$itemPath}.submenu"
                    );
                }
            }

            foreach ($item->access->referencedActionIds() as $refId) {
                if (!$this->actions->has($refId)) {
                    $errors[] = new ValidationError(
                        'unknown_action',
                        "access expression references unknown action \"{$refId}\"",
                        "{$itemPath}.access"
                    );
                }
            }
        }

        if ($node->items === []) {
            $errors[] = new ValidationError('empty_node', "node \"{$node->id}\" has no items", "{$path}.items");
        }
    }

    /**
     * @param array<int,ValidationError> $errors
     */
    private function checkReachability(NavigationDefinition $def, array &$errors): void
    {
        $reachable = [];
        $stack     = [$def->rootId];
        while ($stack !== []) {
            $id = array_pop($stack);
            if (isset($reachable[$id]) || !$def->hasNode($id)) {
                continue;
            }
            $reachable[$id] = true;
            foreach ($def->node($id)->items as $item) {
                if ($item->isSubmenu() && $def->hasNode($item->submenu)) {
                    $stack[] = $item->submenu;
                }
            }
        }

        foreach ($def->nodes() as $node) {
            if (!isset($reachable[$node->id])) {
                $errors[] = new ValidationError(
                    'unreachable_node',
                    "node \"{$node->id}\" cannot be reached from the root",
                    "nodes[{$node->id}]"
                );
            }
        }
    }

    /**
     * Iterative (explicit-stack) DFS cycle detection over submenu links, so a
     * pathologically long chain in a sysop's file cannot overflow the PHP call
     * stack.
     *
     * @param array<int,ValidationError> $errors
     */
    private function checkCycles(NavigationDefinition $def, array &$errors): void
    {
        $colour   = []; // id => 1 grey (on the current path), 2 black (done)
        $reported = [];

        // Each frame: [id, list<submenu target ids not yet descended>, trail].
        $stack = [[$def->rootId, $this->submenuTargets($def, $def->rootId), [$def->rootId]]];
        $colour[$def->rootId] = 1;

        while ($stack !== []) {
            $top   = count($stack) - 1;
            [$id, $pending, $trail] = $stack[$top];

            if ($pending === []) {
                $colour[$id] = 2;
                array_pop($stack);
                continue;
            }

            $child = array_shift($pending);
            $stack[$top][1] = $pending;

            if (($colour[$child] ?? 0) === 1) {
                $from = array_search($child, $trail, true);
                $loop = implode(' -> ', array_slice($trail, $from === false ? 0 : $from)) . " -> {$child}";
                if (!isset($reported[$loop])) {
                    $reported[$loop] = true;
                    $errors[] = new ValidationError('cycle', "submenu cycle: {$loop}", "nodes[{$child}]");
                }
                continue;
            }
            if (($colour[$child] ?? 0) === 2) {
                continue;
            }

            $colour[$child] = 1;
            $stack[] = [$child, $this->submenuTargets($def, $child), [...$trail, $child]];
        }
    }

    /** @return array<int,string> */
    private function submenuTargets(NavigationDefinition $def, string $nodeId): array
    {
        $targets = [];
        foreach ($def->node($nodeId)->items as $item) {
            if ($item->isSubmenu() && $def->hasNode($item->submenu)) {
                $targets[] = $item->submenu;
            }
        }

        return $targets;
    }
}
