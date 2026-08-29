<?php

declare(strict_types=1);

namespace BinktermPHP\Tests\Unit;

use BinktermPHP\ExperiencePresentation;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Privacy-first contract tests for the anonymous Experience discovery window
 * (Slice 9B — the public /crossroads surface).
 *
 * The guarantee under test: a logged-out visitor can see Experience metadata
 * and aggregate occupancy counts, and NOTHING that identifies a member or
 * grants participation authority.
 *
 * Fixtures are deliberately hostile — they carry fake usernames, session ids,
 * node numbers, timestamps, viewer participation and raw source manifests — so
 * the tests prove STRIPPING, not an already-clean input.
 */
final class AnonymousExperienceDiscoveryTest extends TestCase
{
    private const IDENTITY_KEYS = [
        'players', 'viewer', 'user_id', 'username', 'session_id',
        'node', 'started_at', 'presence', 'source', 'manifest', 'launch',
    ];

    private const HOSTILE_NEEDLES = [
        'victim_alice', 'victim_bob', 'SESSIONTOKEN-DEADBEEF',
        '/etc/shadow', 'rm -rf /', 'evil-backend-secret-id',
    ];

    private static function hostileExperience(): array
    {
        return [
            'id' => 'hostile',
            'name' => 'Hostile Experience',
            'description' => 'fixture',
            'category' => 'game',
            'backend' => ['type' => 'native', 'id' => 'evil-backend-secret-id'],
            'surfaces' => ['web' => 'full', 'telnet' => 'full'],
            'policy' => ['enabled' => true, 'credit_cost' => 0, 'admin_only' => false],
            'capacity' => ['max_sessions' => 4],
            'capabilities' => ['multiplayer' => true],
            'presentation' => ['icon_url' => '/door-assets/hostile/icon'],
            'participant_actions' => ['profile' => true, 'message' => true],
            'source' => [
                'type' => 'native',
                'manifest' => [
                    'secret_path' => '/etc/shadow',
                    'launch_command' => 'rm -rf /',
                ],
            ],
        ];
    }

    private static function hostileState(): array
    {
        return [
            'active' => true,
            'session_count' => 3,
            'player_count' => 2,
            'players' => [
                [
                    'user_id' => 4242,
                    'username' => 'victim_alice',
                    'session_id' => 'SESSIONTOKEN-DEADBEEF',
                    'node' => 7,
                    'started_at' => 1700000000,
                    'presence' => 'Playing Hostile Experience',
                    'presence_state' => 'playing',
                ],
                [
                    'user_id' => 4243,
                    'username' => 'victim_bob',
                    'session_id' => 'SESSIONTOKEN-DEADBEEF',
                    'node' => 8,
                    'started_at' => 1700000001,
                ],
            ],
        ];
    }

