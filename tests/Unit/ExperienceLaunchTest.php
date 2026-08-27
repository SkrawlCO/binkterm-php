<?php

namespace BinktermPHP\Tests\Unit;

use BinktermPHP\ExperienceLaunch;
use PHPUnit\Framework\TestCase;

final class ExperienceLaunchTest extends TestCase
{
    public function testResolvesNativeExperience(): void
    {
        $result = ExperienceLaunch::resolve([
            'id' => 'usurper',
            'backend' => [
                'type' => 'native',
                'id' => 'usurper',
            ],
        ]);

        self::assertSame([
            'type' => 'native',
            'id' => 'usurper',
            'url' => '/games/nativedoors/usurper?experience=1',
        ], $result);
    }

    public function testResolvesDosExperience(): void
    {
        $result = ExperienceLaunch::resolve([
            'id' => 'example-dos',
            'backend' => [
                'type' => 'dos',
                'id' => 'example-dos',
            ],
        ]);

        self::assertSame([
            'type' => 'dos',
            'id' => 'example-dos',
            'url' => '/games/dosdoors/example-dos',
        ], $result);
    }

    public function testResolvesJsdosExperience(): void
    {
        $result = ExperienceLaunch::resolve([
            'id' => 'example-jsdos',
            'backend' => [
                'type' => 'jsdos',
                'id' => 'example-jsdos',
            ],
        ]);

        self::assertSame([
            'type' => 'jsdos',
            'id' => 'example-jsdos',
            'url' => '/games/jsdos/example-jsdos',
        ], $result);
    }

    public function testResolvesWebExperience(): void
    {
        $result = ExperienceLaunch::resolve([
            'id' => 'wordle',
            'backend' => [
                'type' => 'web',
                'id' => 'wordle',
            ],
        ]);

        self::assertSame([
            'type' => 'web',
            'id' => 'wordle',
            'url' => '/games/wordle',
        ], $result);
    }

    public function testCanLaunchSupportedBackends(): void
    {
        foreach ([
            [
                'backend' => [
                    'type' => 'native',
                    'id' => 'usurper',
                ],
            ],
            [
                'backend' => [
                    'type' => 'dos',
                    'id' => 'example-dos',
                ],
            ],
            [
                'backend' => [
                    'type' => 'jsdos',
                    'id' => 'example-jsdos',
                ],
            ],
            [
                'backend' => [
                    'type' => 'web',
                    'id' => 'blackjack',
                ],
            ],
        ] as $experience) {
            self::assertTrue(ExperienceLaunch::canLaunch($experience));
        }
    }

    public function testPlannedSurfaceIsDiscoverableButNotLaunchable(): void
    {
        $experience = [
            'backend' => [
                'type' => 'web',
                'id' => 'planned-example',
            ],
            'surfaces' => [
                'web' => 'full',
                'telnet' => 'planned',
            ],
            'policy' => [
                'enabled' => true,
            ],
        ];

        self::assertTrue(ExperienceLaunch::canLaunch($experience, 'web'));
        self::assertFalse(ExperienceLaunch::canLaunch($experience, 'telnet'));
        self::assertFalse(ExperienceLaunch::canLaunch($experience, 'terminal'));
        self::assertNull(ExperienceLaunch::resolve($experience, 'telnet'));
    }

    public function testFullTelnetSurfaceResolvesForTelnetAndTerminalAlias(): void
    {
        $experience = [
            'backend' => [
                'type' => 'native',
                'id' => 'full-native',
            ],
            'surfaces' => [
                'web' => 'unavailable',
                'telnet' => 'full',
            ],
            'policy' => [
                'enabled' => true,
            ],
        ];

        self::assertSame(
            ExperienceLaunch::resolve($experience, 'telnet'),
            ExperienceLaunch::resolve($experience, 'terminal')
        );
        self::assertNotNull(ExperienceLaunch::resolve($experience, 'telnet'));
        self::assertNull(ExperienceLaunch::resolve($experience, 'web'));
    }

