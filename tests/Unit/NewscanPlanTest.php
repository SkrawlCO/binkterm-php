<?php

declare(strict_types=1);

use BinktermPHP\Newscan\NewscanArea;
use BinktermPHP\Newscan\NewscanPlan;
use PHPUnit\Framework\TestCase;

final class NewscanPlanTest extends TestCase
{
    public function testEmptyPlan(): void
    {
        $plan = NewscanPlan::empty();

        self::assertTrue($plan->isEmpty());
        self::assertFalse($plan->hasMessages());
        self::assertSame(0, $plan->netmailCount());
        self::assertSame(0, $plan->echomailCount());
        self::assertSame(0, $plan->areaCount());
        self::assertSame(0, $plan->bulletinUnread);
        self::assertFalse($plan->truncated);
    }

    public function testCountsAndFlags(): void
    {
        $plan = new NewscanPlan(
            [1, 2],
            [
                new NewscanArea(10, 'GENERAL', 'fidonet', 'Chatter', [100, 101, 102]),
                new NewscanArea(11, 'LOCAL', '', '', [200]),
            ],
            3,
            true,
        );

        self::assertSame(2, $plan->netmailCount());
        self::assertSame(2, $plan->areaCount());
        self::assertSame(4, $plan->echomailCount());
        self::assertSame(3, $plan->bulletinUnread);
        self::assertTrue($plan->truncated);
        self::assertTrue($plan->hasMessages());
        self::assertFalse($plan->isEmpty());
    }

    public function testBulletinsAloneAreNotTraversableButAreNotEmpty(): void
    {
        $plan = new NewscanPlan([], [], 5);

        self::assertFalse($plan->hasMessages());
        self::assertFalse($plan->isEmpty());
    }

    public function testAreaIdentifier(): void
    {
        self::assertSame('GENERAL@fidonet', (new NewscanArea(1, 'GENERAL', 'fidonet', '', []))->identifier());
        self::assertSame('LOCALTEST', (new NewscanArea(1, 'LOCALTEST', '', '', []))->identifier());
        self::assertSame(2, (new NewscanArea(1, 'X', '', '', [7, 8]))->count());
    }
}
