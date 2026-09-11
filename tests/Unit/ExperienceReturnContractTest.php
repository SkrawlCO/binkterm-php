<?php

declare(strict_types=1);

namespace BinktermPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ExperienceReturnContractTest extends TestCase
{
    public function testNativeDoorRouteReturnsToCanonicalExperience(): void
    {
        $routes = file_get_contents(
            dirname(__DIR__, 2) . '/routes/webdoor-routes.php'
        );

        self::assertIsString($routes);

        self::assertStringContainsString(
            "\$returnUrl = '/experiences/' . rawurlencode((string)\$doorid);",
            $routes
        );
    }

    public function testLegacyDosDoorRetainsGamesFallback(): void
    {
        $routes = file_get_contents(
            dirname(__DIR__, 2) . '/routes/webdoor-routes.php'
        );

        self::assertIsString($routes);

        self::assertStringContainsString(
            "\$returnUrl = '/games';",
            $routes
        );
    }

    public function testBrowserUnloadDetachesWithoutEndingDoorSession(): void
    {
        $player = file_get_contents(
            dirname(__DIR__, 2)
            . '/public_html/webdoors/dosdoors/index.php'
        );

        self::assertIsString($player);

        self::assertStringContainsString(
            "window.addEventListener('beforeunload', () => {",
            $player
        );

        self::assertStringNotContainsString(
            "navigator.sendBeacon('/api/door/end'",
            $player
        );
    }

    public function testWebDoorHostEndsParticipationOnUnloadUnlikeManagedDoors(): void
    {
        // A WebDoor has no live runtime to reconnect to and its progress is
        // saved per game+slot, so leaving the page should end participation
        // (clearing stale Live Now / roster presence). This is the deliberate
        // opposite of the managed-door player asserted above.
        $template = file_get_contents(
            dirname(__DIR__, 2) . '/templates/webdoor_play.twig'
        );

        self::assertIsString($template);

        self::assertStringContainsString(
            "window.addEventListener('beforeunload', function () {",
            $template
        );
        self::assertStringContainsString(
            "navigator.sendBeacon(\n"
            . "            '/api/webdoor/session/end?game_id=' + encodeURIComponent(WEBDOOR_GAME_ID)",
            $template
        );
    }

    public function testTerminalUsesReturnContractForCleanExitAndEndSession(): void
    {
        $player = file_get_contents(
            dirname(__DIR__, 2)
            . '/public_html/webdoors/dosdoors/index.php'
        );

        self::assertIsString($player);

        self::assertStringContainsString(
            "const returnUrl = <?php echo json_encode(\$returnUrl ?? '/games'); ?>;",
            $player
        );

        self::assertStringContainsString(
            'if (event.code === 1000) {',
            $player
        );

        self::assertSame(
            2,
            substr_count(
                $player,
                'window.top.location.href = returnUrl;'
            )
        );
    }

    public function testNativeDoorWrapperReturnsToExperience(): void
    {
        $routes = file_get_contents(
            dirname(__DIR__, 2) . '/routes/webdoor-routes.php'
        );
        $template = file_get_contents(
            dirname(__DIR__, 2) . '/templates/dosdoor_play.twig'
        );

        self::assertIsString($routes);
        self::assertIsString($template);

        self::assertStringContainsString(
            "'return_url' => '/experiences/' . rawurlencode((string)\$game)",
            $routes
        );

        self::assertStringContainsString(
            'href="{{ return_url|default(\'/games\') }}"',
            $template
        );

        self::assertStringContainsString(
            "window.location.href = {{ return_url|default('/games')|json_encode|raw }};",
            $template
        );
    }


