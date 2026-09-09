<?php

namespace BinktermPHP\Terminal\Presentation;

/**
 * One column in a {@see DenseList}: a fixed-width, single-line cell.
 *
 * A {@see $width} of 0 marks the column as flexible — it absorbs whatever
 * horizontal space is left after every fixed column and the row chrome
 * (number, badge, gaps) have taken theirs. At most one flexible column is
 * supported; if none is declared the last column is treated as flexible.
 */
final class DenseListColumn
{
    public const ALIGN_LEFT = 'left';
    public const ALIGN_RIGHT = 'right';

    /**
     * @param string $key      row-cell key this column reads
     * @param int    $width    fixed visible width, or 0 for the flexible column
     * @param string $align    self::ALIGN_LEFT | self::ALIGN_RIGHT
     * @param int    $minWidth floor a fixed column may be shrunk to when the
     *                         terminal is too narrow to honour every width
     */
    public function __construct(
        public readonly string $key,
        public readonly int $width,
        public readonly string $align = self::ALIGN_LEFT,
        public readonly int $minWidth = 4,
    ) {
    }

    public function isFlexible(): bool
    {
        return $this->width === 0;
    }
}
