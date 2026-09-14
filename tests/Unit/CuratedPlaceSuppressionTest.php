<?php

declare(strict_types=1);

use BinktermPHP\CuratedPlaceCatalog;
use BinktermPHP\CuratedPlacePresentation;
use BinktermPHP\CrossroadsShelves;
use BinktermPHP\ExperienceLaunch;
use BinktermPHP\ExperiencePresentation;
use PHPUnit\Framework\TestCase;

final class CuratedPlaceSuppressionTest extends TestCase
{
    private function games(): array
    {
        $games = [];
        foreach (['wordwright', 'hangman', 'blackjack', 'parlour', 'tatham'] as $id) {
            $row = ['id' => $id, 'name' => $id, 'category' => 'game',
                'backend' => ['type' => 'web', 'id' => $id === 'tatham' ? 'tatham-web' : $id],
                'surfaces' => ['web' => 'full', 'telnet' => 'planned'],
                'source' => ['manifest' => ['experience' => ['default_entry' => 'lightup']]],
            ];
            $row['experience_presentation'] = ExperiencePresentation::build($row, 'web');
            $games[] = $row;
        }
        return $games;
    }

    public function testOnlyRootPresentationChangesAndCountsAreCorrect(): void
    {
        $games = $this->games();
        $before = $games;
        $definitions = (new CuratedPlaceCatalog())->getDefinitions();
        $entries = CuratedPlacePresentation::shelfEntries($games, $definitions);
        self::assertCount(1, $entries);
        self::assertSame('place', $entries[0]['kind']);
        $shelves = CrossroadsShelves::compose($entries);
        self::assertSame(['curated' => 1, 'game_hall' => 0, 'utility' => 0, 'gateway' => 0], array_column($shelves, 'count', 'key'));
        self::assertSame($before, $games);
        foreach (array_slice($games, 0, 4) as $game) {
            self::assertSame('/games/' . $game['id'], ExperienceLaunch::resolve($game, 'web')['url']);
        }
        // No member carries any real state in this fixture, so the aggregate
        // is a truthful "nobody's active" rather than "unknown" -- runtime is
        // always supplied for a place card now (see testPlaceCardAggregatesLivePresence).
        self::assertFalse($entries[0]['experience_presentation']['runtime']['active']);
        self::assertSame(0, $entries[0]['experience_presentation']['runtime']['player_count']);
        self::assertSame(['lastword', 'wordwright', 'hangman', 'blackjack', 'parlour', 'tatham/lightup', 'breaklock', 'ordinary-puzzles', 'dokuel', 'everest'], array_column($definitions[0]['members'], 'reference'));
    }

    public function testDisabledOrNonCuratedPlaceCannotHideAnything(): void
    {
        $definitions = (new CuratedPlaceCatalog())->getDefinitions();
        $definitions[0]['enabled'] = false;
        self::assertSame($this->games(), CuratedPlacePresentation::shelfEntries($this->games(), $definitions));
        $definitions[0]['enabled'] = true;
        $definitions[0]['parent'] = 'elsewhere';
        self::assertSame($this->games(), CuratedPlacePresentation::shelfEntries($this->games(), $definitions));
    }

    public function testUnresolvableMembersCannotHideAnUnrelatedRuntime(): void
    {
        $definitions = (new CuratedPlaceCatalog())->getDefinitions();
        $definitions[0]['members'] = [
            ['reference' => 'tatham/slant', 'primary_presentation' => true],
            ['reference' => 'missing', 'primary_presentation' => true],
            ['reference' => 'hangman', 'primary_presentation' => true],
            ['reference' => 'wordwright', 'primary_presentation' => false],
        ];
        $games = $this->games();
        unset($games[1]); // Authorization/discovery omitted Hangman.
        $result = CuratedPlacePresentation::shelfEntries($games, $definitions);
        self::assertSame(['wordwright', 'blackjack', 'parlour', 'tatham'], array_column($result, 'id'));
        self::assertCount(5, $result);
        $games[0]['policy']['enabled'] = false;
        $definitions[0]['members'] = [['reference' => 'wordwright', 'primary_presentation' => true]];
        self::assertSame('wordwright', CuratedPlacePresentation::shelfEntries($games, $definitions)[0]['id']);
    }
    /**
     * A place matches a surface filter when at least one of its members is
     * genuinely available on that surface -- any-member, not all-member --
     * and its primary_presentation members still never duplicate into the
     * top-level runtime catalog.
     */
    public function testPlaceCardSurfacesAggregateAnyMemberAvailability(): void
    {
        $games = $this->games();
        $games[0]['surfaces']['telnet'] = 'full'; // wordwright: genuinely Telnet-capable
        $games[0]['experience_presentation'] = ExperiencePresentation::build($games[0], 'web');
        // hangman ($games[1]) stays web-only (telnet 'planned', not 'full').
        $definitions = (new CuratedPlaceCatalog())->getDefinitions();
        $definitions[0]['members'] = [
            ['reference' => 'wordwright', 'primary_presentation' => true],
            ['reference' => 'hangman', 'primary_presentation' => true],
        ];
        $entries = CuratedPlacePresentation::shelfEntries($games, $definitions);
        self::assertNotContains('wordwright', array_column($entries, 'id'));
        self::assertNotContains('hangman', array_column($entries, 'id'));
        self::assertSame(['blackjack', 'parlour', 'tatham'], array_column($entries, 'id'));
        $placeCards = array_values(array_filter($entries, static fn (array $e): bool => ($e['kind'] ?? null) === 'place'));
        self::assertCount(1, $placeCards);
        self::assertSame('full', $placeCards[0]['experience_presentation']['surfaces']['web']);
        self::assertSame('full', $placeCards[0]['experience_presentation']['surfaces']['telnet']);
    }

