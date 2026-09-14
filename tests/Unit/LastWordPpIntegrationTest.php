<?php

declare(strict_types=1);

use BinktermPHP\CuratedPlaceCatalog;
use BinktermPHP\CuratedPlacePresentation;
use BinktermPHP\GameCatalog;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Slice 4 ("real featured Puzlmastr's Patch integration"): end-to-end
 * coverage against the REAL GameCatalog/WebDoorManifest/places.json — not a
 * mock catalog fixture — since the whole point of this slice is that Last
 * Word is a genuinely catalog-discoverable experience, not something
 * hardcoded into the place/template layer.
 */
final class LastWordPpIntegrationTest extends TestCase
{
    private function twig(): Environment
    {
        $twig = new Environment(new ChainLoader([
            new ArrayLoader(['base.twig' => '<html><head>{% block head %}{% endblock %}</head><body>{% block content %}{% endblock %}</body></html>']),
            new FilesystemLoader(dirname(__DIR__, 2) . '/templates'),
        ]));
        $twig->addFunction(new TwigFunction('t', static fn (string $key): string => $key === 'ui.webdoors.place_featured_title' ? 'Featured' : ($key === 'ui.webdoors.enter' ? 'Enter' : $key)));
        $twig->addGlobal('locale', 'en');
        return $twig;
    }

    private function realGames(): array
    {
        return (new GameCatalog())->getEnabledGames(['id' => 1], 'web');
    }

    // ---- CATALOG -----------------------------------------------------

    public function testLastWordHasDistinctCatalogIdentityFromHangman(): void
    {
        $games = $this->realGames();
        self::assertArrayHasKey('lastword', $games, 'Last Word must be a real, independently discoverable catalog experience');
        self::assertArrayHasKey('hangman', $games, 'ordinary Hangman must remain a separate, unaffected catalog experience');
        self::assertNotSame($games['hangman']['source']['manifest'], $games['lastword']['source']['manifest']);
        self::assertSame('Last Word', $games['lastword']['name']);
        self::assertSame('Hangman', $games['hangman']['name']);
    }

    public function testLastWordCanonicalIconResolvesToItsOwnAsset(): void
    {
        $games = $this->realGames();
        $iconUrl = $games['lastword']['presentation']['icon_url'];
        // The manifest lives in its own webdoors/lastword/ directory and
        // points at the canonical Skippy+Bob icon via a relative path back
        // into webdoors/hangman/lastword/ -- verify that path actually
        // resolves to the real, existing Slice 3A asset on disk.
        $resolvedPath = dirname(__DIR__, 2) . '/public_html' . self::normalizeUrlPath($iconUrl);
        self::assertFileExists($resolvedPath, 'icon_url must resolve to a real file: ' . $iconUrl);
        self::assertStringEndsWith('/webdoors/hangman/lastword/icon.svg', $resolvedPath);
    }

    public function testLastWordManifestDoesNotReuseHangmansOwnManifestFile(): void
    {
        $manifestPath = dirname(__DIR__, 2) . '/public_html/webdoors/lastword/webdoor.json';
        self::assertFileExists($manifestPath);
        $manifest = json_decode((string)file_get_contents($manifestPath), true);
        self::assertSame('lastword', $manifest['game']['id']);
        self::assertSame('Last Word', $manifest['game']['name']);
        self::assertStringContainsString('0.1', (string)$manifest['game']['version']);
    }

    // ---- PP ------------------------------------------------------------

    private function place(): array
    {
        return (new CuratedPlaceCatalog())->getPlace('puzlmastrs-patch', ['id' => 1], 'web');
    }

    public function testLastWordIsFeaturedExactlyOnceAndAbsentFromTheOrdinaryGrid(): void
    {
        $place = $this->place();
        $featured = CuratedPlacePresentation::featuredMembers($place);
        $ordinary = CuratedPlacePresentation::members($place);

        self::assertCount(1, $featured);
        self::assertSame('lastword', $featured[0]['reference']);
        self::assertSame('Last Word', $featured[0]['experience_presentation']['name']);

        self::assertNotContains('lastword', array_column($ordinary, 'reference'),
            'Last Word must not also appear in the ordinary grid');
    }

