<?php

declare(strict_types=1);

use BinktermPHP\Newscan\NewscanPlan;
use BinktermPHP\Newscan\UnifiedNewscanService;
use PHPUnit\Framework\TestCase;

/**
 * Composition, ordering, cap and read-state-safety coverage for
 * {@see UnifiedNewscanService}.
 *
 * The four SELECT-only data-access methods are overridden with fixtures so the
 * planner logic is exercised deterministically with no database. A separate
 * test proves against the real database that `plan()` performs zero writes.
 */
final class UnifiedNewscanServiceTest extends TestCase
{
    /**
     * @param int[]                                                   $netmailIds
     * @param list<array{id:int,tag:string,domain?:string,description?:string,ids:int[]}> $areaFixtures
     */
    private function service(array $netmailIds, array $areaFixtures, int $bulletinUnread = 0): UnifiedNewscanService
    {
        return new class ($netmailIds, $areaFixtures, $bulletinUnread) extends UnifiedNewscanService {
            /** @var int[] */
            private array $nm;
            /** @var list<array<string,mixed>> */
            private array $areaFixtures;
            private int $bull;

            public function __construct(array $nm, array $areaFixtures, int $bull)
            {
                // Intentionally does NOT call parent::__construct — the overridden
                // data-access methods never touch $db / $messages.
                $this->nm           = $nm;
                $this->areaFixtures = $areaFixtures;
                $this->bull         = $bull;
            }

            protected function unreadNetmailIds(int $userId, int $cap): array
            {
                return $this->nm;
            }

            protected function candidateAreas(int $userId, bool $isAdmin, int $cap): array
            {
                // Mirrors the real method's SQL `LIMIT :cap`.
                $rows = array_map(static fn (array $f): array => [
                    'id'          => $f['id'],
                    'tag'         => $f['tag'],
                    'domain'      => $f['domain'] ?? '',
                    'description' => $f['description'] ?? '',
                ], $this->areaFixtures);

                return array_slice($rows, 0, $cap);
            }

            protected function newMessageIds(int $userId, int $echoareaId, int $cap): array
            {
                foreach ($this->areaFixtures as $f) {
                    if ($f['id'] === $echoareaId) {
                        return $f['ids'];
                    }
                }

                return [];
            }

            protected function bulletinUnreadCount(int $userId): int
            {
                return $this->bull;
            }
        };
    }

    private const USER = ['user_id' => 7, 'is_admin' => false];

    public function testEmptyNewscan(): void
    {
        $plan = $this->service([], [])->plan(self::USER);

        self::assertTrue($plan->isEmpty());
        self::assertFalse($plan->hasMessages());
        self::assertSame(0, $plan->netmailCount());
        self::assertSame(0, $plan->echomailCount());
        self::assertSame(0, $plan->areaCount());
        self::assertFalse($plan->truncated);
    }

    public function testUnknownUserGetsAnEmptyPlanAndTouchesNothing(): void
    {
        $plan = $this->service([1, 2, 3], [['id' => 1, 'tag' => 'X', 'ids' => [9]]])
            ->plan(['user_id' => 0]);

        self::assertTrue($plan->isEmpty());
    }

    public function testNetmailOnly(): void
    {
        $plan = $this->service([10, 11, 12], [])->plan(self::USER);

        self::assertSame([10, 11, 12], $plan->netmailIds);
        self::assertSame(3, $plan->netmailCount());
        self::assertSame(0, $plan->areaCount());
        self::assertTrue($plan->hasMessages());
        self::assertFalse($plan->isEmpty());
    }

    public function testEchomailOneArea(): void
    {
        $plan = $this->service([], [
            ['id' => 5, 'tag' => 'GENERAL', 'domain' => 'fidonet', 'description' => 'Chatter', 'ids' => [100, 101, 102]],
        ])->plan(self::USER);

        self::assertSame([], $plan->netmailIds);
        self::assertCount(1, $plan->areas);
        self::assertSame('GENERAL@fidonet', $plan->areas[0]->identifier());
        self::assertSame('Chatter', $plan->areas[0]->description);
        self::assertSame([100, 101, 102], $plan->areas[0]->messageIds);
        self::assertSame(3, $plan->echomailCount());
    }

    public function testEchomailMultipleAreasInFixtureOrder(): void
    {
        $plan = $this->service([], [
            ['id' => 1, 'tag' => 'ALPHA', 'ids' => [1, 2]],
            ['id' => 2, 'tag' => 'BRAVO', 'ids' => [3]],
            ['id' => 3, 'tag' => 'CHARLIE', 'ids' => [4, 5, 6]],
        ])->plan(self::USER);

        self::assertSame(['ALPHA', 'BRAVO', 'CHARLIE'], array_map(static fn ($a) => $a->tag, $plan->areas));
        self::assertSame(6, $plan->echomailCount());
        self::assertSame(3, $plan->areaCount());
    }

