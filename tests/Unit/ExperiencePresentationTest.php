<?php

declare(strict_types=1);

use BinktermPHP\ExperiencePresentation;
use PHPUnit\Framework\TestCase;

final class ExperiencePresentationTest extends TestCase
{
    public function testCatalogOnlyFullExperienceHasStablePresentation(): void
    {
        $view = ExperiencePresentation::build($this->experience(), 'web');

        self::assertSame('arena', $view['id']);
        self::assertSame('Arena', $view['name']);
        self::assertSame('Battle together.', $view['description']);
        self::assertSame('game', $view['category']);
        self::assertSame('Builder', $view['author']);
        self::assertSame('1.2', $view['version']);
        self::assertSame('/arena.png', $view['presentation']['icon_url']);
        self::assertSame('Native', $view['backend']['label']);
        self::assertTrue($view['capabilities']['multiplayer']);
        self::assertSame(8, $view['capacity']['max_sessions']);
        self::assertSame(0, $view['cost']['credits']);
        self::assertTrue($view['cost']['free']);
        self::assertSame('full', $view['surfaces']['web']);
        self::assertSame('planned', $view['surfaces']['telnet']);
        self::assertSame('play', $view['actions']['primary']);
        self::assertTrue($view['actions']['play']);
        self::assertFalse($view['runtime']['supplied']);
        self::assertNull($view['runtime']['player_count']);
        self::assertSame([], $view['runtime']['players']);
    }

    /** @dataProvider unsupportedSurfaceProvider */
    public function testUnsupportedCurrentSurfaceUsesDetailsWithoutPlay(
        string $state,
        string $primary
    ): void {
        $experience = $this->experience();
        $experience['surfaces']['telnet'] = $state;

        $view = ExperiencePresentation::build($experience, 'terminal');

        self::assertSame('telnet', $view['surfaces']['requested']);
        self::assertSame($state, $view['surfaces']['current']);
        self::assertSame($primary, $view['actions']['primary']);
        self::assertTrue($view['actions']['details']);
        self::assertFalse($view['actions']['play']);
        self::assertFalse($view['actions']['static_launchable']);
    }

    public static function unsupportedSurfaceProvider(): array
    {
        return [
            'planned' => ['planned', 'planned'],
            'unavailable' => ['unavailable', 'unavailable'],
        ];
    }

    public function testRuntimeAndViewerParticipationAreComposedWhenSupplied(): void
    {
        $player = [
            'user_id' => 7,
            'username' => 'Player',
            'session_id' => 'active-session',
        ];
        $state = [
            'active' => true,
            'session_count' => 2,
            'player_count' => 1,
            'players' => [$player],
        ];

        $view = ExperiencePresentation::build(
            $this->experience(),
            'web',
            $state,
            $player
        );

        self::assertTrue($view['runtime']['supplied']);
        self::assertTrue($view['runtime']['active']);
        self::assertSame(2, $view['runtime']['session_count']);
        self::assertSame(1, $view['runtime']['player_count']);
        self::assertSame([$player], $view['runtime']['players']);
        self::assertTrue($view['viewer']['participating']);
        self::assertSame('return', $view['actions']['primary']);
        self::assertFalse($view['actions']['play']);
        self::assertTrue($view['actions']['return']);
        self::assertTrue($view['actions']['end_participation']);
    }

    public function testOptionalMetadataHasStableFallbacks(): void
    {
        $view = ExperiencePresentation::build([
            'id' => 'minimal',
            'backend' => ['type' => 'unknown', 'id' => 'minimal'],
        ], 'web');

        self::assertSame('minimal', $view['name']);
        self::assertSame('', $view['description']);
        self::assertSame('game', $view['category']);
        self::assertNull($view['author']);
        self::assertNull($view['version']);
        self::assertNull($view['presentation']['icon_url']);
        self::assertFalse($view['capabilities']['multiplayer']);
        self::assertNull($view['capacity']['max_sessions']);
        self::assertSame('unavailable', $view['surfaces']['current']);
        self::assertSame('unavailable', $view['actions']['primary']);
        self::assertFalse($view['actions']['play']);
    }

    public function testPlayerModeIsMultiplayerForAMultiplayerGame(): void
    {
        $view = ExperiencePresentation::build($this->experience(), 'web');

        self::assertSame('game', $view['category']);
        self::assertTrue($view['capabilities']['multiplayer']);
        self::assertSame('multiplayer', $view['capabilities']['player_mode']);
    }

    public function testPlayerModeIsSinglePlayerForASoloGame(): void
    {
        $experience = $this->experience();
        $experience['capabilities']['multiplayer'] = false;

        $view = ExperiencePresentation::build($experience, 'web');

        self::assertSame('game', $view['category']);
        self::assertFalse($view['capabilities']['multiplayer']);
        self::assertSame('single_player', $view['capabilities']['player_mode']);
    }