    public function testJsdosWrapperReturnsToExperience(): void
    {
        $routes = file_get_contents(
            dirname(__DIR__, 2) . '/routes/webdoor-routes.php'
        );
        $player = file_get_contents(
            dirname(__DIR__, 2) . '/templates/jsdosdoor_play.twig'
        );

        self::assertIsString($routes);
        self::assertIsString($player);

        self::assertStringContainsString(
            "'return_url' => '/experiences/' . rawurlencode((string)\$game)",
            $routes
        );

        self::assertStringContainsString(
            "window.parent.postMessage({type: 'jsdos-exit'}, window.location.origin);",
            $player
        );

        self::assertStringNotContainsString(
            "window.top.location.href = '/games';",
            $player
        );
    }


    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testWebDoorWrapperReturnsToExperience(): void
    {
        $routes = file_get_contents(
            dirname(__DIR__, 2) . '/routes/webdoor-routes.php'
        );
        $template = file_get_contents(
            dirname(__DIR__, 2) . '/templates/webdoor_play.twig'
        );

        self::assertIsString($routes);
        self::assertIsString($template);

        // Execute the route's actual return/render block, with catalog and output
        // collaborators isolated. Keep canonicalization and PP validation real.
        // This intentionally excludes unrelated launch/session/database setup.
        class_alias(ReturnFixtureCatalog::class, 'BinktermPHP\\GameCatalog');
        class_alias(ReturnFixtureTemplate::class, 'BinktermPHP\\Template');
        $start = strpos($routes, '    $returnExperienceId =');
        self::assertNotFalse($start);
        $end = strpos($routes, "\n});", $start);
        self::assertNotFalse($end);
        $block = substr($routes, $start, $end - $start);
        $render = eval('use BinktermPHP\\Template; return static function ($game, $user) {'
            . '$gameData = []; $gameUrl = "/unused";' . $block . '};');
        try {
            foreach (['tatham-web' => 'tatham', 'breaklock' => 'breaklock', 'ordinary-puzzles' => 'ordinary-puzzles'] as $backend => $canonical) {
                foreach ([null, 'puzlmastrs-patch', 'missing', 'https://evil.invalid', ['puzlmastrs-patch']] as $parent) {
                    $_GET = $parent === null ? [] : ['parent_place_id' => $parent];
                    $render($backend, ['id' => 7]);
                    self::assertSame('webdoor_play.twig', ReturnFixtureTemplate::$name);
                    self::assertSame($backend, ReturnFixtureTemplate::$data['game_id']);
                    self::assertSame($parent === 'puzlmastrs-patch'
                        ? '/places/puzlmastrs-patch' : '/experiences/' . $canonical,
                        ReturnFixtureTemplate::$data['return_url']);
                }
            }
        } finally { $_GET = []; }

        self::assertStringContainsString(
            'href="{{ return_url|default(\'/games\') }}"',
            $template
        );

        self::assertStringNotContainsString(
            'href="/games" class="btn btn-outline-secondary btn-sm me-3"',
            $template
        );
    }

    public function testDoorLaunchResumesBeforeCapacityCheck(): void
    {
        $routes = file_get_contents(
            dirname(__DIR__, 2) . '/routes/door-routes.php'
        );

        self::assertIsString($routes);

        $resume = strpos(
            $routes,
            '$existingSession = $sessionManager->getUserSession($doorContext->userId, $doorName);'
        );

        $capacity = strpos(
            $routes,
            '$activeSessions >= $maxNodes'
        );

        self::assertNotFalse($resume);
        self::assertNotFalse($capacity);

        self::assertLessThan(
            $capacity,
            $resume,
            'Existing participation must be resumed before capacity can reject a launch.'
        );

        self::assertStringContainsString(
            'Resuming existing session:',
            $routes
        );
    }


    public function testManagedDoorResumeRecordsADoorPlayFootprint(): void
    {
        // A resumed managed session is a genuine Experience re-entry and must
        // record a door-play footprint, exactly like a fresh launch. The write
        // must live inside the resume branch (after the resume log line, before
        // the resume response is emitted).
        $routes = file_get_contents(
            dirname(__DIR__, 2) . '/routes/door-routes.php'
        );

        self::assertIsString($routes);

        $resumeLog = strpos($routes, 'Resuming existing session:');
        $resumeRecord = strpos(
            $routes,
            'DoorPlayActivity::record(',
            $resumeLog === false ? 0 : $resumeLog
        );
        $resumeResponse = strpos($routes, "'ui.api.door.session_resumed'");

        self::assertNotFalse($resumeLog);
        self::assertNotFalse($resumeRecord);
        self::assertNotFalse($resumeResponse);

        self::assertLessThan(
            $resumeResponse,
            $resumeRecord,
            'Resume branch must record the door-play footprint before returning the resumed session.'
        );

        self::assertStringContainsString(
            'use BinktermPHP\Crossroads\DoorPlayActivity;',
            $routes
        );
    }

