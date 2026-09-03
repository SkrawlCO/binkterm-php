<?php

declare(strict_types=1);

namespace BinktermPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Slice 3 — Crossroads visual identity.
 *
 * The L33TEST brand mark is a user-supplied, tracked static asset; the
 * masthead on both catalog surfaces uses it as one strong primary mark
 * (decorative, so the <h1> carries the name); and the obsolete flat-library
 * "complete Experience library" wrapper is gone.
 */
final class CrossroadsBrandMarkTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';
    private const MARK = self::ROOT . '/public_html/img/l33test-mark.png';

    /** @return array{width:int,height:int,bit_depth:int,color_type:int,interlace:int} */
    private function png(string $path): array
    {
        $fh = fopen($path, 'rb');
        self::assertIsResource($fh);
        try {
            self::assertSame("\x89PNG\r\n\x1a\n", fread($fh, 8), "not a PNG: {$path}");
            fread($fh, 4);
            self::assertSame('IHDR', fread($fh, 4));
            $u = unpack('Nw/Nh/Cbd/Cct/Ccomp/Cfilt/Cil', (string)fread($fh, 13));
        } finally {
            fclose($fh);
        }

        return ['width' => $u['w'], 'height' => $u['h'], 'bit_depth' => $u['bd'], 'color_type' => $u['ct'], 'interlace' => $u['il']];
    }

    public function testBrandMarkAssetIsTrackedAnd512SquareTransparentPng(): void
    {
        self::assertFileExists(self::MARK);
        $bytes = filesize(self::MARK);
        self::assertGreaterThan(1024, $bytes);
        self::assertLessThan(400_000, $bytes);

        $ihdr = $this->png(self::MARK);
        self::assertSame(512, $ihdr['width']);
        self::assertSame(512, $ihdr['height']);
        self::assertSame(0, $ihdr['interlace']);
        self::assertSame(6, $ihdr['color_type'], 'the mark must keep its alpha channel (RGBA)');
        self::assertSame(8, $ihdr['bit_depth']);
    }

    public function testProvenanceIsRecordedAndNotClaimedAsUpstream(): void
    {
        $readme = (string)file_get_contents(self::ROOT . '/public_html/img/README.md');
        self::assertStringContainsString('l33test-mark.png', $readme);
        self::assertStringContainsString(
            'User-supplied L33TEST branding asset; not upstream BinkTerm artwork.',
            $readme
        );
    }

    private function twig(): Environment
    {
        $twig = new Environment(new FilesystemLoader(self::ROOT . '/templates'));
        $twig->addFunction(new TwigFunction('t', static function (string $key, array $params = []): string {
            return strtr($key, array_combine(
                array_map(static fn ($k) => '{' . $k . '}', array_keys($params)),
                array_map('strval', array_values($params))
            ));
        }));
        $twig->addFunction(new TwigFunction('bbs_feature_enabled', static fn (string $f): bool => false));
        $twig->addGlobal('locale', 'en');
        $twig->addGlobal('current_user', null);
        $twig->addGlobal('system_name', 'L33Test Gaming');

        return $twig;
    }

    private function shelves(): array
    {
        return [
            ['key' => 'curated', 'entries' => [], 'count' => 0, 'collapsible' => false, 'default_expanded' => true],
            ['key' => 'game_hall', 'entries' => [], 'count' => 0, 'collapsible' => true, 'default_expanded' => true],
            ['key' => 'gateway', 'entries' => [], 'count' => 0, 'collapsible' => true, 'default_expanded' => false],
        ];
    }

    public function testGamesMastheadUsesTheMarkAsADecorativePrimaryLockup(): void
    {
        $html = $this->twig()->render('webdoors.twig', [
            'games' => [], 'catalog_shelves' => $this->shelves(),
            'your_places' => [], 'live_experiences' => [], 'recent_activity' => [],
            'experience_states' => [], 'around_active_players' => 0, 'around_active_experiences' => 0,
            'show_global_presence_summary' => true, 'leaderboard' => [],
            'leaderboard_month_label' => 'x', 'leaderboard_month_offset' => 0, 'scoreboard_expanded' => false,
        ]);

        // one masthead lockup, mark once, decorative
        self::assertSame(1, substr_count($html, 'class="crossroads-lockup"'));
        self::assertStringContainsString('src="/img/l33test-mark.png"', $html);
        self::assertMatchesRegularExpression('/<img class="crossroads-mark"[^>]*\balt=""[^>]*aria-hidden="true"/', $html);
        // the <h1> carries the accessible name, not the image
        self::assertMatchesRegularExpression('/<h1 class="crossroads-wordmark[^"]*"[^>]*>ui\.webdoors\.heading<\/h1>/', $html);
        // the generic compass glyph is gone from the masthead
        $masthead = substr($html, strpos($html, 'crossroads-masthead'), 600);
        self::assertStringNotContainsString('fa-compass', $masthead);
    }

    public function testCrossroadsPublicMastheadMatchesAndIsDecorative(): void
    {
        $html = $this->twig()->render('crossroads.twig', [
            'cards' => [], 'catalog_shelves' => $this->shelves(),
            'around_active_people' => 0, 'around_active_experiences' => 0,
        ]);

        self::assertStringContainsString('class="crossroads-lockup"', $html);
        self::assertStringContainsString('src="/img/l33test-mark.png"', $html);
        self::assertMatchesRegularExpression('/<img class="crossroads-mark"[^>]*\balt=""[^>]*aria-hidden="true"/', $html);
    }

    public function testObsoleteFlatLibraryWrapperIsGone(): void
    {
        $games = [[
            'id' => 'x', 'launch' => ['url' => '/x'],
            'category' => 'game', 'curation' => ['curated' => false, 'order' => null],
            'experience_presentation' => [
                'id' => 'x', 'name' => 'X', 'description' => 'd', 'category' => 'game',
                'curation' => ['curated' => false, 'order' => null],
                'presentation' => ['icon_url' => '/i'], 'backend' => ['type' => 'web', 'label' => 'WebDoor'],
                'capabilities' => ['multiplayer' => false, 'player_mode' => 'single_player'],
                'capacity' => ['max_sessions' => null, 'limited' => false, 'at_capacity' => false],
                'cost' => ['credits' => 0, 'free' => true],
                'surfaces' => ['requested' => 'web', 'current' => 'full', 'web' => 'full', 'telnet' => 'planned', 'static_launchable' => true],
                'runtime' => ['supplied' => false, 'active' => null, 'session_count' => null, 'player_count' => null, 'players' => []],
                'viewer' => ['participating' => false, 'blocked_by_capacity' => false],
                'status' => ['code' => 'available'],
                'actions' => ['primary' => 'play', 'details' => true, 'play' => true, 'return' => false, 'end_participation' => false, 'static_launchable' => true],
            ],
        ]];
        $shelves = [
            ['key' => 'curated', 'entries' => [], 'count' => 0, 'collapsible' => false, 'default_expanded' => true],
            ['key' => 'game_hall', 'entries' => $games, 'count' => 1, 'collapsible' => true, 'default_expanded' => true],
            ['key' => 'gateway', 'entries' => [], 'count' => 0, 'collapsible' => true, 'default_expanded' => false],
        ];
        $html = $this->twig()->render('webdoors.twig', [
            'games' => $games, 'catalog_shelves' => $shelves,
            'your_places' => [], 'live_experiences' => [], 'recent_activity' => [],
            'experience_states' => [], 'around_active_players' => 0, 'around_active_experiences' => 0,
            'show_global_presence_summary' => true, 'leaderboard' => [],
            'leaderboard_month_label' => 'x', 'leaderboard_month_offset' => 0, 'scoreboard_expanded' => false,
        ]);

        // The old visible intro copy is gone; the landmark heading survives, hidden.
        self::assertStringContainsString('ui.webdoors.all_experiences_description', $this->stub()); // key still exists
        self::assertStringNotContainsString('ui.webdoors.all_experiences_description', $html);
        self::assertMatchesRegularExpression('/<h2 id="experiences-title" class="visually-hidden"/', $html);
        // functional container + filter behaviour intact
        self::assertStringContainsString('data-experience-filter-root', $html);
        self::assertStringContainsString('data-experience-filter-controls', $html);
        self::assertStringContainsString('function syncShelves()', $html);
    }

    /** the en catalog still defines the (now unused-in-template) key */
    private function stub(): string
    {
        return (string)file_get_contents(self::ROOT . '/config/i18n/en/common.php');
    }

    public function testNoPerGameVisualHardcoding(): void
    {
        $css = (string)file_get_contents(self::ROOT . '/public_html/css/experiences.css');
        $shelf = (string)file_get_contents(self::ROOT . '/templates/partials/experience_shelf.twig');
        foreach (['multizork', 'openglad', 'ascii-royale', 'blackjack', 'doorparty', 'bcrgames'] as $id) {
            self::assertStringNotContainsStringIgnoringCase($id, $css, "experiences.css must not reference {$id}");
            self::assertStringNotContainsStringIgnoringCase($id, $shelf, "shelf partial must not reference {$id}");
        }
    }
}