    public function testCurationDefaultsToNotCuratedWhenAbsent(): void
    {
        $view = ExperiencePresentation::build($this->experience(), 'web');

        self::assertSame(['curated' => false, 'order' => null], $view['curation']);
    }

    public function testCurationBlockPassesThroughFromTheCatalogEntry(): void
    {
        $experience = $this->experience();
        $experience['curation'] = ['curated' => true, 'order' => 2];

        $view = ExperiencePresentation::build($experience, 'web');

        self::assertTrue($view['curation']['curated']);
        self::assertSame(2, $view['curation']['order']);
    }

    public function testCurationIsNormalizedForMalformedInput(): void
    {
        $experience = $this->experience();
        $experience['curation'] = ['curated' => true]; // order missing

        $view = ExperiencePresentation::build($experience, 'web');

        self::assertTrue($view['curation']['curated']);
        self::assertNull($view['curation']['order']);
    }

    public function testPublicProjectionCarriesTheCurationBlock(): void
    {
        $experience = $this->experience();
        $experience['curation'] = ['curated' => true, 'order' => 0];

        $view = ExperiencePresentation::buildPublic($experience, 'web');

        self::assertSame(['curated' => true, 'order' => 0], $view['curation']);
        // Still no identity-bearing keys.
        self::assertArrayNotHasKey('viewer', $view);
        self::assertArrayNotHasKey('launch', $view);
    }

    public function testGatewayExperienceHasNoPlayerModeEvenWhenMultiplayerIsFalse(): void
    {
        // A Gateway is a destination whose internal session model is opaque to
        // Crossroads. It must not be labelled "Single Player" just because the
        // Crossroads-visible multiplayer flag is false.
        $experience = $this->experience();
        $experience['category'] = 'gateway';
        $experience['capabilities']['multiplayer'] = false;

        $view = ExperiencePresentation::build($experience, 'web');

        self::assertSame('gateway', $view['category']);
        self::assertFalse($view['capabilities']['multiplayer']);
        self::assertNull($view['capabilities']['player_mode']);
    }

    public function testGatewayPlayerModeStaysNullEvenIfMultiplayerIsSomehowTrue(): void
    {
        $experience = $this->experience();
        $experience['category'] = 'gateway';
        $experience['capabilities']['multiplayer'] = true;

        $view = ExperiencePresentation::build($experience, 'web');

        self::assertNull($view['capabilities']['player_mode']);
    }

    public function testBuildPublicCarriesThePlayerModeDescriptor(): void
    {
        $experience = $this->experience();
        $experience['category'] = 'gateway';

        $public = ExperiencePresentation::buildPublic($experience, 'web');

        self::assertArrayHasKey('player_mode', $public['capabilities']);
        self::assertNull($public['capabilities']['player_mode']);

        $game = ExperiencePresentation::buildPublic($this->experience(), 'web');
        self::assertSame('multiplayer', $game['capabilities']['player_mode']);
    }

    public function testCreditCostDistinguishesPaidFromFree(): void
    {
        $paid = $this->experience();
        $paid['policy']['credit_cost'] = 5;

        $view = ExperiencePresentation::build($paid, 'web');

        self::assertSame(5, $view['cost']['credits']);
        self::assertFalse($view['cost']['free']);
    }

    public function testBackendTypeDoesNotChangeNormalizedActionSemantics(): void
    {
        $primaries = [];

        foreach (['dos', 'native', 'web', 'jsdos'] as $type) {
            $experience = $this->experience();
            $experience['backend'] = ['type' => $type, 'id' => 'arena'];
            $primaries[] = ExperiencePresentation::build(
                $experience,
                'web'
            )['actions']['primary'];
        }

        self::assertSame(['play', 'play', 'play', 'play'], $primaries);
    }

    public function testPresentationCannotOverrideNonFullSurface(): void
    {
        $experience = $this->experience();
        $experience['surfaces']['web'] = 'planned';
        $experience['actions']['launch'] = true;

        $view = ExperiencePresentation::build($experience, 'web');

        self::assertFalse($view['surfaces']['static_launchable']);
        self::assertFalse($view['actions']['play']);
        self::assertSame('planned', $view['actions']['primary']);
    }

    // ---- Slice 5C: normalized capacity / status contract ----

    public function testLimitedCapacityBelowMaximumIsNotAtCapacity(): void
    {
        $view = ExperiencePresentation::build(
            $this->experience(),
            'web',
            ['active' => true, 'session_count' => 7, 'player_count' => 7, 'players' => []]
        );

        self::assertSame(8, $view['capacity']['max_sessions']);
        self::assertTrue($view['capacity']['limited']);
        self::assertFalse($view['capacity']['at_capacity']);
    }

