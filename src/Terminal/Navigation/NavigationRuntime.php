<?php

namespace BinktermPHP\Terminal\Navigation;

use BinktermPHP\TelnetServer\TerminalRenderContext;

/**
 * The interactive runtime for a declarative navigation definition.
 *
 * It is driven entirely by injected callables so it has no dependency on the
 * telnet/SSH engine: the caller supplies a key reader and an action-invocation
 * context. It renders through {@see NavigationScreenRenderer} (the same renderer
 * as tests and preview), keeps hotkey behaviour, adds arrow/lightbar and
 * Back/Home navigation, and rebuilds the screen on every loop so a geometry
 * change (NAWS -> render context) reflows immediately.
 *
 * The result of {@see run()} tells the caller how the loop ended.
 */
final class NavigationRuntime
{
    public const EXIT_QUIT       = 'quit';
    public const EXIT_DISCONNECT = 'disconnect';
    public const EXIT_BACK_ROOT  = 'back_at_root';

    public function __construct(
        private readonly NavigationDefinition $definition,
        private readonly ActionRegistry $registry,
        private readonly NavigationScreenBuilder $builder,
        private readonly NavigationScreenRenderer $renderer,
    ) {
    }

    /**
     * @param callable():array{0:?string,1:bool,2:bool} $readToken  returns
     *        [token, timedOut, shouldDisconnect]. Token examples: 'CHAR:n',
     *        'UP', 'DOWN', 'ENTER', 'LEFT', 'ESC', 'PGUP', 'PGDN', '' (timeout).
     * @param callable(NavigationScreenModel):void|null $onRender optional hook
     *        after each render (e.g. flush).
     * @return string one of the EXIT_* constants
     */
    public function run(
        callable $readToken,
        TerminalRenderContext $ctx,
        AccessContext $access,
        mixed $invokeContext,
        string $locale = 'en',
        ?callable $onRender = null,
        int $maxIterations = 100000
    ): string {
        $rootLabel = $this->resolveNodeLabel($this->definition->rootId, $locale);
        $path      = NavigationPath::root($this->definition->rootId, $rootLabel);
        $cursor    = 0;

        for ($i = 0; $i < $maxIterations; $i++) {
            $screen = $this->builder->build($this->definition, $access, $path, $locale);
            $selectable = $screen->selectableItems();
            $cursor = max(0, min($cursor, max(0, count($selectable) - 1)));

            $this->renderer->render($ctx, $screen, ['cursor' => $selectable === [] ? null : $cursor]);
            if ($onRender !== null) {
                $onRender($screen);
            }

            [$token, $timedOut, $disconnect] = $readToken();
            if ($disconnect) {
                return self::EXIT_DISCONNECT;
            }
            if ($timedOut || $token === null || $token === '') {
                continue; // re-render (covers idle refresh + geometry change)
            }

            $decision = $this->handleToken($token, $screen, $selectable, $cursor);
            $cursor   = $decision['cursor'];

            switch ($decision['do']) {
                case 'noop':
                    break;
                case 'quit':
                    return self::EXIT_QUIT;
                case 'back':
                    if ($path->isRoot()) {
                        return self::EXIT_BACK_ROOT;
                    }
                    $path   = $path->pop();
                    $cursor = 0;
                    break;
                case 'home':
                    $path   = $path->toRoot();
                    $cursor = 0;
                    break;
                case 'select':
                    /** @var NavigationScreenItem $item */
                    $item = $decision['item'];
                    if ($item->isSubmenu()) {
                        $path   = $path->push($item->targetNodeId, $item->label);
                        $cursor = 0;
                        break;
                    }
                    $actionId = $item->action->actionId;
                    if ($this->registry->has($actionId) && $this->registry->get($actionId)->terminates) {
                        return self::EXIT_QUIT;
                    }
                    if ($this->registry->isBound($actionId)) {
                        $this->registry->invoke($actionId, $invokeContext, $item->action->params);
                    }
                    // fall through to re-render the current screen
                    break;
            }
        }

        return self::EXIT_QUIT;
    }

    /**
     * @param array<int,NavigationScreenItem> $selectable
     * @return array{do:string,cursor:int,item?:NavigationScreenItem}
     */
    private function handleToken(string $token, NavigationScreenModel $screen, array $selectable, int $cursor): array
    {
        $count = count($selectable);

        if ($token === 'UP') {
            return ['do' => 'noop', 'cursor' => $count === 0 ? 0 : ($cursor - 1 + $count) % $count];
        }
        if ($token === 'DOWN') {
            return ['do' => 'noop', 'cursor' => $count === 0 ? 0 : ($cursor + 1) % $count];
        }
        if ($token === 'PGUP' || $token === 'HOME_KEY') {
            return ['do' => 'noop', 'cursor' => 0];
        }
        if ($token === 'PGDN' || $token === 'END_KEY') {
            return ['do' => 'noop', 'cursor' => max(0, $count - 1)];
        }
        if ($token === 'ENTER') {
            return $count > 0 && isset($selectable[$cursor])
                ? ['do' => 'select', 'cursor' => $cursor, 'item' => $selectable[$cursor]]
                : ['do' => 'noop', 'cursor' => $cursor];
        }
        if ($token === 'LEFT' || $token === 'ESC') {
            return ['do' => 'back', 'cursor' => $cursor];
        }

        if (str_starts_with($token, 'CHAR:')) {
            $ch = mb_strtolower(substr($token, 5));
            if ($ch === 'q') {
                return ['do' => 'quit', 'cursor' => $cursor];
            }
            if ($ch === 'b' && $screen->itemForHotkey('b') === null) {
                return ['do' => 'back', 'cursor' => $cursor];
            }
            if ($ch === 'h' && $screen->homeAvailable && $screen->itemForHotkey('h') === null) {
                return ['do' => 'home', 'cursor' => $cursor];
            }
            $item = $screen->itemForHotkey($ch);
            if ($item !== null) {
                return ['do' => 'select', 'cursor' => $cursor, 'item' => $item];
            }
        }

        return ['do' => 'noop', 'cursor' => $cursor];
    }

    private function resolveNodeLabel(string $nodeId, string $locale): string
    {
        $node = $this->definition->node($nodeId);

        return $this->builder->translateLabel($node->labelKey, $node->labelFallback, $locale);
    }
}
