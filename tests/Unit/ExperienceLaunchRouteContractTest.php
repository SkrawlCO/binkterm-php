<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ExperienceLaunchRouteContractTest extends TestCase
{
    private string $doorRoutes;
    private string $webdoorRoutes;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        $this->doorRoutes = file_get_contents($root . '/routes/door-routes.php');
        $this->webdoorRoutes = file_get_contents($root . '/routes/webdoor-routes.php');

        self::assertIsString($this->doorRoutes);
        self::assertIsString($this->webdoorRoutes);
    }

    public function testSurfaceEligibilityPrecedesExistingSessionResume(): void
    {
        $catalogGate = strpos(
            $this->doorRoutes,
            '$experiences = $catalog->getEnabledGames($user, $catalogSurface);'
        );
        $launchGate = strpos(
            $this->doorRoutes,
            'ExperienceLaunch::canLaunch($launchExperience, $surface)'
        );
        $resume = strpos(
            $this->doorRoutes,
            '$existingSession = $sessionManager->getUserSession('
        );

        self::assertNotFalse($catalogGate);
        self::assertNotFalse($launchGate);
        self::assertNotFalse($resume);
        self::assertLessThan($resume, $catalogGate);
        self::assertLessThan($resume, $launchGate);
    }

    public function testDoorLaunchApiFailsClosedForUnknownOrUnsupportedSurface(): void
    {
        self::assertStringContainsString(
            "if (!in_array(\$surface, ['web', 'telnet', 'terminal'], true))",
            $this->doorRoutes
        );
        self::assertStringContainsString(
            "\$catalogSurface = \$surface === 'terminal' ? 'telnet' : \$surface;",
            $this->doorRoutes
        );
        self::assertStringContainsString(
            "!in_array(\$launchBackend, ['dos', 'native'], true)",
            $this->doorRoutes
        );
        self::assertStringNotContainsString(
            "\$surface = 'web';",
            $this->doorRoutes
        );
    }

    public function testDisabledWebDoorCannotLaunchThroughLegacyWrapper(): void
    {
        $enabledGate = strpos(
            $this->webdoorRoutes,
            'if (!GameConfig::isEnabled((string)$game))'
        );
        $webDoorPath = strpos(
            $this->webdoorRoutes,
            "\$gameDir = __DIR__ . '/../public_html/webdoors/'"
        );

        self::assertNotFalse($enabledGate);
        self::assertNotFalse($webDoorPath);
        self::assertLessThan($webDoorPath, $enabledGate);
    }
}