    public function testAreaWithNoNewIdsIsDropped(): void
    {
        $plan = $this->service([], [
            ['id' => 1, 'tag' => 'HAS', 'ids' => [1]],
            ['id' => 2, 'tag' => 'EMPTY', 'ids' => []],
        ])->plan(self::USER);

        self::assertSame(['HAS'], array_map(static fn ($a) => $a->tag, $plan->areas));
    }

    public function testMixedNetmailAndEchomail(): void
    {
        $plan = $this->service([20, 21], [
            ['id' => 1, 'tag' => 'GEN', 'ids' => [1, 2, 3]],
        ], 4)->plan(self::USER);

        self::assertSame(2, $plan->netmailCount());
        self::assertSame(3, $plan->echomailCount());
        self::assertSame(4, $plan->bulletinUnread);
        self::assertTrue($plan->hasMessages());
    }

    public function testBulletinsCountIsCarriedButNeverTraversed(): void
    {
        $plan = $this->service([], [], 9)->plan(self::USER);

        self::assertSame(9, $plan->bulletinUnread);
        self::assertFalse($plan->hasMessages(), 'bulletins do not count as traversable messages');
        self::assertFalse($plan->isEmpty(), 'unread bulletins alone still make the scan non-empty');
    }

    public function testNetmailCapMarksThePlanTruncated(): void
    {
        $plan = $this->service(range(1, 25), [])->plan(self::USER, ['netmail_cap' => 5]);

        self::assertCount(5, $plan->netmailIds);
        self::assertSame([1, 2, 3, 4, 5], $plan->netmailIds);
        self::assertTrue($plan->truncated);
    }

    public function testPerAreaCapMarksThePlanTruncated(): void
    {
        $plan = $this->service([], [
            ['id' => 1, 'tag' => 'BIG', 'ids' => range(1, 50)],
        ])->plan(self::USER, ['per_area_cap' => 4]);

        self::assertSame([1, 2, 3, 4], $plan->areas[0]->messageIds);
        self::assertTrue($plan->truncated);
    }

    public function testAreaCountCapMarksThePlanTruncated(): void
    {
        $fixtures = [];
        for ($i = 1; $i <= 10; $i++) {
            $fixtures[] = ['id' => $i, 'tag' => 'A' . $i, 'ids' => [$i * 100]];
        }
        $plan = $this->service([], $fixtures)->plan(self::USER, ['area_cap' => 3]);

        self::assertSame(3, $plan->areaCount());
        self::assertTrue($plan->truncated);
    }

    public function testPlanConstructionIsDeterministic(): void
    {
        $svc = $this->service([9, 8, 7], [
            ['id' => 2, 'tag' => 'B', 'ids' => [30, 31]],
            ['id' => 1, 'tag' => 'A', 'ids' => [10]],
        ], 2);

        $a = $svc->plan(self::USER);
        $b = $svc->plan(self::USER);

        self::assertEquals($a, $b);
        self::assertSame($a->netmailIds, $b->netmailIds);
        self::assertSame(
            array_map(static fn ($x) => $x->messageIds, $a->areas),
            array_map(static fn ($x) => $x->messageIds, $b->areas)
        );
    }

    // ----- real database: the zero-write contract -----

    public function testPlanPerformsZeroWritesAgainstTheRealDatabase(): void
    {
        try {
            $pdo = \BinktermPHP\Database::getInstance()->getPdo();
            $pdo->query('SELECT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('database not available: ' . $e->getMessage());
        }

        $user = $pdo->query(
            "SELECT id, is_admin FROM users WHERE is_active = TRUE ORDER BY id LIMIT 1"
        )->fetch(\PDO::FETCH_ASSOC);
        if (!$user) {
            self::markTestSkipped('no active user to scan');
        }
        $userId = (int) $user['id'];

        $before = $this->readStateFingerprint($pdo, $userId);

        $plan = (new UnifiedNewscanService())->plan([
            'user_id'  => $userId,
            'is_admin' => (bool) $user['is_admin'],
        ]);

        $after = $this->readStateFingerprint($pdo, $userId);

        self::assertSame($before, $after, 'plan() must not touch message_read_status, last_read_id or bulletin_reads');
        self::assertInstanceOf(NewscanPlan::class, $plan);
    }

    /** @return array{mrs:int,watermarks:string,bulletins:int} */
    private function readStateFingerprint(\PDO $pdo, int $userId): array
    {
        $mrs = (int) $pdo->query(
            "SELECT COUNT(*) FROM message_read_status WHERE user_id = {$userId}"
        )->fetchColumn();

        $wm = (string) $pdo->query(
            "SELECT COALESCE(string_agg(echoarea_id || ':' || COALESCE(last_read_id::text, 'null'), ',' ORDER BY echoarea_id), '')
             FROM user_echoarea_subscriptions WHERE user_id = {$userId}"
        )->fetchColumn();

        $bulletins = 0;
        try {
            $bulletins = (int) $pdo->query(
                "SELECT COUNT(*) FROM bulletin_reads WHERE user_id = {$userId}"
            )->fetchColumn();
        } catch (\Throwable $e) {
            $bulletins = -1;
        }

        return ['mrs' => $mrs, 'watermarks' => $wm, 'bulletins' => $bulletins];
    }
}
