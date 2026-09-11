<?php

declare(strict_types=1);

use BinktermPHP\CuratedPlaceCatalog;
use BinktermPHP\TelnetServer\DoorHandler;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../telnet/src/DoorHandler.php';
require_once __DIR__ . '/../../telnet/src/TelnetUtils.php';

final class CuratedPlaceTerminalTest extends TestCase
{
    public function testPlaceDirectoryEntryIsCuratedWithoutRuntimeFields(): void
    {
        $place = (new CuratedPlaceCatalog())->getDefinition('puzlmastrs-patch');
        $data = ['kind' => 'place', 'name' => $place['name'], 'description' => $place['description'],
            'curation' => ['curated' => true, 'order' => PHP_INT_MAX]];
        $method = new ReflectionMethod(DoorHandler::class, 'buildDestinationShelves');
        $result = $method->invoke(null, [['id' => $place['id'], 'data' => $data]], static fn ($key, $params, $fallback) => $fallback);
        self::assertSame('Curated Experiences', $result['sections'][0]->title);
        self::assertSame("Puzlmastr's Patch", $result['sections'][0]->rows[0]->label);
        self::assertArrayNotHasKey('backend', $result['doorList'][0]['data']);
        self::assertArrayNotHasKey('actions', $result['doorList'][0]['data']);
    }

    public function testPlaceLoopReusesSharedResolutionAndExistingLauncher(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/telnet/src/DoorHandler.php');
        $start = strpos($source, 'private function showPlace(');
        $end = strpos($source, '/** Three presentation lines', $start);
        $loop = substr($source, $start, $end - $start);
        self::assertStringContainsString("getPlace(\$parentPlaceId, \$user, 'telnet')", $loop);
        self::assertStringContainsString("if (\$member['launch'] === null)", $loop);
        self::assertStringContainsString("\$candidate['reference'] === \$member['reference']", $loop);
        self::assertStringContainsString("\$current['experience_id']", $loop);
        self::assertStringNotContainsString('showExperienceDetail(', $loop);
        self::assertStringNotContainsString('puzlmastrs-patch', $loop);
        self::assertStringNotContainsString("\$state['parent", $loop);
        self::assertStringContainsString("['common', 'terminalserver']", $loop);
    }
}
