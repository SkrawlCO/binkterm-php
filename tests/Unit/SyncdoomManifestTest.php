<?php

declare(strict_types=1);

use BinktermPHP\CrossroadsShelves;
use BinktermPHP\ExperiencePresentation;
use BinktermPHP\GameCatalog;
use BinktermPHP\NativeDoorManager;
use PHPUnit\Framework\TestCase;

/**
 * SyncDOOM's Game Hall placement.
 *
 * SyncDOOM's engineering (single-player, Create/Join, Co-op/Deathmatch/
 * Altdeath, the registry-cleanup fix) is complete and already covered by
 * {@see SyncdoomMultiplayerWrapperTest}. This is a distinct, later product
 * decision: SyncDOOM belongs on Crossroads' Game Hall shelf, not Curated
 * Experiences. These tests assert only that placement -- discoverable to an
 * ordinary member, classified as Game Hall (never curated), launching
 * through the existing wrapper, with its custom icon wired up and Curated
 * Experiences/neighboring Game Hall entries left untouched.
 */
final class SyncdoomManifestTest extends TestCase
{
    private string $doorDir;
    /** @var array<string,mixed> */
    private array $rawManifest;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        $this->doorDir = $root . '/native-doors/doors/syncdoom';

        $manifestPath = $this->doorDir . '/nativedoor.json';
        self::assertFileExists($manifestPath, 'syncdoom manifest is missing');

