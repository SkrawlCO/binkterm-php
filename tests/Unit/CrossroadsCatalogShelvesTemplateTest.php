<?php

declare(strict_types=1);

namespace BinktermPHP\Tests\Unit;

use BinktermPHP\CrossroadsShelves;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Slice 2 — the three-shelf catalog composition, rendered.
 *
 * Asserts the shelf partial and both catalog surfaces (webdoors.twig,
 * crossroads.twig) lay the composed shelves out in order, with the accepted
 * default disclosure states, using the shared card partial and no per-ID
 * branching.
 */
final class CrossroadsCatalogShelvesTemplateTest extends TestCase
{
    private function twig(): Environment
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'));
        $twig->addFunction(new TwigFunction('t', static function (string $key, array $params = []): string {
            // Echo the key so assertions can target it; interpolate {count}.
            return strtr($key, array_combine(
                array_map(static fn ($k) => '{' . $k . '}', array_keys($params)),
                array_map('strval', array_values($params))
            ));
        }));
        $twig->addFunction(new TwigFunction('bbs_feature_enabled', static fn (string $f): bool => false));
        $twig->addGlobal('locale', 'en');
        $twig->addGlobal('current_user', null);

        return $twig;
    }

    /** @param array{curated?:bool,order?:int|null} $curation */
    private function game(string $id, string $category = 'game', array $curation = [], int $playerCount = 0): array
    {
        return [
            'id' => $id,
            'launch' => ['type' => 'native', 'id' => $id, 'url' => '/games/nativedoors/' . $id],
            'category' => $category,
            'curation' => [
                'curated' => $curation['curated'] ?? false,
                'order' => $curation['order'] ?? null,
            ],
            'experience_presentation' => [
                'id' => $id,
                'name' => ucfirst($id),
                'description' => 'An Experience.',
                'category' => $category,
                'curation' => [
                    'curated' => $curation['curated'] ?? false,
                    'order' => $curation['order'] ?? null,
                ],
                'presentation' => ['icon_url' => '/icon/' . $id],
                'backend' => ['type' => 'native', 'label' => 'Native'],
                'capabilities' => ['multiplayer' => true, 'player_mode' => 'multiplayer'],
                'capacity' => ['max_sessions' => 8, 'limited' => true, 'at_capacity' => false],
                'cost' => ['credits' => 0, 'free' => true],
                'surfaces' => ['requested' => 'web', 'current' => 'full', 'web' => 'full', 'telnet' => 'full', 'static_launchable' => true],
                'runtime' => ['supplied' => true, 'active' => $playerCount > 0, 'session_count' => $playerCount, 'player_count' => $playerCount, 'players' => []],
                'viewer' => ['participating' => false, 'blocked_by_capacity' => false],
                'status' => ['code' => 'available'],
                'actions' => ['primary' => 'play', 'details' => true, 'play' => true, 'return' => false, 'end_participation' => false, 'static_launchable' => true],
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function liveCatalog(): array
    {
        return [
            $this->game('multizork', 'game', ['curated' => true, 'order' => 0]),
            $this->game('ascii-royale-m3', 'game', ['curated' => true, 'order' => 1]),
            $this->game('openglad', 'game', ['curated' => true, 'order' => 2]),
            $this->game('blackjack'),
            $this->game('lord'),
            $this->game('bcrgames', 'gateway'),
            $this->game('doorparty', 'gateway'),
        ];
    }

    private function renderShelf(array $shelf, bool $publicMode = false): string
    {
        return $this->twig()->render('partials/experience_shelf.twig', [
            'shelf' => $shelf,
            'public_mode' => $publicMode,
        ]);
    }

    public function testShelfPartialRendersCollapsibleGatewayCollapsed(): void
    {
        $shelves = CrossroadsShelves::compose($this->liveCatalog());
        $gateway = array_values(array_filter($shelves, fn ($s) => $s['key'] === 'gateway'))[0];

        $html = $this->renderShelf($gateway);

        self::assertStringContainsString('data-experience-shelf="gateway"', $html);
        self::assertStringContainsString('<details class="experience-shelf-disclosure" data-experience-shelf-disclosure>', $html);
        self::assertStringNotContainsString('<details class="experience-shelf-disclosure" open', $html);
        self::assertStringContainsString('ui.webdoors.shelf_gateway_title', $html);
        self::assertStringContainsString('data-experience-shelf-count', $html);
        // shared card partial, one per entry
        self::assertSame(2, substr_count($html, 'data-filter-name="'));
        self::assertStringContainsString('/experiences/bcrgames', $html);
    }

    public function testShelfPartialRendersGameHallExpanded(): void
    {
        $shelves = CrossroadsShelves::compose($this->liveCatalog());
        $hall = array_values(array_filter($shelves, fn ($s) => $s['key'] === 'game_hall'))[0];

        $html = $this->renderShelf($hall);

        self::assertStringContainsString('<details class="experience-shelf-disclosure" open data-experience-shelf-disclosure>', $html);
        self::assertStringContainsString('data-shelf-default-expanded="1"', $html);
    }

    public function testShelfPartialRendersCuratedAlwaysOpenWithNoDisclosure(): void
    {
        $shelves = CrossroadsShelves::compose($this->liveCatalog());
        $curated = array_values(array_filter($shelves, fn ($s) => $s['key'] === 'curated'))[0];

        $html = $this->renderShelf($curated);

        self::assertStringNotContainsString('<details', $html);
        self::assertStringContainsString('experience-shelf-heading--static', $html);
        self::assertStringContainsString('ui.webdoors.shelf_curated_title', $html);
        self::assertStringNotContainsString('data-experience-shelf-count', $html);
    }

    public function testEmptyShelfRendersNothing(): void
    {
        $html = $this->renderShelf([
            'key' => 'curated', 'entries' => [], 'count' => 0,
            'collapsible' => false, 'default_expanded' => true,
        ]);

        self::assertSame('', trim($html));
    }

    public function testWebdoorsPageComposesThreeShelvesInOrderUnderTheFilterRoot(): void
    {
        $games = $this->liveCatalog();
        $html = $this->twig()->render('webdoors.twig', [
            'system_name' => 'L33Test',
            'games' => $games,
            'catalog_shelves' => CrossroadsShelves::compose($games),
            'your_places' => [], 'live_experiences' => [], 'recent_activity' => [],
            'experience_states' => [], 'current_user' => null,
            'around_active_players' => 0, 'around_active_experiences' => 0,
            'show_global_presence_summary' => true,
            'leaderboard' => [], 'leaderboard_month_label' => 'x',
            'leaderboard_month_offset' => 0, 'scoreboard_expanded' => false,
        ]);

        $root = strpos($html, 'data-experience-filter-root');
        $curated = strpos($html, 'data-experience-shelf="curated"');
        $hall = strpos($html, 'data-experience-shelf="game_hall"');
        $gateway = strpos($html, 'data-experience-shelf="gateway"');

        self::assertNotFalse($root);
        self::assertGreaterThan($root, $curated);
        self::assertGreaterThan($curated, $hall);
        self::assertGreaterThan($hall, $gateway);

        // All seven cards present, once each, still deep-linking to the lobby.
        self::assertSame(7, substr_count($html, 'data-filter-name="'));
        self::assertSame(1, substr_count($html, 'data-filter-name="Openglad"'));
        self::assertStringContainsString('href="/experiences/openglad"', $html);

        // The filter script learned about shelves but did not move classification.
        self::assertStringContainsString('data-experience-shelf', $html);
        self::assertStringContainsString('function syncShelves()', $html);
        self::assertStringNotContainsString('curation', $html); // no client-side classification
    }

    public function testCrossroadsPublicPageComposesTheSameShelves(): void
    {
        $games = $this->liveCatalog();
        $cards = array_map(
            static fn ($g) => ['experience_presentation' => $g['experience_presentation']],
            $games
        );
        $html = $this->twig()->render('crossroads.twig', [
            'system_name' => 'L33Test',
            'cards' => $cards,
            'catalog_shelves' => CrossroadsShelves::compose($cards),
            'around_active_people' => 0,
            'around_active_experiences' => 0,
        ]);

        self::assertLessThan(
            strpos($html, 'data-experience-shelf="game_hall"'),
            strpos($html, 'data-experience-shelf="curated"')
        );
        self::assertLessThan(
            strpos($html, 'data-experience-shelf="gateway"'),
            strpos($html, 'data-experience-shelf="game_hall"')
        );
        // public surface: no filter toolbar, cards still not deep-linked to launch
        self::assertStringNotContainsString('data-experience-filter-controls', $html);
        self::assertStringContainsString('<details class="experience-shelf-disclosure" open', $html); // game hall
        self::assertSame(7, substr_count($html, 'experience-library-card h-100'));
    }
}
