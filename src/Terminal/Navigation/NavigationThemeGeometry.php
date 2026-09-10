<?php

namespace BinktermPHP\Terminal\Navigation;

/**
 * The themed presentation for one exact terminal geometry: which trusted
 * template art to paint, and where the named regions sit within it.
 *
 * The loader requires MENU/FOOTER for schema 1, all four regions for schema 2,
 * and guarantees every rectangle is in bounds and pairwise disjoint.
 */
final class NavigationThemeGeometry
{
    public const REGION_MENU   = 'MENU';
    public const REGION_FOOTER = 'FOOTER';
    public const REGION_STATUS = 'STATUS';
    public const REGION_DESCRIPTION = 'DESCRIPTION';

    /** The region names M1 understands. */
    public const KNOWN_REGIONS = [self::REGION_MENU, self::REGION_FOOTER];
    public const COMPOSITION_REGIONS = [...self::KNOWN_REGIONS, self::REGION_STATUS, self::REGION_DESCRIPTION];

    /**
     * @param array<string,NavigationThemeRegion> $regions keyed by region name
     */
    public function __construct(
        public readonly int $cols,
        public readonly int $rows,
        public readonly string $templateToken,
        public readonly array $regions,
    ) {
    }

    public function region(string $name): ?NavigationThemeRegion
    {
        return $this->regions[$name] ?? null;
    }

    public function menu(): NavigationThemeRegion
    {
        return $this->regions[self::REGION_MENU];
    }

    public function footer(): NavigationThemeRegion
    {
        return $this->regions[self::REGION_FOOTER];
    }
}
