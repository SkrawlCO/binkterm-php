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

        self::assertSame('DoorParty [7 credits]', $item['label']);
        self::assertSame('A remote door gateway. [Gateway]', $item['detail']);
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

        self::assertSame(
            'Fantasy RPG. [Game / Multiplayer]',
            $item['detail']
        );
    }

    public function testChooserItemFallsBackWhenOptionalMetadataIsAbsent(): void
    {
        $item = DoorHandler::buildExperienceListItem('minimal-door', []);

        self::assertSame('minimal-door', $item['label']);
        self::assertSame('[Game]', $item['detail']);
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
