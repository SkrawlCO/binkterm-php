<?php

declare(strict_types=1);

use BinktermPHP\Messaging\ActivityPlan;
use BinktermPHP\Messaging\TelnetSylcPresenter;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests — no database. Mirrors SylcPulseTest's discipline:
 * TelnetSylcPresenter takes an already-fetched, already-visibility-filtered
 * ActivityPlan (plus already-hydrated rows) and never queries anything.
 */
final class TelnetSylcPresenterTest extends TestCase
{
    private function t(): callable
    {
        return fn (string $key, string $fallback, array $params = []): string =>
            strtr($fallback, array_map(static fn ($v) => (string) $v, array_combine(
                array_map(static fn ($k) => '{' . $k . '}', array_keys($params)),
                array_values($params)
            )));
    }

    private function plan(?array $personal, ?array $ambient, bool $hasPreviousVisit = true): ActivityPlan
    {
        return new ActivityPlan($hasPreviousVisit, $hasPreviousVisit ? '2026-01-01 00:00:00' : null, $personal, $ambient, ['netmailUnread' => 0, 'bulletinUnread' => 0]);
    }

    private function netmailRow(int $id, string $minutesAgo, string $subject = 'Subject'): array
    {
        return ['id' => $id, 'from_name' => 'Sender ' . $id, 'subject' => $subject, 'date_received' => $this->ago($minutesAgo)];
    }

    private function ago(string $minutes): string
    {
        return (new DateTimeImmutable("-{$minutes} minutes"))->format('Y-m-d H:i:s');
    }

    private function area(int $id, string $tag, int $count): array
    {
        return ['echoareaId' => $id, 'tag' => $tag, 'domain' => '', 'isLocal' => false, 'sinceBoundaryCount' => $count];
    }

    // ----- sidebarLine() -----

    public function testSidebarLineNullOnFirstVisit(): void
    {
        $plan = $this->plan(null, null, false);
        self::assertNull(TelnetSylcPresenter::sidebarLine($plan, $this->t()));
    }

    public function testSidebarLineNullWhenQuiet(): void
    {
        $plan = $this->plan(
            ['netmailIds' => [], 'netmailTruncated' => false, 'replyIds' => [], 'repliesTruncated' => false],
            ['areas' => [], 'areasTruncated' => false]
        );
        self::assertNull(TelnetSylcPresenter::sidebarLine($plan, $this->t()));
    }

    public function testSidebarLinePersonalOnly(): void
    {
        $plan = $this->plan(
            ['netmailIds' => [1, 2], 'netmailTruncated' => false, 'replyIds' => [], 'repliesTruncated' => false],
            ['areas' => [], 'areasTruncated' => false]
        );
        self::assertSame('Since Last Call: 2 personal', TelnetSylcPresenter::sidebarLine($plan, $this->t()));
    }

    public function testSidebarLineAmbientOnly(): void
    {
        $plan = $this->plan(
            ['netmailIds' => [], 'netmailTruncated' => false, 'replyIds' => [], 'repliesTruncated' => false],
            ['areas' => [$this->area(1, 'A', 5), $this->area(2, 'B', 1)], 'areasTruncated' => false]
        );
        self::assertSame('Since Last Call: 2 area(s)', TelnetSylcPresenter::sidebarLine($plan, $this->t()));
    }

    public function testSidebarLinePersonalAndAmbient(): void
    {
        $plan = $this->plan(
            ['netmailIds' => [1], 'netmailTruncated' => false, 'replyIds' => [2], 'repliesTruncated' => false],
            ['areas' => [$this->area(1, 'A', 4), $this->area(2, 'B', 2), $this->area(3, 'C', 1)], 'areasTruncated' => false]
        );
        self::assertSame('Since Last Call: 2 personal, 3 area(s)', TelnetSylcPresenter::sidebarLine($plan, $this->t()));
    }

    // ----- detail() -----

    public function testDetailQuietWhenNothingToShow(): void
    {
        $plan = $this->plan(
            ['netmailIds' => [], 'netmailTruncated' => false, 'replyIds' => [], 'repliesTruncated' => false],
            ['areas' => [], 'areasTruncated' => false]
        );
        $detail = TelnetSylcPresenter::detail($plan, [], [], $this->t());
        self::assertTrue($detail['quiet']);
        self::assertSame([], $detail['personal']);
        self::assertSame([], $detail['ambient']);
    }

    public function testDetailPersonalCappedAtTwoMostRecent(): void
    {
        $ids = [1, 2, 3];
        $rows = [
            $this->netmailRow(1, '30'),
            $this->netmailRow(2, '20'),
            $this->netmailRow(3, '10'), // most recent
        ];
        $plan = $this->plan(
            ['netmailIds' => $ids, 'netmailTruncated' => false, 'replyIds' => [], 'repliesTruncated' => false],
            ['areas' => [], 'areasTruncated' => false]
        );
        $detail = TelnetSylcPresenter::detail($plan, $rows, [], $this->t());
        self::assertCount(2, $detail['personal']);
        self::assertTrue($detail['personalMore']);
        // Most recent (id 3) first.
        self::assertStringContainsString('Sender 3', $detail['personal'][0]['label']);
        self::assertStringContainsString('Sender 2', $detail['personal'][1]['label']);
    }

