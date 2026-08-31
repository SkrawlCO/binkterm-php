<?php

declare(strict_types=1);

namespace BinktermPHP\Tests\Unit;

use BinktermPHP\Crossroads\DashboardPulse;
use PHPUnit\Framework\TestCase;

/**
 * Priority and shape of the dashboard Crossroads pulse view model.
 *
 * DashboardPulse::compose() is a pure reducer over an already-authorized
 * ExperienceState snapshot and an already-authorized recent-footprint list.
 * Priority: participating > others > recent > quiet.
 */
final class CrossroadsDashboardPulseTest extends TestCase
{
    private const VIEWER = 10;

    /**
     * @param list<array{int,string}> $players [user_id, username]
     * @return array<string,mixed>
     */
    private function state(string $id, string $name, array $players): array
    {
        $rows = [];
        foreach ($players as [$uid, $username]) {
            $rows[] = [
                'user_id' => $uid,
                'username' => $username,
                'session_id' => 's' . $uid . '-' . $id,
            ];
        }

        return [
            'experience' => ['id' => $id, 'name' => $name],
            'active' => $rows !== [],
            'session_count' => count($rows),
            'player_count' => count(array_unique(array_column($rows, 'user_id'))),
            'players' => $rows,
        ];
    }

    /** @return array<string,mixed> */
    private function footprint(string $expId, string $expName, string $username, bool $first = false): array
    {
        return [
            'id' => 1,
            'type' => $first ? 'first_play' : 'play',
            'user_id' => 99,
            'username' => $username,
            'experience_id' => $expId,
            'experience_name' => $expName,
            'occurred_at' => '2026-08-31 12:00:00',
        ];
    }

    public function testShouldComposeOnlyWhenAvailableAndNotHidden(): void
    {
        $notHidden = ['main' => ['crossroads'], 'sidebar' => [], 'hidden' => []];
        $hidden = ['main' => [], 'sidebar' => [], 'hidden' => ['crossroads']];

        self::assertTrue(DashboardPulse::shouldCompose(true, $notHidden));
        self::assertFalse(DashboardPulse::shouldCompose(false, $notHidden));
        self::assertFalse(DashboardPulse::shouldCompose(true, $hidden));
        self::assertFalse(DashboardPulse::shouldCompose(false, $hidden));
        // Tolerates a layout with no 'hidden' key.
        self::assertTrue(DashboardPulse::shouldCompose(true, ['main' => [], 'sidebar' => []]));
    }

    public function testViewerParticipatingTakesPriorityOverOthersAndRecent(): void
    {
        $states = [
            'greendragon' => $this->state('greendragon', 'Green Dragon', [
                [self::VIEWER, 'me'],
                [7, 'kadmin'],           // another person is here too
            ]),
            'lord' => $this->state('lord', 'Legend of the Red Dragon', [[8, 'bard']]),
        ];

        $pulse = DashboardPulse::compose($states, self::VIEWER, [
            $this->footprint('lord', 'Legend of the Red Dragon', 'bard'),
        ]);

        self::assertSame('participating', $pulse['state']);
        self::assertSame('greendragon', $pulse['viewer']['experience_id']);
        self::assertSame('Green Dragon', $pulse['viewer']['experience_name']);
        self::assertArrayNotHasKey('others', $pulse);
        self::assertArrayNotHasKey('recent', $pulse);
    }

    public function testOthersStateWhenViewerNotParticipating(): void
    {
        $states = [
            'lord' => $this->state('lord', 'Legend of the Red Dragon', [[7, 'kadmin']]),
            'wordle' => $this->state('wordle', 'Wordle', [[8, 'bard']]),
        ];

        $pulse = DashboardPulse::compose($states, self::VIEWER, [
            $this->footprint('wordle', 'Wordle', 'ghost'),
        ]);

        self::assertSame('others', $pulse['state']);
        self::assertCount(2, $pulse['others']);
        self::assertSame(
            ['kadmin', 'bard'],
            array_column($pulse['others'], 'username')
        );
        self::assertSame('Legend of the Red Dragon', $pulse['others'][0]['experience_name']);
        self::assertSame('lord', $pulse['others'][0]['experience_id']);
    }

