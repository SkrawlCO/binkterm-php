<?php

declare(strict_types=1);

namespace BinktermPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Presentation of the dashboard Crossroads pulse partial for each priority
 * state, plus its canonical links and compact shape.
 */
final class DashboardCrossroadsPulseTemplateTest extends TestCase
{
    /** @param array<string,mixed>|null $pulse */
    private function render(?array $pulse): string
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'));
        $twig->addFunction(new TwigFunction('t', static function (string $key, array $params = []): string {
            $strings = [
                'ui.dashboard.card.crossroads' => 'Crossroads',
                'ui.dashboard.crossroads.you_are_in' => "You're in",
                'ui.dashboard.crossroads.playing' => 'is playing',
                'ui.dashboard.crossroads.you_played' => 'You played',
                'ui.dashboard.crossroads.enter' => 'Enter the Crossroads',
                'ui.webdoors.return' => 'Return',
                'ui.webdoors.around_quiet' => 'The Crossroads are quiet right now.',
                'ui.webdoors.recent_activity_played' => 'played',
                'ui.webdoors.recent_activity_first_played' => 'first played',
                'time.just_now' => 'Just now',
                'time.minutes_ago' => '{count} minutes ago',
                'time.hours_ago' => '{count} hour{suffix} ago',
                'time.yesterday' => 'Yesterday',
                'time.days_ago' => '{count} days ago',
                'time.suffix_singular' => '',
                'time.suffix_plural' => 's',
            ];
            $text = $strings[$key] ?? $key;
            foreach ($params as $k => $v) {
                $text = str_replace('{' . $k . '}', (string)$v, $text);
            }
            return $text;
        }));

