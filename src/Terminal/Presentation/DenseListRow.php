<?php

namespace BinktermPHP\Terminal\Presentation;

/**
 * One selectable line in a {@see DenseList}.
 *
 * Carries presentation data only. `cells` maps a {@see DenseListColumn::$key}
 * to that column's plain text for this row. `value` is an opaque payload the
 * caller attaches so it can dispatch on the selection without tracking flat
 * indices — {@see DenseListView::compose()} returns a parallel `values` array.
 */
final class DenseListRow
{
    /**
     * @param array<string,string> $cells     column key => plain cell text
     * @param mixed                $value     opaque caller payload
     * @param string|null          $prefix    short marker rendered before the columns
     *                                         (e.g. a subscription badge "[+]")
     * @param string|null          $prefixSgr SGR string the prefix is colourised with
     *                                         (ignored when colour is disabled)
     */
    public function __construct(
        public readonly array $cells,
        public readonly mixed $value = null,
        public readonly ?string $prefix = null,
        public readonly ?string $prefixSgr = null,
    ) {
    }
}
