<?php

declare(strict_types=1);

namespace BinktermPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Cross-file wiring for the Crossroads dashboard pulse: required i18n keys in
 * every base locale, and the authenticated web navigation using the Crossroads
 * product identity for the /games destination.
 */
final class CrossroadsDashboardWiringTest extends TestCase
{
    private const BASE_LOCALES = ['en', 'de', 'es', 'fr', 'it', 'ru'];

    private const REQUIRED_KEYS = [
        'ui.dashboard.card.crossroads',
        'ui.dashboard.crossroads.you_are_in',
        'ui.dashboard.crossroads.playing',
        'ui.dashboard.crossroads.enter',
    ];

    /** @return array<string,string> */
    private function catalog(string $locale): array
    {
        $path = dirname(__DIR__, 2) . "/config/i18n/{$locale}/common.php";
        self::assertFileExists($path, "missing {$locale}/common.php");

        return require $path;
    }

    public function testRequiredPulseKeysExistInEveryBaseLocale(): void
    {
        foreach (self::BASE_LOCALES as $locale) {
            $catalog = $this->catalog($locale);
            foreach (self::REQUIRED_KEYS as $key) {
                self::assertArrayHasKey(
                    $key,
                    $catalog,
                    "{$locale}/common.php is missing {$key}"
                );
                self::assertNotSame(
                    '',
                    trim((string)$catalog[$key]),
                    "{$locale}/common.php has an empty value for {$key}"
                );
            }
        }
    }

    public function testCrossroadsCardLabelStaysTheProductNameInEveryLocale(): void
    {
        foreach (self::BASE_LOCALES as $locale) {
            self::assertSame(
                'Crossroads',
                $this->catalog($locale)['ui.dashboard.card.crossroads'] ?? null,
                "{$locale} card label should be the Crossroads product name"
            );
        }
    }

    public function testAuthenticatedNavCallsTheDestinationCrossroadsNotDoorsAndGames(): void
    {
        foreach (self::BASE_LOCALES as $locale) {
            self::assertSame(
                'Crossroads',
                $this->catalog($locale)['ui.base.doors_games'] ?? null,
                "{$locale} authenticated nav label for /games should read Crossroads"
            );
        }

        foreach (['templates/base.twig', 'templates/shells/web/base.twig'] as $shell) {
            $html = file_get_contents(dirname(__DIR__, 2) . '/' . $shell);
            self::assertIsString($html);
            // The authenticated /games nav link renders the Crossroads label...
            self::assertStringContainsString(
                "t('ui.base.doors_games', {}, 'common') }}",
                $html,
                "{$shell} authenticated nav should use the ui.base.doors_games label key"
            );
            // ...and never a hardcoded "Doors & Games".
            self::assertStringNotContainsString('Doors &amp; Games', $html, $shell);
            self::assertStringNotContainsString('Doors & Games', $html, $shell);
        }
    }

    public function testDashboardIncludesTheCrossroadsPulsePartialGatedOnAvailability(): void
    {
        $dashboard = file_get_contents(dirname(__DIR__, 2) . '/templates/dashboard.twig');
        self::assertIsString($dashboard);

        self::assertMatchesRegularExpression(
            '/\{%\s*if\s+crossroads_available[^%]*%\}\s*\{%\s*include\s+\'partials\/dashboard_crossroads_pulse\.twig\'\s*%\}/',
            $dashboard
        );
    }
}
