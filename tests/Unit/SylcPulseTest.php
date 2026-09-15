<?php

declare(strict_types=1);

use BinktermPHP\Messaging\ActivityPlan;
use BinktermPHP\Messaging\SylcPulse;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests — no database. SylcPulse takes already-fetched,
 * already-visibility-filtered data (an ActivityPlan from ActivityService,
 * plus SylcHydrator rows for a small already-selected id subset) and never
 * queries anything itself, so every invisible-content guarantee is inherited
 * from ActivityServiceTest's exhaustive visibility matrix, not re-proven here.
 */
final class SylcPulseTest extends TestCase
{
    private function netmailRow(int $id, string $minutesAgo): array
    {
        return ['id' => $id, 'from_name' => 'Sender ' . $id, 'subject' => 'Subject ' . $id, 'date_received' => $this->ago($minutesAgo)];
    }

    private function replyRow(int $id, string $minutesAgo): array
    {
        return ['id' => $id, 'from_name' => 'Replier ' . $id, 'subject' => 'Re: Subject ' . $id, 'date_received' => $this->ago($minutesAgo)];
    }

    private function threadRow(int $id, string $minutesAgo): array
    {
        return ['id' => $id, 'from_name' => 'Participant ' . $id, 'subject' => 'Thread ' . $id, 'date_received' => $this->ago($minutesAgo)];
    }

    private function area(int $id, string $tag, int $count, bool $isLocal = false): array
    {
        return ['echoareaId' => $id, 'tag' => $tag, 'domain' => '', 'isLocal' => $isLocal, 'sinceBoundaryCount' => $count];
    }

    private function ago(string $minutes): string
    {
        return (new DateTimeImmutable("-{$minutes} minutes"))->format('Y-m-d H:i:s');
    }

    private function plan(?array $personal, ?array $ambient): ActivityPlan
    {
        return new ActivityPlan(true, '2026-01-01 00:00:00', $personal, $ambient, ['netmailUnread' => 0, 'bulletinUnread' => 0]);
    }

    // ----- VISIT / first-visit proof -----

    public function testFirstVisitOmitsTheCardByConstruction(): void
    {
        // ActivityPlan::firstVisit() — the shape ActivityService returns when
        // hasPreviousVisit is false — carries null personal/ambient. The
        // route (routes/web-routes.php) only calls SylcPulse::compose() inside
        // an `if ($activityPlan->hasPreviousVisit)` guard, so this case never
        // reaches compose() at all in production. Prove the guard exists.
        $source = file_get_contents(dirname(__DIR__, 2) . '/routes/web-routes.php');
        self::assertMatchesRegularExpression(
            '/if \(\$activityPlan->hasPreviousVisit\) \{\s*\$hydrator = new SylcHydrator\(\);.*?SylcPulse::compose\(/s',
            $source,
            'SylcPulse::compose() must only be called inside a hasPreviousVisit guard'
        );

        $plan = ActivityPlan::firstVisit(['netmailUnread' => 0, 'bulletinUnread' => 0]);
        self::assertFalse($plan->hasPreviousVisit);
        self::assertNull($plan->personal);
        self::assertNull($plan->ambient);
    }

    public function testTemplateRendersPersonalSectionBeforeAmbientSection(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/templates/partials/dashboard_sylc.twig');
        $personalPos = strpos($source, 'sylc.personal_heading');
        $ambientPos = strpos($source, 'sylc.ambient_heading');
        self::assertNotFalse($personalPos);
        self::assertNotFalse($ambientPos);
        self::assertLessThan($ambientPos, $personalPos, 'Personal section must render before ambient section in the template');
    }

    // ----- STATE / hierarchy -----

    public function testPersonalActivityYieldsActiveStateWithPersonalPopulated(): void
    {
        $plan = $this->plan(
            ['netmailIds' => [1], 'netmailTruncated' => false, 'replyIds' => [], 'repliesTruncated' => false],
            ['areas' => [], 'areasTruncated' => false]
        );
        $result = SylcPulse::compose($plan, [$this->netmailRow(1, '5')], []);
        self::assertSame('active', $result['state']);
        self::assertCount(1, $result['personal']);
        self::assertSame([], $result['ambient']);
    }

