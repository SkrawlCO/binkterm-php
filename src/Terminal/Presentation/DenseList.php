<?php

namespace BinktermPHP\Terminal\Presentation;

/**
 * A transport-neutral model of an L33TEST-owned dense, tabular, selectable
 * terminal list — the kind of high-information screen (echomail areas, file
 * areas, a nodelist) where row density is the point and cards would be a
 * regression.
 *
 *   handler -> DenseList -> DenseListView -> shell flat selectable-list contract
 *
 * It is the sibling of {@see Directory}: the same Terminal Experience
 * Unification vocabulary — the caller has arrived *somewhere*, with a location
 * identity, a compact context line and a coherent page indicator — but composed
 * for a column grid on one screen row per item, not an arrival-space directory.
 *
 * Like {@see Directory} it holds only resolved presentation data: no sockets,
 * no services, no live queries. Counts and the page position are passed in
 * already computed; {@see DenseListView} never fetches anything.
 */
final class DenseList
{
    /**
     * @param string                 $location   where the caller is (identity line leaf)
     * @param list<string>            $crumbs     ancestor labels shown before the location
     * @param string|null             $context    one-line compact subtitle under the identity
     * @param list<DenseListColumn>   $columns    column grid, in display order
     * @param list<DenseListRow>      $rows       this page's rows, in display order
     * @param int                     $page       1-based current page
     * @param int                     $totalPages total page count (>= 1)
     */
    public function __construct(
        public readonly string $location,
        public readonly array $crumbs,
        public readonly ?string $context,
        public readonly array $columns,
        public readonly array $rows,
        public readonly int $page = 1,
        public readonly int $totalPages = 1,
    ) {
    }
}