    /**
     * A place is live when at least one member has active callers, and
     * player_count is the SUM across members (not just a boolean) -- reusing
     * each member's own already-computed runtime state, never a second
     * presence query. Surface aggregation from the earlier fix must remain
     * correct alongside this.
     */
    public function testPlaceCardAggregatesLivePresence(): void
    {
        $games = $this->games();
        // wordwright: 1 active player, telnet-capable (surface aggregation still exercised).
        $games[0]['surfaces']['telnet'] = 'full';
        $games[0]['experience_presentation'] = ExperiencePresentation::build($games[0], 'web', ['active' => true, 'player_count' => 1]);
        // hangman: 2 active players.
        $games[1]['experience_presentation'] = ExperiencePresentation::build($games[1], 'web', ['active' => true, 'player_count' => 2]);
        // blackjack: present but nobody active (explicit zero state).
        $games[2]['experience_presentation'] = ExperiencePresentation::build($games[2], 'web', ['active' => false, 'player_count' => 0]);

        $definitions = (new CuratedPlaceCatalog())->getDefinitions();
        $definitions[0]['members'] = [
            ['reference' => 'wordwright', 'primary_presentation' => true],
            ['reference' => 'hangman', 'primary_presentation' => true],
            ['reference' => 'blackjack', 'primary_presentation' => true],
        ];

        $entries = CuratedPlacePresentation::shelfEntries($games, $definitions);

        // Members stay suppressed top-level -- no duplication.
        self::assertNotContains('wordwright', array_column($entries, 'id'));
        self::assertNotContains('hangman', array_column($entries, 'id'));
        self::assertNotContains('blackjack', array_column($entries, 'id'));
        self::assertSame(['parlour', 'tatham'], array_column($entries, 'id'));

        $placeCards = array_values(array_filter($entries, static fn (array $e): bool => ($e['kind'] ?? null) === 'place'));
        self::assertCount(1, $placeCards);
        $runtime = $placeCards[0]['experience_presentation']['runtime'];
        self::assertTrue($runtime['active']);
        self::assertSame(3, $runtime['player_count']);

        // Surface aggregation (prior fix) is unaffected by this change.
        self::assertSame('full', $placeCards[0]['experience_presentation']['surfaces']['telnet']);
    }

    public function testPlaceCardReportsQuietWhenNoMemberIsActive(): void
    {
        $games = $this->games();
        $definitions = (new CuratedPlaceCatalog())->getDefinitions();
        $definitions[0]['members'] = [
            ['reference' => 'wordwright', 'primary_presentation' => true],
            ['reference' => 'hangman', 'primary_presentation' => true],
        ];

        $entries = CuratedPlacePresentation::shelfEntries($games, $definitions);
        $placeCards = array_values(array_filter($entries, static fn (array $e): bool => ($e['kind'] ?? null) === 'place'));
        $runtime = $placeCards[0]['experience_presentation']['runtime'];
        self::assertFalse($runtime['active']);
        self::assertSame(0, $runtime['player_count']);
    }

    public function testExplicitOwnershipKeepsIntentionalGameHallAndDirectLaunches(): void
    {
        $ids = ['doom', 'duke3d', 'galacticbloodshed', 'lord', 'usurper-reborn', 'wordle', 'breaklock', 'ordinary-puzzles', 'tatham', 'dokuel'];
        $games = [];
        foreach ($ids as $id) {
            $games[] = ['id' => $id, 'name' => $id, 'category' => 'game',
                'backend' => ['type' => 'web', 'id' => $id],
                'surfaces' => ['web' => 'full', 'telnet' => 'full'],
                'source' => ['manifest' => ['experience' => ['default_entry' => 'lightup']]],
            ];
        }
        $definitions = (new CuratedPlaceCatalog())->getDefinitions();
        $before = $games;
        $visible = CuratedPlacePresentation::runtimeEntries($games, $definitions);
        self::assertSame(array_slice($ids, 0, 6), array_column(CrossroadsShelves::group($visible)['game_hall'], 'id'));
        self::assertSame($before, $games);
        foreach (array_slice($games, 6) as $game) {
            self::assertNotNull(ExperienceLaunch::resolve($game, 'web'));
            $reference = $game['id'] === 'tatham' ? 'tatham/lightup' : $game['id'];
            self::assertNotNull(CuratedPlaceCatalog::resolveReference($reference, array_column($games, null, 'id'), 'web'));
        }
        foreach ($definitions[0]['members'] as &$member) {
            if ($member['reference'] === 'dokuel') $member['primary_presentation'] = false;
        }
        unset($member);
        self::assertContains('dokuel', array_column(CuratedPlacePresentation::runtimeEntries($games, $definitions), 'id'));
        self::assertNotNull(CuratedPlaceCatalog::resolveReference('dokuel', array_column($games, null, 'id'), 'web'));
    }

}
