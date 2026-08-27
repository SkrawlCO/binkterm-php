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
