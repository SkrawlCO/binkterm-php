<?php

declare(strict_types=1);

namespace BinktermPHP\Tests\Unit;

use BinktermPHP\ExperiencePresentation;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * The Experience lobby and player-page chrome are first-class Crossroads
 * surfaces: they carry the "Crossroads" place identity (never legacy "Games" /
 * "Doors" / "Doors and Games") and all of their user-facing text goes through
 * the project i18n mechanism in every base locale.
 */
final class CrossroadsLobbyIdentityI18nTest extends TestCase
{
    private const BASE_LOCALES = ['en', 'de', 'es', 'fr', 'it', 'ru'];

    /** The lobby-local i18n group this slice introduced. */
    private const REQUIRED_LOBBY_KEYS = [
        'ui.experience_lobby.back',
        'ui.experience_lobby.live_players',
        'ui.experience_lobby.nobody_playing',
        'ui.experience_lobby.recent_activity',
        'ui.experience_lobby.no_recent_activity',
        'ui.experience_lobby.conversation',
        'ui.experience_lobby.conversation_loading',
        'ui.experience_lobby.conversation_placeholder',
        'ui.experience_lobby.conversation_aria',
        'ui.experience_lobby.conversation_send',
        'ui.experience_lobby.no_conversation',
        'ui.experience_lobby.conversation_unavailable',
        'ui.experience_lobby.conversation_load_failed',
        'ui.experience_lobby.send_failed',
        'ui.experience_lobby.end_participation',
        'ui.experience_lobby.end_confirm',
        'ui.experience_lobby.end_failed',
        'ui.experience_lobby.status_at_capacity',
        'ui.experience_lobby.status_planned',
        'ui.experience_lobby.status_unavailable',
        'ui.experience_lobby.presence_playing',
        'ui.experience_lobby.playing_badge',
        'ui.experience_lobby.unknown_user',
        'ui.experience_lobby.credit_cost',
        'ui.experience_lobby.credit_cost_one',
    ];

    /**
     * Legacy / hardcoded UI text that must not reappear in the lobby template
     * outside of comments. Kept small and specific so it catches regressions
     * without being brittle about formatting.
     *
     * @var string[]
     */
    private const LOBBY_FORBIDDEN_LITERALS = [
        'Back to Games',
        '>\n                            Back to Games',
        "container.textContent = 'No recent activity yet.'",
        "container.textContent = 'No conversation yet.'",
        "container.textContent = 'No one is playing right now",
        "'Unable to send message'",
        "'Unable to load conversation'",
        "'Unable to end active participation'",
        "? 'Full'\n",
        "'Return' : 'Enter'",
        "'first recorded play'",
        "return 'just now';",
        "return 'yesterday';",
        "'Conversation is temporarily unavailable.'",
        'End your active participation in ${EXPERIENCE_NAME}',
        "'No open sessions right now.'",
    ];

    /** @return array<string,string> */
    private function catalog(string $locale): array
    {
        $path = dirname(__DIR__, 2) . "/config/i18n/{$locale}/common.php";
        self::assertFileExists($path);

        return require $path;
    }

    private function twig(string $locale): Environment
    {
        $catalog = $this->catalog($locale);

        $twig = new Environment(
            new FilesystemLoader(dirname(__DIR__, 2) . '/templates')
        );
        $twig->addFunction(new TwigFunction(
            't',
            static function (string $key, array $params = [], ...$args) use ($catalog) {
                $value = $catalog[$key] ?? $key;
                foreach ($params as $name => $replacement) {
                    $value = str_replace('{' . $name . '}', (string) $replacement, $value);
                }
                return $value;
            }
        ));
        $twig->addFunction(new TwigFunction(
            'bbs_feature_enabled',
            static fn(string $feature): bool => false
        ));

        return $twig;
    }

    private function renderLobby(string $locale): string
    {
        $experience = [
            'id' => 'usurper',
            'name' => 'Usurper Reborn',
            'description' => 'Usurper Reborn fantasy RPG BBS door.',
            'backend' => ['type' => 'native', 'id' => 'usurper'],
            'presentation' => ['icon_url' => '/x/icon', 'screenshot_url' => null],
            'capabilities' => [
                'multiplayer' => true,
                'conversation' => ['type' => 'chat_room', 'room_id' => 5],
            ],
            'participant_actions' => ['profile' => true, 'message' => true],
            'capacity' => ['max_sessions' => 10],
            'surfaces' => ['web' => 'full', 'telnet' => 'planned'],
            'policy' => ['enabled' => true, 'credit_cost' => 0],
            'actions' => ['launch' => true],
        ];

        $state = [
            'active' => false,
            'session_count' => 0,
            'player_count' => 0,
            'players' => [],
        ];

        return $this->twig($locale)->render('experience_lobby.twig', [
            'locale' => $locale,
            'experience' => $experience,
            'experience_presentation' => ExperiencePresentation::build($experience, 'web', $state, null),
            'state' => $state,
            'launch' => ['type' => 'native', 'id' => 'usurper', 'url' => '/games/nativedoors/usurper'],
            'current_user' => ['user_id' => 7],
        ]);
    }

