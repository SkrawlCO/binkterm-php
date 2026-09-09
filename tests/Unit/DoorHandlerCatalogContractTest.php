<?php

declare(strict_types=1);

use BinktermPHP\TelnetServer\DoorHandler;
use BinktermPHP\TelnetServer\LineShell;
use BinktermPHP\TelnetServer\TelnetUtils;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../telnet/src/DoorHandler.php';
require_once __DIR__ . '/../../telnet/src/TerminalShellInterface.php';
require_once __DIR__ . '/../../telnet/src/LineShell.php';

final class DoorHandlerCatalogContractTest extends TestCase
{
    public function testDirectoryRowConsumesCanonicalCatalogMetadata(): void
    {
        $row = DoorHandler::buildExperienceDirectoryRow('doorparty', [
            'name' => 'DoorParty',
            'description' => 'A remote door gateway.',
            'category' => 'gateway',
            'capabilities' => ['multiplayer' => true],
            'policy' => ['credit_cost' => 7],
        ]);

        // Name is the label; the gateway signal is a compact badge; the catalog
        // description becomes the row's secondary context (never in the label).
        self::assertSame('DoorParty', $row->label);
        self::assertSame('Gateway', $row->badge);
        self::assertSame('A remote door gateway.', $row->description);
        self::assertSame('doorparty', $row->value);
        self::assertStringNotContainsString('A remote door gateway.', $row->label);
        self::assertStringNotContainsString('credits', $row->label);
    }

    public function testDirectoryRowShowsCanonicalMultiplayerCapabilityForGames(): void
    {
        $row = DoorHandler::buildExperienceDirectoryRow('lord', [
            'name' => 'Legend of the Red Dragon',
            'description' => 'Fantasy RPG.',
            'category' => 'game',
            'capabilities' => ['multiplayer' => true],
            'policy' => ['credit_cost' => 0],
        ]);

        self::assertSame('Legend of the Red Dragon', $row->label);
        self::assertSame('Multiplayer', $row->badge);
        self::assertSame('Fantasy RPG.', $row->description);
    }

    public function testDirectoryRowHasNoBadgeForASinglePlayerGame(): void
    {
        $row = DoorHandler::buildExperienceDirectoryRow('nethack', [
            'name' => 'NetHack',
            'description' => 'Dungeon crawl.',
            'category' => 'game',
            'capabilities' => ['multiplayer' => false],
        ]);

        self::assertSame('NetHack', $row->label);
        self::assertNull($row->badge);
        self::assertSame('Dungeon crawl.', $row->description);
    }

    public function testDirectoryRowFallsBackWhenOptionalMetadataIsAbsent(): void
    {
        $row = DoorHandler::buildExperienceDirectoryRow('minimal-door', []);

        self::assertSame('minimal-door', $row->label);
        self::assertNull($row->badge);
        self::assertNull($row->description);
        self::assertSame('minimal-door', $row->value);
    }

    public function testCatalogIsGroupedIntoCanonicalCrossroadsShelves(): void
    {
        $method = new ReflectionMethod(DoorHandler::class, 'buildDestinationShelves');
        $method->setAccessible(true);

        $doorList = [
            ['id' => 'lord', 'data' => ['name' => 'LORD', 'category' => 'game', 'capabilities' => ['multiplayer' => true]]],
            ['id' => 'gb', 'data' => ['name' => 'Galactic Bloodshed', 'category' => 'game']],
            ['id' => 'doorparty', 'data' => ['name' => 'DoorParty', 'category' => 'gateway']],
            ['id' => 'ascii-royale', 'data' => ['name' => 'ascii-royale', 'category' => 'game', 'curation' => ['curated' => true, 'order' => 1]]],
        ];

        $t = static fn (string $key, array $params = [], string $fallback = ''): string => $fallback;
        $result = $method->invoke(null, $doorList, $t);

        $titles = array_map(static fn ($s) => $s->title, $result['sections']);
        self::assertSame(['Curated Experiences', 'Game Hall', 'Gateways'], $titles);

        // Curated shelf first, gateways last; the reordered doorList lines up
        // with the flattened section rows for the $selected - 2 contract.
        $ids = array_map(static fn ($e) => $e['id'], $result['doorList']);
        self::assertSame(['ascii-royale', 'lord', 'gb', 'doorparty'], $ids);

        $flatRowValues = [];
        foreach ($result['sections'] as $section) {
            foreach ($section->rows as $row) {
                $flatRowValues[] = $row->value;
            }
        }
        self::assertSame($ids, $flatRowValues);
    }

    public function testLineShellNormalizesSectionSeparatelyFromSelectableLabel(): void
    {
        $shell = (new ReflectionClass(LineShell::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(LineShell::class, 'normalizeListItem');
        $method->setAccessible(true);

        $row = $method->invoke($shell, [
            'section_before' => 'Experiences',
            'label' => 'BBSLink - Gateway',
            'detail' => '',
        ]);

        self::assertSame('Experiences', $row['section_before']);
        self::assertSame('BBSLink - Gateway', $row['label']);
        self::assertSame('', $row['detail']);
    }

    public function testTuiNormalizesSectionSeparatelyFromSelectableBlock(): void
    {
        $method = new ReflectionMethod(TelnetUtils::class, 'normalizeStructuredSelectableRow');
        $method->setAccessible(true);

        $block = $method->invoke(null, [
            'section_before' => 'Experiences',
            'label' => 'BBSLink - Gateway',
            'detail' => '',
        ], 80);

        self::assertSame('Experiences', $block['section_before']);
        self::assertSame('BBSLink - Gateway', $block['label']);
        self::assertSame(['BBSLink - Gateway'], $block['lines']);
    }

    public function testArrivalUsesCrossroadsTitleAndPreservesSyntheticItemOrder(): void
    {
        $source = (string)file_get_contents(__DIR__ . '/../../telnet/src/DoorHandler.php');
        $showStart = strpos($source, 'public function show(');
        $showEnd = strpos($source, 'public static function composeLiveNow(', $showStart);
        self::assertNotFalse($showStart);
        self::assertNotFalse($showEnd);
        $show = substr($source, $showStart, $showEnd - $showStart);

        self::assertStringContainsString("'ui.terminalserver.doors.title', [], 'Crossroads'", $show);
        $live = strpos($show, 'buildLiveNowArrivalItem');
        $places = strpos($show, 'buildYourPlacesArrivalItem');
        $shelves = strpos($show, 'buildDestinationShelves(');
        self::assertNotFalse($live);
        self::assertNotFalse($places);
        self::assertNotFalse($shelves);
        // Shelf ordering happens before the arrival rows are composed; the two
        // arrival rows still precede the destination sections.
        self::assertLessThan($places, $live);
        // The Live Now (0) / Your Places (1) / Experience ($selected - 2)
        // dispatch contract is unchanged; selection now comes from showDirectory.
        self::assertStringContainsString('$result = $shell->showDirectory(', $show);
        self::assertStringContainsString("(\$result['action'] ?? '') === 'select' ? (int)\$result['index'] : null", $show);
        self::assertStringContainsString('if ($selected === 0)', $show);
        self::assertStringContainsString('if ($selected === 1)', $show);
        self::assertStringContainsString('$entry = $doorList[$selected - 2]', $show);
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
