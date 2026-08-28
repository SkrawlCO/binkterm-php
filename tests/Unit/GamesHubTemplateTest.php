<?php

declare(strict_types=1);

namespace BinktermPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class GamesHubTemplateTest extends TestCase
{
    private function renderHub(
        array $games,
        array $continuePlaying = [],
        array $liveExperiences = [],
        array $leaderboard = [],
        bool $scoreboardExpanded = false,
        array $translationOverrides = []
    ): string {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'));
        $translations = [
            'ui.webdoors.page_title' => 'Experiences',
            'ui.webdoors.heading' => 'Experiences',
            'ui.webdoors.description' => 'Games, doors, gateways, and shared spaces across the BBS.',
            'ui.webdoors.continue_playing' => 'Continue Playing',
            'ui.webdoors.continue_playing_description' => 'Resume description',
            'ui.webdoors.participating_now' => 'You are participating now',
            'ui.webdoors.live_now' => 'Live Now',
            'ui.webdoors.live_now_description' => 'Live description',
            'ui.webdoors.live' => 'Live',
            'ui.webdoors.all_experiences' => 'All Experiences',
            'ui.webdoors.all_experiences_description' => 'All description',
            'ui.webdoors.community_scoreboard' => 'Community Scoreboard',
            'ui.webdoors.community_scoreboard_description' => 'Score description',
            'ui.webdoors.view_full_scoreboard' => 'View full scoreboard',
            'ui.webdoors.show_compact_scoreboard' => 'Show top five',
            'ui.webdoors.details' => 'Details',
            'ui.webdoors.play' => 'Play',
            'ui.webdoors.return' => 'Return',
            'ui.webdoors.category_game' => 'Game',
            'ui.webdoors.category_gateway' => 'Gateway',
            'ui.webdoors.multiplayer' => 'Multiplayer',
            'ui.webdoors.single_player' => 'Single player',
            'ui.webdoors.players_online' => '{count} online',
            'ui.webdoors.free' => 'Free',
            'ui.webdoors.credit_cost' => '{count} credits',
            'ui.webdoors.surface_web' => 'Web',
            'ui.webdoors.surface_telnet' => 'Telnet / SSH',
            'ui.webdoors.surface_full' => 'Available',
            'ui.webdoors.surface_planned' => 'Planned',
            'ui.webdoors.surface_unavailable' => 'Unavailable',
            'ui.webdoors.technology' => 'Technology',
        ];
        $translations = array_merge($translations, $translationOverrides);
        $twig->addFunction(new TwigFunction('t', static function (string $key, array $params = []) use ($translations): string {
            $text = $translations[$key] ?? $key;
            foreach ($params as $name => $value) {
                $text = str_replace('{' . $name . '}', (string)$value, $text);
            }
            return $text;
        }));
        $twig->addFunction(new TwigFunction('bbs_feature_enabled', static fn(string $feature): bool => false));

        return $twig->render('webdoors.twig', [
            'system_name' => 'L33Test Gaming',
            'games' => $games,
            'continue_playing' => $continuePlaying,
            'live_experiences' => $liveExperiences,
            'leaderboard' => $leaderboard,
            'leaderboard_month_label' => 'August 2026',
            'leaderboard_month_offset' => 0,
            'scoreboard_expanded' => $scoreboardExpanded,
        ]);
    }

    public function testPageUsesApprovedExperienceHierarchy(): void
    {
        $game = $this->game('library-entry');
        $html = $this->renderHub([$game], [$game], [$this->game('live-entry', 2)]);

        self::assertStringContainsString('<title>Experiences - L33Test Gaming</title>', $html);
        self::assertStringContainsString('>Experiences</h1>', $html);
        $continue = strpos($html, 'Continue Playing');
        $live = strpos($html, 'Live Now');
        $all = strpos($html, 'All Experiences');
        $scoreboard = strpos($html, 'Community Scoreboard');
        self::assertNotFalse($continue);
        self::assertNotFalse($live);
        self::assertNotFalse($all);
        self::assertNotFalse($scoreboard);
        self::assertLessThan($live, $continue);
        self::assertLessThan($all, $live);
        self::assertLessThan($scoreboard, $all);
    }

