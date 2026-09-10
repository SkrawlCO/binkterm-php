<?php

namespace BinktermPHP\Terminal\Navigation;

/**
 * Parses and validates a terminal presentation theme ({@see NavigationTheme}).
 *
 * The config is a description of named presentation regions, not a drawing
 * language. Validation is strict and total — anything it cannot fully verify is
 * rejected with a path-tagged {@see ValidationError}, and the caller uses the
 * fallback renderer:
 *
 *   - schema must be 1 (M1) or 2 (semantic composition)
 *   - `id` a non-empty string; `enabled` a boolean (default true)
 *   - `geometries` an object whose only key is "80x24" for schema 1
 *   - each geometry: `template` a safe token ([A-Za-z0-9_-]), `regions` an
 *     object with exactly MENU/FOOTER (1), plus STATUS/DESCRIPTION (2)
 *   - each region: integer row/col/width/height, all >= 1, wholly inside the
 *     geometry, and all rectangles must be disjoint
 *   - optional `nodes` (schema 2 only): an object keyed by navigation node id,
 *     each value `{ "geometries": { "80x24": { ...same shape as above... } } }`,
 *     giving one authored non-root node its own trusted template + regions
 */
final class NavigationThemeLoader
{
    private const TOKEN_RE = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/';

    public function fromFile(string $path): NavigationThemeLoadResult
    {
        if (!is_file($path)) {
            return NavigationThemeLoadResult::absent();
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return NavigationThemeLoadResult::invalid([
                new ValidationError('io', 'could not read the theme file', $path),
            ]);
        }

        return $this->fromJson($raw);
    }

    public function fromJson(string $json): NavigationThemeLoadResult
    {
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return NavigationThemeLoadResult::invalid([
                new ValidationError('json', 'not valid JSON: ' . $e->getMessage()),
            ]);
        }

