<?php

declare(strict_types=1);

namespace BinktermPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Crossroads WebDoor fullscreen-escape affordance (templates/webdoor_play.twig).
 *
 * When the .game-container element is fullscreened (what the wrapper's own
 * fullscreen button does for every graphical WebDoor -- Chessmata, OpenGlad,
 * ...), the browser does not paint the sibling top bar, so the caller loses the
 * "Back to Crossroads" control. The template renders a Crossroads-owned escape
 * control INSIDE .game-container that is only shown while it is the fullscreen
 * element, and preserves the existing End Session lifecycle.
 */
final class WebDoorFullscreenEscapeTest extends TestCase
{
    private function render(string $returnUrl = '/experiences/demo'): string
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'));
        $twig->addFunction(new TwigFunction('t', static fn (string $k, $p = [], $l = null, $ns = []): string => $k));
        $twig->addFunction(new TwigFunction('bbs_feature_enabled', static fn ($f): bool => false));
        $twig->addFunction(new TwigFunction('asset', static fn ($a): string => (string)$a));

        return $twig->render('webdoor_play.twig', [
            'game'        => ['name' => 'Demo Game'],
            'game_url'    => '/webdoors/demo/index.php',
            'game_id'     => 'demo',
            'return_url'  => $returnUrl,
            'locale'      => 'en',
            'system_name' => 'L33Test',
        ]);
    }

    public function testEscapeControlIsRenderedInsideTheGameContainer(): void
    {
        $html = $this->render();

        $openContainer = strpos($html, '<div class="game-container">');
        $escape = strpos($html, 'id="webdoor-fs-escape"');
        $iframe = strpos($html, '<iframe id="game-frame"');

        self::assertNotFalse($openContainer);
        self::assertNotFalse($escape);
        self::assertNotFalse($iframe);
        // the escape control lives between the container open tag and the iframe,
        // i.e. it is part of the fullscreen subtree, not the sibling top bar.
        self::assertGreaterThan($openContainer, $escape, 'escape control must be inside .game-container');
        self::assertLessThan($iframe, $escape, 'escape control must precede the game iframe');
    }

    public function testEscapeControlOffersExitFullscreenAndBackToCrossroads(): void
    {
        $html = $this->render('/experiences/demo');

        self::assertMatchesRegularExpression(
            '#<a\b(?=[^>]*\bid="webdoor-fs-leave")(?=[^>]*\bhref="/experiences/demo")[^>]*>#',
            $html,
            'the escape "Back to Crossroads" link must target the same return_url as the wrapper'
        );
        self::assertMatchesRegularExpression(
            '#<button[^>]+id="webdoor-fs-exit"#',
            $html,
            'the escape control must offer an Exit Fullscreen button'
        );
        self::assertStringContainsString('ui.webdoor_play.exit_fullscreen', $html);
        self::assertStringContainsString('ui.webdoor_play.back_to_crossroads', $html);
        self::assertStringContainsString('ui.webdoor_play.fs_controls_label', $html);
    }

    public function testEscapeControlIsInertUntilTheContainerIsFullscreen(): void
    {
        $html = $this->render();

        // hidden + out of the a11y tree + out of the tab order by default
        self::assertMatchesRegularExpression('#id="webdoor-fs-escape"[^>]*aria-hidden="true"#', $html);
        self::assertMatchesRegularExpression('#id="webdoor-fs-exit"[^>]*tabindex="-1"#', $html);
        self::assertMatchesRegularExpression('#id="webdoor-fs-leave"[^>]*tabindex="-1"#', $html);

        // CSS: display:none normally, display:flex only when .game-container is fullscreen
        self::assertMatchesRegularExpression('#\.webdoor-fs-escape\s*\{\s*display:\s*none#', $html);
        self::assertMatchesRegularExpression(
            '#\.game-container:fullscreen\s+\.webdoor-fs-escape[^}]*display:\s*flex#s',
            $html
        );
        self::assertStringContainsString(':-webkit-full-screen .webdoor-fs-escape', $html);
    }

    public function testExitFullscreenIsWiredWithAVendorFallback(): void
    {
        $html = $this->render();

        self::assertStringContainsString("getElementById('webdoor-fs-exit')", $html);
        self::assertStringContainsString('document.exitFullscreen', $html);
        self::assertStringContainsString('document.webkitExitFullscreen', $html);
        // aria-hidden / tabindex are re-synced on fullscreenchange
        self::assertStringContainsString("addEventListener('fullscreenchange'", $html);
        self::assertStringContainsString("addEventListener('webkitfullscreenchange'", $html);
    }

    public function testExistingEndSessionLifecycleIsPreserved(): void
    {
        $html = $this->render();

        // the beforeunload End Session beacon must still be present and unbroken
        self::assertStringContainsString("window.addEventListener('beforeunload'", $html);
        self::assertStringContainsString('/api/webdoor/session/end?game_id=', $html);
        self::assertStringContainsString('navigator.sendBeacon', $html);
        // the original wrapper controls are untouched
        self::assertStringContainsString("getElementById('fullscreen-btn')", $html);
        self::assertMatchesRegularExpression('#<a[^>]+class="btn btn-outline-secondary btn-sm me-3"#', $html);
    }

    public function testNewI18nKeysExistInEveryLocale(): void
    {
        $dir = dirname(__DIR__, 2) . '/config/i18n';
        $locales = array_filter(
            scandir($dir),
            static fn ($e): bool => is_dir($dir . '/' . $e) && !in_array($e, ['.', '..', 'overrides'], true)
        );
        self::assertNotEmpty($locales);

        foreach ($locales as $locale) {
            $catalog = require $dir . '/' . $locale . '/common.php';
            self::assertArrayHasKey('ui.webdoor_play.exit_fullscreen', $catalog, "$locale missing exit_fullscreen");
            self::assertArrayHasKey('ui.webdoor_play.fs_controls_label', $catalog, "$locale missing fs_controls_label");
            self::assertNotSame('', trim((string)$catalog['ui.webdoor_play.exit_fullscreen']));
            self::assertNotSame('', trim((string)$catalog['ui.webdoor_play.fs_controls_label']));
        }
    }
}