    public function testOptionalCommunitySectionsAreOmittedWhenEmpty(): void
    {
        $html = $this->renderHub([$this->game('only-entry')]);
        self::assertStringNotContainsString('Continue Playing', $html);
        self::assertStringNotContainsString('Live Now', $html);
        self::assertStringContainsString('All Experiences', $html);
    }

    public function testContinuePlayingPrioritizesReturnOverPlay(): void
    {
        $participating = $this->game('resume-me', 1, true);
        $section = $this->between(
            $this->renderHub([$participating], [$participating]),
            'Continue Playing',
            'All Experiences'
        );
        self::assertStringContainsString('href="/experiences/resume-me"', $section);
        self::assertStringContainsString('>Return</a>', $section);
        self::assertStringNotContainsString('>Play</a>', $section);
    }

    public function testLiveNowContainsOnlyComposedOccupiedExperiences(): void
    {
        $live = $this->game('occupied', 2);
        $offline = $this->game('offline');
        $section = $this->between(
            $this->renderHub([$live, $offline], [], [$live]),
            'Live Now',
            'All Experiences'
        );
        self::assertStringContainsString('/experiences/occupied', $section);
        self::assertStringNotContainsString('/experiences/offline', $section);
        self::assertStringContainsString('2 online', $section);
    }

    public function testAllExperiencesContainsCompleteVisibleCatalog(): void
    {
        $one = $this->game('one', 1, true);
        $two = $this->game('two', 2);
        $three = $this->game('three');
        $section = $this->between(
            $this->renderHub([$one, $two, $three], [$one], [$two]),
            'All Experiences',
            'Community Scoreboard'
        );
        self::assertStringContainsString('/experiences/one', $section);
        self::assertStringContainsString('/experiences/two', $section);
        self::assertStringContainsString('/experiences/three', $section);
    }

    public function testCardRendersSurfaceStatesAndSubduedBackendMetadata(): void
    {
        $game = $this->game('surface-state');
        $game['experience_presentation']['surfaces']['telnet'] = 'planned';
        $html = $this->renderHub([$game]);
        self::assertStringContainsString('class="card experience-library-card h-100 mb-0"', $html);
        self::assertStringContainsString('class="card-body experience-card-layout"', $html);
        self::assertStringContainsString('class="experience-metadata-cluster small"', $html);
        self::assertStringNotContainsString('experience-surface-grid', $html);
        self::assertStringContainsString('experience-surface-label">Web</span>', $html);
        self::assertStringContainsString('experience-surface-label">Telnet / SSH</span>', $html);
        self::assertStringContainsString('experience-surface-state state-full">Available', $html);
        self::assertStringContainsString('experience-surface-state state-planned">Planned', $html);
        self::assertStringContainsString('class="experience-technical-label"', $html);
        self::assertStringNotContainsString('badge bg-warning text-dark', $html);
        self::assertStringNotContainsString('badge bg-info text-white', $html);
    }

    public function testPlannedAndUnavailableExperiencesNeverRenderPlay(): void
    {
        foreach (['planned', 'unavailable'] as $state) {
            $game = $this->game($state);
            $game['experience_presentation']['surfaces']['current'] = $state;
            $game['experience_presentation']['surfaces']['web'] = $state;
            $game['experience_presentation']['actions']['play'] = false;
            $game['experience_presentation']['actions']['primary'] = $state;
            $section = $this->between(
                $this->renderHub([$game]),
                'All Experiences',
                'Community Scoreboard'
            );
            self::assertStringContainsString('>Details</a>', $section);
            self::assertStringContainsString('>' . ucfirst($state) . '</span>', $section);
            self::assertStringNotContainsString('>Play</a>', $section);
        }
    }

    public function testScoreboardDefaultsToFiveRowsAndPreservesFullAndMonthLinks(): void
    {
        $entries = array_map(fn(int $rank): array => $this->score($rank), range(1, 5));
        $html = $this->renderHub([$this->game('score-game')], [], [], $entries);
        self::assertSame(5, substr_count($html, 'class="text-muted">#'));
        self::assertStringContainsString('/games?month_offset=0&amp;scoreboard=full', $html);
        self::assertStringContainsString('/games?month_offset=1', $html);

        $expanded = $this->renderHub([$this->game('score-game')], [], [], $entries, true);
        self::assertStringContainsString('Show top five', $expanded);
        self::assertStringContainsString('/games?month_offset=1&amp;scoreboard=full', $expanded);
    }

