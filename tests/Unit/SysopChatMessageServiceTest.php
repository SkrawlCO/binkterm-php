<?php

declare(strict_types=1);

require_once __DIR__ . '/Support/TestDatabase.php';

use BinktermPHP\SysopChatMessageService;
use BinktermPHP\SysopChatService;
use BinktermPHP\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * SysopChatMessageService — M1B ephemeral message transport.
 *
 * DB-backed; every test seeds inside a transaction that is always rolled
 * back, and the suite skips when no database is configured (matching
 * SysopChatServiceTest).
 */
final class SysopChatMessageServiceTest extends TestCase
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
            "sysopmsg_{$tag}_$s",
            password_hash('x', PASSWORD_DEFAULT),
            "Sysop Msg Test {$tag} $s",
            $isAdmin ? 'true' : 'false',
        ]);
        return (int) $stmt->fetch(\PDO::FETCH_ASSOC)['id'];
    }

    private function pageService(): SysopChatService
    {
        return new SysopChatService($this->pdo);
    }

    private function messageService(): SysopChatMessageService
    {
        return new SysopChatMessageService($this->pdo);
    }

    /** Create + accept a page for callerA/adminA, returning the page id. */
    private function acceptedPage(?int $caller = null, ?int $admin = null): int
    {
        $caller ??= $this->callerA;
        $admin ??= $this->adminA;
        $page = $this->pageService()->createPage($caller, 'web_ui', null);
        self::assertNotNull($page);
        $accepted = $this->pageService()->acceptPage($page['id'], $admin);
        self::assertNotNull($accepted);
        return $page['id'];
    }

    private function events(string $eventType, ?int $userId = null): array
    {
        if ($userId !== null) {
            $stmt = $this->pdo->prepare(
                "SELECT payload::text AS payload, user_id
                 FROM sse_events WHERE event_type = ? AND user_id = ? ORDER BY id ASC"
            );
            $stmt->execute([$eventType, $userId]);
        } else {
            $stmt = $this->pdo->prepare(
                "SELECT payload::text AS payload, user_id
                 FROM sse_events WHERE event_type = ? ORDER BY id ASC"
            );
            $stmt->execute([$eventType]);
        }
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function messageCount(int $pageId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM sysop_chat_messages WHERE page_id = ?');
        $stmt->execute([$pageId]);
        return (int) $stmt->fetchColumn();
    }

    // -------------------------------------------------------------------
    // SEND
    // -------------------------------------------------------------------

    public function testAcceptedCallerCanSendToAdmin(): void
    {
        $pageId = $this->acceptedPage();

        $msg = $this->messageService()->sendMessage($pageId, $this->callerA, 'hello matt');
        self::assertNotNull($msg);
        self::assertSame('hello matt', $msg['body']);
        self::assertSame($this->callerA, $msg['sender_user_id']);

        $events = $this->events(SysopChatMessageService::EVENT_MESSAGE, $this->adminA);
        self::assertCount(1, $events, 'message event targeted at the admin');
        $payload = json_decode($events[0]['payload'], true);
        self::assertSame($pageId, $payload['page_id']);
        self::assertSame('hello matt', $payload['body']);
    }

    public function testAcceptedAdminCanSendToCaller(): void
    {
        $pageId = $this->acceptedPage();

        $msg = $this->messageService()->sendMessage($pageId, $this->adminA, 'hi there');
        self::assertNotNull($msg);

        $events = $this->events(SysopChatMessageService::EVENT_MESSAGE, $this->callerA);
        self::assertCount(1, $events, 'message event targeted at the caller');
    }

    public function testUnauthorizedThirdUserCannotSend(): void
    {
        $pageId = $this->acceptedPage();

        self::assertNull($this->messageService()->sendMessage($pageId, $this->callerB, 'nope'));
        self::assertNull($this->messageService()->sendMessage($pageId, $this->adminB, 'nope'));
        self::assertSame(0, $this->messageCount($pageId));
    }

    public function testWaitingPageCannotReceiveMessages(): void
    {
        $page = $this->pageService()->createPage($this->callerA, 'web_ui', null);
        self::assertNull($this->messageService()->sendMessage($page['id'], $this->callerA, 'too early'));
    }

    public function testCompletedPageCannotReceiveMessages(): void
    {
        $pageId = $this->acceptedPage();
        self::assertTrue($this->pageService()->completePage($pageId, $this->callerA));

        self::assertNull($this->messageService()->sendMessage($pageId, $this->callerA, 'too late'));
        self::assertNull($this->messageService()->sendMessage($pageId, $this->adminA, 'too late'));
    }

    public function testEmptyMessageIsRejected(): void
    {
        $pageId = $this->acceptedPage();
        self::assertNull($this->messageService()->sendMessage($pageId, $this->callerA, ''));
        self::assertNull($this->messageService()->sendMessage($pageId, $this->callerA, '   '));
    }

    public function testOversizedMessageIsRejected(): void
    {
        $pageId = $this->acceptedPage();
        self::assertNull($this->messageService()->sendMessage($pageId, $this->callerA, str_repeat('x', 2001)));
        self::assertNotNull($this->messageService()->sendMessage($pageId, $this->callerA, str_repeat('x', 2000)));
    }

    // -------------------------------------------------------------------
    // LIST
    // -------------------------------------------------------------------

    public function testMessagesListInOrderForBothParticipants(): void
    {
        $pageId = $this->acceptedPage();
        $svc = $this->messageService();
        $svc->sendMessage($pageId, $this->callerA, 'one');
        $svc->sendMessage($pageId, $this->adminA, 'two');
        $svc->sendMessage($pageId, $this->callerA, 'three');

        $asCaller = $svc->listMessages($pageId, $this->callerA);
        $asAdmin = $svc->listMessages($pageId, $this->adminA);

        self::assertSame(['one', 'two', 'three'], array_column($asCaller, 'body'));
        self::assertSame(['one', 'two', 'three'], array_column($asAdmin, 'body'));
    }

    public function testUnauthorizedThirdUserCannotList(): void
    {
        $pageId = $this->acceptedPage();
        $this->messageService()->sendMessage($pageId, $this->callerA, 'secret');

        self::assertNull($this->messageService()->listMessages($pageId, $this->callerB));
        self::assertNull($this->messageService()->listMessages($pageId, $this->adminB));
    }

    public function testListOnUnknownPageReturnsNull(): void
    {
        self::assertNull($this->messageService()->listMessages(999999999, $this->callerA));
    }

    // -------------------------------------------------------------------
    // PURGE (via SysopChatService::completePage())
    // -------------------------------------------------------------------

    public function testCompletingChatPurgesItsMessages(): void
    {
        $pageId = $this->acceptedPage();
        $this->messageService()->sendMessage($pageId, $this->callerA, 'one');
        $this->messageService()->sendMessage($pageId, $this->adminA, 'two');
        self::assertSame(2, $this->messageCount($pageId));

        self::assertTrue($this->pageService()->completePage($pageId, $this->adminA));

        self::assertSame(0, $this->messageCount($pageId), 'messages purged once the chat ends');
    }

    public function testCompletingOnePageNeverTouchesAnotherPagesMessages(): void
    {
        // M1's singleton-active-chat rule means two pages can never be
        // ACCEPTED at once, so this proves scoping sequentially: page A is
        // fully completed (and purged) before page B is even created, then
        // B's messages must be untouched by A's earlier completion.
        $pageA = $this->acceptedPage($this->callerA, $this->adminA);
        $this->messageService()->sendMessage($pageA, $this->callerA, 'a');
        self::assertTrue($this->pageService()->completePage($pageA, $this->callerA));
        self::assertSame(0, $this->messageCount($pageA));

        $pageB = $this->acceptedPage($this->callerB, $this->adminB);
        $this->messageService()->sendMessage($pageB, $this->callerB, 'b');

        self::assertSame(0, $this->messageCount($pageA), 'A stays purged');
        self::assertSame(1, $this->messageCount($pageB), 'B unaffected by an earlier, unrelated page\'s purge');
    }

    public function testPurgeForPageIsScopedAndDoesNotThrowOnEmptyPage(): void
    {
        $pageId = $this->acceptedPage();
        $this->messageService()->sendMessage($pageId, $this->callerA, 'one');

        self::assertSame(1, $this->messageService()->purgeForPage($pageId));
        self::assertSame(0, $this->messageCount($pageId));
        self::assertSame(0, $this->messageService()->purgeForPage($pageId), 'idempotent on an already-empty page');
    }
}
