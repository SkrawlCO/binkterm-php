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
            'url' => '/games/nativedoors/usurper',
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
            'url' => '/games/nativedoors/usurper',
        ], $result);
    }
}
