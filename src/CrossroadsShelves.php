<?php

namespace BinktermPHP;

/**
 * Pure classification and composition of normalized Experience entries into the
 * Crossroads Curated Catalog shelves.
 *
 * Input entries may be raw {@see GameCatalog} rows, {@see ExperiencePresentation}
 * view models, or route wrappers of the form `['experience_presentation' => $view,
 * ...]` — classification reads `category` and the `curation` block from the
 * nested view when present, else from the entry itself. This helper performs no
 * I/O and renders nothing; the Twig templates consume {@see compose()}.
 *
 * Rules, in order:
 *   1. entry is curated (`curation.curated`)      -> CURATED
 *   2. else category === 'gateway'                -> GATEWAY
 *   3. else category is a non-empty, non-'game'   -> UTILITY
 *      value (e.g. 'utility', 'chat', 'tool')
 *   4. else (category 'game', empty, or absent)   -> GAME_HALL
 *
 * Curation deliberately wins over category: an operator may curate something
 * that is otherwise a gateway or a utility, and it then belongs on the curated
 * shelf. The UTILITY branch is a generic catch for any explicitly non-game,
 * non-gateway category so new non-game categories need no code change here.
 *
 * Ordering:
 *   - CURATED   : ascending `curation.order` (the operator's list order);
 *                 entries without a numeric order sort last, then by input order.
 *   - GAME_HALL : input order preserved (caller supplies display/alpha order).
 *   - UTILITY   : input order preserved.
 *   - GATEWAY   : input order preserved.
 */
final class CrossroadsShelves
{
    public const CURATED = 'curated';
    public const GAME_HALL = 'game_hall';
    public const UTILITY = 'utility';
    public const GATEWAY = 'gateway';

    /** Shelf order + default open/closed policy for the Curated Catalog. */
    private const SHELF_ORDER = [self::CURATED, self::GAME_HALL, self::UTILITY, self::GATEWAY];
    private const COLLAPSIBLE = [
        self::CURATED => false,
        self::GAME_HALL => true,
        self::UTILITY => true,
        self::GATEWAY => true,
    ];
    private const DEFAULT_EXPANDED = [
        self::CURATED => true,
        self::GAME_HALL => true,
        self::UTILITY => true,
        self::GATEWAY => false,
    ];

    /**
     * The classification facet of an entry: the nested presentation view when
     * the entry is a route wrapper, else the entry itself.
     *
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    private static function facet(array $entry): array
    {
        return isset($entry['experience_presentation']) && is_array($entry['experience_presentation'])
            ? $entry['experience_presentation']
            : $entry;
    }

    /**
     * The shelf a single entry belongs on.
     *
     * @param array<string,mixed> $entry
     * @return self::CURATED|self::GAME_HALL|self::UTILITY|self::GATEWAY
     */
    public static function classify(array $entry): string
    {
        $facet = self::facet($entry);

        if (!empty($facet['curation']['curated'])) {
            return self::CURATED;
        }

        $category = $facet['category'] ?? null;
        $category = is_string($category) ? strtolower(trim($category)) : '';

        if ($category === 'gateway') {
            return self::GATEWAY;
        }

        // Any explicit non-game, non-gateway category (utility, chat, tool, ...)
        // is a non-game destination and belongs on the Utilities shelf.
        if ($category !== '' && $category !== 'game') {
            return self::UTILITY;
        }

        return self::GAME_HALL;
    }

    /**
     * Partition a list of entries into ordered shelves.
     *
     * @param iterable<array<string,mixed>> $entries
     * @return array{curated:list<array<string,mixed>>,game_hall:list<array<string,mixed>>,utility:list<array<string,mixed>>,gateway:list<array<string,mixed>>}
     */
    public static function group(iterable $entries): array
    {
        $shelves = [
            self::CURATED => [],
            self::GAME_HALL => [],
            self::UTILITY => [],
            self::GATEWAY => [],
        ];

        foreach ($entries as $entry) {
            $shelves[self::classify($entry)][] = $entry;
        }

        $shelves[self::CURATED] = self::sortByCuratedOrder($shelves[self::CURATED]);

        return $shelves;
    }

    /**
     * Render-ready shelf list, in display order, each carrying its key, ordered
     * entries, count, and default disclosure state. Copy (titles/captions) is
     * the template's concern, keyed off `key`.
     *
     * @param iterable<array<string,mixed>> $entries
     * @return list<array{key:string,entries:list<array<string,mixed>>,count:int,collapsible:bool,default_expanded:bool}>
     */
    public static function compose(iterable $entries): array
    {
        $grouped = self::group($entries);

        $shelves = [];
        foreach (self::SHELF_ORDER as $key) {
            $shelves[] = [
                'key' => $key,
                'entries' => $grouped[$key],
                'count' => count($grouped[$key]),
                'collapsible' => self::COLLAPSIBLE[$key],
                'default_expanded' => self::DEFAULT_EXPANDED[$key],
            ];
        }

        return $shelves;
    }

    /**
     * Stable sort by `curation.order` ascending. A missing/non-numeric order
     * sorts after every explicitly ordered entry, preserving input order among
     * equals.
     *
     * @param list<array<string,mixed>> $entries
     * @return list<array<string,mixed>>
     */
    private static function sortByCuratedOrder(array $entries): array
    {
        $indexed = [];
        foreach ($entries as $i => $entry) {
            $order = self::facet($entry)['curation']['order'] ?? null;
            $indexed[] = [
                'sort' => is_numeric($order) ? (int)$order : PHP_INT_MAX,
                'seq' => $i,
                'entry' => $entry,
            ];
        }

        usort($indexed, static function (array $a, array $b): int {
            return [$a['sort'], $a['seq']] <=> [$b['sort'], $b['seq']];
        });

        return array_map(static fn (array $row) => $row['entry'], $indexed);
    }
}
