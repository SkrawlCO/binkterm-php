<?php

namespace BinktermPHP\Terminal\Navigation;

/**
 * The themed presentation for one exact terminal geometry: which trusted
 * template art to paint, and where the named regions sit within it.
 *
 * M1 supports a single geometry (80x24) with exactly two regions, MENU and
 * FOOTER. {@see NavigationThemeLoader} guarantees both are present, in bounds,
 * and non-overlapping.
 */
final class NavigationThemeGeometry
{
    public const REGION_MENU   = 'MENU';
    public const REGION_FOOTER = 'FOOTER';

    /** The region names M1 understands. */
    public const KNOWN_REGIONS = [self::REGION_MENU, self::REGION_FOOTER];

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
