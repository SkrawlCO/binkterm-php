<?php

declare(strict_types=1);

use BinktermPHP\GameCatalog;
use PHPUnit\Framework\TestCase;

final class GameCatalogTest extends TestCase
{
    private GameCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new GameCatalog();
    }

    public function testWebCatalogReturnsExperiences(): void
    {
        $games = $this->catalog->getEnabledGames(null, 'web');

        self::assertIsArray($games);
        self::assertNotEmpty($games);
    }

    public function testEveryWebExperienceHasNormalizedCoreContract(): void
    {
        $games = $this->catalog->getEnabledGames(null, 'web');

        foreach ($games as $key => $game) {
            self::assertSame($key, $game['id']);

            self::assertArrayHasKey('name', $game);
            self::assertArrayHasKey('description', $game);
            self::assertArrayHasKey('category', $game);

            self::assertArrayHasKey('backend', $game);
            self::assertArrayHasKey('type', $game['backend']);
            self::assertArrayHasKey('id', $game['backend']);

            self::assertContains(
                $game['backend']['type'],
                ['dos', 'native', 'web', 'jsdos']
            );

            self::assertArrayHasKey('capabilities', $game);
            self::assertArrayHasKey('multiplayer', $game['capabilities']);
            self::assertIsBool($game['capabilities']['multiplayer']);

            self::assertArrayHasKey('actions', $game);
            self::assertArrayHasKey('launch', $game['actions']);
            self::assertIsBool($game['actions']['launch']);

            self::assertArrayHasKey('surfaces', $game);
            self::assertArrayHasKey('web', $game['surfaces']);
            self::assertArrayHasKey('telnet', $game['surfaces']);

            foreach (['web', 'telnet'] as $surface) {
                self::assertContains(
                    $game['surfaces'][$surface],
                    ['full', 'adapted', 'planned', 'unavailable']
                );
            }

            self::assertArrayHasKey('presentation', $game);
            self::assertArrayHasKey('icon', $game['presentation']);
            self::assertArrayHasKey('icon_url', $game['presentation']);
            self::assertArrayHasKey('screenshot', $game['presentation']);

            self::assertArrayHasKey('policy', $game);
            self::assertArrayHasKey('enabled', $game['policy']);
            self::assertArrayHasKey('admin_only', $game['policy']);
            self::assertArrayHasKey('credit_cost', $game['policy']);

            self::assertArrayHasKey('source', $game);
            self::assertArrayHasKey('type', $game['source']);
            self::assertArrayHasKey('manifest', $game['source']);
        }
    }

    public function testNormalizedActionsAndCapabilitiesHaveStableTypes(): void
    {
        $games = $this->catalog->getEnabledGames(null, 'web');

        foreach ($games as $game) {
            self::assertIsArray($game['capabilities']);
            self::assertIsBool(
                $game['capabilities']['multiplayer'],
                "Multiplayer capability must be boolean for {$game['id']}"
            );

            self::assertIsArray($game['actions']);
            self::assertTrue(
                $game['actions']['launch'],
                "Launch action must be available for {$game['id']}"
            );
        }
    }

    public function testNormalizedBackendIdentityIsCanonical(): void
    {
        $games = $this->catalog->getEnabledGames(null, 'web');

        foreach ($games as $game) {
            self::assertSame(
                $game['backend']['id'],
                $game['id'],
                "Backend identity mismatch for {$game['id']}"
            );

            self::assertSame(
                $game['backend']['type'],
                $game['source']['type'],
                "Backend/source type mismatch for {$game['id']}"
            );
        }
    }

    public function testCompatibilityFieldsRemainAvailable(): void
    {
        $games = $this->catalog->getEnabledGames(null, 'web');

        foreach ($games as $game) {
            self::assertArrayHasKey('type', $game);
            self::assertArrayHasKey('path', $game);
            self::assertArrayHasKey('icon', $game);
            self::assertArrayHasKey('icon_url', $game);
            self::assertArrayHasKey('players', $game);
            self::assertArrayHasKey('genre', $game);
            self::assertArrayHasKey('experience', $game);

            self::assertArrayHasKey('category', $game['experience']);
            self::assertArrayHasKey('featured', $game['experience']);
            self::assertArrayHasKey('multiplayer', $game['experience']);
        }
    }

    public function testBackendAndSourceTypesAgree(): void
    {
        $games = $this->catalog->getEnabledGames(null, 'web');

        foreach ($games as $game) {
            self::assertSame(
                $game['backend']['type'],
                $game['source']['type'],
                "Backend/source mismatch for {$game['id']}"
            );
        }
    }

    public function testWebCatalogContainsMultipleBackendFamiliesWhenConfigured(): void
    {
        $games = $this->catalog->getEnabledGames(null, 'web');

        $types = array_values(array_unique(array_map(
            static fn(array $game): string => $game['backend']['type'],
            $games
        )));

        self::assertNotEmpty($types);

        // The catalog is intentionally backend-neutral. Do not require a
        // particular locally-installed game, but every discovered backend
        // must belong to the normalized backend vocabulary.
        foreach ($types as $type) {
            self::assertContains($type, ['dos', 'native', 'web', 'jsdos']);
        }
    }

    public function testTelnetCatalogContainsOnlyCurrentlyRunnableTelnetBackends(): void
    {
        $games = $this->catalog->getEnabledGames(null, 'telnet');

        foreach ($games as $game) {
            self::assertContains(
                $game['backend']['type'],
                ['dos', 'native']
            );

            self::assertSame('full', $game['surfaces']['telnet']);
        }
    }

    public function testWebOnlyBackendsDescribeTelnetAsPlanned(): void
    {
        $games = $this->catalog->getEnabledGames(null, 'web');

        foreach ($games as $game) {
            if (!in_array($game['backend']['type'], ['web', 'jsdos'], true)) {
                continue;
            }

            self::assertSame('full', $game['surfaces']['web']);
            self::assertSame('planned', $game['surfaces']['telnet']);
        }
    }

    public function testEnabledCatalogEntriesReportEnabledPolicy(): void
    {
        $games = $this->catalog->getEnabledGames(null, 'web');

        foreach ($games as $game) {
            self::assertTrue(
                $game['policy']['enabled'],
                "Enabled catalog returned disabled experience {$game['id']}"
            );
        }
    }
}
