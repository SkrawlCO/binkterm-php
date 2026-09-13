<?php

declare(strict_types=1);

require_once __DIR__ . '/../../telnet/src/OutputSink.php';
require_once __DIR__ . '/../../telnet/src/SocketSink.php';
require_once __DIR__ . '/../../telnet/src/BufferSink.php';
require_once __DIR__ . '/../../telnet/src/GlyphPolicy.php';
require_once __DIR__ . '/../../telnet/src/TerminalCapabilities.php';
require_once __DIR__ . '/../../telnet/src/TerminalRenderContext.php';
require_once __DIR__ . '/../../telnet/src/TelnetUtils.php';
require_once __DIR__ . '/../../telnet/src/TerminalMarkupRenderer.php';
require_once __DIR__ . '/../../telnet/src/TerminalBoxRenderer.php';
require_once __DIR__ . '/../../telnet/src/TerminalEventHandlerInterface.php';
require_once __DIR__ . '/../../telnet/src/TerminalEventPoller.php';
require_once __DIR__ . '/../../telnet/src/SessionKickHandler.php';
require_once __DIR__ . '/../../telnet/src/SysopChatEventHandler.php';
require_once __DIR__ . '/../../telnet/src/BbsSession.php';
require_once __DIR__ . '/../../telnet/src/TerminalLineEditor.php';
require_once __DIR__ . '/../../telnet/src/TerminalLineHistory.php';
require_once __DIR__ . '/Support/TestDatabase.php';

use BinktermPHP\Database;
use BinktermPHP\Realtime\BinkStream;
use BinktermPHP\Security\ActiveSessionService;
use BinktermPHP\SysopChatMessageService;
use BinktermPHP\SysopChatService;
use BinktermPHP\TelnetServer\BbsSession;
use BinktermPHP\TelnetServer\SysopChatEventHandler;
use BinktermPHP\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * M1C — the composite realtime dispatch inside `pumpRealtimeAndCheckSession()`
 * that fans one poll out to both `SessionKickHandler` and
 * `SysopChatEventHandler`. Drives the real (private) pump seam over a socket
 * pair, in the BbsSessionKickIntegrationTest style. DB-backed; skips with no
 * database.
 *
 * Critical regression gate (S0/M1C Step 14): `session.kick` must keep working
 * identically whether or not a SysOp Chat page is active.
 */
final class BbsSessionSysopChatIntegrationTest extends TestCase
{
    /** @var resource */ private $srv;
    /** @var resource */ private $cli;
    private BbsSession $bbs;
    private \PDO $pdo;
    private int $userId;

    protected function setUp(): void
    {
        try {
            $this->pdo = TestDatabase::pdo();
        } catch (\Throwable $e) {
            self::markTestSkipped('database not available: ' . $e->getMessage());
        }
        Database::setInstanceForTesting($this->pdo);

        $this->pdo->beginTransaction();
        $this->userId = $this->newUser();

        [$this->srv, $this->cli] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        stream_set_blocking($this->srv, false);
        stream_set_blocking($this->cli, false);

        $this->bbs = new BbsSession($this->srv, 'http://127.0.0.1:9', false, false, false, false);
    }

    protected function tearDown(): void
    {
        // A test may have already closed one end itself (e.g. to simulate a
        // disconnect) -- PHP 8's fclose() throws a TypeError on an
        // already-invalid resource rather than just warning, and @ does not
        // suppress a TypeError, so guard explicitly rather than relying on it.
        if (is_resource($this->srv)) {
            @fclose($this->srv);
        }
        if (is_resource($this->cli)) {
            @fclose($this->cli);
        }
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        Database::resetInstanceForTesting();
    }

