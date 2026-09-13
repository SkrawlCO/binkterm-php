<?php

declare(strict_types=1);

require_once __DIR__ . '/Support/TestDatabase.php';

use BinktermPHP\SysopChatService;
use BinktermPHP\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * SysopChatService — M1A backend lifecycle.
 *
 * DB-backed; every test seeds inside a transaction that is always rolled
 * back, and the suite skips when no database is configured (matching
 * ActiveSessionServiceTest).
 */
final class SysopChatServiceTest extends TestCase
{
    private \PDO $pdo;
    private int $callerA;
    private int $callerB;
    private int $adminA;
    private int $adminB;

    protected function setUp(): void
    {
        try {
            $this->pdo = TestDatabase::pdo();
        } catch (\Throwable $e) {
            self::markTestSkipped('database not available: ' . $e->getMessage());
        }

        $this->pdo->beginTransaction();
        $this->callerA = $this->newUser('caller_a');
        $this->callerB = $this->newUser('caller_b');
        $this->adminA = $this->newUser('admin_a', true);
        $this->adminB = $this->newUser('admin_b', true);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function newUser(string $tag, bool $isAdmin = false): int
    {
        $s = strtolower(bin2hex(random_bytes(6)));
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (username, password_hash, real_name, is_active, is_admin)
             VALUES (?, ?, ?, TRUE, ?) RETURNING id'
        );
        $stmt->execute([
            "sysop_{$tag}_$s",
            password_hash('x', PASSWORD_DEFAULT),
            "Sysop Test {$tag} $s",
            $isAdmin ? 'true' : 'false',
        ]);
        return (int) $stmt->fetch(\PDO::FETCH_ASSOC)['id'];
    }

    private function newSession(int $userId, ?string $expiresInterval = '1 hour'): string
    {
        $sid = 'sysoptest_' . bin2hex(random_bytes(16));
        $stmt = $this->pdo->prepare(
            "INSERT INTO user_sessions (session_id, user_id, expires_at, service, last_activity)
             VALUES (?, ?, NOW() + ?::interval, 'telnet', NOW())"
        );
        $stmt->execute([$sid, $userId, $expiresInterval]);
        return $sid;
    }

