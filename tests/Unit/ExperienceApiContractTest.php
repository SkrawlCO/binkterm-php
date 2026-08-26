<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ExperienceApiContractTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $this->source = file_get_contents(
            dirname(__DIR__, 2) . '/routes/api-routes.php'
        );

        self::assertIsString($this->source);
    }

    public function testExperienceApiPublishesCanonicalPlayerIdentity(): void
    {
        self::assertStringContainsString(
            "'user_id' => (int)\$player['user_id']",
            $this->source
        );

        self::assertStringContainsString(
            "'username' => (string)\$player['username']",
            $this->source
        );
    }

    public function testExperienceApiPublishesNormalizedParticipantContract(): void
    {
        self::assertStringContainsString(
            "'presence_state' => \$player['presence_state']",
            $this->source
        );

        self::assertStringContainsString(
            "'participant_actions' =>",
            $this->source
        );

        self::assertStringContainsString(
            "'profile' =>",
            $this->source
        );

        self::assertStringContainsString(
            "'message' =>",
            $this->source
        );
    }

    public function testExperienceApiPublishesViewerParticipationContract(): void
    {
        self::assertStringContainsString(
            "'viewer' => [",
            $this->source
        );

        self::assertStringContainsString(
            "'participating' => \$viewerPlayer !== null",
            $this->source
        );

        self::assertStringContainsString(
            "'session_id' => \$viewerPlayer['session_id'] ?? null",
            $this->source
        );

        self::assertStringContainsString(
            "'node' => \$viewerPlayer !== null && \$viewerPlayer['node'] !== null",
            $this->source
        );
    }

    public function testExperienceApiPreservesNullNode(): void
    {
        self::assertStringContainsString(
            "\$player['node'] !== null",
            $this->source
        );

        self::assertStringContainsString(
            "? (int)\$player['node']",
            $this->source
        );

        self::assertStringNotContainsString(
            "'node' => (int)\$player['node'],",
            $this->source
        );
    }

    public function testExperienceApiPublishesNormalizedViewerActions(): void
    {
        self::assertStringContainsString(
            '\\BinktermPHP\\ExperienceParticipation::viewerActions(',
            $this->source
        );

        self::assertStringContainsString(
            "'actions' => \$viewerActions",
            $this->source
        );
    }

    public function testExperienceApiDefinesNormalizedEndParticipationEndpoint(): void
    {
        self::assertStringContainsString(
            "SimpleRouter::post('/experiences/{experienceId}/end'",
            $this->source
        );

        self::assertStringContainsString(
            'new \\BinktermPHP\\ExperienceParticipation()',
            $this->source
        );

        self::assertStringContainsString(
            '$participation->end($experience, $user, $viewerPlayer)',
            $this->source
        );
    }

}
