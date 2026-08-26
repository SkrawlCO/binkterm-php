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
}
