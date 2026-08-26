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
}
