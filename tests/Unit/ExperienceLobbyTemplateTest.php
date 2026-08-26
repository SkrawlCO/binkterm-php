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
        ?int $maxSessions,
        bool $launchEnabled = true,
        ?string $screenshotUrl = null,
        string $name = 'Usurper Reborn',
        bool $multiplayer = true,
        string $iconUrl = '/door-assets/usurper/icon',
        string $launchUrl = '/games/nativedoors/usurper',
        string $launchType = 'native',
        string $launchId = 'usurper',
        array $players = [],
        ?int $currentUserId = null
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
                'name' => $name,
                'description' => 'Usurper Reborn fantasy RPG BBS door.',
                'icon' => 'fas fa-dungeon',
                'presentation' => [
                    'icon_url' => $iconUrl,
                    'screenshot_url' => $screenshotUrl,
                ],
                'capabilities' => [
                    'multiplayer' => $multiplayer,
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
                'player_count' => count($players),
                'players' => $players,
            ],
            'launch' => $launchEnabled ? [
                'type' => $launchType,
                'id' => $launchId,
                'url' => $launchUrl,
            ] : null,
            'current_user' => $currentUserId !== null ? [
                'user_id' => $currentUserId,
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

    public function testWebExperienceUsesSameCanonicalLobbyAndLaunchAction(): void
    {
        $html = $this->renderLobby(
            0,
            null,
            true,
            null,
            'Blackjack',
            false,
            '/webdoors/blackjack/icon.svg',
            '/games/blackjack'
        );

        self::assertStringContainsString('Blackjack', $html);
        self::assertStringContainsString('Single Player', $html);
        self::assertStringContainsString('Free to play', $html);
        self::assertStringContainsString(
            'src="/webdoors/blackjack/icon.svg"',
            $html
        );
        self::assertStringContainsString(
            'href="/games/blackjack"',
            $html
        );
        self::assertStringContainsString('Play Blackjack', $html);

        self::assertStringNotContainsString('Capacity:', $html);
        self::assertStringNotContainsString(
            'aria-label="Experience capacity"',
            $html
        );
    }

    public function testJsdosExperienceUsesSameCanonicalLobbyAndLaunchAction(): void
    {
        $html = $this->renderLobby(
            0,
            null,
            true,
            null,
            'Doom',
            false,
            '/jsdos-doors/doomsw/icon-v2.png',
            '/games/jsdos/doomsw',
            'jsdos',
            'doomsw'
        );

        self::assertStringContainsString('Doom', $html);
        self::assertStringContainsString('Single Player', $html);
        self::assertStringContainsString('Free to play', $html);
        self::assertStringContainsString(
            'src="/jsdos-doors/doomsw/icon-v2.png"',
            $html
        );
        self::assertStringContainsString(
            'href="/games/jsdos/doomsw"',
            $html
        );
        self::assertStringContainsString('Play Doom', $html);

        self::assertStringNotContainsString('Capacity:', $html);
        self::assertStringNotContainsString(
            'aria-label="Experience capacity"',
            $html
        );
    }

    public function testPlayerNodeIsShownOnlyWhenRuntimeProvidesOne(): void
    {
        $nativeHtml = $this->renderLobby(
            1,
            10,
            true,
            null,
            'Usurper Reborn',
            true,
            '/door-assets/usurper/icon',
            '/games/nativedoors/usurper',
            'native',
            'usurper',
            [[
                'user_id' => 3,
                'username' => 'Skrawl',
                'session_id' => 'native-usurper-test',
                'presence' => 'Playing Usurper Reborn',
                'node' => 3,
                'started_at' => time(),
            ]]
        );

        self::assertStringContainsString('Skrawl', $nativeHtml);
        self::assertMatchesRegularExpression(
            '/<span class="badge bg-secondary-subtle text-dark border">\s*Node 3\s*<\/span>/',
            $nativeHtml
        );

        $webHtml = $this->renderLobby(
            1,
            null,
            true,
            null,
            'Blackjack',
            false,
            '/webdoors/blackjack/icon.svg',
            '/games/blackjack',
            'web',
            'blackjack',
            [[
                'user_id' => 3,
                'username' => 'Skrawl',
                'session_id' => 'webdoor-blackjack-test',
                'presence' => 'Playing Blackjack',
                'node' => null,
                'started_at' => time(),
            ]]
        );

        self::assertStringContainsString('Skrawl', $webHtml);
        self::assertStringContainsString('Playing Blackjack', $webHtml);
        self::assertDoesNotMatchRegularExpression(
            '/<span class="badge bg-secondary-subtle text-dark border">\s*Node \d+\s*<\/span>/',
            $webHtml
        );
    }

    public function testLivePlayerLinksToCanonicalBinkTermProfile(): void
    {
        $html = $this->renderLobby(
            1,
            10,
            true,
            null,
            'Usurper Reborn',
            true,
            '/door-assets/usurper/icon',
            '/games/nativedoors/usurper',
            'native',
            'usurper',
            [[
                'user_id' => 3,
                'username' => 'Skrawl',
                'session_id' => 'native-usurper-test',
                'presence' => 'Playing Usurper Reborn',
                'node' => 1,
                'started_at' => time(),
            ]]
        );

        self::assertStringContainsString(
            'href="/profile/Skrawl"',
            $html
        );
        self::assertMatchesRegularExpression(
            '/<a[^>]+href="\/profile\/Skrawl"[^>]*>\s*Skrawl\s*<\/a>/',
            $html
        );
    }

    public function testLivePlayerProfileLinkUrlEncodesUsername(): void
    {
        $html = $this->renderLobby(
            1,
            10,
            true,
            null,
            'Usurper Reborn',
            true,
            '/door-assets/usurper/icon',
            '/games/nativedoors/usurper',
            'native',
            'usurper',
            [[
                'user_id' => 3,
                'username' => 'Test User',
                'session_id' => 'native-usurper-test',
                'presence' => 'Playing Usurper Reborn',
                'node' => 1,
                'started_at' => time(),
            ]]
        );

        self::assertStringContainsString(
            'href="/profile/Test%20User"',
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

    public function testLivePlayerOffersUniversalParticipantActions(): void
    {
        $html = $this->renderLobby(
            1,
            10,
            true,
            null,
            'Usurper Reborn',
            true,
            '/door-assets/usurper/icon',
            '/games/nativedoors/usurper',
            'native',
            'usurper',
            [[
                'user_id' => 7,
                'username' => 'Bard',
                'session_id' => 'native-usurper-bard',
                'presence' => 'Playing Usurper Reborn',
                'node' => 2,
                'started_at' => time(),
            ]],
            3
        );

        self::assertStringContainsString(
            'href="/profile/Bard"',
            $html
        );

        self::assertStringContainsString(
            'href="/chat?dm_user_id=7"',
            $html
        );

        self::assertStringContainsString('Message', $html);
        self::assertStringContainsString('Node 2', $html);
    }

    public function testLivePlayerOffersCanonicalChatDmLink(): void
    {
        $source = file_get_contents(__DIR__ . '/../../templates/experience_lobby.twig');

        self::assertStringContainsString(
            'href="/chat?dm_user_id={{ player.user_id }}"',
            $source
        );
        self::assertStringContainsString(
            'player.user_id != (current_user.user_id ?? current_user.id)',
            $source
        );
        self::assertStringContainsString('Message', $source);
    }

    public function testChatSupportsCanonicalDmDeepLink(): void
    {
        $source = file_get_contents(__DIR__ . '/../../public_html/js/chat-page.js');

        self::assertStringContainsString(
            "params.get('dm_user_id')",
            $source
        );
        self::assertStringContainsString(
            "setActiveThread({ type: 'dm', id: deepLinkedDmUserId })",
            $source
        );
        self::assertStringContainsString(
            'userId === currentUserId',
            $source
        );
    }

}
