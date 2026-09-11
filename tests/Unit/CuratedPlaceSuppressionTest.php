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
        foreach (['wordwright', 'hangman', 'blackjack', 'klondike-solitaire', 'tatham'] as $id) {
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
        self::assertCount(2, $entries);
        self::assertSame('tatham', $entries[0]['id']);
        self::assertSame('place', $entries[1]['kind']);
        $shelves = CrossroadsShelves::compose($entries);
        self::assertSame(['curated' => 1, 'game_hall' => 1, 'utility' => 0, 'gateway' => 0], array_column($shelves, 'count', 'key'));
        self::assertSame($before, $games);
        foreach (array_slice($games, 0, 4) as $game) {
            self::assertSame('/games/' . $game['id'], ExperienceLaunch::resolve($game, 'web')['url']);
        }
        self::assertNull($entries[1]['experience_presentation']['runtime']['active']);
        self::assertSame(['wordwright', 'hangman', 'blackjack', 'klondike-solitaire', 'tatham/lightup', 'breaklock', 'ordinary-puzzles'], array_column($definitions[0]['members'], 'reference'));
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
        self::assertSame(['wordwright', 'blackjack', 'klondike-solitaire', 'tatham'], array_column($result, 'id'));
        self::assertCount(5, $result);
        $games[0]['policy']['enabled'] = false;
        $definitions[0]['members'] = [['reference' => 'wordwright', 'primary_presentation' => true]];
        self::assertSame('wordwright', CuratedPlacePresentation::shelfEntries($games, $definitions)[0]['id']);
    }
}
