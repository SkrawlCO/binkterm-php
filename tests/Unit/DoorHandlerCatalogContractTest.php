<?php

declare(strict_types=1);

use BinktermPHP\TelnetServer\DoorHandler;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../telnet/src/DoorHandler.php';

final class DoorHandlerCatalogContractTest extends TestCase
{
    public function testChooserItemConsumesCanonicalCatalogMetadata(): void
    {
        $item = DoorHandler::buildExperienceListItem('doorparty', [
            'name' => 'DoorParty',
            'description' => 'A remote door gateway.',
            'category' => 'gateway',
            'capabilities' => ['multiplayer' => true],
            'policy' => ['credit_cost' => 7],
        ]);

        self::assertSame('DoorParty - Gateway', $item['label']);
        self::assertSame('', $item['detail']);
        self::assertStringNotContainsString('A remote door gateway.', $item['label']);
        self::assertStringNotContainsString('credits', $item['label']);
    }

    public function testChooserItemShowsCanonicalMultiplayerCapabilityForGames(): void
    {
        $item = DoorHandler::buildExperienceListItem('lord', [
            'name' => 'Legend of the Red Dragon',
            'description' => 'Fantasy RPG.',
            'category' => 'game',
            'capabilities' => ['multiplayer' => true],
            'policy' => ['credit_cost' => 0],
        ]);

        self::assertSame('Legend of the Red Dragon - Multiplayer', $item['label']);
        self::assertSame('', $item['detail']);
        self::assertStringNotContainsString('Fantasy RPG.', $item['label']);
    }

    public function testChooserItemFallsBackWhenOptionalMetadataIsAbsent(): void
    {
        $item = DoorHandler::buildExperienceListItem('minimal-door', []);

        self::assertSame('minimal-door - Game', $item['label']);
        self::assertSame('', $item['detail']);
    }

    public function testFirstCatalogItemCarriesLightweightExperiencesSectionCue(): void
    {
        $item = DoorHandler::buildExperienceListItem('doorparty', [
            'name' => 'DoorParty',
            'category' => 'gateway',
        ], null, true);

        self::assertSame('Experiences - DoorParty - Gateway', $item['label']);
        self::assertSame('', $item['detail']);
    }

    public function testArrivalUsesCrossroadsTitleAndPreservesSyntheticItemOrder(): void
    {
        $source = (string)file_get_contents(__DIR__ . '/../../telnet/src/DoorHandler.php');
        $showStart = strpos($source, 'public function show(');
        $showEnd = strpos($source, 'public static function composeLiveNow(', $showStart);
        self::assertNotFalse($showStart);
        self::assertNotFalse($showEnd);
        $show = substr($source, $showStart, $showEnd - $showStart);

        self::assertStringContainsString("doors.title', 'Crossroads'", $show);
        $live = strpos($show, 'buildLiveNowArrivalItem');
        $places = strpos($show, 'buildYourPlacesArrivalItem');
        $catalog = strpos($show, 'buildExperienceListItem');
        self::assertNotFalse($live);
        self::assertNotFalse($places);
        self::assertNotFalse($catalog);
        self::assertLessThan($places, $live);
        self::assertLessThan($catalog, $places);
        self::assertStringContainsString('$catalogIndex === 0', $show);
    }

    public function testRawNativeExperienceUsesRawRelayMode(): void
    {
        self::assertSame('raw', DoorHandler::resolveTerminalMode([
            'backend' => ['type' => 'native', 'id' => 'lord'],
            'terminal' => ['mode' => 'raw'],
        ]));
    }

    public function testMissingOrUnknownTerminalModeFallsBackToDoorway(): void
    {
        self::assertSame('doorway', DoorHandler::resolveTerminalMode([]));
        self::assertSame('doorway', DoorHandler::resolveTerminalMode([
            'terminal' => ['mode' => 'unexpected'],
        ]));
    }
}
