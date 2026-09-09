<?php

namespace BinktermPHP\Terminal\Navigation;

/**
 * Loads and fully validates a declarative navigation definition.
 *
 * A definition is only ever handed back if it parses cleanly AND passes every
 * semantic check. Anything else comes back as a {@see NavigationLoadResult}
 * carrying {@see ValidationError}s — the caller (and ultimately the terminal
 * runtime) then falls back to the built-in menu. A missing file, a typo, or a
 * bad access expression must never break terminal login.
 */
final class NavigationDefinitionLoader
{
    public function __construct(private readonly ActionRegistry $actions)
    {
    }

    /**
     * @param array<string,mixed> $raw
     */
    public function fromArray(array $raw, string $sourceLabel = 'definition'): NavigationLoadResult
    {
        try {
            $definition = NavigationDefinition::fromArray($raw, $sourceLabel);
        } catch (NavigationSchemaException $e) {
            return NavigationLoadResult::failed([
                new ValidationError('schema', $e->getMessage(), $e->path),
            ]);
        }

        $errors = (new NavigationValidator($this->actions))->validate($definition);
        if ($errors !== []) {
            return NavigationLoadResult::failed($errors);
        }

        return NavigationLoadResult::ok($definition);
    }

    public function fromJson(string $json, string $sourceLabel = 'definition'): NavigationLoadResult
    {
        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return NavigationLoadResult::failed([
                new ValidationError('json', 'invalid JSON: ' . $e->getMessage()),
            ]);
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            return NavigationLoadResult::failed([
                new ValidationError('json', 'top level must be a JSON object'),
            ]);
        }

        return $this->fromArray($decoded, $sourceLabel);
    }

    /**
     * Load from a file path. A non-existent file is reported as a single
     * `missing` error (not an exception) so the caller can treat "no custom
     * navigation configured" as the normal zero-config case.
     */
    public function fromFile(string $path): NavigationLoadResult
    {
        if (!is_file($path)) {
            return NavigationLoadResult::failed([
                new ValidationError('missing', "no navigation definition at {$path}"),
            ]);
        }

        $json = @file_get_contents($path);
        if ($json === false) {
            return NavigationLoadResult::failed([
                new ValidationError('unreadable', "cannot read {$path}"),
            ]);
        }

        return $this->fromJson($json, basename($path));
    }
}
