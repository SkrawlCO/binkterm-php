<?php

namespace BinktermPHP\Terminal\Navigation;

/**
 * A parsed, validated terminal presentation theme.
 *
 * ANSI is presentation only. A theme adds a trusted, absolute-positioned frame
 * around the declarative navigation content for one or more exact geometries;
 * it never carries navigation structure, actions, ACS, or executable behaviour.
 * The declarative renderer stays the canonical content composer and the
 * universal fallback — a theme that does not apply (disabled, geometry not
 * themed, template missing/unsafe, validation failure) simply yields to it.
 *
 * Schema 1 retains MENU + FOOTER. Schema 2 adds STATUS + DESCRIPTION, an
 * optional `root_only` flag, and an optional `nodes` map that authors named
 * non-root navigation nodes (e.g. Messages) with their own template + regions;
 * a node the map does not name still uses the theme geometry or the fallback.
 * All geometries currently use exact 80x24.
 */
final class NavigationTheme
{
    public const SCHEMA = 1;
    public const COMPOSITION_SCHEMA = 2;

    /** The only geometry a schema-1 theme may define. */
    public const SUPPORTED_GEOMETRY = '80x24';

    /**
     * @param array<string,NavigationThemeGeometry> $geometries keyed "<cols>x<rows>"
     * @param array<string,array<string,NavigationThemeGeometry>> $nodeGeometries
     *        optional per-node presentation, keyed node id then "<cols>x<rows>".
     *        A schema-2 addition: it lets one authored non-root navigation node
     *        (e.g. Messages) carry its own trusted template + regions while every
     *        other node uses the flowing renderer. Empty for a plain theme.
     */
    public function __construct(
        public readonly string $id,
        public readonly bool $enabled,
        public readonly array $geometries,
        public readonly int $schema = self::SCHEMA,
        public readonly bool $rootOnly = false,
        public readonly array $nodeGeometries = [],
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * The themed geometry that exactly matches the live terminal size, or null
     * when this size is not themed (the caller then uses the fallback renderer).
     */
    public function forGeometry(int $cols, int $rows): ?NavigationThemeGeometry
    {
        return $this->geometries["{$cols}x{$rows}"] ?? null;
    }

    /** True when this theme carries authored presentation for the given node id. */
    public function themesNode(string $nodeId): bool
    {
        return isset($this->nodeGeometries[$nodeId]) && $this->nodeGeometries[$nodeId] !== [];
    }

    /**
     * The themed geometry for a specific node at the live terminal size, or null
     * when the node is not authored or that size is not themed for it.
     */
    public function forNodeGeometry(string $nodeId, int $cols, int $rows): ?NavigationThemeGeometry
    {
        return $this->nodeGeometries[$nodeId]["{$cols}x{$rows}"] ?? null;
    }

    /**
     * Resolve the geometry to paint for a screen: a node override when one
     * exists for this node and size, otherwise the theme's own geometry when it
     * applies to this screen (root, or a non-root-only theme). Null means "this
     * screen is not themed" and the caller uses the flowing renderer.
     */
    public function geometryForScreen(string $nodeId, bool $isRoot, int $cols, int $rows): ?NavigationThemeGeometry
    {
        $node = $this->forNodeGeometry($nodeId, $cols, $rows);
        if ($node !== null) {
            return $node;
        }
        if ($this->rootOnly && !$isRoot) {
            return null;
        }

        return $this->forGeometry($cols, $rows);
    }

    /** @return array<int,string> the geometry keys this theme defines */
    public function geometryKeys(): array
    {
        return array_keys($this->geometries);
    }
}