    /** Rows for one event type, in id order, optionally filtered to a target user. */
    private function events(string $eventType, ?int $userId = null): array
    {
        if ($userId !== null) {
            $stmt = $this->pdo->prepare(
                "SELECT payload::text AS payload, user_id, admin_only
                 FROM sse_events
                 WHERE event_type = ? AND user_id = ?
                 ORDER BY id ASC"
            );
            $stmt->execute([$eventType, $userId]);
        } else {
            $stmt = $this->pdo->prepare(
                "SELECT payload::text AS payload, user_id, admin_only
                 FROM sse_events
                 WHERE event_type = ?
                 ORDER BY id ASC"
            );
            $stmt->execute([$eventType]);
        }
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function statusOf(int $pageId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT status FROM sysop_pages WHERE id = ?');
        $stmt->execute([$pageId]);
        $s = $stmt->fetchColumn();
        return $s === false ? null : (string) $s;
    }

    private function service(): SysopChatService
    {
        return new SysopChatService($this->pdo);
    }

    // -------------------------------------------------------------------
    // CREATE
    // -------------------------------------------------------------------

    public function testCreatePageSucceedsAndEmitsAdminOnlyRequestEvent(): void
    {
        $page = $this->service()->createPage($this->callerA, 'telnet', 'sess-a');
        self::assertNotNull($page);
        self::assertSame('waiting', $page['status']);
        self::assertSame('waiting', $this->statusOf($page['id']));

        $events = $this->events(SysopChatService::EVENT_REQUEST);
        self::assertCount(1, $events);
        self::assertNull($events[0]['user_id'], 'request event has no single target');
        self::assertTrue($events[0]['admin_only'] === true || $events[0]['admin_only'] === 't', 'request event is admin-only');

        $payload = json_decode($events[0]['payload'], true);
        self::assertSame($page['id'], $payload['page_id']);
        self::assertSame($this->callerA, $payload['caller_user_id']);
        self::assertSame('telnet', $payload['surface']);
    }

    public function testDuplicateWaitingPageForSameCallerIsBlocked(): void
    {
        $first = $this->service()->createPage($this->callerA, 'telnet', 'sess-a');
        self::assertNotNull($first);

        $second = $this->service()->createPage($this->callerA, 'web_ui', null);
        self::assertNull($second, 'caller already has a live waiting page');

        // Only one row exists for this caller.
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM sysop_pages WHERE caller_user_id = ?');
        $stmt->execute([$this->callerA]);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testDifferentCallersMayEachHaveAWaitingPage(): void
    {
        $a = $this->service()->createPage($this->callerA, 'telnet', 'sess-a');
        $b = $this->service()->createPage($this->callerB, 'web_ui', null);
        self::assertNotNull($a);
        self::assertNotNull($b);
    }

    public function testWebUiPageAcceptsNullSessionId(): void
    {
        $page = $this->service()->createPage($this->callerA, 'web_ui', null);
        self::assertNotNull($page);

        $stmt = $this->pdo->prepare('SELECT caller_session_id FROM sysop_pages WHERE id = ?');
        $stmt->execute([$page['id']]);
        self::assertNull($stmt->fetchColumn() ?: null);
    }

    public function testInvalidSurfaceIsRejected(): void
    {
        self::assertNull($this->service()->createPage($this->callerA, 'not_a_real_surface'));
    }

    // -------------------------------------------------------------------
    // CANCEL
    // -------------------------------------------------------------------

    public function testCallerCanCancelOwnWaitingPage(): void
    {
        $page = $this->service()->createPage($this->callerA, 'telnet', 'sess-a');
        self::assertTrue($this->service()->cancelPage($page['id'], $this->callerA));
        self::assertSame('cancelled', $this->statusOf($page['id']));

        $events = $this->events(SysopChatService::EVENT_CANCELLED);
        self::assertCount(1, $events);
        self::assertNull($events[0]['user_id']);
    }

    public function testOtherCallerCannotCancelSomeoneElsesPage(): void
    {
        $page = $this->service()->createPage($this->callerA, 'telnet', 'sess-a');
        self::assertFalse($this->service()->cancelPage($page['id'], $this->callerB));
        self::assertSame('waiting', $this->statusOf($page['id']));
    }

    public function testCancelIsSafeWhenPageAlreadyGone(): void
    {
        self::assertFalse($this->service()->cancelPage(999999999, $this->callerA));
    }

    // -------------------------------------------------------------------
    // ACCEPT
    // -------------------------------------------------------------------

    public function testAcceptWaitingPageSucceedsAndTargetsCaller(): void
    {
        $sid = $this->newSession($this->callerA);
        $page = $this->service()->createPage($this->callerA, 'telnet', $sid);

        $accepted = $this->service()->acceptPage($page['id'], $this->adminA);
        self::assertNotNull($accepted);
        self::assertSame($this->callerA, $accepted['caller_user_id']);
        self::assertSame('accepted', $this->statusOf($page['id']));

        $stmt = $this->pdo->prepare('SELECT accepted_by_user_id FROM sysop_pages WHERE id = ?');
        $stmt->execute([$page['id']]);
        self::assertSame($this->adminA, (int) $stmt->fetchColumn());

        $events = $this->events(SysopChatService::EVENT_ACCEPTED, $this->callerA);
        self::assertCount(1, $events);
    }

    public function testAlreadyAcceptedPageCannotBeAcceptedAgain(): void
    {
        $sid = $this->newSession($this->callerA);
        $page = $this->service()->createPage($this->callerA, 'telnet', $sid);
        self::assertNotNull($this->service()->acceptPage($page['id'], $this->adminA));

        self::assertNull($this->service()->acceptPage($page['id'], $this->adminB));
        self::assertSame('accepted', $this->statusOf($page['id']));
    }

    public function testExpiredPageCannotBeAccepted(): void
    {
        $page = $this->service()->createPage($this->callerA, 'web_ui', null);
        $this->pdo->prepare("UPDATE sysop_pages SET expires_at = NOW() - INTERVAL '1 second' WHERE id = ?")
            ->execute([$page['id']]);

        self::assertNull($this->service()->acceptPage($page['id'], $this->adminA));
    }

    public function testCancelledPageCannotBeAccepted(): void
    {
        $page = $this->service()->createPage($this->callerA, 'web_ui', null);
        self::assertTrue($this->service()->cancelPage($page['id'], $this->callerA));
        self::assertNull($this->service()->acceptPage($page['id'], $this->adminA));
    }

    public function testDeclinedPageCannotBeAccepted(): void
    {
        $page = $this->service()->createPage($this->callerA, 'web_ui', null);
        self::assertTrue($this->service()->declinePage($page['id'], $this->adminA));
        self::assertNull($this->service()->acceptPage($page['id'], $this->adminB));
    }

    public function testSingletonActiveChatBlocksAcceptingASecondPage(): void
    {
        $sidA = $this->newSession($this->callerA);
        $sidB = $this->newSession($this->callerB);
        $pageA = $this->service()->createPage($this->callerA, 'telnet', $sidA);
        $pageB = $this->service()->createPage($this->callerB, 'telnet', $sidB);

        self::assertNotNull($this->service()->acceptPage($pageA['id'], $this->adminA));
        self::assertNull(
            $this->service()->acceptPage($pageB['id'], $this->adminA),
            'a second page cannot become active while one chat is already active'
        );
        self::assertSame('waiting', $this->statusOf($pageB['id']), 'second page remains waiting, not silently mutated');
    }

    public function testAcceptingAfterCallerSessionIsGoneExpiresInsteadOfActivating(): void
    {
        $sid = $this->newSession($this->callerA, '1 hour');
        $page = $this->service()->createPage($this->callerA, 'telnet', $sid);

        // Caller disconnects: their auth session row is gone.
        $this->pdo->prepare('DELETE FROM user_sessions WHERE session_id = ?')->execute([$sid]);

        self::assertNull($this->service()->acceptPage($page['id'], $this->adminA));
        self::assertSame('expired', $this->statusOf($page['id']));
    }

    public function testAcceptingWebUiPageNeedsNoSessionCheck(): void
    {
        $page = $this->service()->createPage($this->callerA, 'web_ui', null);
        self::assertNotNull($this->service()->acceptPage($page['id'], $this->adminA));
        self::assertSame('accepted', $this->statusOf($page['id']));
    }

    // -------------------------------------------------------------------
    // DECLINE
    // -------------------------------------------------------------------

    public function testDeclineWaitingPageTargetsCaller(): void
    {
        $page = $this->service()->createPage($this->callerA, 'web_ui', null);
        self::assertTrue($this->service()->declinePage($page['id'], $this->adminA));
        self::assertSame('declined', $this->statusOf($page['id']));

        $events = $this->events(SysopChatService::EVENT_DECLINED, $this->callerA);
        self::assertCount(1, $events);
    }

    public function testCannotDeclineAlreadyAcceptedPage(): void
    {
        $page = $this->service()->createPage($this->callerA, 'web_ui', null);
        self::assertNotNull($this->service()->acceptPage($page['id'], $this->adminA));
        self::assertFalse($this->service()->declinePage($page['id'], $this->adminB));
    }

    // -------------------------------------------------------------------
    // EXPIRE
    // -------------------------------------------------------------------

    public function testStaleWaitingPageIsExpiredByOpportunisticSweep(): void
    {
        $page = $this->service()->createPage($this->callerA, 'web_ui', null);
        $this->pdo->prepare("UPDATE sysop_pages SET expires_at = NOW() - INTERVAL '1 second' WHERE id = ?")
            ->execute([$page['id']]);

        $count = $this->service()->expireStalePages($this->callerA);
        self::assertSame(1, $count);
        self::assertSame('expired', $this->statusOf($page['id']));

        $events = $this->events(SysopChatService::EVENT_EXPIRED, $this->callerA);
        self::assertCount(1, $events);
    }

    public function testGetWaitingPagesExcludesExpiredAndSweepsFirst(): void
    {
        $fresh = $this->service()->createPage($this->callerA, 'telnet', 'sess-a');
        $stale = $this->service()->createPage($this->callerB, 'telnet', 'sess-b');
        $this->pdo->prepare("UPDATE sysop_pages SET expires_at = NOW() - INTERVAL '1 second' WHERE id = ?")
            ->execute([$stale['id']]);

        $waiting = $this->service()->getWaitingPages();
        $ids = array_column($waiting, 'id');

        self::assertContains($fresh['id'], $ids);
        self::assertNotContains($stale['id'], $ids);
    }

    public function testExpiredPageAfterCreateCanBePagedAgain(): void
    {
        $first = $this->service()->createPage($this->callerA, 'telnet', 'sess-a');
        $this->pdo->prepare("UPDATE sysop_pages SET expires_at = NOW() - INTERVAL '1 second' WHERE id = ?")
            ->execute([$first['id']]);

        // createPage() opportunistically sweeps this caller's own stale row first.
        $second = $this->service()->createPage($this->callerA, 'telnet', 'sess-a-2');
        self::assertNotNull($second, 'a caller whose previous page merely timed out can page again');
        self::assertSame('expired', $this->statusOf($first['id']));
    }

    // -------------------------------------------------------------------
    // COMPLETE
    // -------------------------------------------------------------------

    public function testCallerCanCompleteAnAcceptedChatAndAdminIsNotified(): void
    {
        $page = $this->service()->createPage($this->callerA, 'web_ui', null);
        self::assertNotNull($this->service()->acceptPage($page['id'], $this->adminA));

        self::assertTrue($this->service()->completePage($page['id'], $this->callerA));
        self::assertSame('completed', $this->statusOf($page['id']));

        $events = $this->events(SysopChatService::EVENT_COMPLETED, $this->adminA);
        self::assertCount(1, $events, 'the admin who accepted is notified when the caller ends it');
    }

    public function testAdminCanCompleteAnAcceptedChatAndCallerIsNotified(): void
    {
        $page = $this->service()->createPage($this->callerA, 'web_ui', null);
        self::assertNotNull($this->service()->acceptPage($page['id'], $this->adminA));

        self::assertTrue($this->service()->completePage($page['id'], $this->adminA));

        $events = $this->events(SysopChatService::EVENT_COMPLETED, $this->callerA);
        self::assertCount(1, $events, 'the caller is notified when the admin ends it');
    }

    public function testWaitingPageCannotBeCompleted(): void
    {
        $page = $this->service()->createPage($this->callerA, 'web_ui', null);
        self::assertFalse($this->service()->completePage($page['id'], $this->callerA));
        self::assertSame('waiting', $this->statusOf($page['id']));
    }

    public function testUnrelatedUserCannotCompleteSomeoneElsesChat(): void
    {
        $page = $this->service()->createPage($this->callerA, 'web_ui', null);
        self::assertNotNull($this->service()->acceptPage($page['id'], $this->adminA));
        self::assertFalse($this->service()->completePage($page['id'], $this->callerB));
    }

    // -------------------------------------------------------------------
    // QUERY
    // -------------------------------------------------------------------

    public function testGetCallerPageReturnsCurrentWaitingOrActivePage(): void
    {
        self::assertNull($this->service()->getCallerPage($this->callerA));

        $page = $this->service()->createPage($this->callerA, 'telnet', 'sess-a');
        $current = $this->service()->getCallerPage($this->callerA);
        self::assertNotNull($current);
        self::assertSame($page['id'], $current['id']);
        self::assertSame('waiting', $current['status']);
    }

    public function testGetActiveChatReturnsTheSingleAcceptedPage(): void
    {
        self::assertNull($this->service()->getActiveChat());

        $page = $this->service()->createPage($this->callerA, 'web_ui', null);
        self::assertNotNull($this->service()->acceptPage($page['id'], $this->adminA));

        $active = $this->service()->getActiveChat();
        self::assertNotNull($active);
        self::assertSame($page['id'], $active['id']);
        self::assertSame($this->adminA, $active['accepted_by_user_id']);
    }
}
