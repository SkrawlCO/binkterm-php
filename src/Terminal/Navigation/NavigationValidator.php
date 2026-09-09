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
     * @param array<int,ValidationError> $errors
     */
    private function checkCycles(NavigationDefinition $def, array &$errors): void
    {
        $state = []; // id => 1 visiting, 2 done
        $reported = [];

        $visit = function (string $id, array $trail) use (&$visit, &$state, &$errors, &$reported, $def): void {
            if (($state[$id] ?? 0) === 2) {
                return;
            }
            if (($state[$id] ?? 0) === 1) {
                $loop = implode(' -> ', array_slice($trail, array_search($id, $trail, true))) . " -> {$id}";
                if (!isset($reported[$loop])) {
                    $reported[$loop] = true;
                    $errors[] = new ValidationError('cycle', "submenu cycle: {$loop}", "nodes[{$id}]");
                }

                return;
            }
            $state[$id] = 1;
            $trail[] = $id;
            foreach ($def->node($id)->items as $item) {
                if ($item->isSubmenu() && $def->hasNode($item->submenu)) {
                    $visit($item->submenu, $trail);
                }
            }
            $state[$id] = 2;
        };

        $visit($def->rootId, []);
    }
}
