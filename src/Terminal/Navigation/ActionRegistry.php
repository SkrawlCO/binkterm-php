<?php

namespace BinktermPHP\Terminal\Navigation;

/**
 * The safety boundary between declarative configuration and PHP.
 *
 * Configuration refers to actions by *id only*. This registry is the single
 * place those ids are resolved: to metadata ({@see TerminalAction}) for
 * validation and preview, and — separately, at runtime only — to a bound
 * callable that drives the existing handler. Configuration can never register,
 * name, or influence the callable.
 */
final class ActionRegistry
{
    /** @var array<string,TerminalAction> */
    private array $actions = [];

    /** @var array<string,callable> */
    private array $bindings = [];

    public function register(TerminalAction $action): self
    {
        $this->actions[$action->id] = $action;

        return $this;
    }

    public function has(string $id): bool
    {
        return isset($this->actions[$id]);
    }

    public function get(string $id): TerminalAction
    {
        if (!isset($this->actions[$id])) {
            throw new \OutOfBoundsException("unknown terminal action \"{$id}\"");
        }

        return $this->actions[$id];
    }

    /** @return array<string,TerminalAction> */
    public function all(): array
    {
        return $this->actions;
    }

    /** @return array<int,string> */
    public function ids(): array
    {
        return array_keys($this->actions);
    }

    /**
     * Attach the runtime behaviour for an action id. The callable receives the
     * caller-supplied invocation context and the {@see ActionReference} params.
     *
     * @param callable(mixed,array<string,scalar>):mixed $binding
     */
    public function bind(string $id, callable $binding): self
    {
        if (!isset($this->actions[$id])) {
            throw new \OutOfBoundsException("cannot bind unknown terminal action \"{$id}\"");
        }
        $this->bindings[$id] = $binding;

        return $this;
    }

    public function isBound(string $id): bool
    {
        return isset($this->bindings[$id]);
    }

    /**
     * Invoke a bound action.
     *
     * @param array<string,scalar> $params
     * @throws \LogicException if the id has no binding
     */
    public function invoke(string $id, mixed $context, array $params = []): mixed
    {
        if (!isset($this->bindings[$id])) {
            throw new \LogicException("terminal action \"{$id}\" has no runtime binding");
        }

        return ($this->bindings[$id])($context, $params);
    }

    /**
     * Whether an action is currently usable for the given access context (its
     * declared availability expression evaluates true). Used both to gate
     * navigation items and to resolve `action:<id>` access predicates.
     */
    public function isAvailable(string $id, AccessContext $ctx): bool
    {
        return isset($this->actions[$id]) && $this->actions[$id]->availability->evaluate($ctx);
    }
}
