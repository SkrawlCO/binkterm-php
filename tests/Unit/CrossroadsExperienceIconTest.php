<?php

declare(strict_types=1);

use BinktermPHP\GameCatalog;
use BinktermPHP\NativeDoorManager;
use PHPUnit\Framework\TestCase;

/**
 * Crossroads custom Experience icon contract.
 *
 * A custom icon is a normal onboarding/polish step for a new Experience. Native
 * doors declare it as `game.icon` in their manifest; the file lives in the door
 * directory and is served by GET /door-assets/{id}/icon (routes/door-routes.php).
 *
 * Canonical asset for painterly/raster artwork: an optimized 512x512 PNG,
 * square, RGB(A), well under a megabyte, original/licence-safe.
 *
 * This slice ships icons for Legend of the Red Dragon and Tristam Island, and
 * stages an Elsewhere icon that is intentionally NOT yet wired into the paused
 * Elsewhere Experience. ascii-royale (M4 Slice 3) ships its own custom icon —
 * an original terminal targeting-reticle mark — the same way.
 */
final class CrossroadsExperienceIconTest extends TestCase
{
    private const REPO_ROOT = __DIR__ . '/../..';

    /** Generous upper bound: "avoid multi-megabyte" — painterly 512px PNGs land ~0.5-0.6 MB. */
    private const MAX_ICON_BYTES = 1_000_000;

    /**
     * Read a PNG IHDR without any image extension.
     *
     * @return array{width:int,height:int,bit_depth:int,color_type:int,interlace:int}
     */
    private function readPngIhdr(string $path): array
    {
        $fh = fopen($path, 'rb');
        self::assertIsResource($fh, "cannot open {$path}");
        try {
            $sig = fread($fh, 8);
            self::assertSame("\x89PNG\r\n\x1a\n", $sig, "not a PNG: {$path}");
            // First chunk must be IHDR: 4-byte length, 4-byte type, 13-byte data.
            $len = unpack('N', (string)fread($fh, 4))[1];
            self::assertSame('IHDR', fread($fh, 4), "first chunk is not IHDR: {$path}");
            self::assertSame(13, $len, "IHDR length is not 13: {$path}");
            $ihdr = (string)fread($fh, 13);
        } finally {
            fclose($fh);
        }
        $u = unpack('Nwidth/Nheight/Cbit_depth/Ccolor_type/Ccompression/Cfilter/Cinterlace', $ihdr);

        return [
            'width' => $u['width'],
            'height' => $u['height'],
            'bit_depth' => $u['bit_depth'],
            'color_type' => $u['color_type'],
            'interlace' => $u['interlace'],
        ];
    }

    private function assertCanonicalIcon(string $path): void
    {
        self::assertFileExists($path);
        $bytes = filesize($path);
        self::assertGreaterThan(1024, $bytes, "icon is implausibly small: {$path}");
        self::assertLessThan(
            self::MAX_ICON_BYTES,
            $bytes,
            "icon exceeds the sane size budget ({$bytes} bytes): {$path}"
        );

        $ihdr = $this->readPngIhdr($path);
        self::assertSame(512, $ihdr['width'], "icon must be exactly 512px wide: {$path}");
        self::assertSame(512, $ihdr['height'], "icon must be exactly 512px tall: {$path}");
        self::assertSame(0, $ihdr['interlace'], "icon must be non-interlaced: {$path}");
        self::assertContains(
            $ihdr['color_type'],
            [2, 6], // 2 = RGB, 6 = RGBA
            "icon must be RGB or RGBA: {$path}"
        );
        self::assertSame(8, $ihdr['bit_depth'], "icon must be 8-bit: {$path}");
    }

    // ---- Legend of the Red Dragon ------------------------------------------

    public function testLordIconAssetIsCanonical(): void
    {
        $this->assertCanonicalIcon(self::REPO_ROOT . '/native-doors/doors/lord/icon.png');
    }

    public function testLordManifestDeclaresIcon(): void
    {
        $manifest = json_decode(
            (string)file_get_contents(self::REPO_ROOT . '/native-doors/doors/lord/nativedoor.json'),
            true
        );
        self::assertSame('icon.png', $manifest['game']['icon'] ?? null);

        $door = (new NativeDoorManager())->getDoor('lord');
        self::assertIsArray($door);
        self::assertSame('icon.png', $door['icon'] ?? null);
    }

    public function testLordCatalogPresentationExposesIcon(): void
    {
        $game = $this->enabledExperience('lord');
        self::assertSame('icon.png', $game['presentation']['icon']);
        self::assertSame('/door-assets/lord/icon', $game['presentation']['icon_url']);
    }

    // ---- Tristam Island ---------------------------------------------------

    public function testTristamIconAssetIsCanonical(): void
    {
        $this->assertCanonicalIcon(self::REPO_ROOT . '/native-doors/doors/tristam/icon.png');
    }