        return $this->fromArray(is_array($data) ? $data : ['__notObject' => $data]);
    }

    /**
     * @param array<mixed> $data
     */
    public function fromArray(array $data): NavigationThemeLoadResult
    {
        $errors = [];

        if (!$this->isAssoc($data)) {
            return NavigationThemeLoadResult::invalid([
                new ValidationError('shape', 'the theme must be a JSON object'),
            ]);
        }

        $schema = $data['schema'] ?? null;
        if (!in_array($schema, [NavigationTheme::SCHEMA, NavigationTheme::COMPOSITION_SCHEMA], true)) {
            $errors[] = new ValidationError(
                'schema',
                sprintf('unsupported schema %s (this release understands 1 and 2)', var_export($schema, true)),
                'schema'
            );
        }

        $id = $data['id'] ?? null;
        if (!is_string($id) || trim($id) === '') {
            $errors[] = new ValidationError('id', 'id must be a non-empty string', 'id');
            $id = '';
        }

        $enabled = true;
        if (array_key_exists('enabled', $data)) {
            if (!is_bool($data['enabled'])) {
                $errors[] = new ValidationError('enabled', 'enabled must be true or false', 'enabled');
            } else {
                $enabled = $data['enabled'];
            }
        }

        $rootOnly = $data['root_only'] ?? false;
        if (array_key_exists('root_only', $data)
            && ($schema !== NavigationTheme::COMPOSITION_SCHEMA || !is_bool($data['root_only']))) {
            $errors[] = new ValidationError('root_only', 'root_only requires schema 2 and a boolean', 'root_only');
        }
        $regionNames = $schema === NavigationTheme::COMPOSITION_SCHEMA
            ? NavigationThemeGeometry::COMPOSITION_REGIONS : NavigationThemeGeometry::KNOWN_REGIONS;
        $geometries = [];
        $rawGeos = $data['geometries'] ?? null;
        if (!is_array($rawGeos) || !$this->isAssoc($rawGeos) || $rawGeos === []) {
            $errors[] = new ValidationError('geometries', 'geometries must be a non-empty object', 'geometries');
        } else {
            foreach ($rawGeos as $key => $geoData) {
                if (is_string($key) && str_starts_with($key, '_')) {
                    continue; // documentation key
                }
                $path = "geometries[{$key}]";
                if ($key !== NavigationTheme::SUPPORTED_GEOMETRY) {
                    $errors[] = new ValidationError(
                        'geometry_unsupported',
                        sprintf("geometry '%s' is not supported in this release; only %s may be themed (other sizes use the fallback renderer)", $key, NavigationTheme::SUPPORTED_GEOMETRY),
                        $path
                    );
                    continue;
                }
                [$cols, $rows] = $this->parseGeometryKey($key);
                $geo = $this->geometry($cols, $rows, $geoData, $path, $errors, $regionNames);
                if ($geo !== null) {
                    $geometries[$key] = $geo;
                }
            }
        }

        $nodeGeometries = [];
        if (array_key_exists('nodes', $data)) {
            if ($schema !== NavigationTheme::COMPOSITION_SCHEMA) {
                $errors[] = new ValidationError('nodes', 'nodes requires schema 2', 'nodes');
            } elseif (!is_array($data['nodes']) || !$this->isAssoc($data['nodes'])) {
                $errors[] = new ValidationError('nodes', 'nodes must be an object keyed by node id', 'nodes');
            } else {
                foreach ($data['nodes'] as $nodeId => $nodeData) {
                    if (is_string($nodeId) && str_starts_with($nodeId, '_')) {
                        continue; // documentation key
                    }
                    if (!is_string($nodeId) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/', $nodeId)) {
                        $errors[] = new ValidationError('node_id', 'node id must be a simple token', "nodes[{$nodeId}]");
                        continue;
                    }
                    $geos = $this->nodeGeometries($nodeId, $nodeData, $errors, $regionNames);
                    if ($geos !== []) {
                        $nodeGeometries[$nodeId] = $geos;
                    }
                }
            }
        }

        if ($errors !== []) {
            return NavigationThemeLoadResult::invalid($errors);
        }

        return NavigationThemeLoadResult::ok(
            new NavigationTheme((string) $id, $enabled, $geometries, $schema, $rootOnly, $nodeGeometries)
        );
    }

    /**
     * Parse the `geometries` object of one entry in the theme's `nodes` map,
     * with the same strictness and the same geometry validator as the theme's
     * own top-level geometries.
     *
     * @param array<mixed> $errors passed by reference
     * @param array<int,string> $regionNames
     * @return array<string,NavigationThemeGeometry>
     */
    private function nodeGeometries(string $nodeId, mixed $nodeData, array &$errors, array $regionNames): array
    {
        $base = "nodes[{$nodeId}]";
        if (!is_array($nodeData) || !$this->isAssoc($nodeData)) {
            $errors[] = new ValidationError('node_shape', 'a node theme must be an object', $base);

            return [];
        }
        foreach (array_keys($nodeData) as $key) {
            if (is_string($key) && ($key === 'geometries' || str_starts_with($key, '_'))) {
                continue;
            }
            $errors[] = new ValidationError('node_field', "unknown node theme field \"{$key}\"", $base);
        }

        $rawGeos = $nodeData['geometries'] ?? null;
        if (!is_array($rawGeos) || !$this->isAssoc($rawGeos) || $rawGeos === []) {
            $errors[] = new ValidationError('node_geometries', 'a node theme needs a non-empty geometries object', "{$base}.geometries");

            return [];
        }

        $geometries = [];
        foreach ($rawGeos as $key => $geoData) {
            if (is_string($key) && str_starts_with($key, '_')) {
                continue;
            }
            $path = "{$base}.geometries[{$key}]";
            if ($key !== NavigationTheme::SUPPORTED_GEOMETRY) {
                $errors[] = new ValidationError(
                    'geometry_unsupported',
                    sprintf("geometry '%s' is not supported; only %s may be themed", $key, NavigationTheme::SUPPORTED_GEOMETRY),
                    $path
                );
                continue;
            }
            [$cols, $rows] = $this->parseGeometryKey($key);
            $geo = $this->geometry($cols, $rows, $geoData, $path, $errors, $regionNames);
            if ($geo !== null) {
                $geometries[$key] = $geo;
            }
        }

        return $geometries;
    }

    /**
     * @param array<mixed> $errors passed by reference
     */
    private function geometry(int $cols, int $rows, mixed $geoData, string $path, array &$errors, array $regionNames): ?NavigationThemeGeometry
    {
        if (!is_array($geoData) || !$this->isAssoc($geoData)) {
            $errors[] = new ValidationError('geometry_shape', 'a geometry must be an object', $path);

            return null;
        }

        $token = $geoData['template'] ?? null;
        if (!is_string($token) || !preg_match(self::TOKEN_RE, $token)) {
            $errors[] = new ValidationError(
                'template',
                'template must be a simple asset token (letters, digits, _ or -); it names telnet/screens/<token>.ans',
                "{$path}.template"
            );
            $token = '';
        }

        $rawRegions = $geoData['regions'] ?? null;
        if (!is_array($rawRegions) || !$this->isAssoc($rawRegions)) {
            $errors[] = new ValidationError('regions', 'regions must be an object', "{$path}.regions");

            return null;
        }

        $regions = [];
        $seen    = [];
        foreach ($rawRegions as $name => $rectData) {
            if (is_string($name) && str_starts_with($name, '_')) {
                continue; // documentation key
            }
            $rpath = "{$path}.regions[{$name}]";
            if (!in_array($name, $regionNames, true)) {
                $errors[] = new ValidationError(
                    'region_unknown',
                    sprintf("unknown region '%s'; this schema supports: %s", $name, implode(', ', $regionNames)),
                    $rpath
                );
                continue;
            }
            $seen[$name] = true;
            $rect = $this->region((string) $name, $cols, $rows, $rectData, $rpath, $errors);
            if ($rect !== null) {
                $regions[$name] = $rect;
            }
        }

        foreach ($regionNames as $required) {
            if (!isset($seen[$required])) {
                $errors[] = new ValidationError(
                    'region_missing',
                    "the {$required} region is required",
                    "{$path}.regions"
                );
            }
        }

        $rectangles = array_values($regions);
        foreach ($rectangles as $i => $rect) {
            foreach (array_slice($rectangles, $i + 1) as $other) {
                if ($rect->intersects($other)) {
                    $errors[] = new ValidationError(
                        'region_overlap',
                        "{$rect->name} and {$other->name} regions overlap; their rectangles must be disjoint",
                        "{$path}.regions"
                    );
                }
            }
        }

        if ($errors !== []) {
            return null;
        }

        return new NavigationThemeGeometry($cols, $rows, $token, $regions);
    }

    /**
     * @param array<mixed> $errors passed by reference
     */
    private function region(string $name, int $cols, int $rows, mixed $rectData, string $path, array &$errors): ?NavigationThemeRegion
    {
        if (!is_array($rectData) || !$this->isAssoc($rectData)) {
            $errors[] = new ValidationError('region_shape', 'a region must be an object', $path);

            return null;
        }

        $vals = [];
        foreach (['row', 'col', 'width', 'height'] as $field) {
            $v = $rectData[$field] ?? null;
            if (!is_int($v) || $v < 1) {
                $errors[] = new ValidationError('region_bounds', "{$field} must be an integer >= 1", "{$path}.{$field}");
                $vals[$field] = null;
            } else {
                $vals[$field] = $v;
            }
        }

        if (in_array(null, $vals, true)) {
            return null;
        }

        $rect = new NavigationThemeRegion($name, $vals['row'], $vals['col'], $vals['width'], $vals['height']);

        if ($rect->bottomRow() > $rows || $rect->rightCol() > $cols) {
            $errors[] = new ValidationError(
                'region_bounds',
                sprintf(
                    'region extends to row %d, col %d — outside the %dx%d geometry',
                    $rect->bottomRow(),
                    $rect->rightCol(),
                    $cols,
                    $rows
                ),
                $path
            );

            return null;
        }

        return $rect;
    }

    /** @return array{0:int,1:int} */
    private function parseGeometryKey(string $key): array
    {
        if (preg_match('/^(\d+)x(\d+)$/', $key, $m)) {
            return [(int) $m[1], (int) $m[2]];
        }

        return [80, 24];
    }

    private function isAssoc(mixed $v): bool
    {
        return is_array($v) && (
            $v === [] || array_keys($v) !== range(0, count($v) - 1)
        );
    }
}
