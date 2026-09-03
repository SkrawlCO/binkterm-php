<?php

declare(strict_types=1);

use BinktermPHP\CrossroadsShelves;
use PHPUnit\Framework\TestCase;

/**
 * Pure classification contract for the Crossroads Curated Catalog shelves.
 * No Twig, no catalog I/O — entries are plain arrays shaped like a normalized
 * catalog row / presentation view (category + curation block).
 */
final class CrossroadsShelvesTest extends TestCase
{
    /** @param array{curated?:bool,order?:int|null} $curation */
    private function entry(string $id, string $category = 'game', array $curation = []): array
    {
        return [
            'id' => $id,
            'category' => $category,
            'curation' => [
                'curated' => $curation['curated'] ?? false,
                'order' => $curation['order'] ?? null,
            ],
        ];
    }

    public function testOrdinaryGameGoesToGameHall(): void
    {
        self::assertSame(
            CrossroadsShelves::GAME_HALL,
            CrossroadsShelves::classify($this->entry('lord'))
        );
    }

    public function testGatewayCategoryGoesToGateway(): void
    {
        self::assertSame(
            CrossroadsShelves::GATEWAY,
            CrossroadsShelves::classify($this->entry('doorparty', 'gateway'))
        );
    }

    public function testCuratedGameGoesToCurated(): void
    {
        self::assertSame(
            CrossroadsShelves::CURATED,
            CrossroadsShelves::classify(
                $this->entry('openglad', 'game', ['curated' => true, 'order' => 0])
            )
        );
    }

    public function testCurationBeatsGatewayCategory(): void
    {
        // An operator may curate something that is otherwise a gateway.
        self::assertSame(
            CrossroadsShelves::CURATED,
            CrossroadsShelves::classify(
                $this->entry('doorparty', 'gateway', ['curated' => true, 'order' => 1])
            )
        );
    }

    public function testMissingCurationBlockIsTreatedAsNotCurated(): void
    {
        self::assertSame(
            CrossroadsShelves::GAME_HALL,
            CrossroadsShelves::classify(['id' => 'x', 'category' => 'game'])
        );
        self::assertSame(
            CrossroadsShelves::GATEWAY,
            CrossroadsShelves::classify(['id' => 'y', 'category' => 'gateway'])
        );
    }

    public function testGroupPartitionsAndOrdersEveryEntry(): void
    {
        $entries = [
            $this->entry('lord'),
            $this->entry('bcrgames', 'gateway'),
            $this->entry('openglad', 'game', ['curated' => true, 'order' => 2]),
            $this->entry('blackjack'),
            $this->entry('bbslinknative', 'gateway'),
            $this->entry('multizork', 'game', ['curated' => true, 'order' => 0]),
            $this->entry('ascii-royale-m3', 'game', ['curated' => true, 'order' => 1]),
        ];

        $shelves = CrossroadsShelves::group($entries);

        self::assertSame(
            ['multizork', 'ascii-royale-m3', 'openglad'],
            array_column($shelves[CrossroadsShelves::CURATED], 'id'),
            'curated shelf follows operator order, not input order'
        );
        self::assertSame(
            ['lord', 'blackjack'],
            array_column($shelves[CrossroadsShelves::GAME_HALL], 'id'),
            'game hall preserves input order'
        );
        self::assertSame(
            ['bcrgames', 'bbslinknative'],
            array_column($shelves[CrossroadsShelves::GATEWAY], 'id'),
            'gateway shelf preserves input order'
        );

        // Nothing lost, nothing duplicated.
        $total = count($shelves[CrossroadsShelves::CURATED])
            + count($shelves[CrossroadsShelves::GAME_HALL])
            + count($shelves[CrossroadsShelves::GATEWAY]);
        self::assertSame(count($entries), $total);
    }

    public function testGroupAlwaysReturnsAllThreeShelfKeys(): void
    {
        $shelves = CrossroadsShelves::group([]);

        self::assertSame(
            [
                CrossroadsShelves::CURATED,
                CrossroadsShelves::GAME_HALL,
                CrossroadsShelves::GATEWAY,
            ],
            array_keys($shelves)
        );
        self::assertSame([], $shelves[CrossroadsShelves::CURATED]);
        self::assertSame([], $shelves[CrossroadsShelves::GAME_HALL]);
        self::assertSame([], $shelves[CrossroadsShelves::GATEWAY]);
    }

    public function testCuratedEntriesWithoutNumericOrderSortLast(): void
    {
        $entries = [
            $this->entry('c', 'game', ['curated' => true, 'order' => null]),
            $this->entry('a', 'game', ['curated' => true, 'order' => 5]),
            $this->entry('b', 'game', ['curated' => true, 'order' => 5]),
        ];

        $shelves = CrossroadsShelves::group($entries);

        self::assertSame(
            ['a', 'b', 'c'],
            array_column($shelves[CrossroadsShelves::CURATED], 'id'),
            'equal orders keep input order; missing order sorts last'
        );
    }