    /** Recursively collect every array key and every scalar value as strings. */
    private static function flatten(mixed $value, array &$keys, array &$scalars): void
    {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $keys[] = (string)$k;
                self::flatten($v, $keys, $scalars);
            }
            return;
        }
        if (is_scalar($value) || $value === null) {
            $scalars[] = (string)$value;
        }
    }

    // ---------------------------------------------------------------- A: projection

    public function testBuildPublicStripsEveryIdentityBearingKey(): void
    {
        $out = ExperiencePresentation::buildPublic(
            self::hostileExperience(),
            'web',
            self::hostileState()
        );

        $keys = [];
        $scalars = [];
        self::flatten($out, $keys, $scalars);

        foreach (self::IDENTITY_KEYS as $forbidden) {
            self::assertNotContains(
                $forbidden,
                $keys,
                "buildPublic() output must not contain the key '{$forbidden}'"
            );
        }

        $json = json_encode($out);
        foreach (self::HOSTILE_NEEDLES as $needle) {
            self::assertStringNotContainsString(
                $needle,
                (string)$json,
                "buildPublic() output leaked hostile value '{$needle}'"
            );
        }
    }

    public function testBuildPublicNeverProducesParticipatingStatusOrActions(): void
    {
        // Even if a viewerPlayer somehow reached build() (it cannot through
        // buildPublic), the projection re-asserts the boundary.
        $out = ExperiencePresentation::buildPublic(
            self::hostileExperience(),
            'web',
            self::hostileState()
        );

        self::assertContains(
            $out['status']['code'],
            ['available', 'at_capacity', 'planned', 'unavailable']
        );
        self::assertNotSame('participating', $out['status']['code']);

        self::assertFalse($out['actions']['play']);
        self::assertFalse($out['actions']['return']);
        self::assertFalse($out['actions']['end_participation']);
        self::assertArrayNotHasKey('primary', $out['actions']);
        self::assertArrayNotHasKey('viewer', $out);
        self::assertArrayNotHasKey('launch', $out);
        self::assertArrayNotHasKey('source', $out);
    }

    public function testBuildPublicKeepsAggregateOccupancyAndMetadata(): void
    {
        $out = ExperiencePresentation::buildPublic(
            self::hostileExperience(),
            'web',
            self::hostileState()
        );

        self::assertSame('Hostile Experience', $out['name']);
        self::assertTrue($out['capabilities']['multiplayer']);
        self::assertSame(4, $out['capacity']['max_sessions']);
        self::assertSame(2, $out['runtime']['player_count']);
        self::assertSame(3, $out['runtime']['session_count']);
        self::assertTrue($out['runtime']['active']);
        self::assertArrayNotHasKey('players', $out['runtime']);
    }

    public function testBuildPublicWithNoStateOmitsOccupancy(): void
    {
        $out = ExperiencePresentation::buildPublic(self::hostileExperience(), 'web', null);

        self::assertFalse($out['runtime']['supplied']);
        self::assertNull($out['runtime']['player_count']);
        self::assertNull($out['runtime']['session_count']);
        self::assertArrayNotHasKey('players', $out['runtime']);
    }

    // ---------------------------------------------------------------- B: aggregate state contract

    public function testPublicAggregateReadsBuildNoRosterInSource(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 2) . '/src/ExperienceState.php'
        );

        $method = $this->between(
            $src,
            'public function getPublicExperienceAggregates(',
            'public function getPublicActivePeopleCount('
        );

        // No users join, no identity columns, no roster assembly.
        self::assertStringNotContainsString('JOIN users', $method);
        self::assertStringNotContainsString('u.username', $method);
        self::assertStringNotContainsString('username', $method);
        self::assertStringNotContainsString("'players'", $method);
        self::assertStringNotContainsString('session_id', $method);
        self::assertStringNotContainsString('node_number', $method);
        self::assertStringNotContainsString('public_activity', $method);

        // The only keys placed on each returned entry.
        self::assertStringContainsString("'active' => \$sessionCount > 0", $method);
        self::assertStringContainsString("'session_count' => \$sessionCount", $method);
        self::assertStringContainsString("'player_count' => count(", $method);

        $peopleMethod = $this->between(
            $src,
            'public function getPublicActivePeopleCount(',
            "\n}"
        );
        self::assertStringNotContainsString('JOIN users', $peopleMethod);
        self::assertStringNotContainsString('username', $peopleMethod);
        self::assertStringContainsString('return count($activeUserIds);', $peopleMethod);
    }

    // ---------------------------------------------------------------- C: catalog boundary

    public function testNullUserCatalogExposesNoAdminOnlyExperience(): void
    {
        $catalog = (new \BinktermPHP\GameCatalog())->getEnabledGames(null, 'web');

        self::assertNotEmpty($catalog);
        foreach ($catalog as $id => $experience) {
            self::assertEmpty(
                $experience['policy']['admin_only'] ?? false,
                "Anonymous web catalog exposed admin-only Experience '{$id}'"
            );
        }
    }

    public function testCatalogSourceGuardsAdminOnlyAndHideFromWeb(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 2) . '/src/GameCatalog.php'
        );

        self::assertStringContainsString(
            "!empty(\$door['admin_only']) && empty(\$user['is_admin'])",
            $src
        );
        self::assertStringContainsString(
            "\$surface === 'web' && !empty(\$door['config']['hide_from_web'])",
            $src
        );
    }

    // ---------------------------------------------------------------- D: route trust boundary

    public function testCrossroadsRouteIsPublicAndToggleGated(): void
    {
        $web = (string)file_get_contents(
            dirname(__DIR__, 2) . '/routes/web-routes.php'
        );

        $route = $this->between(
            $web,
            "SimpleRouter::get('/crossroads',",
            "SimpleRouter::get('/experiences/{experienceId}',"
        );

        // Public: no auth gate of any kind in the handler.
        self::assertStringNotContainsString('requireAuth', $route);
        self::assertStringNotContainsString('requireAdmin', $route);
        self::assertStringNotContainsString("redirect('/login')", $route);

        // Toggle-gated, and does not advertise itself when disabled.
        self::assertStringContainsString(
            '!BbsConfig::isAnonymousExperienceDiscoveryEnabled()',
            $route
        );
        self::assertStringContainsString("'404.twig'", $route);

        // Reuses the projection + aggregate primitives, renders the public template.
        self::assertStringContainsString('getEnabledGames(null, \'web\')', $route);
        self::assertStringContainsString('getPublicExperienceAggregates(', $route);
        self::assertStringContainsString('ExperiencePresentation::buildPublic(', $route);
        self::assertStringContainsString("renderResponse('crossroads.twig'", $route);

        // Must not touch identity-bearing / participation subsystems.
        self::assertStringNotContainsString('ExperienceActivity', $route);
        self::assertStringNotContainsString('ExperienceScoreboard', $route);
        self::assertStringNotContainsString('ExperiencePresence', $route);
        self::assertStringNotContainsString('getExperienceStates(', $route);
        self::assertStringNotContainsString('findViewerPlayer', $route);
    }

    public function testAuthenticatedExperienceRoutesRemainGated(): void
    {
        $root = dirname(__DIR__, 2);
        $web = (string)file_get_contents($root . '/routes/web-routes.php');
        $webdoor = (string)file_get_contents($root . '/routes/webdoor-routes.php');
        $api = (string)file_get_contents($root . '/routes/api-routes.php');

        // /games still bounces anonymous visitors to login.
        $games = $this->between(
            $webdoor,
            "SimpleRouter::get('/games', function() {",
            "SimpleRouter::get('/games/dosdoors/"
        );
        self::assertStringContainsString('if (!$user)', $games);
        self::assertStringContainsString("redirect('/login')", $games);

        // /experiences/{id} lobby still requires auth.
        $lobby = $this->between(
            $web,
            "SimpleRouter::get('/experiences/{experienceId}', function(string \$experienceId) {",
            "SimpleRouter::get('/', function() {"
        );
        self::assertStringContainsString('RouteHelper::requireAuth()', $lobby);

        // The authenticated Experience state + end APIs still require auth.
        $stateApi = $this->between(
            $api,
            "SimpleRouter::get('/experiences/{experienceId}/state',",
            "SimpleRouter::post('/experiences/{experienceId}/end',"
        );
        self::assertStringContainsString('RouteHelper::requireAuth()', $stateApi);

        $endApi = $this->between(
            $api,
            "SimpleRouter::post('/experiences/{experienceId}/end',",
            "SimpleRouter::get('/whosonline',"
        );
        self::assertStringContainsString('RouteHelper::requireAuth()', $endApi);
    }

    public function testSiteRootOpensCrossroadsForAnonymousWhenDiscoveryEnabled(): void
    {
        $web = (string)file_get_contents(
            dirname(__DIR__, 2) . '/routes/web-routes.php'
        );

        $root = $this->between(
            $web,
            "SimpleRouter::get('/', function() {",
            "SimpleRouter::get('/bulletins',"
        );

        // Anonymous branch only: the feature flag is the sole authority, and it
        // gates the redirect target between /crossroads (on) and /login (off).
        $anonBranch = $this->between(
            $root,
            'if (!$user) {',
            "\n\n"
        );
        self::assertStringContainsString(
            'BbsConfig::isAnonymousExperienceDiscoveryEnabled()',
            $anonBranch
        );
        self::assertStringContainsString("redirect('/crossroads')", $anonBranch);
        self::assertStringContainsString("redirect('/login')", $anonBranch);

        // No new configuration option was introduced for this behavior.
        self::assertStringNotContainsString('isFeatureEnabled(', $anonBranch);

        // Authenticated GET / must be untouched: the bulletin redirect and the
        // dashboard render both remain in the handler.
        self::assertStringContainsString(
            "redirect('/bulletins?unread=1')",
            $root
        );
        self::assertStringContainsString(
            "renderResponse('dashboard.twig'",
            $root
        );

        // No redirect loop: /crossroads only ever renders or 404s for an
        // anonymous visitor — it never redirects back to the site root.
        $crossroads = $this->between(
            $web,
            "SimpleRouter::get('/crossroads',",
            "SimpleRouter::get('/experiences/{experienceId}',"
        );
        self::assertStringNotContainsString("redirect('/')", $crossroads);
    }

    // ---------------------------------------------------------------- E: template privacy

    private function renderPublicCard(array $view): string
    {
        $twig = new Environment(
            new FilesystemLoader(dirname(__DIR__, 2) . '/templates')
        );
        $twig->addFunction(new TwigFunction(
            't',
            static fn(string $key, array $params = [], ...$rest): string => $key
        ));

        return $twig->render('partials/experience_library_card.twig', [
            'public_mode' => true,
            'locale' => 'en',
            'game' => ['experience_presentation' => $view],
        ]);
    }

    public function testPublicCardRendersNoIdentityNoLobbyLinkNoLaunch(): void
    {
        // A projection-shaped view, but salted with hostile extras a buggy
        // template could surface.
        $view = ExperiencePresentation::buildPublic(
            self::hostileExperience(),
            'web',
            self::hostileState()
        );
        $view['players'] = self::hostileState()['players'];
        $view['viewer'] = ['participating' => true, 'session_id' => 'SESSIONTOKEN-DEADBEEF'];

        $html = $this->renderPublicCard($view);

        self::assertStringNotContainsString('/experiences/', $html);
        self::assertStringNotContainsString('/profile/', $html);
        self::assertStringNotContainsString('victim_alice', $html);
        self::assertStringNotContainsString('victim_bob', $html);
        self::assertStringNotContainsString('SESSIONTOKEN-DEADBEEF', $html);
        self::assertStringNotContainsString('/games/nativedoors/', $html);
        self::assertStringNotContainsString('ui.webdoors.return', $html);
        self::assertStringNotContainsString('ui.webdoors.enter', $html);

        // The intentional public affordance.
        self::assertStringContainsString('ui.discovery.sign_in_to_play', $html);
        self::assertStringContainsString('href="/login"', $html);
        // Aggregate occupancy still shows.
        self::assertStringContainsString('ui.webdoors.players_online', $html);
    }

    public function testPublicCardIsQuietWhenNobodyIsPlaying(): void
    {
        $exp = self::hostileExperience();
        $exp['capabilities']['multiplayer'] = true;
        $exp['policy']['credit_cost'] = 0;

        $view = ExperiencePresentation::buildPublic($exp, 'web', [
            'active' => false,
            'session_count' => 0,
            'player_count' => 0,
        ]);

        $html = $this->renderPublicCard($view);

        self::assertStringNotContainsString('ui.webdoors.players_online', $html);
        self::assertStringNotContainsString('ui.webdoors.full_capacity', $html);
        // No engagement-pressure language keys.
        self::assertStringNotContainsString('experience-metadata-cluster', $html);
        // Still discoverable, still offers the sign-in path.
        self::assertStringContainsString('ui.discovery.sign_in_to_play', $html);
    }

    // ---------------------------------------------------------------- helpers

    private function between(string $source, string $start, string $end): string
    {
        $a = strpos($source, $start);
        self::assertNotFalse($a, "missing start marker: {$start}");
        $b = strpos($source, $end, $a + strlen($start));
        self::assertNotFalse($b, "missing end marker: {$end}");

        return substr($source, $a, $b - $a);
    }
}
