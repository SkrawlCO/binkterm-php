<?php

declare(strict_types=1);

namespace BinktermPHP\Tests\Unit;

use BinktermPHP\CrossroadsShelves;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class GamesHubTemplateTest extends TestCase
{
    private function renderHub(
        array $games,
        array $yourPlaces = [],
        array $liveExperiences = [],
        array $leaderboard = [],
        bool $scoreboardExpanded = false,
        array $translationOverrides = [],
        array $experienceStates = [],
        ?array $currentUser = null,
        ?int $aroundActivePlayers = null,
        ?int $aroundActiveExperiences = null,
        ?bool $showGlobalPresenceSummary = null,
        array $recentActivity = []
    ): string {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'));
        $translations = [
            'ui.webdoors.page_title' => 'Crossroads',
            'ui.webdoors.heading' => 'Crossroads',
            'ui.webdoors.description' => 'Where the people, games, and worlds of {system_name} come together.',
            'ui.webdoors.your_places' => 'Your Places',
            'ui.webdoors.your_places_description' => 'Resume description',
            'ui.webdoors.participating_now' => 'You are participating now',
            'ui.webdoors.live_now' => 'Live Now',
            'ui.webdoors.live_now_description' => 'Live description',
            'ui.webdoors.live' => 'Live',
            'ui.webdoors.recent_activity' => 'Recently in the Crossroads',
            'ui.webdoors.recent_activity_played' => 'played',
            'ui.webdoors.recent_activity_first_played' => 'first played',
            'ui.webdoors.experiences' => 'Experiences',
            'ui.webdoors.all_experiences' => 'All Experiences',
            'ui.webdoors.all_experiences_description' => 'All description',
            'time.just_now' => 'just now',
            'time.minutes_ago' => '{count} minutes ago',
            'time.hours_ago' => '{count} hour{suffix} ago',
            'time.yesterday' => 'yesterday',
            'time.days_ago' => '{count} days ago',
            'time.suffix_singular' => '',
            'time.suffix_plural' => 's',
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
            'ui.webdoors.enter' => 'Enter',
            'ui.webdoors.full_capacity' => 'Full',
            'ui.webdoors.coming_soon' => 'Coming soon',
            'ui.webdoors.terminal_only' => 'Terminal only',
            'ui.webdoors.category_game' => 'Game',
            'ui.webdoors.category_gateway' => 'Gateway',
            'ui.webdoors.multiplayer' => 'Multiplayer',
            'ui.webdoors.single_player' => 'Single player',
            'ui.webdoors.players_online' => '{count} online',
            'ui.webdoors.playing_with' => 'Playing with',
            'ui.webdoors.roster_more' => '+{count} more',
            'ui.webdoors.around_active' => '{players} players in {experiences} Experiences right now',
            'ui.webdoors.around_active_1e' => '{players} players in 1 Experience right now',
            'ui.webdoors.around_active_1p' => '1 player in {experiences} Experiences right now',
            'ui.webdoors.around_active_1p_1e' => '1 player in 1 Experience right now',
            'ui.webdoors.around_quiet' => 'The Crossroads are quiet right now.',
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

        // Default the "Around the Crossroads" aggregates from the supplied
        // states the same way routes/webdoor-routes.php does, unless a test
        // passes explicit values.
        if ($aroundActivePlayers === null || $aroundActiveExperiences === null) {
            $activeExperiences = 0;
            $userIds = [];
            foreach ($experienceStates as $state) {
                if ((int)($state['player_count'] ?? 0) > 0) {
                    $activeExperiences++;
                }
                foreach ($state['players'] ?? [] as $player) {
                    $uid = (int)($player['user_id'] ?? 0);
                    if ($uid > 0) {
                        $userIds[$uid] = true;
                    }
                }
            }
            $aroundActivePlayers ??= count($userIds);
            $aroundActiveExperiences ??= $activeExperiences;
        }

        if ($showGlobalPresenceSummary === null) {
            $viewerId = (int)($currentUser['user_id'] ?? $currentUser['id'] ?? 0);
            $viewerIsParticipating = false;
            $activeUserIds = [];
            foreach ($experienceStates as $state) {
                foreach ($state['players'] ?? [] as $player) {
                    $playerId = (int)($player['user_id'] ?? 0);
                    if ($playerId > 0) {
                        $activeUserIds[$playerId] = true;
                    }
                    if ($viewerId > 0 && $playerId === $viewerId) {
                        $viewerIsParticipating = true;
                    }
                }
            }
            $showGlobalPresenceSummary = !(
                $viewerIsParticipating
                && count($activeUserIds) === 1
                && isset($activeUserIds[$viewerId])
            );
        }

        return $twig->render('webdoors.twig', [
            'system_name' => 'L33Test Gaming',
            'games' => $games,
            'catalog_shelves' => CrossroadsShelves::compose($games),
            'your_places' => $yourPlaces,
            'live_experiences' => $liveExperiences,
            'recent_activity' => $recentActivity,
            'experience_states' => $experienceStates,
            'current_user' => $currentUser,
            'around_active_players' => $aroundActivePlayers,
            'around_active_experiences' => $aroundActiveExperiences,
            'show_global_presence_summary' => $showGlobalPresenceSummary,
            'leaderboard' => $leaderboard,
            'leaderboard_month_label' => 'August 2026',
            'leaderboard_month_offset' => 0,
            'scoreboard_expanded' => $scoreboardExpanded,
        ]);
    }

    public function testPageUsesApprovedCrossroadsHierarchy(): void
    {
        $mine = $this->game('mine', 1, true);
        $html = $this->renderHub(
            [$this->game('library-entry')],
            [$mine],
            [$this->game('live-entry', 2)],
            recentActivity: [$this->recentEntry('Bard', 'live-entry', 'Live Entry')]
        );

        // Crossroads is the human-facing identity of the place.
        self::assertStringContainsString('<title>Crossroads - L33Test Gaming</title>', $html);
        self::assertStringContainsString('>Crossroads</h1>', $html);

        // Who is around? -> Where do I belong? -> Who has been through? ->
        // What else is here? -> scores.
        $live = strpos($html, 'id="live-now-title"');
        $places = strpos($html, 'id="your-places-title"');
        $recent = strpos($html, 'id="recent-activity-title"');
        $experiences = strpos($html, 'id="experiences-title"');
        $scoreboard = strpos($html, 'id="community-scoreboard-title"');
        self::assertNotFalse($live);
        self::assertNotFalse($places);
        self::assertNotFalse($recent);
        self::assertNotFalse($experiences);
        self::assertNotFalse($scoreboard);
        self::assertLessThan($places, $live);
        self::assertLessThan($recent, $places);
        self::assertLessThan($experiences, $recent);
        self::assertLessThan($scoreboard, $experiences);
    }

    public function testOptionalContextualSectionsAreOmittedWhenEmpty(): void
    {
        $html = $this->renderHub([$this->game('only-entry')]);
        self::assertStringNotContainsString('id="your-places-title"', $html);
        self::assertStringNotContainsString('id="live-now-title"', $html);
        self::assertStringNotContainsString('id="recent-activity-title"', $html);
        self::assertStringContainsString('id="experiences-title"', $html);
    }

    public function testRecentActivityListsExistingPlayFootprints(): void
    {
        $html = $this->renderHub(
            [$this->game('lord'), $this->game('usurper')],
            recentActivity: [
                $this->recentEntry('Bard', 'lord', 'Legend of the Red Dragon', 'play', 1200),
                $this->recentEntry('Skrawl', 'usurper', 'Usurper Reborn', 'first_play', 7200),
            ]
        );

        $section = $this->between(
            $html,
            'id="recent-activity-title"',
            'id="experiences-title"'
        );

        self::assertStringContainsString('Bard', $section);
        self::assertStringContainsString('played', $section);
        self::assertStringContainsString(
            '<a href="/experiences/lord" class="text-decoration-none">Legend of the Red Dragon</a>',
            $section
        );
        self::assertStringContainsString('Skrawl', $section);
        self::assertStringContainsString('first played', $section);
        self::assertStringContainsString(
            '<a href="/experiences/usurper" class="text-decoration-none">Usurper Reborn</a>',
            $section
        );

        // Deliberately quiet: no cards, avatars, reactions, counts, durations,
        // surface labels, or pagination.
        self::assertStringNotContainsString('experience-live-card', $section);
        self::assertStringNotContainsString('experience-resume-card', $section);
        self::assertStringNotContainsString('data-experience-filter-item', $section);
        self::assertStringNotContainsString('load more', strtolower($section));
        self::assertStringNotContainsString('minutes online', $section);
    }

    public function testRecentActivitySectionHasNoSubtitle(): void
    {
        $html = $this->renderHub(
            [$this->game('lord')],
            recentActivity: [$this->recentEntry('Bard', 'lord', 'Legend of the Red Dragon')],
            translationOverrides: ['ui.webdoors.recent_activity_description' => 'SHOULD NOT RENDER']
        );

        $heading = $this->between($html, 'aria-labelledby="recent-activity-title"', '<ul class="list-unstyled');
        // Only the h2 title, no descriptive <p> beneath it.
        self::assertStringContainsString('>Recently in the Crossroads</h2>', $heading);
        self::assertStringNotContainsString('SHOULD NOT RENDER', $html);
        self::assertStringNotContainsString('recent_activity_description', $html);
        self::assertStringNotContainsString('<p ', $heading);
    }

    public function testRecentActivityRendersRelativeTimeFromOccurredAt(): void
    {
        $html = $this->renderHub(
            [$this->game('lord')],
            recentActivity: [
                $this->recentEntry('Bard', 'lord', 'Legend of the Red Dragon', 'play', 1500),
            ]
        );

        // ~25 minutes ago -> the existing time.minutes_ago convention.
        self::assertMatchesRegularExpression('/\b2[0-9] minutes ago\b/', $html);
    }

    public function testRecentActivityIsHiddenWhenNoRows(): void
    {
        $html = $this->renderHub([$this->game('lord')], recentActivity: []);
        self::assertStringNotContainsString('id="recent-activity-title"', $html);
        self::assertStringNotContainsString('experience-recent-activity', $html);
        // No empty-state card either.
        self::assertStringNotContainsString('Recently in the Crossroads', $html);
    }

    public function testRecentActivityRendersAtMostFiveRows(): void
    {
        $entries = [];
        foreach (range(1, 9) as $i) {
            $entries[] = $this->recentEntry('User' . $i, 'lord', 'Legend of the Red Dragon', 'play', $i * 300);
        }

        $html = $this->renderHub([$this->game('lord')], recentActivity: $entries);
        $section = $this->between($html, 'id="recent-activity-title"', 'id="experiences-title"');

        self::assertSame(5, substr_count($section, '<li class="text-muted small py-1">'));
    }

    public function testRecentActivityTemplateRendersEachSuppliedFootprint(): void
    {
        // Collapsing repeated same-user/same-Experience plays is the read
        // model's job (ExperienceActivity::recentAcrossCatalog(), covered in
        // ExperienceActivityTest). The template renders exactly the already
        // distinct footprints the route hands it, in order.
        $entries = [
            $this->recentEntry('Bard', 'lord', 'Legend of the Red Dragon', 'play', 2460),
            $this->recentEntry('Skrawl', 'lord', 'Legend of the Red Dragon', 'play', 2520),
            $this->recentEntry('Bard', 'usurper', 'Usurper Reborn', 'play', 10800),
        ];

        $html = $this->renderHub(
            [$this->game('lord'), $this->game('usurper')],
            recentActivity: $entries
        );
        $section = $this->between($html, 'id="recent-activity-title"', 'id="experiences-title"');

        self::assertSame(3, substr_count($section, '<li class="text-muted small py-1">'));
        self::assertLessThan(
            strpos($section, 'Usurper Reborn'),
            strpos($section, 'Legend of the Red Dragon')
        );
    }

    public function testYourPlacesPrioritizesReturnOverPlay(): void
    {
        $participating = $this->game('resume-me', 1, true);
        $section = $this->between(
            $this->renderHub([$participating], [$participating]),
            'id="your-places-title"',
            'id="experiences-title"'
        );
        self::assertStringContainsString('href="/experiences/resume-me"', $section);
        self::assertStringContainsString('>Return</a>', $section);
        self::assertStringNotContainsString('>Play</a>', $section);

        // Your Places "Return" is a text-only Crossroads action: no
        // door/fuel-pump icon, no replacement icon on the fidonet button.
        self::assertStringContainsString(
            '<a href="/experiences/resume-me" class="btn btn-fidonet btn-sm">Return</a>',
            $section
        );
        self::assertStringNotContainsString('fa-sign-in-alt', $section);
        self::assertDoesNotMatchRegularExpression(
            '/class="btn btn-fidonet[^"]*"[^>]*>\s*<i /s',
            $section
        );
        // The Details button is unchanged.
        self::assertStringContainsString(
            '<a href="/experiences/resume-me" class="btn btn-outline-secondary btn-sm">Details</a>',
            $section
        );
    }

    public function testLiveNowContainsOnlyComposedOccupiedExperiences(): void
    {
        $live = $this->game('occupied', 2);
        $offline = $this->game('offline');
        $section = $this->between(
            $this->renderHub([$live, $offline], [], [$live]),
            'id="live-now-title"',
            'id="experiences-title"'
        );
        self::assertStringContainsString('/experiences/occupied', $section);
        self::assertStringNotContainsString('/experiences/offline', $section);
        self::assertStringContainsString('2 online', $section);
    }

    public function testExperiencesSectionContainsCompleteVisibleCatalog(): void
    {
        $one = $this->game('one', 1, true);
        $two = $this->game('two', 2);
        $three = $this->game('three');
        $section = $this->between(
            $this->renderHub([$one, $two, $three], [$one], [$two]),
            'id="experiences-title"',
            'id="community-scoreboard-title"'
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
            'id="experiences-title"',
            'id="community-scoreboard-title"'
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

    public function testFiltersTargetOnlyTheExperiencesSection(): void
    {
        $library = $this->game('library-only');
        $mine = $this->game('mine-only', 1, true);
        $live = $this->game('live-only', 2);
        $html = $this->renderHub([$library], [$mine], [$live]);

        self::assertSame(1, substr_count($html, 'data-filter-name="'));
        self::assertStringNotContainsString(
            'data-experience-filter-item',
            $this->between($html, 'id="live-now-title"', 'id="your-places-title"')
        );
        self::assertStringNotContainsString(
            'data-experience-filter-item',
            $this->between($html, 'id="your-places-title"', 'id="experiences-title"')
        );
        self::assertStringNotContainsString(
            'data-filter-name="',
            $this->between($html, 'id="community-scoreboard-title"', '</section>')
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

    // ---- Slice 5F: card de-clutter + truthful status/action ----

    /** The All Experiences card body for a single supplied game. */
    private function libraryCard(array $game, array $translationOverrides = []): string
    {
        return $this->between(
            $this->renderHub([$game], [], [], [], false, $translationOverrides),
            'data-experience-filter-root',
            'community-scoreboard-title'
        );
    }

    public function testLibraryCardDropsBackendLabelFreeBadgeAndSurfacePills(): void
    {
        $card = $this->libraryCard($this->game('lateania', 1));

        // No implementation vocabulary.
        self::assertStringNotContainsString('Native', $card);
        self::assertStringNotContainsString('WebDoor', $card);
        self::assertStringNotContainsString('experience-technical-label', $card);
        // No routine "Free".
        self::assertStringNotContainsString('>Free<', $card);
        // No routine Web/Telnet availability pills.
        self::assertStringNotContainsString('experience-surface-token', $card);
        self::assertStringNotContainsString('experience-surface-label', $card);
        self::assertStringNotContainsString('experience-surface-state', $card);
        // No separate Details button.
        self::assertStringNotContainsString('>Details</a>', $card);
        self::assertStringNotContainsString("ui.webdoors.details", $card);
    }

    public function testLibraryCardKeepsTitleLinkTaxonomyAndOccupancy(): void
    {
        $card = $this->libraryCard($this->game('lateania', 3));

        self::assertStringContainsString(
            '<a href="/experiences/lateania" class="experience-title-link">Lateania</a>',
            $card
        );
        self::assertStringContainsString('experience-card-taxonomy', $card);
        self::assertStringContainsString('Game · Multiplayer', $card);
        // Live occupancy is preserved (player_count, not session_count).
        self::assertStringContainsString('3 online', $card);
    }

    public function testLibraryCardShowsCostChipOnlyWhenNonZero(): void
    {
        $free = $this->game('free-one', 1);
        self::assertStringNotContainsString('credits', $this->libraryCard($free));
        self::assertStringNotContainsString('>Free<', $this->libraryCard($free));

        $paid = $this->game('paid-one', 1);
        $paid['experience_presentation']['cost'] = ['credits' => 5, 'free' => false];
        $paidCard = $this->libraryCard($paid);
        self::assertStringContainsString('experience-cost-token', $paidCard);
        self::assertStringContainsString('5 credits', $paidCard);
    }

    public function testLibraryCardActionIsEnterForAvailableNonParticipant(): void
    {
        $card = $this->libraryCard($this->game('available-one'));

        // Text-only action — no door/fuel-pump icon, no replacement icon.
        self::assertStringContainsString(
            '<a href="/experiences/available-one" class="btn btn-fidonet btn-sm experience-card-action">Enter</a>',
            $card
        );
        self::assertStringNotContainsString('>Play</a>', $card);
        self::assertStringNotContainsString('>Open</a>', $card);
        self::assertStringNotContainsString('fa-door-open', $card);
        self::assertDoesNotMatchRegularExpression(
            '/class="btn btn-fidonet[^"]*"[^>]*>\s*<i /s',
            $card
        );
    }

    public function testLibraryCardActionIsReturnForParticipant(): void
    {
        $card = $this->libraryCard($this->game('resume-one', 2, true));

        // Text-only action — no icon.
        self::assertStringContainsString(
            '<a href="/experiences/resume-one" class="btn btn-fidonet btn-sm experience-card-action">Return</a>',
            $card
        );
        self::assertStringNotContainsString('>Enter</a>', $card);
        self::assertStringNotContainsString('fa-sign-in-alt', $card);
    }

    public function testLibraryCardAtCapacityIsFullAndNotLive(): void
    {
        $game = $this->game('busy-one', 10, false, 'at_capacity');
        $game['experience_presentation']['capacity']['max_sessions'] = 10;
        $game['experience_presentation']['runtime']['session_count'] = 10;
        $card = $this->libraryCard($game);

        // Occupancy reads Full · N/M.
        self::assertStringContainsString('experience-presence--full', $card);
        self::assertMatchesRegularExpression('/Full\s*·\s*10\/10/s', $card);
        // Action is a disabled, non-interactive "Full" — never a live link.
        self::assertStringContainsString(
            '<span class="btn btn-outline-secondary btn-sm experience-card-action disabled" aria-disabled="true">Full</span>',
            $card
        );
        self::assertDoesNotMatchRegularExpression(
            '/<a [^>]*class="btn btn-fidonet[^"]*"[^>]*>(?:(?!<\/a>).)*(?:Enter|Play|Return)/s',
            $card
        );
    }

    public function testLibraryCardPlannedIsComingSoonAndMutedAndNotLive(): void
    {
        $game = $this->game('soon-one', 0, false, 'planned');
        $game['experience_presentation']['surfaces']['web'] = 'planned';
        $game['experience_presentation']['surfaces']['current'] = 'planned';
        $card = $this->libraryCard($game);

        self::assertStringContainsString('experience-library-card--muted', $card);
        self::assertStringContainsString(
            '<span class="btn btn-outline-secondary btn-sm experience-card-action disabled" aria-disabled="true">Coming soon</span>',
            $card
        );
        self::assertStringNotContainsString('btn btn-fidonet', $card);
    }

    public function testLibraryCardWebUnavailableCannotLookPlayable(): void
    {
        // Playable from a terminal.
        $terminalOnly = $this->game('term-one', 0, false, 'unavailable');
        $terminalOnly['experience_presentation']['surfaces']['web'] = 'unavailable';
        $terminalOnly['experience_presentation']['surfaces']['telnet'] = 'full';
        $card = $this->libraryCard($terminalOnly);
        self::assertStringContainsString('experience-library-card--muted', $card);
        self::assertStringContainsString('Terminal only', $card);
        self::assertStringNotContainsString('btn btn-fidonet', $card);
        self::assertStringNotContainsString('btn-outline-secondary btn-sm experience-card-action disabled', $card);

        // Not playable anywhere on the hub.
        $none = $this->game('gone-one', 0, false, 'unavailable');
        $none['experience_presentation']['surfaces']['web'] = 'unavailable';
        $none['experience_presentation']['surfaces']['telnet'] = 'unavailable';
        $noneCard = $this->libraryCard($none);
        self::assertStringContainsString('Unavailable', $noneCard);
        self::assertStringNotContainsString('btn btn-fidonet', $noneCard);
    }

    public function testLibraryCardNeverDeepLinksToLaunchUrl(): void
    {
        foreach (['available', 'participating', 'at_capacity', 'planned', 'unavailable'] as $status) {
            $game = $this->game('deep-' . $status, 1, $status === 'participating', $status);
            $card = $this->libraryCard($game);
            self::assertStringNotContainsString('/games/nativedoors/', $card, "status {$status} leaked launch.url");
            self::assertStringNotContainsString('launch.url', $card);
        }
    }

    public function testHubPresenceJsKeepsAtCapacityLabelTruthfulOnRefresh(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/templates/webdoors.twig');
        self::assertIsString($source);

        self::assertStringContainsString(
            'presentation.capacity && presentation.capacity.at_capacity',
            $source
        );
        self::assertStringContainsString('fullCapacityT()', $source);
        self::assertStringContainsString(
            "element.classList.toggle('experience-presence--full', atCapacity)",
            $source
        );
    }

    public function testDeclutteredCardsDoNotChangeFilterDataAttributes(): void
    {
        $game = $this->game('filter-safe', 2);
        $game['experience_presentation']['surfaces']['telnet'] = 'planned';
        $card = $this->libraryCard($game);

        self::assertStringContainsString('data-filter-web="full"', $card);
        self::assertStringContainsString('data-filter-telnet="planned"', $card);
        self::assertStringContainsString('data-filter-player-count="2"', $card);
        self::assertStringContainsString('data-filter-multiplayer="1"', $card);
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
        self::assertSame(1, substr_count($route, "getEnabledGames(\$user, 'web')"));
        self::assertStringContainsString('ExperienceScoreboard())->getMonthlyScores(', $route);
        self::assertStringNotContainsString('ucfirst($row[\'game_id\'])', $route);

        // Recently in the Crossroads: exactly one bounded recent-activity read,
        // reusing the already-authorized $games catalog — no extra discovery,
        // no collection ExperienceState read, no per-Experience query.
        self::assertSame(1, substr_count($route, 'recentAcrossCatalog('));
        self::assertStringContainsString('->recentAcrossCatalog($games, 5)', $route);
        self::assertStringContainsString("'recent_activity' => \$recentActivity", $route);

        // Crossroads arrival: Your Places is canonical viewer participation,
        // Live Now is the shared "distinct other caller" predicate, and the two
        // lists are populated by independent `if` blocks (no `elseif`) so an
        // Experience the viewer shares with another caller lands in both.
        $loop = $this->between($route, '$viewerIsParticipating = false;', 'unset($game);');
        self::assertStringContainsString('if ($viewerPlayer !== null) {', $loop);
        self::assertStringContainsString('$yourPlaces[] = $game;', $loop);
        self::assertStringContainsString(
            'if (\BinktermPHP\ExperienceParticipation::hasDistinctOtherPlayer(',
            $loop
        );
        self::assertStringContainsString('$liveExperiences[] = $game;', $loop);
        self::assertStringNotContainsString('elseif', $loop);
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

        $liveSection = $this->between($html, 'id="live-now-title"', 'id="experiences-title"');

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

        $liveSection = $this->between($html, 'id="live-now-title"', 'id="experiences-title"');

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

        $resumeSection = $this->between($html, 'id="your-places-title"', 'id="experiences-title"');

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

        $resumeSection = $this->between($html, 'id="your-places-title"', 'id="experiences-title"');

        self::assertStringNotContainsString('Playing with', $resumeSection);
        self::assertStringNotContainsString('experience-hub-roster', $resumeSection);
    }

    public function testHubRendersNoRosterUiWhenStateIsMissing(): void
    {
        $game = $this->game('green-dragon', 2);
        $html = $this->renderHub([$game], [], [$game], [], false, [], []); // no experience_states

        $liveSection = $this->between($html, 'id="live-now-title"', 'id="experiences-title"');

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

    // ---- Slice 5E: "Around the Crossroads" presence summary ----

    private function aroundLine(string $html): string
    {
        self::assertSame(
            1,
            preg_match(
                '/<p class="experiences-around[^"]*"[^>]*>\s*(.+?)\s*<\/p>/s',
                $html,
                $m
            ),
            'expected exactly one .experiences-around line'
        );

        return html_entity_decode(trim($m[1]), ENT_QUOTES);
    }

    public function testAroundSummaryIsPositionedBelowHeaderAboveContextualSections(): void
    {
        $game = $this->game('green-dragon', 2);
        $html = $this->renderHub(
            [$game],
            [$game],
            [$game],
            [],
            false,
            [],
            ['green-dragon' => $this->rosterState('green-dragon', ['Skrawl', 'Bard'])]
        );

        $headerPos = strpos($html, 'experiences-library-header');
        $aroundPos = strpos($html, 'experiences-around');
        $livePos = strpos($html, 'id="live-now-title"');
        $placesPos = strpos($html, 'id="your-places-title"');

        self::assertNotFalse($aroundPos);
        self::assertLessThan($aroundPos, $headerPos);
        self::assertLessThan($livePos, $aroundPos);
        self::assertLessThan($placesPos, $livePos);
    }

    public function testAroundSummaryShowsAggregateWhenPlayersAreActive(): void
    {
        $html = $this->renderHub(
            [$this->game('a', 1), $this->game('b', 2)],
            [],
            [$this->game('a', 1), $this->game('b', 2)],
            [],
            false,
            [],
            [
                'a' => $this->rosterState('a', ['Skrawl'], 1),
                'b' => $this->rosterState('b', ['Bard', 'Rogue'], 2),
            ]
        );

        // 3 distinct players across 2 active Experiences.
        self::assertSame('3 players in 2 Experiences right now', $this->aroundLine($html));
        self::assertStringContainsString('experiences-around--active', $html);
    }

    public function testAroundSummaryShowsSingleTruthfulQuietStateWhenNobodyIsActive(): void
    {
        $html = $this->renderHub([$this->game('a', 0)], [], [], [], false, [], []);

        self::assertSame('The Crossroads are quiet right now.', $this->aroundLine($html));
        // The "Around" line is the only quiet message: Live Now renders nothing.
        self::assertStringNotContainsString('id="live-now-title"', $html);
        self::assertStringNotContainsString('id="your-places-title"', $html);
        // Quiet state carries no "active" modifier (no green dot).
        self::assertStringNotContainsString('experiences-around--active', $html);
        // Not a Bootstrap alert.
        self::assertDoesNotMatchRegularExpression(
            '/<p class="experiences-around[^"]*"[^>]*>\s*.{0,20}alert/s',
            $html
        );
    }

    public function testAroundSummaryIsHiddenWhenViewerIsSoleActivePlayer(): void
    {
        $game = $this->game('lateania', 1, true);
        $html = $this->renderHub(
            games: [$game],
            yourPlaces: [$game],
            experienceStates: [
                'lateania' => $this->rosterState('lateania', ['Skrawl']),
            ],
            currentUser: ['user_id' => 1]
        );

        self::assertStringNotContainsString('experiences-around', $html);

        $continueSection = $this->between(
            $html,
            'id="your-places-title"',
            'id="experiences-title"'
        );
        self::assertStringContainsString('You are participating now', $continueSection);
        self::assertStringContainsString('>Return</a>', $continueSection);

        $librarySection = $this->between(
            $html,
            'data-experience-filter-root',
            'community-scoreboard-title'
        );
        self::assertStringContainsString('1 online · 1/8', $librarySection);
        self::assertStringNotContainsString('id="live-now-title"', $html);
    }

    public function testAroundSummaryRemainsWhenViewerHasCoPlayerInSameExperience(): void
    {
        $game = $this->game('lateania', 2, true);
        $html = $this->renderHub(
            games: [$game],
            yourPlaces: [$game],
            experienceStates: [
                'lateania' => $this->rosterState('lateania', ['Skrawl', 'Bard']),
            ],
            currentUser: ['user_id' => 1]
        );

        self::assertSame('2 players in 1 Experience right now', $this->aroundLine($html));
        self::assertStringContainsString('id="your-places-title"', $html);
        self::assertStringNotContainsString('id="live-now-title"', $html);
    }

    public function testAroundSummaryRemainsWhenOtherPlayerIsInAnotherExperience(): void
    {
        $viewerGame = $this->game('lateania', 1, true);
        $otherGame = $this->game('trade-wars', 1);
        $html = $this->renderHub(
            games: [$viewerGame, $otherGame],
            yourPlaces: [$viewerGame],
            liveExperiences: [$otherGame],
            experienceStates: [
                'lateania' => $this->rosterState('lateania', ['Skrawl'], 1),
                'trade-wars' => $this->rosterState('trade-wars', ['Bard'], 2),
            ],
            currentUser: ['user_id' => 1]
        );

        self::assertSame('2 players in 2 Experiences right now', $this->aroundLine($html));
        self::assertStringContainsString('id="your-places-title"', $html);
        self::assertStringContainsString('id="live-now-title"', $html);
    }

    public function testAroundSummaryRemainsForOneOtherPlayerWhenViewerIsNotParticipating(): void
    {
        $game = $this->game('trade-wars', 1);
        $html = $this->renderHub(
            games: [$game],
            liveExperiences: [$game],
            experienceStates: [
                'trade-wars' => $this->rosterState('trade-wars', ['Bard'], 2),
            ],
            currentUser: ['user_id' => 1]
        );

        self::assertSame('1 player in 1 Experience right now', $this->aroundLine($html));
        self::assertStringNotContainsString('id="your-places-title"', $html);
        self::assertStringContainsString('id="live-now-title"', $html);
    }

    /**
     * @dataProvider aroundSingularPluralProvider
     */
    public function testAroundSummarySingularAndPluralWording(
        int $players,
        int $experiences,
        string $expected
    ): void {
        $html = $this->renderHub(
            [$this->game('a', 1)],
            [],
            [],
            [],
            false,
            [],
            [],
            null,
            $players,
            $experiences
        );

        self::assertSame($expected, $this->aroundLine($html));
    }

    /** @return array<string,array{0:int,1:int,2:string}> */
    public static function aroundSingularPluralProvider(): array
    {
        return [
            '1p 1e' => [1, 1, '1 player in 1 Experience right now'],
            '1p 2e' => [1, 2, '1 player in 2 Experiences right now'],
            '2p 1e' => [2, 1, '2 players in 1 Experience right now'],
            '5p 3e' => [5, 3, '5 players in 3 Experiences right now'],
        ];
    }

    public function testAroundSummaryCountsDistinctUsersNotSessions(): void
    {
        // One user (id 7) holds two sessions in the same Experience: two rows
        // in players[], but player_count is 1.
        $state = [
            'experience' => ['id' => 'green-dragon'],
            'active' => true,
            'session_count' => 2,
            'player_count' => 1,
            'players' => [
                ['user_id' => 7, 'username' => 'Skrawl', 'session_id' => 'a', 'node' => 1, 'started_at' => 1_756_000_001],
                ['user_id' => 7, 'username' => 'Skrawl', 'session_id' => 'b', 'node' => 2, 'started_at' => 1_756_000_002],
            ],
        ];

        $html = $this->renderHub(
            [$this->game('green-dragon', 1)],
            [],
            [$this->game('green-dragon', 1)],
            [],
            false,
            [],
            ['green-dragon' => $state]
        );

        // 1 distinct player, not 2 sessions.
        self::assertSame('1 player in 1 Experience right now', $this->aroundLine($html));
    }

    public function testAroundSummaryDeduplicatesUserPresentInMultipleExperiences(): void
    {
        $html = $this->renderHub(
            [$this->game('a', 1), $this->game('b', 1)],
            [],
            [$this->game('a', 1), $this->game('b', 1)],
            [],
            false,
            [],
            [
                'a' => $this->rosterState('a', ['Skrawl'], 7),
                'b' => $this->rosterState('b', ['Skrawl'], 7),
            ]
        );

        // Same user in two Experiences -> 1 player, 2 Experiences.
        self::assertSame('1 player in 2 Experiences right now', $this->aroundLine($html));
    }

    public function testAroundActiveExperienceCountOnlyIncludesStatesWithPlayers(): void
    {
        $html = $this->renderHub(
            [$this->game('a', 2), $this->game('b', 0), $this->game('c', 1)],
            [],
            [$this->game('a', 2), $this->game('c', 1)],
            [],
            false,
            [],
            [
                'a' => $this->rosterState('a', ['Skrawl', 'Bard'], 1),
                'b' => $this->rosterState('b', [], 1),
                'c' => $this->rosterState('c', ['Rogue'], 3),
            ]
        );

        // 'b' has an empty roster -> excluded; 3 players across 2 Experiences.
        self::assertSame('3 players in 2 Experiences right now', $this->aroundLine($html));
    }

    public function testAroundSummaryRendersNoPlayerNames(): void
    {
        $html = $this->renderHub(
            [$this->game('a', 2)],
            [],
            [$this->game('a', 2)],
            [],
            false,
            [],
            ['a' => $this->rosterState('a', ['Skrawl', 'Bard'])]
        );

        // The summary itself carries counts only; names appear only in Live Now.
        $around = $this->between($html, 'experiences-around', '</p>');
        self::assertStringNotContainsString('Skrawl', $around);
        self::assertStringNotContainsString('Bard', $around);
        self::assertStringNotContainsString('/profile/', $around);
    }

    public function testAroundSummaryRouteAggregatesFromAuthorizedStateWithoutNewQuery(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/routes/webdoor-routes.php');
        self::assertIsString($source);

        $start = strpos($source, "SimpleRouter::get('/games'");
        $end = strpos($source, '// GET /games/dosdoors');
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $route = substr($source, $start, $end - $start);

        // Aggregation reuses the single bulk state read; no extra query / no
        // session table access / no public_activity inference.
        self::assertSame(1, substr_count($route, 'getExperienceStates('));
        self::assertStringContainsString('$aroundActivePlayers = count($aroundActiveUserIds);', $route);
        self::assertStringContainsString("(int)(\$aroundState['player_count'] ?? 0) > 0", $route);
        self::assertStringNotContainsString('session_count', substr($route, strpos($route, '$aroundActiveExperiences = 0;')));
        self::assertStringContainsString("'around_active_players' => \$aroundActivePlayers", $route);
        self::assertStringContainsString("'around_active_experiences' => \$aroundActiveExperiences", $route);
        self::assertStringContainsString('$viewerIsParticipating = true;', $route);
        self::assertStringContainsString('count($aroundActiveUserIds) === 1', $route);
        self::assertStringContainsString('isset($aroundActiveUserIds[$currentUserId])', $route);
        self::assertStringContainsString(
            "'show_global_presence_summary' => \$showGlobalPresenceSummary",
            $route
        );
    }

    private function game(
        string $id,
        int $playerCount = 0,
        bool $participating = false,
        ?string $statusCode = null
    ): array {
        $statusCode ??= $participating ? 'participating' : 'available';
        $atCapacity = $statusCode === 'at_capacity';
        $launchable = in_array($statusCode, ['participating', 'at_capacity', 'available'], true);

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
                'capabilities' => ['multiplayer' => true, 'player_mode' => 'multiplayer'],
                'capacity' => ['max_sessions' => 8, 'limited' => true, 'at_capacity' => $atCapacity],
                'cost' => ['credits' => 0, 'free' => true],
                'surfaces' => ['requested' => 'web', 'current' => 'full', 'web' => 'full', 'telnet' => 'full', 'static_launchable' => $launchable],
                'runtime' => ['supplied' => true, 'active' => $playerCount > 0, 'session_count' => $playerCount, 'player_count' => $playerCount, 'players' => []],
                'viewer' => ['participating' => $participating, 'blocked_by_capacity' => $atCapacity && !$participating],
                'status' => ['code' => $statusCode],
                'actions' => [
                    'primary' => $participating ? 'return' : ($statusCode === 'available' ? 'play' : $statusCode),
                    'details' => true,
                    'play' => !$participating && $launchable,
                    'return' => $participating,
                    'end_participation' => $participating,
                    'static_launchable' => $launchable,
                ],
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

    /**
     * One row as composed by ExperienceActivity::recentAcrossCatalog().
     * $secondsAgo controls the rendered relative time.
     */
    private function recentEntry(
        string $username,
        string $experienceId,
        string $experienceName,
        string $type = 'play',
        int $secondsAgo = 600
    ): array {
        return [
            'id' => random_int(1, 1_000_000),
            'type' => $type,
            'user_id' => random_int(2, 9999),
            'username' => $username,
            'experience_id' => $experienceId,
            'experience_name' => $experienceName,
            'occurred_at' => gmdate('Y-m-d H:i:s', time() - $secondsAgo) . '+00',
        ];
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