    public function testPlannedWebSurfaceDoesNotResolveLaunchTarget(): void
    {
        $experience = [
            'backend' => [
                'type' => 'native',
                'id' => 'terminal-only',
            ],
            'surfaces' => [
                'web' => 'planned',
                'telnet' => 'full',
            ],
            'policy' => [
                'enabled' => true,
            ],
        ];

        self::assertFalse(ExperienceLaunch::canLaunch($experience, 'web'));
        self::assertNull(ExperienceLaunch::resolve($experience, 'web'));
        self::assertTrue(ExperienceLaunch::canLaunch($experience, 'telnet'));
    }

    public function testFullSurfaceDoesNotBypassDisabledPolicy(): void
    {
        $experience = [
            'backend' => [
                'type' => 'native',
                'id' => 'disabled-native',
            ],
            'surfaces' => [
                'web' => 'full',
                'telnet' => 'full',
            ],
            'policy' => [
                'enabled' => false,
            ],
        ];

        self::assertFalse(ExperienceLaunch::canLaunch($experience, 'web'));
        self::assertFalse(ExperienceLaunch::canLaunch($experience, 'telnet'));
        self::assertNull(ExperienceLaunch::resolve($experience, 'web'));
    }

    public function testUnknownSurfaceDoesNotResolveDeclaredExperience(): void
    {
        self::assertNull(ExperienceLaunch::resolve([
            'backend' => [
                'type' => 'web',
                'id' => 'known-surfaces-only',
            ],
            'surfaces' => [
                'web' => 'full',
                'telnet' => 'planned',
            ],
            'policy' => [
                'enabled' => true,
            ],
        ], 'unknown'));
    }

    public function testNullSurfaceCannotBypassNormalizedSurfaceState(): void
    {
        self::assertNull(ExperienceLaunch::resolve([
            'backend' => [
                'type' => 'web',
                'id' => 'planned-without-context',
            ],
            'surfaces' => [
                'web' => 'full',
                'telnet' => 'planned',
            ],
            'policy' => [
                'enabled' => true,
            ],
        ]));
    }

    public function testLegacyFixtureWithoutSurfacesRetainsCompatibility(): void
    {
        self::assertNotNull(ExperienceLaunch::resolve([
            'backend' => [
                'type' => 'dos',
                'id' => 'legacy-fixture',
            ],
        ]));
    }

    public function testCannotLaunchUnsupportedOrIncompleteExperience(): void
    {
        self::assertFalse(ExperienceLaunch::canLaunch([
            'id' => 'broken',
        ]));

        self::assertFalse(ExperienceLaunch::canLaunch([
            'backend' => [
                'type' => 'native',
            ],
        ]));

        self::assertFalse(ExperienceLaunch::canLaunch([
            'backend' => [
                'type' => 'future',
                'id' => 'future-game',
            ],
        ]));
    }

    public function testRejectsMissingBackend(): void
    {
        self::assertNull(ExperienceLaunch::resolve([
            'id' => 'broken',
        ]));
    }

    public function testRejectsIncompleteBackend(): void
    {
        self::assertNull(ExperienceLaunch::resolve([
            'id' => 'broken',
            'backend' => [
                'type' => 'native',
            ],
        ]));

        self::assertNull(ExperienceLaunch::resolve([
            'id' => 'broken',
            'backend' => [
                'id' => 'native',
            ],
        ]));
    }

    public function testRejectsUnsupportedBackend(): void
    {
        self::assertNull(ExperienceLaunch::resolve([
            'id' => 'future-game',
            'backend' => [
                'type' => 'future',
                'id' => 'future-game',
            ],
        ]));
    }

    public function testTrimsBackendIdentifiers(): void
    {
        $result = ExperienceLaunch::resolve([
            'id' => 'usurper',
            'backend' => [
                'type' => ' native ',
                'id' => ' usurper ',
            ],
        ]);

        self::assertSame([
            'type' => 'native',
            'id' => 'usurper',
            'url' => '/games/nativedoors/usurper?experience=1',
        ], $result);
    }
}