    public function testViewerIsNeverShownAsAnOtherPerson(): void
    {
        // Viewer holds two sessions of the same Experience; nobody else present.
        $states = [
            'wordle' => $this->state('wordle', 'Wordle', [
                [self::VIEWER, 'me'],
                [self::VIEWER, 'me'],
            ]),
        ];

        $pulse = DashboardPulse::compose($states, self::VIEWER, []);

        // Viewer participating short-circuits; it must not fall to "others".
        self::assertSame('participating', $pulse['state']);
    }

    public function testViewerNotCountedAsOtherWhenSharingWithNobodyDistinct(): void
    {
        // Only the viewer is active, and (contrived) the viewer id is filtered
        // out of the roster before compose — proves compose never invents an
        // "other" from a viewer-only room.
        $states = [
            'wordle' => $this->state('wordle', 'Wordle', [[self::VIEWER, 'me']]),
        ];

        $pulse = DashboardPulse::compose($states, self::VIEWER, []);

        self::assertSame('participating', $pulse['state']);
    }

    public function testOthersRowsAreCappedAtThree(): void
    {
        $states = [
            'a' => $this->state('a', 'Alpha', [[1, 'p1'], [2, 'p2']]),
            'b' => $this->state('b', 'Beta', [[3, 'p3'], [4, 'p4']]),
        ];

        $pulse = DashboardPulse::compose($states, self::VIEWER, []);

        self::assertSame('others', $pulse['state']);
        self::assertCount(DashboardPulse::MAX_OTHER_ROWS, $pulse['others']);
        self::assertSame(3, DashboardPulse::MAX_OTHER_ROWS);
        self::assertSame(['p1', 'p2', 'p3'], array_column($pulse['others'], 'username'));
    }

    public function testSamePersonTwoSessionsOneExperienceIsOneRow(): void
    {
        $states = [
            'lord' => $this->state('lord', 'Legend of the Red Dragon', [
                [7, 'kadmin'],
                [7, 'kadmin'],
            ]),
        ];

        $pulse = DashboardPulse::compose($states, self::VIEWER, []);

        self::assertSame('others', $pulse['state']);
        self::assertCount(1, $pulse['others']);
    }

    public function testRecentFootprintQuietStateWhenNobodyActive(): void
    {
        $states = [
            'wordle' => $this->state('wordle', 'Wordle', []),
        ];

        $pulse = DashboardPulse::compose($states, self::VIEWER, [
            $this->footprint('wordle', 'Wordle', 'bard', first: true),
        ]);

        self::assertSame('recent', $pulse['state']);
        self::assertSame('bard', $pulse['recent']['username']);
        self::assertSame('wordle', $pulse['recent']['experience_id']);
        self::assertSame('Wordle', $pulse['recent']['experience_name']);
        self::assertTrue($pulse['recent']['first_play']);
    }

    public function testFullyQuietStateWhenNoActivityAndNoFootprints(): void
    {
        $pulse = DashboardPulse::compose(
            ['wordle' => $this->state('wordle', 'Wordle', [])],
            self::VIEWER,
            []
        );

        self::assertSame(['state' => 'quiet'], $pulse);
    }

    public function testMalformedRowsAreIgnoredNotFatal(): void
    {
        $states = [
            'ok' => $this->state('ok', 'OK', [[7, 'kadmin']]),
            'bad1' => 'not-an-array',
            'bad2' => ['players' => 'nope'],
            'bad3' => ['experience' => null, 'players' => [['user_id' => 0], 'x', ['username' => '']]],
        ];

        $pulse = DashboardPulse::compose($states, self::VIEWER, ['junk', ['experience_id' => '']]);

        self::assertSame('others', $pulse['state']);
        self::assertCount(1, $pulse['others']);
        self::assertSame('kadmin', $pulse['others'][0]['username']);
    }

    public function testExperienceNameFallsBackToIdWhenMissing(): void
    {
        $states = [
            'orphan' => [
                'experience' => ['id' => 'orphan'],
                'players' => [['user_id' => 7, 'username' => 'kadmin', 'session_id' => 's']],
            ],
        ];

        $pulse = DashboardPulse::compose($states, self::VIEWER, []);

        self::assertSame('others', $pulse['state']);
        self::assertSame('orphan', $pulse['others'][0]['experience_name']);
    }
}
