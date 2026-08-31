<?php

declare(strict_types=1);

use BinktermPHP\NativeDoorManager;
use PHPUnit\Framework\TestCase;

/**
 * M1: the BCR Games Server Crossroads gateway.
 *
 * BCR Games Server (Shooter Jennings / Black Country Rock) is a remotely hosted
 * Telnet service. The whole integration is a native-door manifest and a tiny
 * wrapper (bcr.sh) that opens an ordinary, anonymous Telnet connection to the
 * publicly advertised endpoint — no BinktermPHP platform source is touched, and
 * nothing about the BinkTerm user is sent to BCR.
 *
 * These tests assert the manifest parses against the real native-door schema,
 * identifies BCR correctly, is a gateway (not a single game), is not admin-only,
 * routes launches through bcr.sh in raw/UTF-8 mode, and carries the deterministic
 * inputs that make GameCatalog expose it on both the web and telnet surfaces.
 * Nothing here involves RLogin.
 */
final class BcrGamesManifestTest extends TestCase
{
    private string $doorDir;
    /** @var array<string,mixed> */
    private array $rawManifest;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        $this->doorDir = $root . '/native-doors/doors/bcrgames';

        $manifestPath = $this->doorDir . '/nativedoor.json';
        self::assertFileExists($manifestPath, 'bcrgames manifest is missing');

        $decoded = json_decode((string)file_get_contents($manifestPath), true);
        self::assertIsArray($decoded, 'bcrgames manifest is not valid JSON');
        $this->rawManifest = $decoded;
    }

    public function testManifestParsesAgainstTheNativeDoorScanner(): void
    {
        // getDoor() returns null on any schema violation the scanner rejects.
        $door = (new NativeDoorManager())->getDoor('bcrgames');

        self::assertIsArray($door, 'NativeDoorManager could not parse the bcrgames manifest');
        self::assertSame('bcrgames', $door['door_id']);
        self::assertSame('nativedoor', $door['type']);
        self::assertSame('bcr.sh', $door['executable']);
    }

    public function testManifestIdentifiesBcrGamesServer(): void
    {
        $door = (new NativeDoorManager())->getDoor('bcrgames');

        self::assertSame('BCR Games Server', $door['name']);
        self::assertSame('BCR Games Server', $this->rawManifest['game']['name']);
        self::assertSame('BCRGAMES', $door['short_name']);
        // Attribution names the operator; L33TEST does not claim authorship.
        self::assertSame('Shooter Jennings / Black Country Rock', $door['author']);
        self::assertStringNotContainsStringIgnoringCase('l33test', (string)$door['author']);
    }

    public function testExperienceIsAGateway(): void
    {
        // BCR is a destination containing multiple games + shared facilities,
        // not a single standalone game.
        self::assertSame('gateway', $this->rawManifest['experience']['category'] ?? null);
    }

    public function testMultiplayerCapabilityIsFalseBecauseBcrRosterIsOpaque(): void
    {
        // BCR's games are multiplayer, but Crossroads cannot see BCR's internal
        // player state — the capability flag would falsely imply a shared,
        // Crossroads-visible roster.
        self::assertFalse((bool)($this->rawManifest['experience']['multiplayer'] ?? true));
        self::assertFalse((bool)($this->rawManifest['experience']['participant_messaging'] ?? true));
    }

    public function testTerminalModeIsRawUtf8AndAnsi(): void
    {
        self::assertSame('raw', $this->rawManifest['door']['terminal_mode'] ?? null);
        self::assertSame('utf8', $this->rawManifest['door']['output_encoding'] ?? null);
        self::assertTrue((bool)($this->rawManifest['door']['ansi_required'] ?? false));
    }

    public function testLaunchGoesThroughTheWrapper(): void
    {
        $launch = (string)($this->rawManifest['door']['launch_command'] ?? '');

        self::assertStringContainsString('bcr.sh', $launch);
        self::assertFileExists($this->doorDir . '/bcr.sh');
        self::assertTrue(is_executable($this->doorDir . '/bcr.sh'), 'bcr.sh must be executable');
    }

    public function testDoorIsNotAdminOnlyAndNotAnonymous(): void
    {
        $door = (new NativeDoorManager())->getDoor('bcrgames');

        // Any registered L33TEST member may launch it...
        self::assertFalse($door['admin_only'], 'bcrgames must not be admin-only');
        // ...but not anonymous guests (keeps presence/activity meaningful and
        // ties each outbound relay to a known caller).
        self::assertFalse(
            (bool)($door['config']['allow_anonymous'] ?? false),
            'bcrgames must not permit anonymous launches'
        );
    }

    public function testManifestCarriesTheInputsForBothSurfaces(): void
    {
        // GameCatalog::addManagedDoors is deterministic: a managed door that is
        // enabled, not admin-only, and does not set hide_from_web is exposed as
        //   surfaces => { web: 'full', telnet: 'full' }
        // (telnet is always 'full' for managed doors). Assert the manifest
        // inputs so this holds without depending on runtime enablement.
        self::assertFalse(
            (bool)($this->rawManifest['requirements']['admin_only'] ?? false),
            'admin_only would drop the door from ordinary discovery'
        );
        self::assertArrayNotHasKey(
            'hide_from_web',
            $this->rawManifest['config'] ?? [],
            'hide_from_web in the manifest would make the web surface unavailable'
        );
    }

    public function testNoRloginConfigurationIsInvolved(): void
    {
        // This is a native (Telnet) door, resolved by NativeDoorManager. It must
        // not resemble an rlogin door in any way.
        self::assertSame('nativedoor', $this->rawManifest['type']);
        self::assertNull((new \BinktermPHP\RLoginDoorManager())->getDoor('bcrgames'));

        $blob = strtolower((string)json_encode($this->rawManifest));
        foreach (['rlogin', 'bbs_type', 'client_username', 'server_username', 'pre_login_command', '513'] as $needle) {
            self::assertStringNotContainsString($needle, $blob, "manifest leaks an rlogin concept: {$needle}");
        }
    }
}