    public function testAmbientOnlyActivityYieldsActiveStateWithAmbientPopulated(): void
    {
        $plan = $this->plan(
            ['netmailIds' => [], 'netmailTruncated' => false, 'replyIds' => [], 'repliesTruncated' => false],
            ['areas' => [$this->area(10, 'GENERAL', 3)], 'areasTruncated' => false]
        );
        $result = SylcPulse::compose($plan, [], []);
        self::assertSame('active', $result['state']);
        self::assertSame([], $result['personal']);
        self::assertCount(1, $result['ambient']);
    }

    public function testNoActivityYieldsQuietState(): void
    {
        $plan = $this->plan(
            ['netmailIds' => [], 'netmailTruncated' => false, 'replyIds' => [], 'repliesTruncated' => false],
            ['areas' => [], 'areasTruncated' => false]
        );
        $result = SylcPulse::compose($plan, [], []);
        self::assertSame('quiet', $result['state']);
        self::assertSame([], $result['personal']);
        self::assertSame([], $result['ambient']);
    }

    // ----- CAPS -----

    public function testPersonalRowsCappedAtFiveMostRecent(): void
    {
        // 7 netmail candidates, only the 5 most recent should survive, newest first.
        $rows = [];
        $ids = [];
        for ($i = 1; $i <= 7; $i++) {
            $rows[] = $this->netmailRow($i, (string) (70 - $i * 10)); // id 7 = most recent
            $ids[] = $i;
        }
        $plan = $this->plan(
            ['netmailIds' => $ids, 'netmailTruncated' => false, 'replyIds' => [], 'repliesTruncated' => false],
            null
        );
        $result = SylcPulse::compose($plan, $rows, []);
        self::assertCount(5, $result['personal']);
        self::assertSame([7, 6, 5, 4, 3], array_column($result['personal'], 'id'));
        self::assertTrue($result['personal_more']);
    }

    public function testPersonalMoreFalseWhenExactlyFiveAndNoServiceTruncation(): void
    {
        $rows = [];
        $ids = [];
        for ($i = 1; $i <= 5; $i++) {
            $rows[] = $this->netmailRow($i, (string) $i);
            $ids[] = $i;
        }
        $plan = $this->plan(
            ['netmailIds' => $ids, 'netmailTruncated' => false, 'replyIds' => [], 'repliesTruncated' => false],
            null
        );
        $result = SylcPulse::compose($plan, $rows, []);
        self::assertCount(5, $result['personal']);
        self::assertFalse($result['personal_more']);
    }

    public function testAmbientRowsCappedAtFiveHighestCount(): void
    {
        $areas = [
            $this->area(1, 'AAA', 1),
            $this->area(2, 'BBB', 9),
            $this->area(3, 'CCC', 3),
            $this->area(4, 'DDD', 7),
            $this->area(5, 'EEE', 2),
            $this->area(6, 'FFF', 5),
        ];
        $plan = $this->plan(null, ['areas' => $areas, 'areasTruncated' => false]);
        $result = SylcPulse::compose($plan, [], []);
        self::assertCount(5, $result['ambient']);
        self::assertSame(['BBB', 'DDD', 'FFF', 'CCC', 'EEE'], array_column($result['ambient'], 'tag'));
        self::assertTrue($result['ambient_more']);
    }

    public function testServiceLevelTruncationFlagPropagatesEvenUnderPresentationCap(): void
    {
        // Only 1 item shown, well under the cap of 5, but ActivityService
        // itself hit its 300-item DB cap — "more" must still be signalled.
        $plan = $this->plan(
            ['netmailIds' => [1], 'netmailTruncated' => true, 'replyIds' => [], 'repliesTruncated' => false],
            ['areas' => [$this->area(1, 'A', 1)], 'areasTruncated' => true]
        );
        $result = SylcPulse::compose($plan, [$this->netmailRow(1, '1')], []);
        self::assertTrue($result['personal_more']);
        self::assertTrue($result['ambient_more']);
    }

    // ----- NETMAIL + REPLY combination -----

    public function testNetmailAndRepliesInterleaveByRecency(): void
    {
        $plan = $this->plan(
            ['netmailIds' => [1], 'netmailTruncated' => false, 'replyIds' => [2], 'repliesTruncated' => false],
            null
        );
        $netmail = [$this->netmailRow(1, '30')]; // older
        $reply = [$this->replyRow(2, '5')];       // more recent
        $result = SylcPulse::compose($plan, $netmail, $reply);
        self::assertSame([2, 1], array_column($result['personal'], 'id'));
        self::assertSame(['reply', 'netmail'], array_column($result['personal'], 'type'));
    }