        $decoded = json_decode((string)file_get_contents($manifestPath), true);
        self::assertIsArray($decoded, 'syncdoom manifest is not valid JSON');
        $this->rawManifest = $decoded;
    }

    public function testManifestParsesAndIdentifiesSyncdoom(): void
    {
        $door = (new NativeDoorManager())->getDoor('syncdoom');

        self::assertIsArray($door, 'NativeDoorManager could not parse the syncdoom manifest');
        self::assertSame('syncdoom', $door['door_id']);
        self::assertSame('SyncDOOM', $door['name']);
        // Not renamed after the shipped IWAD -- Freedoom Phase 2 is content,
        // SyncDOOM is the Experience identity.
        self::assertStringNotContainsStringIgnoringCase('freedoom', $door['name']);
    }

    public function testDoorIsOpenToOrdinaryMembersNotJustAdmins(): void
    {
        $door = (new NativeDoorManager())->getDoor('syncdoom');

        self::assertFalse($door['admin_only'], 'a completed Game Hall entry must not still be admin-only');
        self::assertTrue((bool)($door['config']['enabled'] ?? false));
    }

    public function testLaunchStillGoesThroughTheCompletedWrapperUnchanged(): void
    {
        $launch = (string)($this->rawManifest['door']['launch_command'] ?? '');

        self::assertSame('/bin/bash syncdoom-mp.sh', $launch);
        self::assertFileExists($this->doorDir . '/syncdoom-mp.sh');

        // The accepted rendering/session contract is untouched by catalog work.
        self::assertSame('cp437', $this->rawManifest['door']['output_encoding'] ?? null);
        self::assertSame('raw', $this->rawManifest['door']['terminal_mode'] ?? null);
        self::assertSame('DOOR32.SYS', $this->rawManifest['door']['dropfile_format'] ?? null);
    }

    public function testManifestDeclaresGameHallAppropriateMetadata(): void
    {
        self::assertSame('game', $this->rawManifest['experience']['category'] ?? null);
        self::assertTrue((bool)($this->rawManifest['experience']['multiplayer'] ?? false));

        // Not a Curated-Experiences claim, and no rename to the IWAD.
        self::assertStringNotContainsStringIgnoringCase('curated', $this->rawManifest['game']['description'] ?? '');
        self::assertStringContainsString('SyncDOOM', $this->rawManifest['game']['name'] ?? '');
    }

    public function testCustomIconIsWiredUpAndMeetsTheCanonicalAssetSpec(): void
    {
        $icon = (string)($this->rawManifest['game']['icon'] ?? '');
        self::assertNotSame('', $icon, 'SyncDOOM should carry a custom icon now that it is a Game Hall entry');

        $iconPath = $this->doorDir . '/' . $icon;
        self::assertFileExists($iconPath);

        $info = getimagesize($iconPath);
        self::assertNotFalse($info, 'icon is not a readable image');
        [$width, $height, $type] = $info;
        self::assertSame(512, $width, 'canonical raster icon width is 512px');
        self::assertSame(512, $height, 'canonical raster icon height is 512px');
        self::assertSame(IMAGETYPE_PNG, $type, 'canonical raster icon format is PNG');
        // Keep it small (docs/NativeDoors.md: ~0.3-0.6MB is normal for a
        // painterly 512px PNG; this one is a flat-color pixel-art design and
        // should compress well under that).
        self::assertLessThan(700_000, filesize($iconPath), 'icon.png is unexpectedly large');
    }

    public function testIconAssetRouteResolvesTheSameFile(): void
    {
        $door = (new NativeDoorManager())->getDoor('syncdoom');
        self::assertSame('icon.png', $door['icon'] ?? null);
    }

    public function testDiscoverableToAnOrdinaryMemberOnTheWebSurface(): void
    {
        $catalog = new GameCatalog();
        $experiences = $catalog->getEnabledGames(['is_admin' => false], 'web');

        self::assertArrayHasKey('syncdoom', $experiences, 'syncdoom must be discoverable to an ordinary member on web');
        self::assertSame('full', $experiences['syncdoom']['surfaces']['web']);
    }

    public function testClassifiesAsGameHallNeverCurated(): void
    {
        $catalog = new GameCatalog();
        $experiences = $catalog->getEnabledGames(['is_admin' => false], 'web');
        self::assertArrayHasKey('syncdoom', $experiences);

        $entry = $experiences['syncdoom'];
        self::assertFalse($entry['curation']['curated'], 'SyncDOOM must not be curated -- Game Hall placement is deliberate');
        self::assertSame(CrossroadsShelves::GAME_HALL, CrossroadsShelves::classify($entry));

        $shelves = CrossroadsShelves::compose($experiences);
        $shelvesByKey = [];
        foreach ($shelves as $shelf) {
            $shelvesByKey[$shelf['key']] = array_column($shelf['entries'], 'id');
        }

        self::assertContains('syncdoom', $shelvesByKey[CrossroadsShelves::GAME_HALL]);
        self::assertNotContains('syncdoom', $shelvesByKey[CrossroadsShelves::CURATED]);
        self::assertNotContains('syncdoom', $shelvesByKey[CrossroadsShelves::GATEWAY]);
    }

    public function testExistingCuratedExperiencesAndNeighboringGameHallEntriesAreUnaffected(): void
    {
        $catalog = new GameCatalog();
        $experiences = $catalog->getEnabledGames(['is_admin' => false], 'web');
        $shelves = CrossroadsShelves::compose($experiences);

        $curatedIds = [];
        $gameHallIds = [];
        foreach ($shelves as $shelf) {
            if ($shelf['key'] === CrossroadsShelves::CURATED) {
                $curatedIds = array_column($shelf['entries'], 'id');
            }
            if ($shelf['key'] === CrossroadsShelves::GAME_HALL) {
                $gameHallIds = array_column($shelf['entries'], 'id');
            }
        }

        // The operator's curated list is untouched by this transaction.
        foreach (['multizork', 'ascii-royale-m3', 'openglad', 'chessmata', 'tournament-trivia'] as $id) {
            if (isset($experiences[$id])) {
                self::assertContains($id, $curatedIds, "$id should remain curated");
            }
        }

        // A representative pre-existing Game Hall entry is still present
        // alongside the new one.
        if (isset($experiences['tristam'])) {
            self::assertContains('tristam', $gameHallIds);
        }
    }

    public function testPresentationViewCardCopyFitsGameHallConventions(): void
    {
        $catalog = new GameCatalog();
        $experiences = $catalog->getEnabledGames(['is_admin' => false], 'web');
        $view = ExperiencePresentation::build($experiences['syncdoom'], 'web');

        self::assertSame('multiplayer', $view['capabilities']['player_mode']);
        self::assertNotSame('', trim($view['description']));
        self::assertStringNotContainsString('DOOR32.SYS', $view['description']);
        self::assertStringNotContainsString('UDP', $view['description']);
        self::assertStringNotContainsStringIgnoringCase('wrapper', $view['description']);
        self::assertSame('/door-assets/syncdoom/icon', $view['presentation']['icon_url']);

        // Public/anonymous projection carries no identity but the same copy
        // and icon -- this is what the logged-out Crossroads page shows.
        $publicView = ExperiencePresentation::buildPublic($experiences['syncdoom'], 'web');
        self::assertSame($view['description'], $publicView['description']);
        self::assertSame($view['presentation']['icon_url'], $publicView['presentation']['icon_url']);
    }
}