    public function testExistingNineTitlesRemainPresentInOriginalRelativeOrder(): void
    {
        $ordinary = CuratedPlacePresentation::members($this->place());
        self::assertSame(
            ['wordwright', 'hangman', 'blackjack', 'parlour', 'tatham/lightup', 'breaklock', 'ordinary-puzzles', 'dokuel', 'everest'],
            array_column($ordinary, 'reference')
        );
    }

    public function testHangmanRemainsSeparatelyPresentAndFunctionalInTheOrdinaryGrid(): void
    {
        $ordinary = CuratedPlacePresentation::members($this->place());
        $hangman = null;
        foreach ($ordinary as $card) {
            if ($card['reference'] === 'hangman') {
                $hangman = $card;
            }
        }
        self::assertNotNull($hangman, 'ordinary Hangman must still be present');
        self::assertSame('Hangman', $hangman['experience_presentation']['name']);
        self::assertStringStartsWith('/games/hangman', (string)$hangman['destination_url']);
    }

    public function testFeaturedPresentationCarriesTheAcceptedEyebrowAndCaption(): void
    {
        $featured = CuratedPlacePresentation::featuredMembers($this->place());
        self::assertSame('EARLY PLAYTEST · v0.1', $featured[0]['featured_presentation']['eyebrow']);
        self::assertSame('Play it. Break it. Tell us what you think.', $featured[0]['featured_presentation']['caption']);
    }

    public function testRenderedPageShowsIdentityHeaderFeaturedCardAndFullOrdinaryGrid(): void
    {
        $place = $this->place();
        $featured = CuratedPlacePresentation::featuredMembers($place);
        $ordinary = CuratedPlacePresentation::members($place);
        $html = $this->twig()->render('curated_place.twig', ['place' => $place, 'featured_cards' => $featured, 'member_cards' => $ordinary]);

        self::assertStringContainsString('curated-place-header--hero', $html, 'Slice 1 PP identity header must remain intact');
        self::assertStringContainsString('curated-place-featured-card', $html);
        self::assertSame(1, substr_count($html, 'href="/webdoors/hangman/lastword-skippy.html"'));
        foreach (['Wordwright', 'Hangman', 'Blackjack', 'Parlour', 'Light Up', 'BreakLock', 'Ordinary Puzzles', 'Dokuel', 'Everest'] as $name) {
            self::assertStringContainsString($name, $html);
        }
    }

    public function testAnotherCuratedPlaceIsUnaffectedByTheLastWordFeaturedMember(): void
    {
        // Same synthetic-place technique as CuratedPlaceWebTest.php's
        // hero-less-place regression: a place definition that carries no
        // `lastword` member at all must render its normal empty-grid
        // message, untouched by anything added to Puzlmastr's Patch.
        $place = [
            'id' => 'some-other-place',
            'name' => 'Some Other Place',
            'description' => 'Unrelated to Puzlmastr\'s Patch.',
            'association' => [],
        ];
        $html = $this->twig()->render('curated_place.twig', ['place' => $place, 'featured_cards' => [], 'member_cards' => []]);
        self::assertStringNotContainsString('Last Word', $html);
        self::assertStringNotContainsString('curated-place-featured', $html);
    }

    // ---- LAUNCH ----------------------------------------------------------

    public function testFeaturedEnterTargetIsThePlainCanonicalLastWordUrl(): void
    {
        $featured = CuratedPlacePresentation::featuredMembers($this->place());
        $url = $featured[0]['destination_url'];
        self::assertSame('/webdoors/hangman/lastword-skippy.html', $url);
        self::assertStringNotContainsString('?', $url, 'no query parameters at all -- not ?predicament=safe, not even ?parent_place_id');
        self::assertStringNotContainsString('predicament=safe', $url);
        self::assertStringNotContainsString('bob=1', $url);
    }

    public function testTheCanonicalLastWordEntrypointFileActuallyExistsOnDisk(): void
    {
        $path = dirname(__DIR__, 2) . '/public_html/webdoors/hangman/lastword-skippy.html';
        self::assertFileExists($path);
    }

    private static function normalizeUrlPath(string $url): string
    {
        // Collapse "a/b/../c" -> "a/c" the same way a browser/webserver
        // would when actually requesting the URL, so file-existence checks
        // below test the real resolved path.
        $parts = [];
        foreach (explode('/', $url) as $segment) {
            if ($segment === '..') {
                array_pop($parts);
            } elseif ($segment !== '.' && $segment !== '') {
                $parts[] = $segment;
            }
        }
        return '/' . implode('/', $parts);
    }
}
