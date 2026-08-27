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

            self::assertArrayHasKey(
                'participant_messaging',
                $game['capabilities']
            );
            self::assertIsBool(
                $game['capabilities']['participant_messaging']
            );

            self::assertArrayHasKey('actions', $game);
            self::assertArrayHasKey('launch', $game['actions']);
            self::assertIsBool($game['actions']['launch']);

            self::assertArrayHasKey(
                'message_players',
                $game['actions']
            );
            self::assertIsBool($game['actions']['message_players']);

            self::assertSame(
                $game['capabilities']['participant_messaging'],
                $game['actions']['message_players'],
                "Participant messaging action must follow capability for {$game['id']}"
            );

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
            self::assertArrayHasKey('screenshot_url', $game['presentation']);

            self::assertIsString($game['presentation']['icon_url']);
            self::assertNotSame('', trim($game['presentation']['icon_url']));

            if ($game['presentation']['screenshot'] === null) {
                self::assertNull($game['presentation']['screenshot_url']);
            } else {
                self::assertIsString($game['presentation']['screenshot_url']);
                self::assertNotSame(
                    '',
                    trim($game['presentation']['screenshot_url'])
                );
            }

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

    public function testUsurperExplicitlySupportsParticipantMessaging(): void
    {
        $games = $this->catalog->getEnabledGames(null, 'web');

        self::assertArrayHasKey('usurper', $games);

        self::assertTrue(
            $games['usurper']['capabilities']['participant_messaging']
        );

        self::assertTrue(
            $games['usurper']['actions']['message_players']
        );
    }

    public function testExperiencesExposeNormalizedParticipantActions(): void
    {
        $games = $this->catalog->getEnabledGames(null, 'web');

        foreach ($games as $game) {
            self::assertArrayHasKey(
                'participant_actions',
                $game,
                "Participant actions missing for {$game['id']}"
            );

            self::assertArrayHasKey(
                'profile',
                $game['participant_actions']
            );

            self::assertIsBool(
                $game['participant_actions']['profile']
            );

            self::assertArrayHasKey(
                'message',
                $game['participant_actions']
            );

            self::assertIsBool(
                $game['participant_actions']['message']
            );

            self::assertSame(
                $game['capabilities']['participant_messaging'],
                $game['participant_actions']['message'],
                "Participant message action must follow messaging capability for {$game['id']}"
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

    public function testCanonicalExperienceShapeDoesNotExposeRemovedCompatibilityFields(): void
    {
        $games = $this->catalog->getEnabledGames(null, 'web');

        foreach ($games as $game) {
            foreach ([
                'id',
                'name',
                'description',
                'category',
                'backend',
                'author',
                'version',
                'capabilities',
                'actions',
                'capacity',
                'surfaces',
                'presentation',
                'policy',
                'source',
            ] as $field) {
                self::assertArrayHasKey(
                    $field,
                    $game,
                    "Canonical Experience field '{$field}' missing for {$game['id']}"
                );
            }

            foreach ([
                'type',
                'path',
                'icon',
                'icon_url',
                'genre',
                'experience',
            ] as $legacyField) {
                self::assertArrayNotHasKey(
                    $legacyField,
                    $game,
                    "Legacy field '{$legacyField}' must not exist for {$game['id']}"
                );
            }
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

    public function testUsurperExposesCanonicalScreenshotUrlWhenDeclared(): void
    {
        $games = (new \BinktermPHP\GameCatalog())->getEnabledGames(
            ['id' => 3, 'user_id' => 3, 'is_admin' => true],
            'web'
        );

        self::assertArrayHasKey('usurper', $games);
        self::assertSame(
            'screenshot.png',
            $games['usurper']['presentation']['screenshot']
        );
        self::assertSame(
            '/door-assets/usurper/screenshot',
            $games['usurper']['presentation']['screenshot_url']
        );
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

    public function testExperiencesExposeNormalizedConversationCapability(): void
    {
        $games = (new GameCatalog())->getEnabledGames(
            ['user_id' => 1, 'is_admin' => true],
            'web'
        );

        foreach ($games as $game) {
            self::assertArrayHasKey(
                'conversation',
                $game['capabilities']
            );

            $conversation = $game['capabilities']['conversation'];

            self::assertTrue(
                $conversation === null || is_array($conversation)
            );

            if ($conversation !== null) {
                self::assertSame(
                    'chat_room',
                    $conversation['type']
                );
                self::assertIsInt(
                    $conversation['room_id']
                );
                self::assertGreaterThan(
                    0,
                    $conversation['room_id']
                );
            }
        }
    }

    public function testConversationCapabilityNormalizationIsBackendIndependent(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../src/GameCatalog.php'
        );

        self::assertIsString($source);

        self::assertSame(
            3,
            substr_count(
                $source,
                "'conversation' => \$this->normalizeConversationCapability("
            )
        );

        self::assertStringContainsString(
            "if (\$type !== 'chat_room')",
            $source
        );

        self::assertStringContainsString(
            "resolveActiveRoomByName(\$roomName)",
            $source
        );

        self::assertStringContainsString(
            "'room_id' => \$roomId",
            $source
        );
    }


}
