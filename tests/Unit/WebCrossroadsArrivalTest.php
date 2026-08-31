<?php

declare(strict_types=1);

namespace BinktermPHP\Tests\Unit;

use BinktermPHP\ExperienceParticipation;
use PHPUnit\Framework\TestCase;

/**
 * Membership semantics for the authenticated web Crossroads arrival.
 *
 * The `/games` route classifies every authorized Experience into two optional
 * contextual sections using the shared, transport-agnostic predicates exercised
 * here. The complete authorized catalog always renders under "Experiences"
 * regardless of contextual-section membership.
 *
 *   Live Now    -> ExperienceParticipation::hasDistinctOtherPlayer()
 *   Your Places -> ExperienceParticipation::findViewerPlayer()
 *
 * Live Now and Your Places overlap intentionally: an Experience the viewer
 * shares with another distinct caller belongs in both.
 */
final class WebCrossroadsArrivalTest extends TestCase
{
    private const VIEWER_ID = 10;

    /**
     * Mirror of the route's classification loop, expressed purely through the
     * shared predicates it uses.
     *
     * @param array<string,array<string,mixed>> $experienceStates
     * @return array{live:list<string>,places:list<string>,experiences:list<string>}
     */
    private function classify(array $experienceStates, int $viewerId): array
    {
        $live = [];
        $places = [];
        $experiences = [];

        foreach ($experienceStates as $id => $state) {
            // "Experiences" is the complete authorized catalog — every entry,
            // always, independent of contextual-section membership.
            $experiences[] = (string)$id;

            if (ExperienceParticipation::findViewerPlayer($state, $viewerId) !== null) {
                $places[] = (string)$id;
            }

            if (ExperienceParticipation::hasDistinctOtherPlayer($state, $viewerId)) {
                $live[] = (string)$id;
            }
        }

        return ['live' => $live, 'places' => $places, 'experiences' => $experiences];
    }

    /**
     * @param list<array{int,string}> $players [user_id, session_id] pairs
     * @return array<string,mixed>
     */
    private function state(string $id, array $players): array
    {
        $rows = [];
        foreach ($players as [$userId, $sessionId]) {
            $rows[] = ['user_id' => $userId, 'username' => 'u' . $userId, 'session_id' => $sessionId];
        }

        return [
            'experience' => ['id' => $id],
            'active' => $rows !== [],
            'session_count' => count($rows),
            'player_count' => count(array_unique(array_column($rows, 'user_id'))),
            'players' => $rows,
        ];
    }

    public function testOtherOnlyExperienceIsLiveNowOnly(): void
    {
        $result = $this->classify(
            ['green-dragon' => $this->state('green-dragon', [[11, 'a1'], [12, 'a2']])],
            self::VIEWER_ID
        );

        self::assertSame(['green-dragon'], $result['live']);
        self::assertSame([], $result['places']);
    }

    public function testViewerOnlyExperienceIsYourPlacesOnly(): void
    {
        $result = $this->classify(
            ['lateania' => $this->state('lateania', [[self::VIEWER_ID, 'mine']])],
            self::VIEWER_ID
        );

        self::assertSame([], $result['live']);
        self::assertSame(['lateania'], $result['places']);
    }

    public function testViewerPlusAnotherCallerIsBothSections(): void
    {
        $result = $this->classify(
            ['trade-wars' => $this->state('trade-wars', [[self::VIEWER_ID, 'mine'], [11, 'theirs']])],
            self::VIEWER_ID
        );

        self::assertSame(['trade-wars'], $result['live']);
        self::assertSame(['trade-wars'], $result['places']);
    }

    public function testUnoccupiedExperienceIsInNeitherContextualSection(): void
    {
        $result = $this->classify(
            ['usurper' => $this->state('usurper', [])],
            self::VIEWER_ID
        );

        self::assertSame([], $result['live']);
        self::assertSame([], $result['places']);
    }

    public function testViewersOwnSecondSessionDoesNotMakeAnExperienceLiveNow(): void
    {
        $result = $this->classify(
            ['barren' => $this->state('barren', [[self::VIEWER_ID, 'node1'], [self::VIEWER_ID, 'node2']])],
            self::VIEWER_ID
        );

        self::assertSame([], $result['live']);
        self::assertSame(['barren'], $result['places']);
    }

    public function testEveryAuthorizedExperienceRemainsInTheExperiencesCatalog(): void
    {
        $states = [
            'other-only' => $this->state('other-only', [[11, 'a']]),
            'viewer-only' => $this->state('viewer-only', [[self::VIEWER_ID, 'b']]),
            'together' => $this->state('together', [[self::VIEWER_ID, 'c'], [12, 'd']]),
            'empty' => $this->state('empty', []),
        ];

        $result = $this->classify($states, self::VIEWER_ID);

        self::assertSame(
            ['other-only', 'viewer-only', 'together', 'empty'],
            $result['experiences'],
            'the Experiences catalog is the complete authorized set, in catalog order'
        );
        self::assertSame(['other-only', 'together'], $result['live']);
        self::assertSame(['viewer-only', 'together'], $result['places']);
    }
}
