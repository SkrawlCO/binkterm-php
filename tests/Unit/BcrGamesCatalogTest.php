<?php

declare(strict_types=1);

use BinktermPHP\ExperienceLaunch;
use BinktermPHP\GameCatalog;
use PHPUnit\Framework\TestCase;

/**
 * M1: BCR Games Server as a normalized Crossroads Experience.
 *
 * These assertions run against the live catalog and only apply when the door is
 * actually enabled in this environment's Native Doors runtime configuration
 * (config/nativedoors.json, which is not committed). On a bare checkout with the
 * door not yet enabled they skip; on the L33TEST host they verify the real
 * catalog contract.
 */
final class BcrGamesCatalogTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $webExperience;
    /** @var array<string,mixed> */
    private array $telnetExperience;

    protected function setUp(): void
    {
        $catalog = new GameCatalog();

        $web = $catalog->getEnabledGames(null, 'web');
        $telnet = $catalog->getEnabledGames(null, 'telnet');

        if (!isset($web['bcrgames']) && !isset($telnet['bcrgames'])) {
            self::markTestSkipped('bcrgames is not enabled in this environment');
        }

        self::assertArrayHasKey('bcrgames', $web, 'bcrgames missing from the web catalog');
        self::assertArrayHasKey('bcrgames', $telnet, 'bcrgames missing from the telnet catalog');

        $this->webExperience = $web['bcrgames'];
        $this->telnetExperience = $telnet['bcrgames'];
    }

    public function testExperienceIdentityAndBackend(): void
    {
        self::assertSame('bcrgames', $this->webExperience['id']);
        self::assertSame('BCR Games Server', $this->webExperience['name']);
        self::assertSame('gateway', $this->webExperience['category']);
        self::assertSame('native', $this->webExperience['backend']['type'] ?? null);
        self::assertSame('bcrgames', $this->webExperience['backend']['id'] ?? null);
    }

    public function testWebSurfaceIsFullAndLaunchable(): void
    {
        self::assertSame('full', $this->webExperience['surfaces']['web'] ?? null);
        self::assertTrue(
            ExperienceLaunch::canLaunch($this->webExperience, 'web'),
            'web launch did not resolve'
        );
        self::assertSame(
            '/games/nativedoors/bcrgames?experience=1',
            ExperienceLaunch::resolve($this->webExperience, 'web')['url'] ?? null
        );
    }

    public function testTelnetSurfaceIsFullAndLaunchable(): void
    {
        self::assertSame('full', $this->telnetExperience['surfaces']['telnet'] ?? null);
        self::assertTrue(
            ExperienceLaunch::canLaunch($this->telnetExperience, 'telnet'),
            'telnet launch did not resolve'
        );
    }

    public function testExperienceIsNotAdminOnlyAndRunsInRawMode(): void
    {
        self::assertFalse((bool)($this->webExperience['policy']['admin_only'] ?? true));
        self::assertSame('raw', $this->webExperience['terminal']['mode'] ?? null);
    }

    public function testMultiplayerCapabilityIsFalse(): void
    {
        self::assertFalse((bool)($this->webExperience['capabilities']['multiplayer'] ?? true));
    }
}