    public function testGroupAcceptsAGenerator(): void
    {
        $gen = (function () {
            yield $this->entry('lord');
            yield $this->entry('multizork', 'game', ['curated' => true, 'order' => 0]);
        })();

        $shelves = CrossroadsShelves::group($gen);

        self::assertSame(['multizork'], array_column($shelves[CrossroadsShelves::CURATED], 'id'));
        self::assertSame(['lord'], array_column($shelves[CrossroadsShelves::GAME_HALL], 'id'));
    }

    // ---- route wrappers: { experience_presentation: view, ... } ----

    /** @param array{curated?:bool,order?:int|null} $curation */
    private function wrapped(string $id, string $category = 'game', array $curation = []): array
    {
        return [
            'id' => $id,
            'experience_presentation' => [
                'id' => $id,
                'category' => $category,
                'curation' => [
                    'curated' => $curation['curated'] ?? false,
                    'order' => $curation['order'] ?? null,
                ],
            ],
        ];
    }

    public function testClassifyReadsTheNestedPresentationView(): void
    {
        self::assertSame(
            CrossroadsShelves::GATEWAY,
            CrossroadsShelves::classify($this->wrapped('doorparty', 'gateway'))
        );
        self::assertSame(
            CrossroadsShelves::CURATED,
            CrossroadsShelves::classify(
                $this->wrapped('openglad', 'game', ['curated' => true, 'order' => 0])
            )
        );
    }

    public function testGroupOrdersCuratedWrappersByOperatorOrder(): void
    {
        $entries = [
            $this->wrapped('openglad', 'game', ['curated' => true, 'order' => 2]),
            $this->wrapped('multizork', 'game', ['curated' => true, 'order' => 0]),
            $this->wrapped('ascii-royale-m3', 'game', ['curated' => true, 'order' => 1]),
            $this->wrapped('lord'),
        ];

        $grouped = CrossroadsShelves::group($entries);

        self::assertSame(
            ['multizork', 'ascii-royale-m3', 'openglad'],
            array_column($grouped[CrossroadsShelves::CURATED], 'id')
        );
        self::assertSame(['lord'], array_column($grouped[CrossroadsShelves::GAME_HALL], 'id'));
    }

    // ---- compose(): render-ready shelf list ----

    public function testComposeReturnsThreeShelvesInDisplayOrderWithPolicy(): void
    {
        $shelves = CrossroadsShelves::compose([]);

        self::assertCount(3, $shelves);
        self::assertSame(
            [CrossroadsShelves::CURATED, CrossroadsShelves::GAME_HALL, CrossroadsShelves::GATEWAY],
            array_column($shelves, 'key')
        );

        [$curated, $hall, $gateway] = $shelves;

        self::assertFalse($curated['collapsible']);
        self::assertTrue($curated['default_expanded']);

        self::assertTrue($hall['collapsible']);
        self::assertTrue($hall['default_expanded']);

        self::assertTrue($gateway['collapsible']);
        self::assertFalse($gateway['default_expanded']);

        foreach ($shelves as $shelf) {
            self::assertSame(0, $shelf['count']);
            self::assertSame([], $shelf['entries']);
        }
    }

    public function testComposePartitionsCountsAndOrdersALiveShapedCatalog(): void
    {
        $entries = [
            $this->entry('blackjack'),
            $this->entry('bcrgames', 'gateway'),
            $this->entry('openglad', 'game', ['curated' => true, 'order' => 2]),
            $this->entry('lord'),
            $this->entry('doorparty', 'gateway'),
            $this->entry('multizork', 'game', ['curated' => true, 'order' => 0]),
            $this->entry('ascii-royale-m3', 'game', ['curated' => true, 'order' => 1]),
        ];

        $shelves = CrossroadsShelves::compose($entries);
        $byKey = [];
        foreach ($shelves as $s) {
            $byKey[$s['key']] = $s;
        }

        self::assertSame(3, $byKey['curated']['count']);
        self::assertSame(
            ['multizork', 'ascii-royale-m3', 'openglad'],
            array_column($byKey['curated']['entries'], 'id')
        );
        self::assertSame(2, $byKey['game_hall']['count']);
        self::assertSame(['blackjack', 'lord'], array_column($byKey['game_hall']['entries'], 'id'));
        self::assertSame(2, $byKey['gateway']['count']);
        self::assertSame(['bcrgames', 'doorparty'], array_column($byKey['gateway']['entries'], 'id'));

        // Every entry lands on exactly one shelf.
        $total = array_sum(array_column($shelves, 'count'));
        self::assertSame(count($entries), $total);
    }
}
