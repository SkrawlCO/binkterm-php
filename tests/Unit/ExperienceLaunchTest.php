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
            'id' => 'doorparty',
            'backend' => [
                'type' => 'dos',
                'id' => 'doorparty',
            ],
        ]);

        self::assertSame([
            'type' => 'dos',
            'id' => 'doorparty',
            'url' => '/games/dosdoors/doorparty',
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