    // ----- PARTICIPATED CONVERSATION ACTIVITY (Phase 1) -----

    public function testDirectReplyOutranksParticipatedActivityUnderCap(): void
    {
        // Messaging Evolution Phase 1 precedence: even though the thread
        // item is more recent, direct personal relevance (netmail/reply)
        // must fill the row budget first — the two pools are never merged
        // into one recency sort.
        $plan = $this->plan(
            ['netmailIds' => [], 'netmailTruncated' => false, 'replyIds' => [1], 'repliesTruncated' => false, 'participatedIds' => [2], 'participatedTruncated' => false],
            null
        );
        $result = SylcPulse::compose($plan, [], [$this->replyRow(1, '30')], [$this->threadRow(2, '1')]);
        self::assertSame(['reply', 'thread'], array_column($result['personal'], 'type'));
        self::assertSame([1, 2], array_column($result['personal'], 'id'));
    }

    public function testParticipatedActivityDistinctTypeFromDirectReply(): void
    {
        $plan = $this->plan(
            ['netmailIds' => [], 'netmailTruncated' => false, 'replyIds' => [], 'repliesTruncated' => false, 'participatedIds' => [2], 'participatedTruncated' => false],
            null
        );
        $result = SylcPulse::compose($plan, [], [], [$this->threadRow(2, '1')]);
        self::assertSame('active', $result['state']);
        self::assertSame(['thread'], array_column($result['personal'], 'type'));
    }

    public function testParticipatedActivityAloneStillYieldsActiveState(): void
    {
        // Participated activity alone (no netmail/reply/ambient) must still
        // be recognized as "something happened", not folded into quiet.
        $plan = $this->plan(
            ['netmailIds' => [], 'netmailTruncated' => false, 'replyIds' => [], 'repliesTruncated' => false, 'participatedIds' => [3], 'participatedTruncated' => false],
            ['areas' => [], 'areasTruncated' => false]
        );
        $result = SylcPulse::compose($plan, [], [], [$this->threadRow(3, '1')]);
        self::assertSame('active', $result['state']);
    }

    public function testParticipatedTruncationFlagPropagates(): void
    {
        $plan = $this->plan(
            ['netmailIds' => [], 'netmailTruncated' => false, 'replyIds' => [], 'repliesTruncated' => false, 'participatedIds' => [2], 'participatedTruncated' => true],
            null
        );
        $result = SylcPulse::compose($plan, [], [], [$this->threadRow(2, '1')]);
        self::assertTrue($result['personal_more']);
    }

    public function testComposeBackwardCompatibleWithoutParticipatedArgument(): void
    {
        // Pre-Phase-1 3-argument call shape must keep working unchanged.
        $plan = $this->plan(
            ['netmailIds' => [1], 'netmailTruncated' => false, 'replyIds' => [], 'repliesTruncated' => false],
            null
        );
        $result = SylcPulse::compose($plan, [$this->netmailRow(1, '1')], []);
        self::assertSame('active', $result['state']);
        self::assertCount(1, $result['personal']);
    }

    // ----- CONTRACT -----

    public function testShouldComposeRespectsHiddenCard(): void
    {
        self::assertTrue(SylcPulse::shouldCompose([]));
        self::assertTrue(SylcPulse::shouldCompose(['hidden' => []]));
        self::assertFalse(SylcPulse::shouldCompose(['hidden' => ['since_last_call']]));
        self::assertTrue(SylcPulse::shouldCompose(['hidden' => ['unread']]));
    }

    public function testComposeIsPureWithNoDatabaseDependency(): void
    {
        // SylcPulse must remain a pure reducer, mirroring DashboardPulse's own
        // discipline — no queries, so calling plan() twice can never mutate
        // visit or read state merely by rendering the dashboard.
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/Messaging/SylcPulse.php');
        self::assertStringNotContainsString('Database::', $source);
        self::assertStringNotContainsString('->execute(', $source);
        self::assertStringNotContainsString('->prepare(', $source);
    }
}