    public function testLimitedCapacityAtMaximumIsAtCapacity(): void
    {
        $view = ExperiencePresentation::build(
            $this->experience(),
            'web',
            ['active' => true, 'session_count' => 8, 'player_count' => 8, 'players' => []]
        );

        self::assertTrue($view['capacity']['limited']);
        self::assertTrue($view['capacity']['at_capacity']);
    }

    public function testUnlimitedCapacityIsNeverAtCapacity(): void
    {
        $experience = $this->experience();
        $experience['capacity']['max_sessions'] = null;

        $view = ExperiencePresentation::build(
            $experience,
            'web',
            ['active' => true, 'session_count' => 999, 'player_count' => 999, 'players' => []]
        );

        self::assertNull($view['capacity']['max_sessions']);
        self::assertFalse($view['capacity']['limited']);
        self::assertFalse($view['capacity']['at_capacity']);
    }

    public function testAtCapacityIsFalseWhenNoRuntimeStateSupplied(): void
    {
        $view = ExperiencePresentation::build($this->experience(), 'web');

        self::assertTrue($view['capacity']['limited']);
        self::assertFalse($view['capacity']['at_capacity']);
        self::assertFalse($view['viewer']['blocked_by_capacity']);
    }

    public function testNonParticipantAtCapacityIsBlockedByCapacity(): void
    {
        $view = ExperiencePresentation::build(
            $this->experience(),
            'web',
            ['active' => true, 'session_count' => 8, 'player_count' => 8, 'players' => []],
            null
        );

        self::assertFalse($view['viewer']['participating']);
        self::assertTrue($view['viewer']['blocked_by_capacity']);
        self::assertSame('at_capacity', $view['status']['code']);
    }

    public function testParticipantAtCapacityIsNotBlockedByCapacity(): void
    {
        $player = ['user_id' => 7, 'username' => 'P', 'session_id' => 'sid'];

        $view = ExperiencePresentation::build(
            $this->experience(),
            'web',
            ['active' => true, 'session_count' => 8, 'player_count' => 8, 'players' => [$player]],
            $player
        );

        self::assertTrue($view['viewer']['participating']);
        self::assertFalse($view['viewer']['blocked_by_capacity']);
        self::assertTrue($view['capacity']['at_capacity']);
    }

    public function testParticipantAtCapacityStatusIsParticipatingNotAtCapacity(): void
    {
        $player = ['user_id' => 7, 'username' => 'P', 'session_id' => 'sid'];

        $view = ExperiencePresentation::build(
            $this->experience(),
            'web',
            ['active' => true, 'session_count' => 8, 'player_count' => 8, 'players' => [$player]],
            $player
        );

        self::assertSame('participating', $view['status']['code']);
    }

    /** @dataProvider statusPrecedenceProvider */
    public function testStatusCodePrecedence(
        string $webSurface,
        int $sessionCount,
        bool $participating,
        string $expected
    ): void {
        $experience = $this->experience();
        $experience['surfaces']['web'] = $webSurface;

        $players = [];
        $viewerPlayer = null;
        if ($participating) {
            $viewerPlayer = ['user_id' => 7, 'username' => 'P', 'session_id' => 'sid'];
            $players[] = $viewerPlayer;
        }

        $view = ExperiencePresentation::build(
            $experience,
            'web',
            [
                'active' => $sessionCount > 0,
                'session_count' => $sessionCount,
                'player_count' => $sessionCount,
                'players' => $players,
            ],
            $viewerPlayer
        );

        self::assertSame($expected, $view['status']['code']);
    }

    /** @return array<string,array{0:string,1:int,2:bool,3:string}> */
    public static function statusPrecedenceProvider(): array
    {
        return [
            'planned beats everything'          => ['planned', 8, true, 'planned'],
            'unavailable beats participating'   => ['unavailable', 8, true, 'unavailable'],
            'participating beats at_capacity'   => ['full', 8, true, 'participating'],
            'at_capacity for blocked viewer'    => ['full', 8, false, 'at_capacity'],
            'available with room'               => ['full', 2, false, 'available'],
        ];
    }

    /** @return array<string,mixed> */
    private function experience(): array
    {
        return [
            'id' => 'arena',
            'name' => 'Arena',
            'description' => 'Battle together.',
            'category' => 'game',
            'author' => 'Builder',
            'version' => '1.2',
            'backend' => ['type' => 'native', 'id' => 'arena'],
            'capabilities' => ['multiplayer' => true],
            'capacity' => ['max_sessions' => 8],
            'policy' => ['enabled' => true, 'credit_cost' => 0],
            'surfaces' => ['web' => 'full', 'telnet' => 'planned'],
            'presentation' => [
                'icon' => 'arena.png',
                'icon_url' => '/arena.png',
                'screenshot' => null,
                'screenshot_url' => null,
            ],
        ];
    }
}
