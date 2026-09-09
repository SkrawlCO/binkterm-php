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
 * M1 scope: schema 1, a single 80x24 geometry, MENU + FOOTER regions.
 */
final class NavigationTheme
{
    public const SCHEMA = 1;

    /** The only geometry a schema-1 theme may define. */
    public const SUPPORTED_GEOMETRY = '80x24';

    /**
     * @param array<string,NavigationThemeGeometry> $geometries keyed "<cols>x<rows>"
     */
    public function __construct(
        public readonly string $id,
        public readonly bool $enabled,
        public readonly array $geometries,
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

    /** @return array<int,string> the geometry keys this theme defines */
    public function geometryKeys(): array
    {
        return array_keys($this->geometries);
    }
}
