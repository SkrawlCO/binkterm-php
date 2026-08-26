<?php

declare(strict_types=1);

namespace BinktermPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class GamesHubTemplateTest extends TestCase
{
    private function renderHub(array $state): string
    {
        $twig = new Environment(
            new FilesystemLoader(dirname(__DIR__, 2) . '/templates')
        );

        $twig->addFunction(new TwigFunction(
            't',
            static fn(string $key, array $params = [], ...$args): string => $key
        ));

        $twig->addFunction(new TwigFunction(
            'bbs_feature_enabled',
            static fn(string $feature): bool => false
        ));

        return $twig->render('webdoors.twig', [
            'system_name' => 'L33Test Gaming',
            'games' => [[
                'id' => 'usurper',
                'name' => 'Usurper Reborn',
                'description' => 'Usurper Reborn fantasy RPG BBS door.',
                'author' => 'Binary Knight',
                'version' => '1.0',
                'players' => 'Multiplayer',
                'backend' => [
                    'type' => 'native',
                    'id' => 'usurper',
                ],
                'capabilities' => [
                    'multiplayer' => true,
                ],
                'presentation' => [
                    'icon_url' => '/door-assets/usurper/icon',
                ],
                'actions' => [
                    'launch' => true,
                ],
                'launch' => [
                    'type' => 'native',
                    'id' => 'usurper',
                    'url' => '/games/nativedoors/usurper',
                ],
            ]],
            'experience_states' => [
                'usurper' => $state,
            ],
            'leaderboard' => [],
            'leaderboard_month_label' => 'August 2026',
            'leaderboard_month_offset' => 0,
        ]);
    }

    public function testInactiveMultiplayerExperienceHidesPresenceRow(): void
    {
        $html = $this->renderHub([
            'active' => false,
            'session_count' => 0,
            'player_count' => 0,
            'players' => [],
        ]);

        self::assertStringContainsString(
            'href="/experiences/usurper"',
            $html
        );
        self::assertStringContainsString(
            '>Experience<',
            preg_replace('/\s+/', '', $html)
        );
        self::assertStringContainsString(
            'class="fas fa-compass me-1"',
            $html
        );
        self::assertMatchesRegularExpression(
            '/class="experience-presence small mb-2 d-none"/',
            $html
        );
        self::assertStringNotContainsString(
            'No players online',
            $html
        );
    }

    public function testActiveMultiplayerExperienceShowsLivePlayer(): void
    {
        $html = $this->renderHub([
            'active' => true,
            'session_count' => 1,
            'player_count' => 1,
            'players' => [[
                'user_id' => 3,
                'username' => 'Skrawl',
                'session_id' => 'runtime-test',
                'presence' => 'Playing Usurper Reborn',
                'node' => 1,
                'started_at' => time(),
            ]],
        ]);

        self::assertStringContainsString(
            'href="/experiences/usurper"',
            $html
        );
        self::assertStringContainsString(
            'class="experience-presence small mb-2"',
            $html
        );
        self::assertStringNotContainsString(
            'class="experience-presence small mb-2 d-none"',
            $html
        );

        self::assertStringContainsString('<strong>LIVE</strong>', $html);
        self::assertStringContainsString('Skrawl', $html);
        self::assertStringContainsString(
            'Playing Usurper Reborn',
            $html
        );
        self::assertStringContainsString(
            'href="/experiences/usurper"',
            $html
        );
    }

    public function testPresencePollingCanHideAndRevealRow(): void
    {
        $template = file_get_contents(
            dirname(__DIR__, 2) . '/templates/webdoors.twig'
        );

        self::assertIsString($template);
        self::assertStringContainsString(
            "element.classList.toggle('d-none', !visible);",
            $template
        );
        self::assertStringContainsString(
            'setPresenceVisible(element, false);',
            $template
        );
        self::assertStringContainsString(
            'setPresenceVisible(element, true);',
            $template
        );
    }
}
