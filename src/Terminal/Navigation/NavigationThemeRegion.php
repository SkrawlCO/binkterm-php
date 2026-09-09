<?php

namespace BinktermPHP\Terminal\Navigation;

/**
 * A validated, named rectangle inside a themed geometry: the area of the
 * terminal that a piece of composed navigation content is positioned into.
 *
 * Coordinates are 1-based (the terminal's own CUP convention): `row` / `col`
 * are the top-left cell, `width` / `height` the extent. All four are positive
 * integers and the rectangle is guaranteed by {@see NavigationThemeLoader} to
 * sit wholly within its geometry. A region is a placement target, never a
 * drawing program — it carries no ANSI, no per-cell instructions, no logic.
 */
final class NavigationThemeRegion
{
    public function __construct(
        public readonly string $name,
        public readonly int $row,
        public readonly int $col,
        public readonly int $width,
        public readonly int $height,
    ) {
    }

    public function bottomRow(): int
    {
        return $this->row + $this->height - 1;
    }

    public function rightCol(): int
    {
        return $this->col + $this->width - 1;
    }

    /** True when this rectangle shares at least one cell with $other. */
    public function intersects(self $other): bool
    {
        return $this->col <= $other->rightCol()
            && $other->col <= $this->rightCol()
            && $this->row <= $other->bottomRow()
            && $other->row <= $this->bottomRow();
    }
}