    private function newUser(): int
    {
        $s = strtolower(bin2hex(random_bytes(6)));
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (username, password_hash, real_name, is_active)
             VALUES (?, ?, ?, TRUE) RETURNING id'
        );
        $stmt->execute(["sysopint_$s", password_hash('x', PASSWORD_DEFAULT), "Sysop Int $s"]);
        return (int) $stmt->fetch(\PDO::FETCH_ASSOC)['id'];
    }

    private function newSession(): string
    {
        $sid = 'sysopint_' . bin2hex(random_bytes(16));
        $stmt = $this->pdo->prepare(
            "INSERT INTO user_sessions (session_id, user_id, expires_at, service, last_activity)
             VALUES (?, ?, NOW() + INTERVAL '1 hour', 'telnet', NOW())"
        );
        $stmt->execute([$sid, $this->userId]);
        return $sid;
    }

    private function state(): array
    {
        return [
            'input_echo' => true, 'cols' => 80, 'rows' => 24, 'locale' => 'en', 'pushback' => '',
            'user_id' => $this->userId, 'username' => 'sysopint', 'is_admin' => false,
            'last_activity' => time(), 'idle_warned' => false,
            'idle_warning_timeout' => 300, 'idle_disconnect_timeout' => 420,
        ];
    }

    private function beginWatch(string $sessionId): void
    {
        $m = new \ReflectionMethod(BbsSession::class, 'beginSessionKickWatch');
        $m->setAccessible(true);
        $m->invoke($this->bbs, $sessionId, $this->userId, false);
    }

    private function newAdmin(): int
    {
        $s = strtolower(bin2hex(random_bytes(6)));
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (username, password_hash, real_name, is_active, is_admin)
             VALUES (?, ?, ?, TRUE, TRUE) RETURNING id'
        );
        $stmt->execute(["sysopint_admin_$s", password_hash('x', PASSWORD_DEFAULT), "Sysop Int Admin $s"]);
        return (int) $stmt->fetch(\PDO::FETCH_ASSOC)['id'];
    }

    /** Invoke the private runSysopChatModal() over the real socket pair. */
    private function runChatModal(array &$state, int $pageId): void
    {
        $m = new \ReflectionMethod(BbsSession::class, 'runSysopChatModal');
        $m->setAccessible(true);
        $m->invokeArgs($this->bbs, [$this->srv, &$state, $pageId]);
    }

    private function pump(array &$state): bool
    {
        $m = new \ReflectionMethod(BbsSession::class, 'pumpRealtimeAndCheckSession');
        $m->setAccessible(true);
        return (bool) $m->invokeArgs($this->bbs, [$this->srv, &$state]);
    }

    private function sysopChatHandler(): SysopChatEventHandler
    {
        $p = new \ReflectionProperty(BbsSession::class, 'sysopChatEventHandler');
        $p->setAccessible(true);
        return $p->getValue($this->bbs);
    }

    private function drain(): string
    {
        $out = '';
        while (($chunk = @fread($this->cli, 8192)) !== false && $chunk !== '') {
            $out .= $chunk;
        }
        return preg_replace('/\033\[[0-9;?]*[A-Za-z]/', '', $out) ?? $out;
    }

    /** Like drain(), but keeps ANSI escape sequences intact. */
    private function drainRaw(): string
    {
        $out = '';
        while (($chunk = @fread($this->cli, 8192)) !== false && $chunk !== '') {
            $out .= $chunk;
        }
        return $out;
    }

    private function emitKick(string $sessionId, string $code): void
    {
        BinkStream::emit($this->pdo, 'session.kick', ['session_id' => $sessionId, 'code' => $code], $this->userId);
    }

    private function emitSysopChat(string $type, int $pageId): void
    {
        BinkStream::emit($this->pdo, $type, ['page_id' => $pageId], $this->userId);
    }

    // -------------------------------------------------------------------
    // Dispatch
    // -------------------------------------------------------------------

    public function testAcceptedEventForTheExpectedPageReachesTheHandler(): void
    {
        $sid = $this->newSession();
        $this->beginWatch($sid);
        $this->sysopChatHandler()->setExpectedPageId(7);
        $this->emitSysopChat('sysop_chat.accepted', 7);

        $state = $this->state();
        self::assertFalse($this->pump($state), 'a sysop_chat event alone never terminates the session');
        self::assertSame('sysop_chat.accepted', $this->sysopChatHandler()->takePendingTransition());
    }

    public function testMessageEventSetsTheNewMessageFlag(): void
    {
        $sid = $this->newSession();
        $this->beginWatch($sid);
        $this->sysopChatHandler()->setExpectedPageId(7);
        $this->emitSysopChat('sysop_chat.message', 7);

        $state = $this->state();
        self::assertFalse($this->pump($state));
        self::assertTrue($this->sysopChatHandler()->takeHasNewMessage());
    }

    public function testEventForAnotherPageIsIgnoredByTheHandler(): void
    {
        $sid = $this->newSession();
        $this->beginWatch($sid);
        $this->sysopChatHandler()->setExpectedPageId(7);
        $this->emitSysopChat('sysop_chat.accepted', 999);

        $state = $this->state();
        self::assertFalse($this->pump($state));
        self::assertNull($this->sysopChatHandler()->takePendingTransition());
    }

    public function testUnrelatedEventDoesNotReachTheSysopChatHandler(): void
    {
        $sid = $this->newSession();
        $this->beginWatch($sid);
        $this->sysopChatHandler()->setExpectedPageId(7);
        BinkStream::emit($this->pdo, 'dashboard_stats', [], $this->userId);

        $state = $this->state();
        self::assertFalse($this->pump($state));
        self::assertNull($this->sysopChatHandler()->takePendingTransition());
        self::assertFalse($this->sysopChatHandler()->takeHasNewMessage());
    }

    // -------------------------------------------------------------------
    // session.kick coexistence (critical regression gate — Step 14)
    // -------------------------------------------------------------------

    public function testKickStillTerminatesWhileAPageIsActive(): void
    {
        $sid = $this->newSession();
        $this->beginWatch($sid);
        $this->sysopChatHandler()->setExpectedPageId(7);
        $this->emitKick($sid, ActiveSessionService::CODE_REVOKED);

        $state = $this->state();
        self::assertTrue($this->pump($state), 'a kick still terminates the session during an active page/chat');
        self::assertStringContainsString('signed out from another device', $this->drain());
    }

    public function testKickAndSysopChatEventInTheSameBatchBothDispatchKickWins(): void
    {
        $sid = $this->newSession();
        $this->beginWatch($sid);
        $this->sysopChatHandler()->setExpectedPageId(7);

        // Same batch: a sysop_chat event followed by a kick.
        $this->emitSysopChat('sysop_chat.accepted', 7);
        $this->emitKick($sid, ActiveSessionService::CODE_REVOKED);

        $state = $this->state();
        self::assertTrue($this->pump($state), 'kick termination wins the pump result');
        self::assertStringContainsString('signed out from another device', $this->drain());
        // The sysop_chat event was still dispatched to its own handler in the
        // same pass — kick winning the pump's return value does not starve it.
        self::assertSame('sysop_chat.accepted', $this->sysopChatHandler()->takePendingTransition());
    }

    public function testReadKeyWithTimeoutStillSurfacesKickWithSysopChatHandlerWired(): void
    {
        $sid = $this->newSession();
        $this->beginWatch($sid);
        $this->sysopChatHandler()->setExpectedPageId(7);
        $this->emitKick($sid, ActiveSessionService::CODE_REVOKED);

        $state = $this->state();
        [$key, $timedOut, $shouldDisconnect] = $this->bbs->readKeyWithTimeout($this->srv, $state, 50);

        self::assertNull($key);
        self::assertTrue($shouldDisconnect);
    }

    public function testNoEventLeavesNormalInputUndisturbedWithSysopChatHandlerWired(): void
    {
        $sid = $this->newSession();
        $this->beginWatch($sid);
        $this->sysopChatHandler()->setExpectedPageId(7);

        $state = $this->state();
        self::assertFalse($this->pump($state));

        fwrite($this->cli, 'x');
        fflush($this->cli);
        [$key, $timedOut, $shouldDisconnect] = $this->bbs->readKeyWithTimeout($this->srv, $state, 200);
        self::assertFalse($shouldDisconnect);
        self::assertSame('CHAR:x', $key);
    }

    // -------------------------------------------------------------------
    // M1D blocker regression — the real disconnect/fallback failure mode
    // ---------------------------------------------------------------------
    // Root cause (see docs/SysopChat/M1C.md's M1D blocker note and the real
    // production stack trace it records): runSysopChatModal()'s catch-up
    // closure referenced $locale without capturing it in its `use (...)`
    // list, so every render of an existing message threw
    // "TypeError: ...renderSysopChatLine(): Argument #4 ($locale) must be
    // of type string, null given" the instant the modal was entered with at
    // least one message already on the page (which is unconditional on
    // every entry, including every re-entry into an already-ACCEPTED page).
    // Uncaught from the legacy menu (no surrounding try/catch there) this
    // was a hard PHP fatal error -- a real disconnect. Caught by
    // DeclarativeMenuBridge's own catch-all, it silently fell the *entire
    // remaining declarative session* back to the legacy menu as a side
    // effect -- not a genuine declarative/legacy control-flow
    // incompatibility, just this one bug surfacing differently per caller.

    public function testEnteringChatWithAnExistingMessageDoesNotThrow(): void
    {
        $adminId = $this->newAdmin();
        $pageSvc = new SysopChatService($this->pdo);
        $msgSvc = new SysopChatMessageService($this->pdo);

        $sid = $this->newSession();
        $this->beginWatch($sid);

        $page = $pageSvc->createPage($this->userId, 'telnet', $sid);
        self::assertNotNull($page);
        $accepted = $pageSvc->acceptPage($page['id'], $adminId);
        self::assertNotNull($accepted);
        // A message already exists before the caller's modal is (re-)entered
        // -- exactly the condition that crashed in production: the modal's
        // unconditional catch-up render on entry hits this row immediately.
        self::assertNotNull($msgSvc->sendMessage($page['id'], $adminId, 'Hey there.'));

        // Simulate the client having gone away: runSysopChatModal() must get
        // through its catch-up render (the vulnerable call) BEFORE it ever
        // reaches the read loop that would notice this and return.
        @fclose($this->cli);

        $state = $this->state();
        $threw = null;
        try {
            $this->runChatModal($state, (int) $page['id']);
        } catch (\Throwable $e) {
            $threw = $e;
        }

        self::assertNull(
            $threw,
            $threw !== null ? ('runSysopChatModal() threw: ' . $threw->getMessage()) : ''
        );

        // The disconnect branch still ran to completion afterward (best-effort
        // completePage) -- proof the call actually reached and passed the
        // catch-up render, not that the read loop was skipped entirely.
        $status = $this->pdo
            ->query('SELECT status FROM sysop_pages WHERE id = ' . (int) $page['id'])
            ->fetchColumn();
        self::assertSame('completed', $status);
    }

    public function testRunPageSysopFlowNeverLetsAnExceptionEscape(): void
    {
        // Defense in depth (the fix added alongside the root-cause fix):
        // runPageSysopFlow() itself must never let *any* exception propagate,
        // regardless of cause -- neither caller (the legacy menu, no
        // try/catch of its own; DeclarativeMenuBridge, whose catch-all drops
        // the *whole remaining session* into the legacy menu as a side
        // effect) may ever observe one from this flow.
        $adminId = $this->newAdmin();
        $pageSvc = new SysopChatService($this->pdo);
        $msgSvc = new SysopChatMessageService($this->pdo);

        $sid = $this->newSession();
        $this->beginWatch($sid);

        $page = $pageSvc->createPage($this->userId, 'telnet', $sid);
        $accepted = $pageSvc->acceptPage($page['id'], $adminId);
        self::assertNotNull($accepted);
        self::assertNotNull($msgSvc->sendMessage($page['id'], $adminId, 'Hey there.'));

        @fclose($this->cli);

        $state = $this->state();
        $m = new \ReflectionMethod(BbsSession::class, 'runPageSysopFlow');
        $m->setAccessible(true);

        $threw = null;
        try {
            $m->invokeArgs($this->bbs, [$this->srv, &$state, $sid]);
        } catch (\Throwable $e) {
            $threw = $e;
        }

        self::assertNull($threw, $threw !== null ? $threw->getMessage() : '');
    }

    // -------------------------------------------------------------------
    // M1D human acceptance — visual wart: "> messageYou: message" run-together
    // -------------------------------------------------------------------
    // redrawSysopChatInput() deliberately never terminates the live input-echo
    // line with a newline (so typing keeps redrawing in place). Before this
    // fix, renderSysopChatLine() wrote its "You: "/"SysOp: " line straight
    // after that dangling line with no separator -- concatenating onto
    // whatever the input echo currently showed instead of starting a fresh
    // line. renderSysopChatLine() now clears the current line first.

    public function testRenderedMessageClearsTheDanglingInputLineFirst(): void
    {
        $adminId = $this->newAdmin();
        $pageSvc = new SysopChatService($this->pdo);
        $msgSvc = new SysopChatMessageService($this->pdo);

        $sid = $this->newSession();
        $this->beginWatch($sid);

        $page = $pageSvc->createPage($this->userId, 'telnet', $sid);
        self::assertNotNull($page);
        self::assertNotNull($pageSvc->acceptPage($page['id'], $adminId));
        self::assertNotNull($msgSvc->sendMessage($page['id'], $adminId, 'Hey there.'));

        // Let the loop see one real key (ESC) immediately so it runs the
        // catch-up render, then exits cleanly on its own -- no forced
        // disconnect, so the socket stays open and every written byte,
        // including the raw ANSI sequences, is still readable afterward.
        fwrite($this->cli, "\x1b");
        fflush($this->cli);

        $state = $this->state();
        $this->runChatModal($state, (int) $page['id']);

        $raw = $this->drainRaw();

        // The catch-up render of the existing message must clear the current
        // line (\r\033[K) immediately before the rendered "SysOp: " line --
        // not simply append to whatever was there.
        self::assertStringContainsString("\r\033[K" . "SysOp: Hey there.", $raw);
        // And never the run-together artifact this fix targets.
        self::assertStringNotContainsString('Hey there.SysOp:', $raw);
    }
}