        // Decode entities so assertions can read the natural wording (Twig
        // autoescapes the apostrophe in "You're in").
        return html_entity_decode(
            $twig->render('partials/dashboard_crossroads_pulse.twig', [
                'locale' => 'en',
                'crossroads_pulse' => $pulse,
            ]),
            ENT_QUOTES | ENT_HTML5
        );
    }

    public function testWrapperCarriesCrossroadsCardId(): void
    {
        $html = $this->render(['state' => 'quiet']);
        self::assertStringContainsString('data-card-id="crossroads"', $html);
        self::assertMatchesRegularExpression('/<h6[^>]*>\s*<i[^>]*><\/i>\s*Crossroads\s*<\/h6>/', $html);
    }

    public function testParticipatingStateShowsExperienceAndReturnToCanonicalLobby(): void
    {
        $html = $this->render([
            'state' => 'participating',
            'viewer' => ['experience_id' => 'green dragon', 'experience_name' => 'Green Dragon'],
        ]);

        self::assertStringContainsString("You're in", $html);
        self::assertStringContainsString('Green Dragon', $html);
        self::assertMatchesRegularExpression('/class="btn[^"]*"[^>]*>\s*Return\s*</', $html);
        // Canonical Experience lobby route, url-encoded id.
        self::assertStringContainsString('href="/experiences/green%20dragon"', $html);
        self::assertStringNotContainsString('is playing', $html);
    }

    public function testOthersStateRendersCompactRowsToCanonicalLobbyRoutes(): void
    {
        $html = $this->render([
            'state' => 'others',
            'others' => [
                ['username' => 'kadmin', 'experience_id' => 'lord', 'experience_name' => 'Legend of the Red Dragon'],
                ['username' => 'bard', 'experience_id' => 'wordle', 'experience_name' => 'Wordle'],
            ],
        ]);

        self::assertSame(2, substr_count($html, 'is playing'));
        self::assertStringContainsString('kadmin', $html);
        self::assertStringContainsString('href="/experiences/lord"', $html);
        self::assertStringContainsString('href="/experiences/wordle"', $html);
        self::assertStringNotContainsString('Return', $html);
    }

    public function testRecentStateRendersFirstPlayedWording(): void
    {
        $html = $this->render([
            'state' => 'recent',
            'recent' => [
                'username' => 'bard',
                'experience_id' => 'wordle',
                'experience_name' => 'Wordle',
                'first_play' => true,
            ],
        ]);

        self::assertStringContainsString('bard', $html);
        self::assertStringContainsString('first played', $html);
        self::assertStringContainsString('href="/experiences/wordle"', $html);
    }

    public function testRecentSelfStateRendersYouPlayedWithCanonicalLinkAndRelativeTime(): void
    {
        $html = $this->render([
            'state' => 'recent_self',
            'recent_self' => [
                'experience_id' => 'usurper',
                'experience_name' => 'Usurper Reborn',
                'occurred_at' => '2020-01-01 00:00:00+00',
            ],
        ]);

        self::assertStringContainsString('You played', $html);
        self::assertStringContainsString('Usurper Reborn', $html);
        // Canonical Experience destination, same as every other pulse state.
        self::assertStringContainsString('href="/experiences/usurper"', $html);
        // A day-level relative-time token from the existing time.* ladder.
        self::assertStringContainsString('days ago', $html);
    }

    public function testRecentSelfStateExposesNoReturnOrResumeSemantics(): void
    {
        $html = $this->render([
            'state' => 'recent_self',
            'recent_self' => [
                'experience_id' => 'usurper',
                'experience_name' => 'Usurper Reborn',
                'occurred_at' => '2026-08-30 12:00:00+00',
            ],
        ]);

        // Historical relationship only: no Return button, no resume/continue
        // wording, no "playing now" present tense.
        self::assertStringNotContainsString('Return', $html);
        self::assertStringNotContainsStringIgnoringCase('resume', $html);
        self::assertStringNotContainsStringIgnoringCase('continue', $html);
        self::assertStringNotContainsString('is playing', $html);
        self::assertStringNotContainsString('btn-primary', $html);
    }

    public function testQuietStateUsesExistingCrossroadsQuietWording(): void
    {
        $html = $this->render(['state' => 'quiet']);
        self::assertStringContainsString('The Crossroads are quiet right now.', $html);
    }

    public function testAbsentPulseFallsBackToQuiet(): void
    {
        $html = $this->render(null);
        self::assertStringContainsString('The Crossroads are quiet right now.', $html);
    }

    public function testEveryStateAlwaysOffersEnterTheCrossroadsToGames(): void
    {
        foreach ([
            ['state' => 'quiet'],
            ['state' => 'participating', 'viewer' => ['experience_id' => 'x', 'experience_name' => 'X']],
            ['state' => 'others', 'others' => [['username' => 'a', 'experience_id' => 'x', 'experience_name' => 'X']]],
            ['state' => 'recent', 'recent' => ['username' => 'a', 'experience_id' => 'x', 'experience_name' => 'X', 'first_play' => false]],
            ['state' => 'recent_self', 'recent_self' => ['experience_id' => 'x', 'experience_name' => 'X', 'occurred_at' => '2026-08-30 12:00:00+00']],
        ] as $pulse) {
            $html = $this->render($pulse);
            self::assertStringContainsString('Enter the Crossroads', $html);
            self::assertStringContainsString('href="/games"', $html);
        }
    }

    public function testCardStaysCompactAtAboutFourInformationalRows(): void
    {
        // Worst case: 3 other-player rows + the Enter link.
        $html = $this->render([
            'state' => 'others',
            'others' => [
                ['username' => 'a', 'experience_id' => 'x', 'experience_name' => 'X'],
                ['username' => 'b', 'experience_id' => 'y', 'experience_name' => 'Y'],
                ['username' => 'c', 'experience_id' => 'z', 'experience_name' => 'Z'],
            ],
        ]);

        self::assertSame(3, substr_count($html, '<li'));
        self::assertStringNotContainsString('experience-library-card', $html);
        self::assertStringNotContainsString('scoreboard', strtolower($html));
        self::assertStringNotContainsString('filter', strtolower($html));
    }
}