    public function testExistingLobbyLinksAndPresencePollingRemainIntact(): void
    {
        $html = $this->renderHub([$this->game('launch-target')]);
        self::assertStringContainsString('href="/experiences/launch-target"', $html);
        self::assertStringNotContainsString('href="/games/nativedoors/launch-target"', $html);
        self::assertStringContainsString('window.setInterval(refreshAllPresence, 15000);', $html);
        self::assertStringContainsString("'/api/experiences/'", $html);
        self::assertStringContainsString(
            "const playersOnlineFallback = '\\u007Bcount\\u007D\\u0020online';",
            $html
        );
        self::assertStringContainsString("window.t(\n                'ui.webdoors.players_online'", $html);
        self::assertStringNotContainsString("'Playing'", $html);
        self::assertStringNotContainsString("'1 player online'", $html);
        self::assertStringNotContainsString("' players online'", $html);

        $overridden = $this->renderHub(
            [$this->game('localized-presence')],
            [],
            [],
            [],
            false,
            ['ui.webdoors.players_online' => 'Besucher-{count}']
        );
        self::assertStringContainsString(
            "const playersOnlineFallback = 'Besucher\\u002D\\u007Bcount\\u007D';",
            $overridden
        );
    }

    public function testRouteUsesNumericViewerIdentityAndSingleBulkStateRead(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/routes/webdoor-routes.php');
        self::assertIsString($source);
        self::assertStringContainsString("\$currentUserId = (int)(\$user['user_id'] ?? \$user['id'] ?? 0);", $source);
        self::assertStringContainsString('ExperienceParticipation::findViewerPlayer(', $source);
        $start = strpos($source, "SimpleRouter::get('/games'");
        $end = strpos($source, '// GET /games/dosdoors');
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $route = substr($source, $start, $end - $start);
        self::assertSame(1, substr_count($route, 'getExperienceStates('));
        self::assertStringContainsString("getEnabledGames(\$user, 'web')", $route);
        self::assertStringContainsString('ExperienceScoreboard())->getMonthlyScores(', $route);
        self::assertStringNotContainsString('ucfirst($row[\'game_id\'])', $route);
    }

    private function game(string $id, int $playerCount = 0, bool $participating = false): array
    {
        return [
            'id' => $id,
            'launch' => ['type' => 'native', 'id' => $id, 'url' => '/games/nativedoors/' . $id],
            'experience_presentation' => [
                'id' => $id,
                'name' => ucfirst($id),
                'description' => 'A shared BBS Experience.',
                'category' => 'game',
                'author' => 'Builder',
                'version' => '1.0',
                'presentation' => ['icon_url' => '/icon/' . $id],
                'backend' => ['type' => 'native', 'label' => 'Native'],
                'capabilities' => ['multiplayer' => true],
                'capacity' => ['max_sessions' => 8],
                'cost' => ['credits' => 0, 'free' => true],
                'surfaces' => ['requested' => 'web', 'current' => 'full', 'web' => 'full', 'telnet' => 'full', 'static_launchable' => true],
                'runtime' => ['supplied' => true, 'active' => $playerCount > 0, 'session_count' => $playerCount, 'player_count' => $playerCount, 'players' => []],
                'viewer' => ['participating' => $participating],
                'actions' => ['primary' => $participating ? 'return' : 'play', 'details' => true, 'play' => !$participating, 'return' => $participating, 'end_participation' => $participating, 'static_launchable' => true],
            ],
        ];
    }

    private function score(int $rank): array
    {
        return ['rank' => $rank, 'display_name' => 'Player' . $rank, 'score' => 1000 - $rank, 'game_id' => 'score-game', 'game_name' => 'Score Game', 'game_launch' => ['url' => '/games/score-game'], 'board' => 'default', 'date' => '2026-08-26'];
    }

    private function between(string $html, string $start, string $end): string
    {
        $startAt = strpos($html, $start);
        $endAt = strpos($html, $end, $startAt === false ? 0 : $startAt);
        self::assertNotFalse($startAt);
        self::assertNotFalse($endAt);
        return substr($html, $startAt, $endAt - $startAt);
    }
}
