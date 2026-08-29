<?php

declare(strict_types=1);

use BinktermPHP\NativeDoorManager;
use PHPUnit\Framework\TestCase;

/**
 * M4E-A: the Elsewhere native-door manifest.
 *
 * Elsewhere is the world; Tangaria is the provider/engine. The manifest must
 * parse against the real native-door schema, identify Elsewhere correctly,
 * stay admin-only during M4E validation, and route launches through the
 * local home-adapter wrapper (launch-elsewhere.sh) rather than any bespoke
 * BinkTerm+Tangaria script.
 */
final class ElsewhereManifestTest extends TestCase
{
    private string $doorDir;
    /** @var array<string,mixed> */
    private array $rawManifest;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        $this->doorDir = $root . '/native-doors/doors/elsewhere';

        $manifestPath = $this->doorDir . '/nativedoor.json';
        self::assertFileExists($manifestPath, 'elsewhere manifest is missing');

        $decoded = json_decode((string)file_get_contents($manifestPath), true);
        self::assertIsArray($decoded, 'elsewhere manifest is not valid JSON');
        $this->rawManifest = $decoded;
    }

    public function testManifestParsesAgainstTheNativeDoorScanner(): void
    {
        // getDoor() returns null on any schema violation the scanner rejects.
        $door = (new NativeDoorManager())->getDoor('elsewhere');

        self::assertIsArray($door, 'NativeDoorManager could not parse the elsewhere manifest');
        self::assertSame('elsewhere', $door['door_id']);
        self::assertSame('nativedoor', $door['type']);
        self::assertSame('launch-elsewhere.sh', $door['executable']);
    }

    public function testManifestIdentifiesElsewhereAsATangariaWorld(): void
    {
        $door = (new NativeDoorManager())->getDoor('elsewhere');

        self::assertSame('Elsewhere', $door['name']);
        self::assertSame('ELSEWHERE', $door['short_name']);
        self::assertStringContainsString('Tangaria', $door['description']);
        self::assertStringContainsString('L33TEST', $door['description']);

        // Player-facing identity is "Elsewhere", not "Tangaria".
        self::assertSame('Elsewhere', $this->rawManifest['game']['name']);
        self::assertNotSame('tangaria', strtolower((string)$this->rawManifest['game']['name']));
    }

    public function testManifestIsMultiplayerGameExperience(): void
    {
        self::assertSame('game', $this->rawManifest['experience']['category'] ?? null);
        self::assertTrue((bool)($this->rawManifest['experience']['multiplayer'] ?? false));
    }

    public function testManifestIsAdminOnlyDuringM4EValidation(): void
    {
        $door = (new NativeDoorManager())->getDoor('elsewhere');

        self::assertTrue($door['admin_only'], 'Elsewhere must stay admin_only:true for M4E');
        self::assertTrue((bool)($this->rawManifest['requirements']['admin_only'] ?? false));
    }

    public function testManifestDeniesAnonymousLaunch(): void
    {
        self::assertFalse((bool)($this->rawManifest['config']['allow_anonymous'] ?? false));
        self::assertSame(0, (int)($this->rawManifest['config']['guest_max_sessions'] ?? -1));
    }

    public function testLaunchGoesThroughTheGenericHomeAdapterWrapper(): void
    {
        $launch = (string)($this->rawManifest['door']['launch_command'] ?? '');

        self::assertStringContainsString('launch-elsewhere.sh', $launch);
        self::assertFileExists($this->doorDir . '/launch-elsewhere.sh');
        self::assertTrue(is_executable($this->doorDir . '/launch-elsewhere.sh'));

        // ANSI + UTF-8, per the task's manifest requirements.
        self::assertTrue((bool)($this->rawManifest['door']['ansi_required'] ?? false));
        self::assertSame('utf8', $this->rawManifest['door']['output_encoding'] ?? null);
    }

    public function testManifestUsesNoBespokeTangariaLaunchMechanics(): void
    {
        // The manifest must not encode engine launch mechanics directly; those
        // belong only behind the wrapper / Tangaria adapter.
        $blob = strtolower(json_encode($this->rawManifest, JSON_UNESCAPED_SLASHES));
        foreach (['pwmangclient', 'pwmangrc', 'pwmangband', '18346', 'birth_deeptown'] as $needle) {
            self::assertStringNotContainsString(
                $needle,
                $blob,
                "manifest leaks engine launch mechanic: {$needle}"
            );
        }
    }
}