    public function testManagedDoorLaunchAndResumeUseTheDoorPlayContractNotRawTracking(): void
    {
        // Both managed door-play write sites go through the de-duplicating
        // DoorPlayActivity contract; neither writes TYPE_DOSDOOR_PLAY directly
        // via ActivityTracker::track() any more.
        $routes = file_get_contents(
            dirname(__DIR__, 2) . '/routes/door-routes.php'
        );

        self::assertIsString($routes);

        self::assertSame(
            2,
            substr_count($routes, 'DoorPlayActivity::record('),
            'Expected exactly the fresh-launch and resume door-play footprints.'
        );

        self::assertStringNotContainsString(
            'ActivityTracker::track($doorContext->userId, ActivityTracker::TYPE_DOSDOOR_PLAY',
            $routes
        );
    }

    public function testWebDoorAndJsdosPlaySitesUseTheDoorPlayContract(): void
    {
        $routes = file_get_contents(
            dirname(__DIR__, 2) . '/routes/webdoor-routes.php'
        );

        self::assertIsString($routes);

        self::assertSame(
            2,
            substr_count($routes, 'DoorPlayActivity::record('),
            'Expected the JS-DOS session and WebDoor session play footprints.'
        );

        self::assertStringNotContainsString(
            'ActivityTracker::track($userId, ActivityTracker::TYPE_WEBDOOR_PLAY',
            $routes
        );
    }

    public function testDoorPresenceOwnerFollowsAuthenticatedLaunchAndResume(): void
    {
        $routes = file_get_contents(
            dirname(__DIR__, 2) . '/routes/door-routes.php'
        );

        self::assertIsString($routes);

        self::assertStringContainsString(
            '$doorContext->authSessionId',
            $routes
        );

        self::assertStringContainsString(
            '$sessionManager->setAuthSessionId(',
            $routes
        );
    }



    public function testDoorTerminationClearsStoredPresenceOwner(): void
    {
        $manager = file_get_contents(
            dirname(__DIR__, 2) . '/src/DoorSessionManager.php'
        );

        self::assertIsString($manager);

        self::assertStringContainsString(
            "'auth_session_id' => \$session['auth_session_id'] ?? null",
            $manager
        );

        self::assertStringContainsString(
            '(new ExperiencePresence())->leave($authSessionId);',
            $manager
        );
    }



    public function testBridgeClearsPresenceBeforeDeletingDoorSession(): void
    {
        $bridge = file_get_contents(
            dirname(__DIR__, 2)
            . '/scripts/dosbox-bridge/multiplexing-server.js'
        );

        self::assertIsString($bridge);

        self::assertStringContainsString(
            'auth_session_id',
            $bridge
        );

        $clear = strpos(
            $bridge,
            'this.clearExperiencePresence('
        );

        $delete = strpos(
            $bridge,
            'this.deleteSession(session.sessionId, session.slog);'
        );

        self::assertNotFalse($clear);
        self::assertNotFalse($delete);

        self::assertLessThan(
            $delete,
            $clear,
            'Bridge cleanup must clear Experience presence before deleting the door session.'
        );
    }


}

/** Caller-authorized catalog fixture built from the actual grouped manifests. */
final class ReturnFixtureCatalog
{
    public function getEnabledGames(?array $user, string $surface): array
    {
        if ($user !== ['id' => 7] || $surface !== 'web') {
            throw new \RuntimeException('Unexpected return resolver caller/surface');
        }
        $rows = [];
        foreach (['tatham-web', 'tatham-terminal', 'breaklock', 'breaklock-terminal', 'ordinary-puzzles', 'ordinary-puzzles-terminal'] as $id) {
            $native = str_ends_with($id, '-terminal');
            $path = $native ? '/native-doors/doors/' . $id . '/nativedoor.json'
                : '/public_html/webdoors/' . $id . '/webdoor.json';
            $manifest = json_decode(file_get_contents(dirname(__DIR__, 2) . $path), true, 512, JSON_THROW_ON_ERROR);
            $rows[$id] = ['id' => $id, 'name' => $id,
                'backend' => ['type' => $native ? 'native' : 'web', 'id' => $id],
                'surfaces' => ['web' => 'full', 'telnet' => $native ? 'full' : 'unavailable'],
                'policy' => ['enabled' => true], 'grouping' => $manifest['experience'],
                'source' => ['manifest' => $manifest]];
        }
        return \BinktermPHP\ExperienceComposition::compose($rows);
    }
}
final class ReturnFixtureTemplate
{
    public static string $name = '';
    public static array $data = [];
    public function renderResponse(string $name, array $data): void
    {
        self::$name = $name; self::$data = $data;
    }
}
