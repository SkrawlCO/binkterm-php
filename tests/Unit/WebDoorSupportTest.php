<?php

declare(strict_types=1);

use BinktermPHP\WebDoorSupport;
use PHPUnit\Framework\TestCase;

final class WebDoorSupportTest extends TestCase
{
    public function testNoRequirementsAreSatisfied(): void
    {
        self::assertTrue(
            WebDoorSupport::requirementsSatisfied([])
        );

        self::assertTrue(
            WebDoorSupport::requirementsSatisfied([
                'requirements' => [],
            ])
        );

        self::assertTrue(
            WebDoorSupport::requirementsSatisfied([
                'requirements' => [
                    'features' => [],
                ],
            ])
        );
    }

    public function testBuiltInStorageAndLeaderboardRequirementsAreSatisfied(): void
    {
        self::assertTrue(
            WebDoorSupport::requirementsSatisfied([
                'requirements' => [
                    'features' => ['storage'],
                ],
            ])
        );

        self::assertTrue(
            WebDoorSupport::requirementsSatisfied([
                'requirements' => [
                    'features' => ['leaderboard'],
                ],
            ])
        );

        self::assertTrue(
            WebDoorSupport::requirementsSatisfied([
                'requirements' => [
                    'features' => ['storage', 'leaderboard'],
                ],
            ])
        );
    }

    public function testUnknownRequirementIsRejected(): void
    {
        self::assertFalse(
            WebDoorSupport::requirementsSatisfied([
                'requirements' => [
                    'features' => ['definitely-not-a-real-feature'],
                ],
            ])
        );
    }

    public function testAvailableFeaturesContainsCoreWebDoorFeatures(): void
    {
        $features = WebDoorSupport::getAvailableFeatures();

        self::assertContains('storage', $features);
        self::assertContains('leaderboard', $features);
    }
}