    public function testTristamManifestDeclaresIcon(): void
    {
        $manifest = json_decode(
            (string)file_get_contents(self::REPO_ROOT . '/native-doors/doors/tristam/nativedoor.json'),
            true
        );
        self::assertSame('icon.png', $manifest['game']['icon'] ?? null);

        $door = (new NativeDoorManager())->getDoor('tristam');
        self::assertIsArray($door);
        self::assertSame('icon.png', $door['icon'] ?? null);
    }

    public function testTristamCatalogPresentationExposesIcon(): void
    {
        $game = $this->enabledExperience('tristam');
        self::assertSame('icon.png', $game['presentation']['icon']);
        self::assertSame('/door-assets/tristam/icon', $game['presentation']['icon_url']);
    }

    // ---- ascii-royale (M4 Slice 3) --------------------------------------

    public function testAsciiRoyaleIconAssetIsCanonical(): void
    {
        $this->assertCanonicalIcon(self::REPO_ROOT . '/native-doors/doors/ascii-royale-m3/icon.png');
    }

    public function testAsciiRoyaleManifestDeclaresIcon(): void
    {
        $manifest = json_decode(
            (string)file_get_contents(self::REPO_ROOT . '/native-doors/doors/ascii-royale-m3/nativedoor.json'),
            true
        );
        self::assertSame('icon.png', $manifest['game']['icon'] ?? null);

        $door = (new NativeDoorManager())->getDoor('ascii-royale-m3');
        self::assertIsArray($door);
        self::assertSame('icon.png', $door['icon'] ?? null);
    }

    public function testAsciiRoyaleCatalogPresentationExposesIcon(): void
    {
        $game = $this->enabledExperience('ascii-royale-m3');
        self::assertSame('icon.png', $game['presentation']['icon']);
        self::assertSame('/door-assets/ascii-royale-m3/icon', $game['presentation']['icon_url']);
    }

    // ---- BCR Games Server (gateway Experience) --------------------------

    public function testBcrGamesIconAssetIsCanonical(): void
    {
        $this->assertCanonicalIcon(self::REPO_ROOT . '/native-doors/doors/bcrgames/icon.png');
    }

    public function testBcrGamesManifestDeclaresIcon(): void
    {
        $manifest = json_decode(
            (string)file_get_contents(self::REPO_ROOT . '/native-doors/doors/bcrgames/nativedoor.json'),
            true
        );
        self::assertSame('icon.png', $manifest['game']['icon'] ?? null);

        $door = (new NativeDoorManager())->getDoor('bcrgames');
        self::assertIsArray($door);
        self::assertSame('icon.png', $door['icon'] ?? null);
    }

    public function testBcrGamesCatalogPresentationExposesIcon(): void
    {
        $games = (new GameCatalog())->getEnabledGames(null, 'web');
        if (!isset($games['bcrgames'])) {
            self::markTestSkipped('bcrgames is not enabled in this environment');
        }
        self::assertSame('icon.png', $games['bcrgames']['presentation']['icon']);
        self::assertSame('/door-assets/bcrgames/icon', $games['bcrgames']['presentation']['icon_url']);
    }

    // ---- Elsewhere: staged, NOT wired -----------------------------------

    public function testElsewhereIconIsStagedAsCanonicalArtwork(): void
    {
        $this->assertCanonicalIcon(self::REPO_ROOT . '/assets/crossroads-icons/elsewhere.png');
    }

    public function testElsewhereIconIsNotWiredIntoTheExperience(): void
    {
        // The paused Elsewhere door must still declare no icon.
        $manifest = json_decode(
            (string)file_get_contents(self::REPO_ROOT . '/native-doors/doors/elsewhere/nativedoor.json'),
            true
        );
        self::assertNull(
            $manifest['game']['icon'] ?? null,
            'Elsewhere manifest must not reference the staged icon yet'
        );

        $door = (new NativeDoorManager())->getDoor('elsewhere');
        if (is_array($door)) {
            self::assertNull($door['icon'] ?? null, 'Elsewhere door must expose no icon');
        }

        // And it must not have leaked the staged file into the door directory.
        self::assertFileDoesNotExist(
            self::REPO_ROOT . '/native-doors/doors/elsewhere/icon.png'
        );
    }

    // ---- Asset-serving contract ------------------------------------------

    public function testDoorAssetRouteServesPngIcons(): void
    {
        // The /door-assets/{id}/{asset} route maps the .png extension to the
        // image/png MIME type; assert the contract this slice depends on.
        $routeSrc = (string)file_get_contents(self::REPO_ROOT . '/routes/door-routes.php');
        self::assertStringContainsString("'/door-assets/{doorid}/{asset}'", $routeSrc);
        self::assertMatchesRegularExpression(
            "/'png'\s*=>\s*'image\\/png'/",
            $routeSrc,
            'door-assets route no longer maps png -> image/png'
        );
    }

    /** @return array<string,mixed> */
    private function enabledExperience(string $id): array
    {
        $games = (new GameCatalog())->getEnabledGames(null, 'web');
        self::assertArrayHasKey($id, $games, "{$id} is not an enabled web Experience");

        return $games[$id];
    }
}
