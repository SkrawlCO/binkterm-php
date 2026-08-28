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
        array $translationOverrides = [],
        array $experienceStates = [],
        ?array $currentUser = null
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
            'ui.webdoors.filter_controls' => 'Filter Experiences',
            'ui.webdoors.filter_search_label' => 'Search Experiences',
            'ui.webdoors.filter_search_placeholder' => 'Search by name or description',
            'ui.webdoors.filter_live' => 'Live Now filter',
            'ui.webdoors.filter_multiplayer' => 'Multiplayer filter',
            'ui.webdoors.filter_web_available' => 'Web available',
            'ui.webdoors.filter_telnet_available' => 'Telnet available',
            'ui.webdoors.clear_filters' => 'Clear filters',
            'ui.webdoors.no_filter_matches' => 'No matching Experiences.',
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
            'ui.webdoors.playing_with' => 'Playing with',
            'ui.webdoors.roster_more' => '+{count} more',
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
            'experience_states' => $experienceStates,
            'current_user' => $currentUser,
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
        self::assertStringNotContainsString('id="continue-playing-title"', $html);
        self::assertStringNotContainsString('id="live-now-title"', $html);
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
        self::assertLessThan(
            strpos($section, '/experiences/two'),
            strpos($section, '/experiences/one')
        );
        self::assertLessThan(
            strpos($section, '/experiences/three'),
            strpos($section, '/experiences/two')
        );
    }

    public function testAllExperienceFiltersExposeOnlyNormalizedPublicState(): void
    {
        $game = $this->game('filter-entry', 2);
        $view = &$game['experience_presentation'];
        $view['name'] = 'Public Name';
        $view['description'] = 'Public description';
        $view['backend']['label'] = 'Private implementation label';
        $view['capabilities']['multiplayer'] = false;
        $view['surfaces']['web'] = 'full';
        $view['surfaces']['telnet'] = 'planned';

        $section = $this->between(
            $this->renderHub([$game]),
            'All Experiences',
            'Community Scoreboard'
        );

        self::assertStringContainsString('data-experience-filter-controls', $section);
        self::assertStringContainsString('type="search"', $section);
        self::assertStringContainsString('data-filter-name="Public Name"', $section);
        self::assertStringContainsString('data-filter-description="Public description"', $section);
        self::assertStringContainsString('data-filter-player-count="2"', $section);
        self::assertStringContainsString('data-filter-multiplayer="0"', $section);
        self::assertStringContainsString('data-filter-web="full"', $section);
        self::assertStringContainsString('data-filter-telnet="planned"', $section);

        self::assertMatchesRegularExpression(
            '/data-experience-filter-item(?:(?!Private implementation label).)*data-filter-telnet="planned"/s',
            $section
        );
    }

    public function testFiltersTargetOnlyAllExperiences(): void
    {
        $library = $this->game('library-only');
        $continue = $this->game('continue-only', 1, true);
        $live = $this->game('live-only', 2);
        $html = $this->renderHub([$library], [$continue], [$live]);

        self::assertSame(1, substr_count($html, 'data-filter-name="'));
        self::assertStringNotContainsString(
            'data-experience-filter-item',
            $this->between($html, 'Continue Playing', 'Live Now')
        );
        self::assertStringNotContainsString(
            'data-experience-filter-item',
            $this->between($html, 'Live Now', 'All Experiences')
        );
        self::assertStringNotContainsString(
            'data-filter-name="',
            $this->between($html, 'Community Scoreboard', '</section>')
        );
    }

    public function testFilterEmptyStateAndResetUseTranslations(): void
    {
        $html = $this->renderHub(
            [$this->game('filter-copy')],
            [],
            [],
            [],
            false,
            [
                'ui.webdoors.clear_filters' => 'Zurücksetzen',
                'ui.webdoors.no_filter_matches' => 'Keine passenden Einträge.',
            ]
        );

        self::assertStringContainsString('Keine passenden Einträge.', $html);
        self::assertSame(2, substr_count($html, '>Zurücksetzen</button>'));
        self::assertStringContainsString('data-experience-filter-empty aria-live="polite"', $html);
    }

    public function testCatalogEmptyStateDoesNotRenderFilterControls(): void
    {
        $html = $this->renderHub([]);

        self::assertStringNotContainsString('data-experience-filter-controls', $html);
        self::assertStringNotContainsString('class="alert alert-info mt-3 mb-0 d-none experience-filter-empty"', $html);
        self::assertStringContainsString('ui.webdoors.no_games_available', $html);
    }

    public function testFilterScriptUsesNormalizedAndSemanticsWithoutReordering(): void
    {
        $html = $this->renderHub([$this->game('filter-script')]);

        self::assertStringContainsString("Number(item.dataset.filterPlayerCount || 0) > 0", $html);
        self::assertStringContainsString("item.dataset.filterMultiplayer === '1'", $html);
        self::assertStringContainsString("item.dataset.filterWeb === 'full'", $html);
        self::assertStringContainsString("item.dataset.filterTelnet === 'full'", $html);
        self::assertStringContainsString("matchesSearch\n                && matchesLive", $html);
        self::assertStringContainsString('item.hidden = !visible;', $html);
        self::assertStringNotContainsString('appendChild(item)', $html);
        self::assertStringNotContainsString('dataset.filterBackend', $html);
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
        self::assertSame(1, substr_count($html, 'window.setInterval('));
        self::assertStringContainsString("'/api/experiences/'", $html);
        self::assertStringContainsString('updateFilterOccupancy(element, playerCount);', $html);
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

    // ---- Slice 5D: people on the hub ----

    public function testLiveNowShowsPlayerRosterFromAuthorizedState(): void
    {
        $game = $this->game('green-dragon', 3);
        $html = $this->renderHub(
            [$game],
            [],
            [$game],
            [],
            false,
            [],
            ['green-dragon' => $this->rosterState('green-dragon', ['Skrawl', 'Bard', 'Rogue'])]
        );

        $liveSection = $this->between($html, 'id="live-now-title"', 'id="all-experiences-title"');

        self::assertStringContainsString('experience-hub-roster', $liveSection);
        self::assertStringContainsString('href="/profile/Skrawl"', $liveSection);
        self::assertStringContainsString('href="/profile/Bard"', $liveSection);
        self::assertStringContainsString('href="/profile/Rogue"', $liveSection);
        // Public identity only — no session id / node number / timing leakage.
        $roster = $this->between($liveSection, 'experience-hub-roster', '</div>');
        self::assertStringNotContainsString('sess-', $roster);
        self::assertDoesNotMatchRegularExpression('/\bnode\b/i', $roster);
        self::assertStringNotContainsString('started_at', $roster);
        self::assertStringNotContainsString('1756000', $roster);
        // Live Now uses no lead-in prefix.
        self::assertStringNotContainsString('Playing with', $liveSection);
    }

    public function testLiveNowRosterIsCappedWithOverflowLinkToDetail(): void
    {
        $game = $this->game('green-dragon', 7);
        $html = $this->renderHub(
            [$game],
            [],
            [$game],
            [],
            false,
            [],
            ['green-dragon' => $this->rosterState(
                'green-dragon',
                ['Skrawl', 'Bard', 'Rogue', 'Alice', 'Bob', 'Cara', 'Dee']
            )]
        );

        $liveSection = $this->between($html, 'id="live-now-title"', 'id="all-experiences-title"');

        // First 4 shown, 5th+ collapsed.
        self::assertStringContainsString('href="/profile/Alice"', $liveSection);
        self::assertStringNotContainsString('href="/profile/Bob"', $liveSection);
        self::assertStringContainsString('+3 more', $liveSection);
        self::assertStringContainsString(
            'class="experience-hub-roster-more" href="/experiences/green-dragon"',
            $liveSection
        );
    }

    public function testContinuePlayingShowsCoParticipantsExcludingViewer(): void
    {
        $game = $this->game('green-dragon', 3, true);
        $html = $this->renderHub(
            [$game],
            [$game],
            [],
            [],
            false,
            [],
            ['green-dragon' => $this->rosterState('green-dragon', ['Skrawl', 'Bard', 'Rogue'])],
            ['user_id' => 1] // Skrawl
        );

        $resumeSection = $this->between($html, 'id="continue-playing-title"', 'id="all-experiences-title"');

        self::assertStringContainsString('Playing with', $resumeSection);
        self::assertStringContainsString('href="/profile/Bard"', $resumeSection);
        self::assertStringContainsString('href="/profile/Rogue"', $resumeSection);
        // The viewer is not listed among the co-participants.
        self::assertStringNotContainsString('href="/profile/Skrawl"', $resumeSection);
    }

    public function testContinuePlayingRendersNoCoParticipantLineWhenViewerIsAlone(): void
    {
        $game = $this->game('green-dragon', 1, true);
        $html = $this->renderHub(
            [$game],
            [$game],
            [],
            [],
            false,
            [],
            ['green-dragon' => $this->rosterState('green-dragon', ['Skrawl'])],
            ['user_id' => 1]
        );

        $resumeSection = $this->between($html, 'id="continue-playing-title"', 'id="all-experiences-title"');

        self::assertStringNotContainsString('Playing with', $resumeSection);
        self::assertStringNotContainsString('experience-hub-roster', $resumeSection);
    }

    public function testHubRendersNoRosterUiWhenStateIsMissing(): void
    {
        $game = $this->game('green-dragon', 2);
        $html = $this->renderHub([$game], [], [$game], [], false, [], []); // no experience_states

        $liveSection = $this->between($html, 'id="live-now-title"', 'id="all-experiences-title"');

        self::assertStringNotContainsString('experience-hub-roster', $liveSection);
    }

    public function testAllExperiencesCardsDoNotGainPlayerNameRosters(): void
    {
        $game = $this->game('green-dragon', 3);
        $html = $this->renderHub(
            [$game],
            [],
            [$game],
            [],
            false,
            [],
            ['green-dragon' => $this->rosterState('green-dragon', ['Skrawl', 'Bard', 'Rogue'])]
        );

        $catalogSection = $this->between(
            $html,
            'data-experience-filter-root',
            'community-scoreboard-title'
        );

        self::assertStringContainsString('experience-library-card', $catalogSection);
        self::assertStringNotContainsString('experience-hub-roster', $catalogSection);
        self::assertStringNotContainsString('href="/profile/Skrawl"', $catalogSection);
    }

    public function testHubPresenceJsConsumes5cPresentationCapacity(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/templates/webdoors.twig');
        self::assertIsString($source);

        // The confirmed 5C bug path is gone.
        self::assertStringNotContainsString('state.experience?.capacity', $source);
        self::assertStringNotContainsString('state.experience', $source);

        // Live presence reconciliation reads the canonical 5C slice.
        self::assertStringContainsString(
            'presentation.capacity && presentation.capacity.max_sessions',
            $source
        );
        self::assertStringContainsString('renderPresence(element, payload)', $source);
        self::assertStringContainsString('const presentation = payload.presentation || {};', $source);
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

    /**
     * @param list<string> $usernames
     * @return array<string,mixed> one experience_states[] entry
     */
    private function rosterState(string $id, array $usernames, int $firstUserId = 1): array
    {
        $players = [];
        $uid = $firstUserId;
        foreach ($usernames as $username) {
            $players[] = [
                'user_id' => $uid,
                'username' => $username,
                'session_id' => 'sess-' . $uid,
                'presence' => 'Playing ' . ucfirst($id),
                'presence_state' => 'playing',
                'node' => $uid,
                'started_at' => 1_756_000_000 + $uid,
            ];
            $uid++;
        }

        return [
            'experience' => ['id' => $id],
            'active' => $players !== [],
            'session_count' => count($players),
            'player_count' => count($players),
            'players' => $players,
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
