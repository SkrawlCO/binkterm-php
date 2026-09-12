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
        self::assertNull($entries[0]['experience_presentation']['runtime']['active']);
        self::assertSame(['wordwright', 'hangman', 'blackjack', 'parlour', 'tatham/lightup', 'breaklock', 'ordinary-puzzles', 'dokuel'], array_column($definitions[0]['members'], 'reference'));
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
