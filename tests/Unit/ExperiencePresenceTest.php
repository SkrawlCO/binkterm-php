<?php

declare(strict_types=1);

use BinktermPHP\Auth;
use BinktermPHP\ExperiencePresence;
use PHPUnit\Framework\TestCase;

final class ExperiencePresenceTest extends TestCase
{
    public function testEnterPublishesPlayingActivityUsingExperienceName(): void
    {
        $auth = new RecordingAuth();
        $presence = new ExperiencePresence($auth);

        $presence->enter('session-123', [
            'id' => 'lateania',
            'name' => 'Lateania',
        ]);

        self::assertSame([
            ['session-123', 'Playing Lateania'],
        ], $auth->updates);
    }

    public function testEnterFallsBackToExperienceId(): void
    {
        $auth = new RecordingAuth();
        $presence = new ExperiencePresence($auth);

        $presence->enter('session-123', [
            'id' => 'usurper',
        ]);

        self::assertSame([
            ['session-123', 'Playing usurper'],
        ], $auth->updates);
    }

    public function testEnterDoesNothingWithoutSessionId(): void
    {
        $auth = new RecordingAuth();
        $presence = new ExperiencePresence($auth);

        $presence->enter('', [
            'id' => 'lateania',
            'name' => 'Lateania',
        ]);

        self::assertSame([], $auth->updates);
    }

    public function testEnterDoesNothingWithoutExperienceIdentity(): void
    {
        $auth = new RecordingAuth();
        $presence = new ExperiencePresence($auth);

        $presence->enter('session-123', []);

        self::assertSame([], $auth->updates);
    }

    public function testLeaveReturnsSessionToGenericBbsActivity(): void
    {
        $auth = new RecordingAuth();
        $presence = new ExperiencePresence($auth);

        $presence->leave('session-123');

        self::assertSame([
            ['session-123', 'BBS'],
        ], $auth->updates);
    }

    public function testLeaveDoesNothingWithoutSessionId(): void
    {
        $auth = new RecordingAuth();
        $presence = new ExperiencePresence($auth);

        $presence->leave('');

        self::assertSame([], $auth->updates);
    }
}

final class RecordingAuth extends Auth
{
    /** @var list<array{0:string,1:string}> */
    public array $updates = [];

    public function __construct()
    {
        // Deliberately do not initialize the database-backed Auth parent.
    }

    public function updateSessionActivity(string $sessionId, string $activity): void
    {
        $this->updates[] = [$sessionId, $activity];
    }
}
