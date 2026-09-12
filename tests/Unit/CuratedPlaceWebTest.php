<?php

declare(strict_types=1);

use BinktermPHP\CuratedPlaceCatalog;
use BinktermPHP\CuratedPlacePresentation;
use BinktermPHP\CrossroadsShelves;
use BinktermPHP\ExperiencePresentation;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class PlaceWebFixtureCatalog
{
    public static function rows(): array
    {
        $rows = [];
        foreach (['wordwright', 'hangman', 'blackjack', 'parlour', 'tatham', 'breaklock', 'ordinary-puzzles', 'dokuel'] as $id) {
            $rows[$id] = [
                'id' => $id, 'name' => $id, 'description' => '', 'category' => 'game',
                'backend' => ['type' => 'web', 'id' => $id === 'tatham' ? 'tatham-web' : $id],
                'surfaces' => ['web' => 'full', 'telnet' => in_array($id, ['wordwright', 'tatham', 'breaklock', 'ordinary-puzzles', 'dokuel'], true) ? 'full' : 'planned'],
                'source' => ['manifest' => ['experience' => ['default_entry' => $id === 'tatham' ? 'lightup' : null]]],
            ];
        }
        $rows['tatham']['surface_backends']['telnet'] = ['type' => 'native', 'id' => 'tatham-terminal'];
        $rows['wordwright']['surface_backends']['telnet'] = ['type' => 'native', 'id' => 'wordwright-terminal'];
        $rows['breaklock']['surface_backends']['telnet'] = ['type' => 'native', 'id' => 'breaklock-terminal'];
        $rows['ordinary-puzzles']['surface_backends']['telnet'] = ['type' => 'native', 'id' => 'ordinary-puzzles-terminal'];
        $rows['dokuel']['surface_backends']['telnet'] = ['type' => 'native', 'id' => 'dokuel-terminal'];
        return $rows;
    }

    public function getEnabledGames(?array $user, string $surface): array
    {
        if ($user !== ['id' => 7] || $surface !== 'web') {
            throw new RuntimeException('Incorrect caller/surface delegation');
        }
        return self::rows();
    }
}
final class PlaceWebFixtureAuth
{
    public static ?array $user = ['id' => 7];
    public function getCurrentUser(): ?array { return self::$user; }
}
final class PlaceWebFixtureTemplate
{
    public static array $rendered = [];
    public function renderResponse(string $template, array $data): void { self::$rendered = [$template, $data]; }
}
final class PlaceWebFixtureRouter
{
    public static array $routes = [];
    public static function __callStatic(string $method, array $args): void { self::$routes[$method][$args[0]] = $args[1]; }
    public static function response(): self { return new self(); }
    public function redirect(string $url): string { return $url; }
}

final class CuratedPlaceWebTest extends TestCase
{
    private function twig(): Environment
    {
        $twig = new Environment(new ChainLoader([
            new ArrayLoader(['base.twig' => '<html><head>{% block head %}{% endblock %}</head><body>{% block content %}{% endblock %}</body></html>']),
            new FilesystemLoader(dirname(__DIR__, 2) . '/templates'),
        ]));
        $twig->addFunction(new TwigFunction('t', static fn (string $key): string => $key));
        $twig->addGlobal('locale', 'en');
        return $twig;
    }

    private function place(): array
    {
        $place = (new CuratedPlaceCatalog())->getDefinition('puzlmastrs-patch');
        $place['members'] = array_map(static function (array $member): array {
            $resolved = CuratedPlaceCatalog::resolveReference($member['reference'], PlaceWebFixtureCatalog::rows(), 'web');
            self::assertNotNull($resolved, 'Fixture must resolve member ' . $member['reference']);
            $resolved['title'] = $member['title'] ?? $resolved['title'];
            return $resolved;
        }, $place['members']);
        return $place;
    }

    public function testPlaceIsOneCuratedDestinationWithoutRuntimeOrPresence(): void
    {
        $cards = CuratedPlacePresentation::cards((new CuratedPlaceCatalog())->getDefinitions());
        self::assertCount(1, $cards);
        $view = $cards[0]['experience_presentation'];
        self::assertSame('/img/places/puzlmastrs-patch.png', $view['presentation']['icon_url']);
        $icon = getimagesize(dirname(__DIR__, 2) . '/public_html' . $view['presentation']['icon_url']);
        self::assertSame([512, 512, IMAGETYPE_PNG], array_slice($icon, 0, 3));
        self::assertSame('', $view['backend']['type']);
        self::assertFalse($view['surfaces']['static_launchable']);
        self::assertNull($view['runtime']['active']);
        self::assertNull($view['runtime']['session_count']);
        $shelves = CrossroadsShelves::compose($cards);
        self::assertSame($shelves, CrossroadsShelves::compose($cards));
        $html = $this->twig()->render('partials/experience_shelf.twig', ['shelf' => $shelves[0]]);
        self::assertStringContainsString('data-experience-shelf="curated"', $html);
        self::assertSame(1, substr_count($html, 'href="/places/puzlmastrs-patch"'));
        self::assertStringNotContainsString('data-experience-id=', $html);
        self::assertStringNotContainsString('/games/puzlmastrs-patch', $html);
    }