    /**
     * Rendered body with <script> blocks removed and HTML entities decoded, so
     * assertions read the natural wording (Twig autoescapes apostrophes to
     * &#039;, which matters for fr/it strings like "d'activité").
     */
    private static function withoutScripts(string $html): string
    {
        $stripped = (string) preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html);

        return html_entity_decode($stripped, ENT_QUOTES | ENT_HTML5);
    }

    // ---- Lobby identity ----

    public function testLobbyBackControlRendersCrossroadsIdentityInEnglish(): void
    {
        $body = self::withoutScripts($this->renderLobby('en'));

        self::assertStringContainsString('Back to Crossroads', $body);
        self::assertStringNotContainsString('Back to Games', $body);
        self::assertStringNotContainsString('Back to Doors', $body);
    }

    public function testLobbyBackControlIsLocalizedNotHardcodedEnglish(): void
    {
        // The German lobby renders the German back control and none of the
        // English identity strings — proof the surface is genuinely localized.
        $body = self::withoutScripts($this->renderLobby('de'));

        self::assertStringContainsString('Zurück zu Crossroads', $body);
        self::assertStringNotContainsString('Back to Crossroads', $body);
        self::assertStringNotContainsString('Back to Games', $body);
    }

    public function testLobbyHeadingsAndEmptyStatesAreLocalizedInEveryBaseLocale(): void
    {
        foreach (self::BASE_LOCALES as $locale) {
            $catalog = $this->catalog($locale);
            $body = self::withoutScripts($this->renderLobby($locale));

            foreach ([
                'ui.experience_lobby.back',
                'ui.experience_lobby.live_players',
                'ui.experience_lobby.nobody_playing',
                'ui.experience_lobby.recent_activity',
                'ui.experience_lobby.no_recent_activity',
                'ui.experience_lobby.conversation',
                'ui.experience_lobby.end_participation',
            ] as $key) {
                self::assertArrayHasKey($key, $catalog, "{$locale} missing {$key}");
                self::assertStringContainsString(
                    (string) $catalog[$key],
                    $body,
                    "{$locale} lobby did not render {$key}"
                );
            }

            // No raw translation keys leaked into the rendered body.
            self::assertStringNotContainsString('ui.experience_lobby.', $body, "{$locale} leaked a lobby key");
            self::assertStringNotContainsString('ui.webdoors.', $body, "{$locale} leaked a webdoors key");
        }
    }

    public function testLobbyJsGeneratedTextRoutesThroughTranslation(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/templates/experience_lobby.twig'
        );
        self::assertIsString($source);

        // The JS layer resolves every generated string through window.t() with
        // a server-rendered fallback (the lobbyT helper).
        self::assertStringContainsString('function lobbyT(key, fallbackKey, params)', $source);
        self::assertStringContainsString('const EXPERIENCE_LOBBY_I18N = {', $source);

        foreach ([
            "lobbyT('ui.experience_lobby.nobody_playing', 'nobody_playing')",
            "lobbyT('ui.experience_lobby.no_recent_activity', 'no_recent_activity')",
            "lobbyT('ui.experience_lobby.no_conversation', 'no_conversation')",
            "lobbyT('ui.experience_lobby.conversation_unavailable', 'conversation_unavailable')",
            "lobbyT('ui.experience_lobby.send_failed', 'send_failed')",
            "lobbyT('ui.experience_lobby.end_failed', 'end_failed')",
            "lobbyT('ui.experience_lobby.end_confirm', 'end_confirm', { name: EXPERIENCE_NAME })",
            "lobbyT('ui.experience_lobby.status_at_capacity', 'status_at_capacity')",
            "lobbyT('ui.webdoors.players_online', 'players_online'",
            "lobbyT('ui.webdoors.full_capacity', 'full')",
            "lobbyT('ui.webdoors.enter', 'enter')",
            "lobbyT('ui.webdoors.return', 'return')",
            "lobbyT('time.just_now', 'time_just_now')",
            "lobbyT('ui.common.message', 'message')",
        ] as $needle) {
            self::assertStringContainsString($needle, $source, "missing i18n call: {$needle}");
        }
    }

    public function testLobbyTemplateHasNoKnownHardcodedLegacyStrings(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/templates/experience_lobby.twig'
        );
        self::assertIsString($source);

        // Drop Twig comment blocks so prose in {# ... #} never trips the scan.
        $scanned = (string) preg_replace('/\{#.*?#\}/s', '', $source);

        foreach (self::LOBBY_FORBIDDEN_LITERALS as $literal) {
            self::assertStringNotContainsString(
                $literal,
                $scanned,
                "lobby still contains a hardcoded/legacy literal: {$literal}"
            );
        }
    }

    public function testParticipantPartialHasNoHardcodedLobbyText(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/templates/partials/experience_participant.twig'
        );
        self::assertIsString($source);

        self::assertStringContainsString(
            "t('ui.experience_lobby.presence_playing', {'name': experience.name}",
            $source
        );
        self::assertStringContainsString(
            "t('ui.common.message', {}, locale, ['common'])",
            $source
        );
        self::assertStringNotContainsString('Playing {{ experience.name }}', $source);
        self::assertStringNotContainsString(
            "aria-hidden=\"true\"></i>\n                Message",
            $source
        );
    }

    // ---- Locale catalog contract ----

    public function testEveryBaseLocaleHasTheLobbyKeysAndTheCrossroadsBackKey(): void
    {
        foreach (self::BASE_LOCALES as $locale) {
            $catalog = $this->catalog($locale);

            foreach (self::REQUIRED_LOBBY_KEYS as $key) {
                self::assertArrayHasKey($key, $catalog, "{$locale}/common.php missing {$key}");
                self::assertNotSame('', trim((string) $catalog[$key]), "{$locale} empty {$key}");
            }

            self::assertArrayHasKey('ui.webdoor_play.back_to_crossroads', $catalog, "{$locale} missing back_to_crossroads");
            self::assertArrayNotHasKey('ui.webdoor_play.back_to_doors', $catalog, "{$locale} still has the retired back_to_doors key");
        }
    }

    public function testPlayerChromeCatalogValuesCarryCrossroadsIdentity(): void
    {
        foreach (self::BASE_LOCALES as $locale) {
            $catalog = $this->catalog($locale);

            self::assertSame('Crossroads', $catalog['ui.webdoor_play.page_title_suffix'] ?? null, "{$locale} webdoor_play title suffix");
            self::assertSame('Crossroads', $catalog['ui.jsdosdoor.page_title_suffix'] ?? null, "{$locale} jsdosdoor title suffix");

            foreach (['ui.webdoor_play.page_title_suffix', 'ui.jsdosdoor.page_title_suffix', 'ui.webdoor_play.back_to_crossroads'] as $key) {
                $value = (string) ($catalog[$key] ?? '');
                self::assertStringNotContainsStringIgnoringCase('Doors and Games', $value, "{$locale} {$key}");
                self::assertStringNotContainsStringIgnoringCase('Back to Doors', $value, "{$locale} {$key}");
            }
        }

        // English identity is exact.
        $en = $this->catalog('en');
        self::assertSame('Back to Crossroads', $en['ui.webdoor_play.back_to_crossroads']);
    }

    // ---- Player-page templates ----

    /**
     * @return array<string,array{0:string}>
     */
    public static function playerTemplateProvider(): array
    {
        return [
            'dosdoor_play' => ['templates/dosdoor_play.twig'],
            'webdoor_play' => ['templates/webdoor_play.twig'],
            'jsdosdoor_coming_soon' => ['templates/jsdosdoor_coming_soon.twig'],
        ];
    }

    /** @dataProvider playerTemplateProvider */
    public function testPlayerTemplatesUseTheCrossroadsBackKey(string $relativePath): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
        self::assertIsString($source);

        self::assertStringContainsString(
            "t('ui.webdoor_play.back_to_crossroads', {}, locale, ['common'])",
            $source
        );
        self::assertStringNotContainsString('back_to_doors', $source);
    }

    public function testPlayerTitleChromeNoLongerExposesDoorsAndGames(): void
    {
        foreach ([
            'templates/dosdoor_play.twig',
            'templates/webdoor_play.twig',
            'templates/jsdosdoor_play.twig',
            'templates/jsdosdoor_coming_soon.twig',
        ] as $relativePath) {
            $source = file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
            self::assertIsString($source);
            self::assertStringNotContainsStringIgnoringCase('Doors and Games', $source, $relativePath);
        }
    }

    // ---- Relative-time composition (Recent Activity panel) ----

    /**
     * Extract the three self-contained relative-time pieces from the rendered
     * lobby script and run them under node against a faithful reproduction of
     * app.js's window.t(), for both catalog states. This exercises the real
     * Twig -> JS relative-time contract, not just key source strings.
     *
     * @return array<string,string> case label => rendered string
     */
    private function renderRelativeTimeCases(string $locale, bool $catalogLoaded): array
    {
        $html = $this->renderLobby($locale);

        self::assertSame(
            1,
            preg_match('/const EXPERIENCE_LOBBY_I18N = \{.*?\n\};/s', $html, $i18nBlock),
            'EXPERIENCE_LOBBY_I18N block not found'
        );
        self::assertSame(
            1,
            preg_match('/function lobbyT\(key, fallbackKey, params\) \{.*?\n\}/s', $html, $lobbyTBlock),
            'lobbyT() not found'
        );
        self::assertSame(
            1,
            preg_match(
                '/function formatExperienceActivityTime\(value, nowValue = new Date\(\)\) \{.*?\n\}\n\nfunction renderExperienceActivity/s',
                $html,
                $formatBlock
            ),
            'formatExperienceActivityTime() not found'
        );
        $formatFn = preg_replace('/\n\nfunction renderExperienceActivity$/', '', $formatBlock[0]);

        $catalog = $this->catalog($locale);
        $catalogJson = $catalogLoaded
            ? json_encode([
                'common' => [
                    'time.just_now' => $catalog['time.just_now'],
                    'time.minutes_ago' => $catalog['time.minutes_ago'],
                    'time.hours_ago' => $catalog['time.hours_ago'],
                    'time.yesterday' => $catalog['time.yesterday'],
                    'time.suffix_singular' => $catalog['time.suffix_singular'],
                    'time.suffix_plural' => $catalog['time.suffix_plural'],
                ],
            ], JSON_UNESCAPED_UNICODE)
            : '{}';

        $i18nJs = $i18nBlock[0];
        $lobbyTJs = $lobbyTBlock[0];

        // Faithful reproduction of app.js i18nLookup/t; i18nInterpolate uses
        // split/join (behaviourally identical to its RegExp global replace for
        // literal {token}s) to keep this harness escaping-free.
        $harness = <<<JS
        var window = { i18n: { catalogs: {$catalogJson} } };
        function i18nInterpolate(template, params) {
            let output = String(template || '');
            Object.keys(params || {}).forEach(function (key) {
                output = output.split('{' + key + '}').join(String(params[key]));
            });
            return output;
        }
        function i18nLookup(key) {
            const catalogs = (window.i18n && window.i18n.catalogs) || {};
            for (const ns of Object.keys(catalogs)) {
                if (Object.prototype.hasOwnProperty.call(catalogs[ns], key)) {
                    return catalogs[ns][key];
                }
            }
            return null;
        }
        function t(key, params, fallback) {
            params = params || {};
            fallback = fallback || '';
            const value = i18nLookup(key);
            if (typeof value === 'string') { return i18nInterpolate(value, params); }
            if (fallback) { return i18nInterpolate(fallback, params); }
            return key;
        }
        window.t = t;

        {$i18nJs}
        {$lobbyTJs}
        {$formatFn}

        const NOW = new Date('2026-06-15T12:00:00Z');
        const at = ms => formatExperienceActivityTime(new Date(NOW.getTime() - ms), NOW);
        const H = 3600 * 1000;
        const M = 60 * 1000;
        const D = 24 * H;
        console.log(JSON.stringify({
            'just now': at(10 * 1000),
            '1 minute ago': at(1 * M),
            '2 minutes ago': at(2 * M),
            '1 hour ago': at(1 * H),
            '2 hours ago': at(2 * H),
            'yesterday': at(26 * H),
            'older date': at(5 * D)
        }));
        JS;

        $tmp = tempnam(sys_get_temp_dir(), 'lobbytime_') . '.js';
        file_put_contents($tmp, $harness);
        try {
            $output = shell_exec('node ' . escapeshellarg($tmp) . ' 2>&1');
        } finally {
            @unlink($tmp);
        }

        $decoded = json_decode(trim((string) $output), true);
        self::assertIsArray(
            $decoded,
            "node harness did not return JSON for {$locale} (loaded=" . ($catalogLoaded ? '1' : '0') . "): " . $output
        );

        return $decoded;
    }

    private static function interpolate(string $template, array $params): string
    {
        foreach ($params as $k => $v) {
            $template = str_replace('{' . $k . '}', (string) $v, $template);
        }
        return $template;
    }

    /** @return array<string,array{0:string}> */
    public static function baseLocaleProvider(): array
    {
        return array_combine(
            self::BASE_LOCALES,
            array_map(static fn(string $l): array => [$l], self::BASE_LOCALES)
        );
    }

    /**
     * @dataProvider baseLocaleProvider
     * The reported bug: with the catalog not yet fetched, the singular-hour row
     * rendered "1 hourtime.suffix_singular ago" because window.t() returns the
     * key when a catalog miss coincides with an empty-string fallback and
     * time.suffix_singular is legitimately ''.
     */
    public function testRelativeTimeCompositionBeforeCatalogLoads(string $locale): void
    {
        $cases = $this->renderRelativeTimeCases($locale, catalogLoaded: false);
        $catalog = $this->catalog($locale);

        self::assertSame($catalog['time.just_now'], $cases['just now'], "{$locale} just now");
        self::assertSame(
            self::interpolate($catalog['time.minutes_ago'], ['count' => 1]),
            $cases['1 minute ago'],
            "{$locale} 1 minute ago"
        );
        self::assertSame(
            self::interpolate($catalog['time.minutes_ago'], ['count' => 2]),
            $cases['2 minutes ago'],
            "{$locale} 2 minutes ago"
        );
        self::assertSame(
            self::interpolate($catalog['time.hours_ago'], [
                'count' => 1,
                'suffix' => $catalog['time.suffix_singular'],
            ]),
            $cases['1 hour ago'],
            "{$locale} 1 hour ago"
        );
        self::assertSame(
            self::interpolate($catalog['time.hours_ago'], [
                'count' => 2,
                'suffix' => $catalog['time.suffix_plural'],
            ]),
            $cases['2 hours ago'],
            "{$locale} 2 hours ago"
        );
        self::assertSame($catalog['time.yesterday'], $cases['yesterday'], "{$locale} yesterday");

        // No key / placeholder / undefined leakage in any row, especially the
        // singular-hour one.
        foreach ($cases as $label => $rendered) {
            self::assertStringNotContainsString('time.', $rendered, "{$locale} {$label} leaked a key");
            self::assertStringNotContainsString('time_suffix', $rendered, "{$locale} {$label} leaked a fallback key");
            self::assertStringNotContainsString('{', $rendered, "{$locale} {$label} left a placeholder");
            self::assertStringNotContainsString('undefined', $rendered, "{$locale} {$label} rendered undefined");
        }

        // Older date falls through to the Intl month/day path — non-empty and
        // distinct from the relative phrases.
        self::assertNotSame('', trim($cases['older date']), "{$locale} older date empty");
        self::assertNotSame($cases['yesterday'], $cases['older date'], "{$locale} older date == yesterday");
    }

    public function testRelativeTimeCompositionMatchesAfterCatalogLoads(): void
    {
        // Once the live catalog is present the same rows must be identical.
        foreach (['en', 'fr'] as $locale) {
            $before = $this->renderRelativeTimeCases($locale, catalogLoaded: false);
            $after = $this->renderRelativeTimeCases($locale, catalogLoaded: true);

            foreach (['just now', '1 minute ago', '1 hour ago', '2 hours ago', 'yesterday'] as $label) {
                self::assertSame(
                    $before[$label],
                    $after[$label],
                    "{$locale} {$label} differs between catalog states"
                );
            }
        }
    }

    public function testJsdosPlayerTitleRendersCrossroadsIdentity(): void
    {
        $html = $this->twig('en')->render('jsdosdoor_play.twig', [
            'locale' => 'en',
            'system_name' => 'L33Test',
            'game' => ['name' => 'Doom', 'icon' => 'icon.png', 'author' => null, 'version' => null, 'description' => ''],
            'game_path' => 'doomsw',
            'game_id' => 'doomsw',
            'mode_id' => 'play',
            'mode' => ['label' => '', 'description' => null, 'keep_open' => false, 'emulator_config' => [], 'saves' => []],
        ]);

        self::assertStringContainsString('<title>Doom - Crossroads</title>', $html);
        self::assertStringNotContainsString('JS-DOS Door', $html);
    }
}