    public function testDetailAmbientCappedAtThreeHighestCount(): void
    {
        $areas = [
            $this->area(1, 'AAA', 1),
            $this->area(2, 'BBB', 9),
            $this->area(3, 'CCC', 3),
            $this->area(4, 'DDD', 7),
        ];
        $plan = $this->plan(null, ['areas' => $areas, 'areasTruncated' => false]);
        $detail = TelnetSylcPresenter::detail($plan, [], [], $this->t());
        self::assertCount(3, $detail['ambient']);
        self::assertTrue($detail['ambientMore']);
        self::assertSame(['BBB', 'DDD', 'CCC'], array_column($detail['ambient'], 'tag'));
    }

    public function testDetailServiceTruncationFlagPropagatesUnderCap(): void
    {
        $plan = $this->plan(
            ['netmailIds' => [1], 'netmailTruncated' => true, 'replyIds' => [], 'repliesTruncated' => false],
            ['areas' => [$this->area(1, 'A', 1)], 'areasTruncated' => true]
        );
        $detail = TelnetSylcPresenter::detail($plan, [$this->netmailRow(1, '1')], [], $this->t());
        self::assertTrue($detail['personalMore']);
        self::assertTrue($detail['ambientMore']);
    }

    public function testDetailNetmailAndReplyDistinguishedByType(): void
    {
        $plan = $this->plan(
            ['netmailIds' => [1], 'netmailTruncated' => false, 'replyIds' => [2], 'repliesTruncated' => false],
            null
        );
        $detail = TelnetSylcPresenter::detail(
            $plan,
            [$this->netmailRow(1, '5')],
            [['id' => 2, 'from_name' => 'Replier', 'subject' => 'Re: Thing', 'date_received' => $this->ago('1')]],
            $this->t()
        );
        $types = array_column($detail['personal'], 'type');
        self::assertContains('netmail', $types);
        self::assertContains('reply', $types);
    }

    // ----- PARTICIPATED CONVERSATION ACTIVITY (Phase 1) -----

    public function testSidebarLineCountIncludesParticipatedActivity(): void
    {
        $plan = $this->plan(
            ['netmailIds' => [], 'netmailTruncated' => false, 'replyIds' => [], 'repliesTruncated' => false, 'participatedIds' => [1, 2], 'participatedTruncated' => false],
            ['areas' => [], 'areasTruncated' => false]
        );
        self::assertSame('Since Last Call: 2 personal', TelnetSylcPresenter::sidebarLine($plan, $this->t()));
    }

    public function testDetailDirectReplyOutranksParticipatedActivityUnderCap(): void
    {
        // Two direct-reply candidates plus one thread-activity candidate;
        // MAX_PERSONAL_ROWS = 2 means the thread row must be crowded out by
        // the two direct replies, never the reverse.
        $plan = $this->plan(
            ['netmailIds' => [1, 2], 'netmailTruncated' => false, 'replyIds' => [], 'repliesTruncated' => false, 'participatedIds' => [3], 'participatedTruncated' => false],
            null
        );
        $detail = TelnetSylcPresenter::detail(
            $plan,
            [$this->netmailRow(1, '30'), $this->netmailRow(2, '20')],
            [],
            $this->t(),
            [$this->netmailRow(3, '1')] // most recent, but must not bump a direct item
        );
        self::assertCount(2, $detail['personal']);
        self::assertSame(['netmail', 'netmail'], array_column($detail['personal'], 'type'));
        self::assertTrue($detail['personalMore']);
    }

    public function testDetailParticipatedActivityShownWhenRoomRemains(): void
    {
        $plan = $this->plan(
            ['netmailIds' => [1], 'netmailTruncated' => false, 'replyIds' => [], 'repliesTruncated' => false, 'participatedIds' => [2], 'participatedTruncated' => false],
            null
        );
        $detail = TelnetSylcPresenter::detail(
            $plan,
            [$this->netmailRow(1, '30')],
            [],
            $this->t(),
            [$this->netmailRow(2, '1')]
        );
        self::assertSame(['netmail', 'thread'], array_column($detail['personal'], 'type'));
    }

    public function testDetailBackwardCompatibleWithoutParticipatedArgument(): void
    {
        // Pre-Phase-1 4-argument call shape must keep working unchanged.
        $plan = $this->plan(
            ['netmailIds' => [1], 'netmailTruncated' => false, 'replyIds' => [], 'repliesTruncated' => false],
            null
        );
        $detail = TelnetSylcPresenter::detail($plan, [$this->netmailRow(1, '1')], [], $this->t());
        self::assertCount(1, $detail['personal']);
    }

    public function testDetailLongSubjectIsEllipsized(): void
    {
        $longSubject = str_repeat('X', 100);
        $plan = $this->plan(
            ['netmailIds' => [1], 'netmailTruncated' => false, 'replyIds' => [], 'repliesTruncated' => false],
            null
        );
        $detail = TelnetSylcPresenter::detail($plan, [$this->netmailRow(1, '1', $longSubject)], [], $this->t());
        self::assertLessThan(100, mb_strlen($detail['personal'][0]['label']));
    }
}
