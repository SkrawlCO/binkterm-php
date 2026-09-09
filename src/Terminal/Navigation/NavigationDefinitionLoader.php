<?php

namespace BinktermPHP\Terminal\Navigation;

/**
 * Loads and fully validates a declarative navigation definition.
 *
 * A definition is only ever handed back if it parses cleanly AND passes every
 * semantic check. Anything else comes back as a {@see NavigationLoadResult}
 * carrying {@see ValidationError}s — the caller (and ultimately the terminal
 * runtime) then falls back to the built-in menu. A missing file, an unreadable
 * file, malformed JSON, an unsupported schema, a typo, or a bad access
 * expression must never break terminal login and must never partially activate
 * the runtime.
 */
final class NavigationDefinitionLoader
{
    /** JSON nesting depth ceiling — well beyond any realistic definition. */
    private const JSON_MAX_DEPTH = 64;

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
                new ValidationError('schema', $e->getMessage(), $e->path !== '' ? $e->path : $sourceLabel),
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
        if (trim($json) === '') {
            return NavigationLoadResult::failed([
                new ValidationError('json', 'file is empty', $sourceLabel),
            ]);
        }

        try {
            $decoded = json_decode($json, true, self::JSON_MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return NavigationLoadResult::failed([
                new ValidationError('json', 'invalid JSON: ' . $e->getMessage(), $sourceLabel),
            ]);
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            return NavigationLoadResult::failed([
                new ValidationError('json', 'top level must be a JSON object', $sourceLabel),
            ]);
        }

        return $this->fromArray($decoded, $sourceLabel);
    }

    /**
     * Load from a file path.
     *
     * The distinct error codes let a caller log something a sysop can act on:
     *   - `missing`    — the path does not resolve to a regular file (also a
     *                    dangling symlink, or a directory);
     *   - `unreadable` — the file exists but could not be read (permissions);
     *   - `json`       — the file is not valid JSON / not a JSON object / empty;
     *   - `schema`     — structurally invalid (bad/absent schema version, …);
     *   - semantic codes from {@see NavigationValidator}.
     */
    public function fromFile(string $path): NavigationLoadResult
    {
        if (!is_file($path)) {
            $hint = is_dir($path) ? ' (path is a directory)' : (is_link($path) ? ' (broken symlink)' : '');

            return NavigationLoadResult::failed([
                new ValidationError('missing', "no navigation definition at {$path}{$hint}"),
            ]);
        }

        if (!is_readable($path)) {
            return NavigationLoadResult::failed([
                new ValidationError('unreadable', "navigation definition {$path} exists but is not readable (check file permissions)"),
            ]);
        }

        $json = @file_get_contents($path);
        if ($json === false) {
            return NavigationLoadResult::failed([
                new ValidationError('unreadable', "could not read navigation definition {$path}"),
            ]);
        }

        return $this->fromJson($json, $path);
    }
}
