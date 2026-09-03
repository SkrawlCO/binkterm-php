<?php

namespace BinktermPHP;

/**
 * Pure classification of normalized Experience entries into the three
 * Crossroads Curated Catalog shelves.
 *
 * Input entries may be raw {@see GameCatalog} rows or {@see ExperiencePresentation}
 * view models — both expose `category` and the `curation` block. This helper
 * performs no I/O and renders nothing; shelf composition in Twig (Slice 2)
 * consumes its output.
 *
 * Rules, in order:
 *   1. entry is curated (`curation.curated`)      -> CURATED
 *   2. else category === 'gateway'                -> GATEWAY
 *   3. else                                       -> GAME_HALL
 *
 * Curation deliberately wins over category: an operator may curate something
 * that is otherwise a gateway, and it then belongs on the curated shelf.
 *
 * Ordering:
 *   - CURATED   : ascending `curation.order` (the operator's list order);
 *                 entries without a numeric order sort last, then by input order.
 *   - GAME_HALL : input order preserved (caller supplies display/alpha order).
 *   - GATEWAY   : input order preserved.
 */
final class CrossroadsShelves
{
    public const CURATED = 'curated';
    public const GAME_HALL = 'game_hall';
    public const GATEWAY = 'gateway';

    /**
     * The shelf a single entry belongs on.
     *
     * @param array<string,mixed> $entry
     * @return self::CURATED|self::GAME_HALL|self::GATEWAY
     */
    public static function classify(array $entry): string
    {
        if (!empty($entry['curation']['curated'])) {
            return self::CURATED;
        }

        if (($entry['category'] ?? null) === 'gateway') {
            return self::GATEWAY;
        }

        return self::GAME_HALL;
    }

    /**
     * Partition a list of entries into ordered shelves.
     *
     * @param iterable<array<string,mixed>> $entries
     * @return array{curated:list<array<string,mixed>>,game_hall:list<array<string,mixed>>,gateway:list<array<string,mixed>>}
     */
    public static function group(iterable $entries): array
    {
        $shelves = [
            self::CURATED => [],
            self::GAME_HALL => [],
            self::GATEWAY => [],
        ];

        foreach ($entries as $entry) {
            $shelves[self::classify($entry)][] = $entry;
        }

        $shelves[self::CURATED] = self::sortByCuratedOrder($shelves[self::CURATED]);

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
            $order = $entry['curation']['order'] ?? null;
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
