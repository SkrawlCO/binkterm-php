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
