<?php

namespace BinktermPHP\Terminal\Navigation;

/**
 * A reference from a navigation item to a *named* platform action.
 *
 * Configuration can only name an action id that the {@see ActionRegistry}
 * already knows about; it can never supply a PHP class, callback, or method
 * name. Optional params are restricted to scalars so nothing executable can be
 * smuggled through.
 */
final class ActionReference
{
    /**
     * @param array<string,scalar> $params
     */
    private function __construct(
        public readonly string $actionId,
        public readonly array $params = [],
    ) {
    }

    public static function of(string $actionId, array $params = []): self
    {
        return new self($actionId, $params);
    }

    /**
     * @param mixed $raw either a string action id, or `{"id": "...", "params": {...}}`
     */
    public static function fromConfig(mixed $raw, string $path = 'action'): self
    {
        if (is_string($raw)) {
            $id = trim($raw);
            if ($id === '') {
                throw new NavigationSchemaException('action id must not be empty', $path);
            }

            return new self($id);
        }

        if (!is_array($raw) || array_is_list($raw)) {
            throw new NavigationSchemaException('action must be a string id or an object', $path);
        }

        foreach (array_keys($raw) as $key) {
            if (!in_array($key, ['id', 'params'], true)) {
                throw new NavigationSchemaException("unknown action field \"{$key}\"", $path);
            }
        }

        $id = isset($raw['id']) ? trim((string) $raw['id']) : '';
        if ($id === '') {
            throw new NavigationSchemaException('action.id is required', "{$path}.id");
        }

        $params = [];
        if (isset($raw['params'])) {
            if (!is_array($raw['params']) || array_is_list($raw['params'])) {
                throw new NavigationSchemaException('action.params must be an object', "{$path}.params");
            }
            foreach ($raw['params'] as $k => $v) {
                if (!is_scalar($v) && $v !== null) {
                    throw new NavigationSchemaException(
                        "action.params.{$k} must be a scalar",
                        "{$path}.params.{$k}"
                    );
                }
                $params[(string) $k] = $v;
            }
        }

        return new self($id, $params);
    }
}
