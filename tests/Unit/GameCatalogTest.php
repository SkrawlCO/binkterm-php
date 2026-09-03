<?php

declare(strict_types=1);

use BinktermPHP\GameCatalog;
use BinktermPHP\ExperienceLaunch;
use BinktermPHP\NativeDoorManager;
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

            if (in_array($game['backend']['type'], ['dos', 'native'], true)) {
                self::assertArrayHasKey('terminal', $game);
                self::assertArrayHasKey('mode', $game['terminal']);
                self::assertContains(
                    $game['terminal']['mode'],
                    ['doorway', 'raw', 'line']
                );
            }

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
            self::assertSame(
                $game['surfaces']['web'] === 'full',
                $game['actions']['launch'],
                "Launch action must follow web surface state for {$game['id']}"
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
                'curation',
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

    public function testEveryEntryCarriesACurationBlock(): void
    {
        $games = $this->catalog->getEnabledGames(null, 'web');

        foreach ($games as $game) {
            self::assertArrayHasKey('curation', $game);
            self::assertArrayHasKey('curated', $game['curation']);
            self::assertArrayHasKey('order', $game['curation']);
            self::assertIsBool($game['curation']['curated']);
            if ($game['curation']['curated']) {
                self::assertIsInt($game['curation']['order']);
            } else {
                self::assertNull($game['curation']['order']);
            }
        }
    }

    public function testCurationListTagsMatchingEntriesInOperatorOrder(): void
    {
        $catalog = new GameCatalog(null, ['openglad', 'multizork']);
        $games = $catalog->getEnabledGames(null, 'web');

        self::assertTrue($games['openglad']['curation']['curated']);
        self::assertSame(0, $games['openglad']['curation']['order']);
        self::assertTrue($games['multizork']['curation']['curated']);
        self::assertSame(1, $games['multizork']['curation']['order']);

        // Everything not in the list is explicitly not curated.
        self::assertFalse($games['lord']['curation']['curated']);
        self::assertNull($games['lord']['curation']['order']);
        self::assertFalse($games['bcrgames']['curation']['curated']);
    }

    public function testEmptyCurationListLeavesNothingCurated(): void
    {
        $games = (new GameCatalog(null, []))->getEnabledGames(null, 'web');

        foreach ($games as $game) {
            self::assertFalse($game['curation']['curated'], $game['id']);
            self::assertNull($game['curation']['order'], $game['id']);
        }
    }

    public function testStaleCurationIdDoesNotCreateOrReclassifyEntries(): void
    {
        $baseline = (new GameCatalog(null, []))->getEnabledGames(null, 'web');
        $withStale = (new GameCatalog(null, ['no-such-experience', 'openglad']))
            ->getEnabledGames(null, 'web');

        // No phantom card for the missing id.
        self::assertArrayNotHasKey('no-such-experience', $withStale);
        // Same entry set, same order.
        self::assertSame(array_keys($baseline), array_keys($withStale));
        // Only the real curated id is tagged; its order is its list position.
        self::assertTrue($withStale['openglad']['curation']['curated']);
        self::assertSame(1, $withStale['openglad']['curation']['order']);
        // Unrelated entries are untouched.
        self::assertFalse($withStale['lord']['curation']['curated']);
    }

    public function testCurationDoesNotAlterLaunchOrSurfaceContract(): void
    {
        $plain = (new GameCatalog(null, []))->getEnabledGames(null, 'web');
        $curated = (new GameCatalog(null, ['openglad']))->getEnabledGames(null, 'web');

        foreach ($plain as $id => $game) {
            $other = $curated[$id];
            self::assertSame($game['surfaces'], $other['surfaces'], $id);
            self::assertSame($game['actions'], $other['actions'], $id);
            self::assertSame($game['policy'], $other['policy'], $id);
            self::assertEquals(
                array_diff_key($game, ['curation' => true]),
                array_diff_key($other, ['curation' => true]),
                "only the curation block may differ for {$id}"
            );
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

    public function testTelnetCatalogSeparatesDiscoveryFromRunnability(): void
    {
        $games = $this->catalog->getEnabledGames(null, 'telnet');
        $runnableManaged = [];

        foreach ($games as $game) {
            $runnable = $game['surfaces']['telnet'] === 'full';
            self::assertSame($runnable, $game['actions']['launch']);

            if (in_array($game['backend']['type'], ['dos', 'native'], true)) {
                $runnableManaged[] = $game['id'];
                self::assertSame('full', $game['surfaces']['telnet']);
                self::assertTrue($game['actions']['launch']);
            }

            if (in_array($game['backend']['type'], ['web', 'jsdos'], true)) {
                self::assertSame('planned', $game['surfaces']['telnet']);
                self::assertFalse($game['actions']['launch']);
            }
        }

        self::assertNotEmpty(
            $runnableManaged,
            'Expected configured runnable DOS or native fixtures'
        );
    }

    public function testAuthorizedWebOnlyExperiencesAreDiscoveredAcrossSurfaces(): void
    {
        $web = $this->catalog->getEnabledGames(null, 'web');
        $telnet = $this->catalog->getEnabledGames(null, 'telnet');
        $webOnlyIds = [];
        $webOnlyTypes = [];

        foreach ($web as $id => $experience) {
            if (!in_array($experience['backend']['type'], ['web', 'jsdos'], true)) {
                continue;
            }

            $webOnlyIds[] = $id;
            $webOnlyTypes[$experience['backend']['type']] = true;
            self::assertArrayHasKey($id, $telnet);
            self::assertSame('full', $experience['surfaces']['web']);
            self::assertTrue($experience['actions']['launch']);
            self::assertSame('planned', $telnet[$id]['surfaces']['telnet']);
            self::assertFalse($telnet[$id]['actions']['launch']);
            self::assertNull(ExperienceLaunch::resolve($telnet[$id], 'telnet'));
        }

        self::assertNotEmpty(
            $webOnlyIds,
            'Expected configured WebDoor or JS-DOS fixtures'
        );
        self::assertArrayHasKey('web', $webOnlyTypes);
        self::assertArrayHasKey('jsdos', $webOnlyTypes);
    }

    public function testManagedDiscoveryPreservesEnablementAndAuthorizationBoundaries(): void
    {
        $method = new ReflectionMethod(GameCatalog::class, 'addManagedDoors');
        $doors = [
            'disabled' => [
                'config' => ['enabled' => false],
            ],
            'admin-only' => [
                'admin_only' => true,
                'config' => ['enabled' => true],
            ],
            'available' => [
                'config' => ['enabled' => true],
            ],
        ];
        $experiences = [];

        $method->invokeArgs($this->catalog, [
            &$experiences,
            'native',
            $doors,
            ['is_admin' => false],
            'web',
        ]);

        self::assertArrayNotHasKey('disabled', $experiences);
        self::assertArrayNotHasKey('admin-only', $experiences);
        self::assertArrayHasKey('available', $experiences);
    }

    public function testHideFromWebManagedExperiencePreservesVisibilityBoundary(): void
    {
        $method = new ReflectionMethod(GameCatalog::class, 'addManagedDoors');
        $door = [
            'config' => [
                'enabled' => true,
                'hide_from_web' => true,
            ],
        ];
        $webExperiences = [];
        $telnetExperiences = [];

        $method->invokeArgs($this->catalog, [
            &$webExperiences,
            'native',
            ['terminal-only' => $door],
            ['is_admin' => false],
            'web',
        ]);

        $method->invokeArgs($this->catalog, [
            &$telnetExperiences,
            'native',
            ['terminal-only' => $door],
            ['is_admin' => false],
            'telnet',
        ]);

        self::assertArrayNotHasKey('terminal-only', $webExperiences);
        self::assertArrayHasKey('terminal-only', $telnetExperiences);
        self::assertTrue($telnetExperiences['terminal-only']['actions']['launch']);
        self::assertTrue(
            ExperienceLaunch::canLaunch(
                $telnetExperiences['terminal-only'],
                'telnet'
            )
        );
    }

    public function testRequirementsFailingWebDoorIsNotDiscoverable(): void
    {
        $method = new ReflectionMethod(GameCatalog::class, 'isWebDoorDiscoverable');

        self::assertFalse($method->invoke(
            $this->catalog,
            'blackjack',
            [
                'requirements' => [
                    'features' => ['definitely-not-a-real-feature'],
                ],
            ]
        ));
    }

    public function testAdminOnlyWebDoorIsWithheldFromNonAdminDiscovery(): void
    {
        // Uses 'blackjack' only because it is an enabled WebDoor id in this
        // environment (mirrors testRequirementsFailingWebDoorIsNotDiscoverable);
        // the manifest passed in is synthetic.
        $method = new ReflectionMethod(GameCatalog::class, 'isWebDoorDiscoverable');
        $adminOnly = ['requirements' => ['admin_only' => true]];

        // Hidden from an ordinary authenticated viewer and from anonymous.
        self::assertFalse(
            $method->invoke($this->catalog, 'blackjack', $adminOnly, ['is_admin' => false])
        );
        self::assertFalse(
            $method->invoke($this->catalog, 'blackjack', $adminOnly, null)
        );

        // Visible to an admin.
        self::assertTrue(
            $method->invoke($this->catalog, 'blackjack', $adminOnly, ['is_admin' => true])
        );
    }

    public function testNonAdminOnlyWebDoorDiscoveryIsUnchanged(): void
    {
        $method = new ReflectionMethod(GameCatalog::class, 'isWebDoorDiscoverable');

        // No admin_only key: discoverable for every viewer, exactly as before.
        self::assertTrue(
            $method->invoke($this->catalog, 'blackjack', ['requirements' => []], ['is_admin' => false])
        );
        self::assertTrue(
            $method->invoke($this->catalog, 'blackjack', [], null)
        );
        // Backward-compatible two-argument call still resolves.
        self::assertTrue(
            $method->invoke($this->catalog, 'blackjack', [])
        );
    }

    public function testOrdinaryWebDoorsStayVisibleToNonAdminsAndReportAdminOnlyFalse(): void
    {
        // Regression: adding the admin_only gate must not hide ordinary
        // WebDoors from non-admins. The shipped fixtures declare no admin_only.
        $games = $this->catalog->getEnabledGames(
            ['user_id' => 7, 'is_admin' => false],
            'web'
        );

        $webIds = array_keys(array_filter(
            $games,
            static fn(array $g): bool => $g['backend']['type'] === 'web'
        ));

        self::assertNotEmpty(
            $webIds,
            'Expected at least one ordinary WebDoor visible to a non-admin'
        );

        foreach ($webIds as $id) {
            self::assertFalse(
                $games[$id]['policy']['admin_only'],
                "Ordinary WebDoor {$id} must report policy.admin_only = false"
            );
        }
    }

    /**
     * Normalize one managed-door array through GameCatalog::addManagedDoors and
     * return its catalogued experience.
     *
     * @param array<string,mixed> $door
     * @return array<string,mixed>
     */
    private function normalizeManagedDoor(
        array $door,
        string $backendType = 'native',
        string $surface = 'telnet'
    ): array {
        $method = new ReflectionMethod(GameCatalog::class, 'addManagedDoors');
        $experiences = [];
        $method->invokeArgs($this->catalog, [
            &$experiences,
            $backendType,
            ['fx' => $door],
            ['is_admin' => true],
            $surface,
        ]);

        return $experiences['fx'] ?? [];
    }

    public function testRawManagedDoorTerminalModeIsNormalized(): void
    {
        // REAL production shape: NativeDoorManager / DoorManager flatten the
        // manifest, so terminal_mode is a top-level key (no ['door'] nesting).
        $exp = $this->normalizeManagedDoor([
            'game' => [
                'name' => 'Raw Native Door',
                'description' => 'Raw terminal regression fixture.',
            ],
            'terminal_mode' => 'raw',
            'config' => [
                'enabled' => true,
                'credit_cost' => 3,
            ],
            'experience' => [
                'category' => 'game',
                'multiplayer' => true,
            ],
        ]);

        self::assertSame('raw', $exp['terminal']['mode']);
        self::assertSame('game', $exp['category']);
        self::assertTrue($exp['capabilities']['multiplayer']);
        self::assertSame(3, $exp['policy']['credit_cost']);
    }

    public function testLineManagedDoorIsFullOnWebAndTelnet(): void
    {
        $door = [
            'game' => ['name' => 'Private TCP Experience'],
            'terminal_mode' => 'line',
            'relay_host' => '::1',
            'relay_port' => 43023,
            'relay_adapter_class' => 'Example\\RelayAdapter',
            'config' => ['enabled' => true],
        ];

        $web = $this->normalizeManagedDoor($door, 'native', 'web');
        $telnet = $this->normalizeManagedDoor($door, 'native', 'telnet');

        self::assertSame('line', $web['terminal']['mode']);
        self::assertSame('full', $web['surfaces']['web']);
        self::assertSame('full', $web['surfaces']['telnet']);
        self::assertTrue($web['actions']['launch']);
        self::assertSame('line', $telnet['terminal']['mode']);
        self::assertTrue($telnet['actions']['launch']);
    }

    public function testProductionMultiZorkIsWebLaunchableThroughNativeBackend(): void
    {
        $games = $this->catalog->getEnabledGames(['is_admin' => false], 'web');

        self::assertArrayHasKey('multizork', $games);
        $multiZork = $games['multizork'];
        self::assertSame('native', $multiZork['backend']['type']);
        self::assertSame('line', $multiZork['terminal']['mode']);
        self::assertSame('full', $multiZork['surfaces']['web']);
        self::assertSame('full', $multiZork['surfaces']['telnet']);
        self::assertTrue($multiZork['actions']['launch']);
        self::assertSame(
            '/games/nativedoors/multizork?experience=1',
            \BinktermPHP\ExperienceLaunch::resolve($multiZork, 'web')['url'] ?? null
        );
        self::assertSame('/door-assets/multizork/icon', $multiZork['presentation']['icon_url']);
    }

    public function testNestedTerminalModeShapeStillNormalizesToRaw(): void
    {
        // Backwards compatibility: a caller/fixture still passing the old
        // nested shape must keep working.
        $exp = $this->normalizeManagedDoor([
            'game' => ['name' => 'Nested Raw Door'],
            'door' => ['terminal_mode' => 'raw'],
            'config' => ['enabled' => true],
        ]);

        self::assertSame('raw', $exp['terminal']['mode']);
    }

    public function testFlattenedShapeWinsOverStaleNestedShape(): void
    {
        // If both are present, the flattened production key is authoritative.
        $exp = $this->normalizeManagedDoor([
            'game' => ['name' => 'Mixed Shape Door'],
            'terminal_mode' => 'raw',
            'door' => ['terminal_mode' => 'doorway'],
            'config' => ['enabled' => true],
        ]);

        self::assertSame('raw', $exp['terminal']['mode']);
    }

    public function testManagedDoorWithoutTerminalModeDefaultsToDoorway(): void
    {
        $exp = $this->normalizeManagedDoor([
            'game' => ['name' => 'Legacy Door'],
            'config' => ['enabled' => true],
        ]);

        self::assertSame('doorway', $exp['terminal']['mode']);
    }

    public function testExplicitNonRawManagedDoorTerminalModeIsDoorway(): void
    {
        $exp = $this->normalizeManagedDoor([
            'game' => ['name' => 'Doorway Door'],
            'terminal_mode' => 'doorway',
            'config' => ['enabled' => true],
        ]);

        self::assertSame('doorway', $exp['terminal']['mode']);
    }

    public function testRloginBackendIsRawRegardlessOfManifestMode(): void
    {
        // RLogin is always a raw passthrough even if the manifest says doorway
        // (or says nothing).
        $exp = $this->normalizeManagedDoor(
            [
                'game' => ['name' => 'Remote BBS'],
                'terminal_mode' => 'doorway',
                'config' => ['enabled' => true],
            ],
            'rlogin'
        );

        self::assertSame('raw', $exp['terminal']['mode']);
    }

    public function testNativeManagerRawManifestCataloguesAsRaw(): void
    {
        // Producer -> catalog contract against REAL NativeDoorManager output
        // (flattened: terminal_mode at the top level). Generic: it picks up
        // whichever installed native doors declare raw, not a named one.
        $rawDoors = [];
        foreach ((new NativeDoorManager())->getAllDoors() as $id => $door) {
            if (strtolower((string)($door['terminal_mode'] ?? '')) === 'raw') {
                $door['config']['enabled'] = true; // exercise normalization only
                $rawDoors[$id] = $door;
            }
        }

        if ($rawDoors === []) {
            self::markTestSkipped('no installed native door declares terminal_mode=raw');
        }

        $method = new ReflectionMethod(GameCatalog::class, 'addManagedDoors');
        $experiences = [];
        $method->invokeArgs($this->catalog, [
            &$experiences,
            'native',
            $rawDoors,
            ['is_admin' => true],
            'telnet',
        ]);

        foreach (array_keys($rawDoors) as $id) {
            self::assertArrayHasKey($id, $experiences, "raw native door {$id} was not catalogued");
            self::assertSame(
                'raw',
                $experiences[$id]['terminal']['mode'],
                "native door {$id} lost its raw terminal mode through GameCatalog"
            );
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
