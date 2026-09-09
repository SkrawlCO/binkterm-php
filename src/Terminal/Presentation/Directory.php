<?php

namespace BinktermPHP\Terminal\Presentation;

/**
 * A transport-neutral model of an L33TEST-owned directory/junction screen: a
 * place the caller has arrived at, with grouped destinations.
 *
 *   handler -> Directory -> DirectoryView -> shell structured-list contract
 *
 * It is the shared vocabulary the "Terminal Experience Unification" primitive is
 * built on. It deliberately holds only resolved presentation data — no sockets,
 * no services, no live queries. `contextLines` must be pre-resolved plain text
 * (relative times already formatted, counts already taken from cached state);
 * DirectoryView never fetches anything.
 */
final class Directory
{
    /**
     * @param string              $location     where the caller is (masthead identity)
     * @param string|null         $tagline      one-line "what this place is" under the masthead
     * @param list<DirectorySection> $sections   destination groups, in display order
     * @param list<string>        $contextLines optional ambient/recent-activity block,
     *                                           rendered above the first titled section
     */
    public function __construct(
        public readonly string $location,
        public readonly ?string $tagline,
        public readonly array $sections,
        public readonly array $contextLines = [],
    ) {
    }

    /** Every row across every section, in display order. */
    public function rows(): array
    {
        $out = [];
        foreach ($this->sections as $section) {
            foreach ($section->rows as $row) {
                $out[] = $row;
            }
        }

        return $out;
    }
}
