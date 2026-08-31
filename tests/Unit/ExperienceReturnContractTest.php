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

        self::assertStringContainsString(
            "'return_url' => '/experiences/' . rawurlencode((string)\$game)",
            $routes
        );

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