    public function testLandingUsesSharedCardsOrderedLinksAndTruthfulSurfaces(): void
    {
        $place = $this->place();
        $cards = CuratedPlacePresentation::members($place);
        self::assertSame(['wordwright', 'hangman', 'blackjack', 'parlour', 'tatham/lightup', 'breaklock', 'ordinary-puzzles', 'dokuel'], array_column($cards, 'reference'));
        self::assertSame('Light Up', $cards[4]['experience_presentation']['name']);
        self::assertSame('tatham', $cards[4]['experience_presentation']['id']);
        self::assertSame('full', $cards[0]['experience_presentation']['surfaces']['telnet']);
        self::assertSame('full', $cards[4]['experience_presentation']['surfaces']['telnet']);
        self::assertSame('BreakLock', $cards[5]['experience_presentation']['name']);
        self::assertSame('breaklock', $cards[5]['experience_presentation']['id']);
        self::assertSame('full', $cards[5]['experience_presentation']['surfaces']['web']);
        self::assertSame('full', $cards[5]['experience_presentation']['surfaces']['telnet']);
        self::assertSame('ordinary-puzzles', $cards[6]['experience_presentation']['id']);
        self::assertSame('full', $cards[6]['experience_presentation']['surfaces']['telnet']);
        $html = $this->twig()->render('curated_place.twig', ['place' => $place, 'member_cards' => $cards]);
        $previous = -1;
        foreach (['wordwright', 'hangman', 'blackjack', 'parlour', 'tatham-web', 'breaklock', 'ordinary-puzzles', 'dokuel'] as $id) {
            $position = strpos($html, 'href="/games/' . $id . '?parent_place_id=puzlmastrs-patch"');
            self::assertNotFalse($position);
            self::assertGreaterThan($previous, $position);
            $previous = $position;
        }
        self::assertStringContainsString('A little corner of Crossroads for puzzles, casual games, and things worth puzzling over.', $html);
        self::assertStringNotContainsString('endorsement', $html);
        self::assertStringContainsString('href="/games#curated-experiences"', $html);
        self::assertStringContainsString('ui.webdoors.surface_unavailable', $html);
        self::assertStringNotContainsString('resume', strtolower($html));
    }

    public function testExistingRootCardsKeepTheirLinksAndRemainInComposition(): void
    {
        $games = [];
        foreach (PlaceWebFixtureCatalog::rows() as $row) {
            $games[] = ['experience_presentation' => ExperiencePresentation::build($row, 'web')];
        }
        $shelves = CrossroadsShelves::compose(array_merge($games, CuratedPlacePresentation::cards((new CuratedPlaceCatalog())->getDefinitions())));
        self::assertSame(count($games) + 1, array_sum(array_column($shelves, 'count')));
        foreach ($games as $game) {
            $html = $this->twig()->render('partials/experience_library_card.twig', ['game' => $game]);
            self::assertStringContainsString('href="/experiences/' . $game['experience_presentation']['id'] . '"', $html);
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testActualPlaceRouteClosureUsesAuthenticationAndResolver(): void
    {
        class_alias(PlaceWebFixtureAuth::class, 'BinktermPHP\Auth');
        class_alias(PlaceWebFixtureTemplate::class, 'BinktermPHP\Template');
        class_alias(PlaceWebFixtureCatalog::class, 'BinktermPHP\GameCatalog');
        class_alias(PlaceWebFixtureRouter::class, 'Pecee\SimpleRouter\SimpleRouter');
        require dirname(__DIR__, 2) . '/routes/webdoor-routes.php';
        $route = PlaceWebFixtureRouter::$routes['get']['/places/{placeId}'];
        $route('puzlmastrs-patch');
        self::assertSame('curated_place.twig', PlaceWebFixtureTemplate::$rendered[0]);
        self::assertSame(['wordwright', 'hangman', 'blackjack', 'parlour', 'tatham/lightup', 'breaklock', 'ordinary-puzzles', 'dokuel'],
            array_column(PlaceWebFixtureTemplate::$rendered[1]['member_cards'], 'reference'));
        foreach (['missing', '../puzlmastrs-patch', 'puzlmastrs-patch/extra'] as $id) {
            $route($id);
            self::assertSame(404, http_response_code());
            self::assertSame('404.twig', PlaceWebFixtureTemplate::$rendered[0]);
        }
        PlaceWebFixtureAuth::$user = null;
        self::assertSame('/login', $route('puzlmastrs-patch'));
        self::assertArrayHasKey('/games/{game}', PlaceWebFixtureRouter::$routes['get']);
    }
}
