<?php

namespace BinktermPHP\Tests\Unit;

use BinktermPHP\DoorContext;
use PHPUnit\Framework\TestCase;

class DoorContextTest extends TestCase
{
    public function testContextExposesCanonicalIdentityAndDoorInformation(): void
    {
        $context = new DoorContext(
            userId: 3,
            username: 'Skrawl',
            displayName: 'Skrawl',
            doorId: 'usurper',
            surface: 'web',
            authSessionId: 'session-123'
        );

        self::assertSame(3, $context->userId);
        self::assertSame('Skrawl', $context->username);
        self::assertSame('Skrawl', $context->displayName);
        self::assertSame('usurper', $context->doorId);
        self::assertSame('web', $context->surface);
        self::assertSame('session-123', $context->authSessionId);
    }

    public function testContextCanBeCreatedFromAuthenticatedUser(): void
    {
        $context = DoorContext::fromUser(
            [
                'user_id' => 3,
                'username' => 'Skrawl',
                'real_name' => 'Matthew',
            ],
            'usurper',
            'web',
            'session-456'
        );

        self::assertSame(3, $context->userId);
        self::assertSame('Skrawl', $context->username);
        self::assertSame('Matthew', $context->displayName);
        self::assertSame('usurper', $context->doorId);
        self::assertSame('web', $context->surface);
        self::assertSame('session-456', $context->authSessionId);
    }

    public function testAuthSessionMayBeAbsentForNonWebSurfaces(): void
    {
        $context = new DoorContext(
            userId: 7,
            username: 'Bard',
            displayName: 'Bard',
            doorId: 'usurper',
            surface: 'telnet'
        );

        self::assertSame(7, $context->userId);
        self::assertSame('Bard', $context->username);
        self::assertSame('Bard', $context->displayName);
        self::assertSame('usurper', $context->doorId);
        self::assertSame('telnet', $context->surface);
        self::assertNull($context->authSessionId);
    }




    public function testDoorLaunchPresenceReceivesCanonicalContext(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../routes/door-routes.php'
        );

        self::assertIsString($source);

        self::assertStringContainsString(
            'publishDoorExperiencePresence($doorContext, $user);',
            $source
        );

        self::assertSame(
            2,
            substr_count(
                $source,
                'publishDoorExperiencePresence($doorContext, $user);'
            )
        );

        $helperStart = strpos(
            $source,
            'function publishDoorExperiencePresence'
        );

        $helperEnd = strpos(
            $source,
            'function doorApiError',
            $helperStart
        );

        self::assertNotFalse($helperStart);
        self::assertNotFalse($helperEnd);

        $helper = substr(
            $source,
            $helperStart,
            $helperEnd - $helperStart
        );

        self::assertStringNotContainsString(
            '$_COOKIE[\'binktermphp_session\']',
            $helper
        );
    }

    public function testAuthenticatedLaunchUsesDoorContextIdentity(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../routes/door-routes.php'
        );

        self::assertIsString($source);

        self::assertStringContainsString(
            '$sessionManager->getUserSession($doorContext->userId, $doorName)',
            $source
        );

        self::assertStringContainsString(
            '$sessionManager->startSession($doorContext->userId, $doorName, $userData, $doorType)',
            $source
        );

        self::assertStringContainsString(
            'ActivityTracker::track($doorContext->userId,',
            $source
        );
    }

    public function testTerminalLaunchRequestsTerminalSurface(): void
    {
        $handler = file_get_contents(
            __DIR__ . '/../../telnet/src/DoorHandler.php'
        );

        $routes = file_get_contents(
            __DIR__ . '/../../routes/door-routes.php'
        );

        self::assertIsString($handler);
        self::assertIsString($routes);

        self::assertStringContainsString(
            "'door' => \$doorId",
            $handler
        );

        self::assertStringContainsString(
            "'surface' => 'terminal'",
            $handler
        );

        self::assertStringContainsString(
            "(\$_POST['surface'] ?? 'web')",
            $routes
        );

        self::assertStringContainsString(
            "'terminal'",
            $routes
        );
    }

    public function testAuthenticatedWebLaunchContextUsesWebSurface(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../routes/door-routes.php'
        );

        self::assertIsString($source);

        self::assertStringContainsString(
            'DoorContext::fromUser(',
            $source
        );

        self::assertStringContainsString(
            "'web'",
            $source
        );
    }

    public function testContextPropertiesAreReadOnly(): void
    {
        $reflection = new \ReflectionClass(DoorContext::class);

        foreach ([
            'userId',
            'username',
            'displayName',
            'doorId',
            'surface',
            'authSessionId',
        ] as $property) {
            self::assertTrue(
                $reflection->getProperty($property)->isReadOnly(),
                $property . ' must be readonly'
            );
        }
    }
}
