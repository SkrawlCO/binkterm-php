<?php

declare(strict_types=1);

namespace BinktermPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class ExperienceLobbyTemplateTest extends TestCase
{
    private function renderLobby(
        int $sessionCount,
        int $maxSessions,
        bool $launchEnabled = true,
        ?string $screenshotUrl = null
    ): string {
        $twig = new Environment(
            new FilesystemLoader(dirname(__DIR__, 2) . '/templates')
        );

        // The production Template environment provides t(). These tests
        // exercise Experience lobby behavior, so translation itself is
        // intentionally stubbed with a deterministic value.
        $twig->addFunction(new TwigFunction(
            't',
            static fn(string $key, array $params = [], ...$args): string => $key
        ));

        // base.twig also relies on this production Twig helper. The lobby
        // behavior under test does not depend on feature flags, so keep the
        // synthetic test environment deterministic.
        $twig->addFunction(new TwigFunction(
            'bbs_feature_enabled',
            static fn(string $feature): bool => false
        ));

        return $twig->render('experience_lobby.twig', [
            'experience' => [
                'id' => 'usurper',
                'name' => 'Usurper Reborn',
                'description' => 'Usurper Reborn fantasy RPG BBS door.',
                'icon' => 'fas fa-dungeon',
                'presentation' => [
                    'icon_url' => '/door-assets/usurper/icon',
                    'screenshot_url' => $screenshotUrl,
                ],
                'capabilities' => [
                    'multiplayer' => true,
                ],
                'capacity' => [
                    'max_sessions' => $maxSessions,
                ],
                'policy' => [
                    'credit_cost' => 0,
                ],
                'actions' => [
                    'launch' => $launchEnabled,
                ],
            ],
            'state' => [
                'active' => $sessionCount > 0,
                'session_count' => $sessionCount,
                'player_count' => 0,
                'players' => [],
            ],
            'launch' => $launchEnabled ? [
                'type' => 'native',
                'id' => 'usurper',
                'url' => '/games/nativedoors/usurper',
            ] : null,
        ]);
    }

    public function testExperienceRendersCanonicalScreenshotWhenPresent(): void
    {
        $html = $this->renderLobby(
            0,
            10,
            true,
            '/door-assets/usurper/screenshot'
        );

        self::assertStringContainsString(
            'src="/door-assets/usurper/screenshot"',
            $html
        );
        self::assertStringContainsString(
            'alt="Usurper Reborn screenshot"',
            $html
        );
        self::assertStringContainsString(
            'src="/door-assets/usurper/icon"',
            $html
        );
    }

    public function testExperienceOmitsScreenshotHeroWhenAbsent(): void
    {
        $html = $this->renderLobby(0, 10);

        self::assertStringNotContainsString(
            'alt="Usurper Reborn screenshot"',
            $html
        );
        self::assertStringNotContainsString(
            'src="/door-assets/usurper/screenshot"',
            $html
        );
        self::assertStringContainsString(
            'src="/door-assets/usurper/icon"',
            $html
        );
    }

    public function testEmptyExperienceUsesCompactOccupancyState(): void
    {
        $html = $this->renderLobby(0, 10);

        self::assertStringContainsString('Capacity: 10', $html);
        self::assertStringContainsString('Waiting for players.', $html);
        self::assertStringContainsString('0 / 10 capacity', $html);
        self::assertStringContainsString(
            'No one is playing right now. Be the first one in.',
            $html
        );

        self::assertStringNotContainsString('Quiet', $html);
        self::assertStringNotContainsString('0 active sessions', $html);
        self::assertStringNotContainsString(
            'aria-label="Experience capacity"',
            $html
        );
    }

    public function testAvailableExperiencePresentsPlayableLaunch(): void
    {
        $html = $this->renderLobby(0, 10);

        self::assertStringContainsString('Ready to launch.', $html);
        self::assertStringContainsString('Available', $html);
        self::assertStringContainsString('Play Usurper Reborn', $html);
        self::assertStringContainsString(
            'href="/games/nativedoors/usurper"',
            $html
        );
        self::assertStringContainsString(
            'class="btn btn-fidonet"',
            $html
        );
        self::assertStringNotContainsString(
            'aria-disabled="true"',
            $html
        );
    }

    public function testExperienceAtCapacityDisablesLaunch(): void
    {
        $html = $this->renderLobby(10, 10);

        self::assertStringContainsString(
            'This Experience is currently at capacity.',
            $html
        );
        self::assertStringContainsString('At capacity', $html);
        self::assertStringContainsString('At Capacity', $html);
        self::assertStringContainsString(
            'aria-disabled="true"',
            $html
        );
        self::assertStringContainsString(
            'tabindex="-1"',
            $html
        );
        self::assertStringNotContainsString(
            'Play Usurper Reborn',
            $html
        );
    }

    public function testUnavailableLaunchDoesNotRenderPlayAction(): void
    {
        $html = $this->renderLobby(0, 10, false);

        self::assertStringContainsString(
            'Launch is not currently available from this surface.',
            $html
        );
        self::assertStringContainsString('Unavailable', $html);
        self::assertStringNotContainsString(
            'Play Usurper Reborn',
            $html
        );
        self::assertStringNotContainsString(
            'href="/games/nativedoors/usurper"',
            $html
        );
    }
}
