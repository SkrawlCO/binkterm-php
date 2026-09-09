<?php

namespace BinktermPHP\Terminal\Presentation;

/**
 * A titled group of {@see DirectoryRow}s within a {@see Directory}.
 *
 * An empty `title` renders the rows with no heading (a flat leading block —
 * used for navigational rows that are not destinations, e.g. "Live Now").
 * A non-empty title renders as an uppercase section heading above its rows,
 * matching the declarative front door's grouping treatment.
 */
final class DirectorySection
{
    /**
     * @param string             $title   heading text, or '' for an unheaded block
     * @param list<DirectoryRow>  $rows
     * @param bool                $compact fold each row's description onto its
     *                                     primary line instead of a secondary
     *                                     row (for navigational rows / quiet
     *                                     states, not destinations)
     */
    public function __construct(
        public readonly string $title,
        public readonly array $rows,
        public readonly bool $compact = false,
    ) {
    }
}
