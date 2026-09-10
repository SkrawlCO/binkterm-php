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
require_once __DIR__ . '/../../telnet/src/BbsSession.php';
require_once __DIR__ . '/../../telnet/src/TerminalLineEditor.php';
require_once __DIR__ . '/../../telnet/src/TerminalLineHistory.php';

use BinktermPHP\Database;
use BinktermPHP\Realtime\BinkStream;
use BinktermPHP\Security\ActiveSessionService;
use BinktermPHP\TelnetServer\BbsSession;
use PHPUnit\Framework\TestCase;

/**
 * Slice A — the revocation-actually-disconnects wiring inside BbsSession.
 *
 * Drives the real (private) pump seam and the real `readKeyWithTimeout()`
 * primitive over a socket pair, in the FileSearchTerminalTest / FileAreaDenseListTest
 * style. DB-backed for the poller + validity net; skips with no database.
 */
final class BbsSessionKickIntegrationTest extends TestCase
{
    /** @var resource */ private $srv;
    /** @var resource */ private $cli;
    private BbsSession $bbs;
    private \PDO $pdo;
    private int $userId;

    protected function setUp(): void
    {
        try {
            $this->pdo = Database::getInstance()->getPdo();
            $this->pdo->query('SELECT 1');
        } catch (\Throwable $e) {
            try {
                $this->pdo = Database::reconnect()->getPdo();
                $this->pdo->query('SELECT 1');
            } catch (\Throwable $e2) {
                self::markTestSkipped('database not available: ' . $e2->getMessage());
            }
        }
        $this->pdo->beginTransaction();
        $this->userId = $this->newUser();

        [$this->srv, $this->cli] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        stream_set_blocking($this->srv, false);
        stream_set_blocking($this->cli, false);

        // A dead API base so the best-effort logout on termination is a no-op.
        $this->bbs = new BbsSession($this->srv, 'http://127.0.0.1:9', false, false, false, false);
    }

    protected function tearDown(): void
    {
        @fclose($this->srv);
        @fclose($this->cli);
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function newUser(): int
    {
        $s = strtolower(bin2hex(random_bytes(6)));
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (username, password_hash, real_name, is_active)
             VALUES (?, ?, ?, TRUE) RETURNING id'
        );
        $stmt->execute(["kickint_$s", password_hash('x', PASSWORD_DEFAULT), "Kick Int $s"]);
        return (int) $stmt->fetch(\PDO::FETCH_ASSOC)['id'];
    }

    private function newSession(): string
    {
        $sid = 'kickint_' . bin2hex(random_bytes(16));
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
            'user_id' => $this->userId, 'username' => 'kickint', 'is_admin' => false,
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

    private function pump(array &$state): bool
    {
        $m = new \ReflectionMethod(BbsSession::class, 'pumpRealtimeAndCheckSession');
        $m->setAccessible(true);
        return (bool) $m->invokeArgs($this->bbs, [$this->srv, &$state]);
    }

    private function setPrivate(string $prop, $value): void
    {
        $p = new \ReflectionProperty(BbsSession::class, $prop);
        $p->setAccessible(true);
        $p->setValue($this->bbs, $value);
    }

    private function drain(): string
    {
        $out = '';
        while (($chunk = @fread($this->cli, 8192)) !== false && $chunk !== '') {
            $out .= $chunk;
        }
        return preg_replace('/\033\[[0-9;?]*[A-Za-z]/', '', $out) ?? $out;
    }

    private function emitKick(string $sessionId, string $code): void
    {
        BinkStream::emit($this->pdo, 'session.kick', ['session_id' => $sessionId, 'code' => $code], $this->userId);
    }

    public function testMatchingKickTerminatesAndWritesTheLocalizedMessage(): void
    {
        $sid = $this->newSession();
        $this->beginWatch($sid);
        $this->emitKick($sid, ActiveSessionService::CODE_REVOKED);

        $state = $this->state();
        self::assertTrue($this->pump($state), 'a matching kick terminates the session');
        self::assertStringContainsString('signed out from another device', $this->drain());
    }

    public function testReadPrimitiveInvokesThePumpAndDisconnectsOnKick(): void
    {
        $sid = $this->newSession();
        $this->beginWatch($sid);
        $this->emitKick($sid, ActiveSessionService::CODE_REVOKED);

        $state = $this->state();
        [$key, $timedOut, $shouldDisconnect] = $this->bbs->readKeyWithTimeout($this->srv, $state, 50);

        self::assertNull($key);
        self::assertTrue($shouldDisconnect, 'readKeyWithTimeout surfaces the kick as a disconnect');
    }

    public function testKickForADifferentSessionOfTheSameUserIsIgnored(): void
    {
        $sid = $this->newSession();
        $this->beginWatch($sid);
        $this->emitKick('kickint_some_other_session', ActiveSessionService::CODE_REVOKED);

        $state = $this->state();
        self::assertFalse($this->pump($state));
        self::assertSame('', trim($this->drain()));
    }

    public function testNoEventLeavesNormalInputUndisturbed(): void
    {
        $sid = $this->newSession();
        $this->beginWatch($sid);

        $state = $this->state();
        self::assertFalse($this->pump($state));

        // A real keystroke still reads through cleanly.
        fwrite($this->cli, 'x');
        fflush($this->cli);
        [$key, $timedOut, $shouldDisconnect] = $this->bbs->readKeyWithTimeout($this->srv, $state, 200);
        self::assertFalse($shouldDisconnect);
        self::assertSame('CHAR:x', $key);
    }

    public function testValidityNetIsTimeThrottledNotPerCall(): void
    {
        $sid = $this->newSession();
        $this->beginWatch($sid);

        // Invalidate the session directly, with no event on the bus.
        $this->pdo->prepare('DELETE FROM user_sessions WHERE session_id = ?')->execute([$sid]);

        // beginWatch stamped lastSessionValidityCheckAt = now -> not due yet.
        $state = $this->state();
        self::assertFalse($this->pump($state), 'validity net does not run on every call');

        // Age the stamp past the interval -> the net fires.
        $this->setPrivate('lastSessionValidityCheckAt', microtime(true) - 61.0);
        self::assertTrue($this->pump($state), 'an invalid session is eventually terminated without an event');
        self::assertStringContainsString('session has ended', $this->drain());
    }

    public function testTerminationIsHandledOnceNoDuplicateMessage(): void
    {
        $sid = $this->newSession();
        $this->beginWatch($sid);
        $this->emitKick($sid, ActiveSessionService::CODE_REVOKED);

        $state = $this->state();
        self::assertTrue($this->pump($state));
        $first = $this->drain();
        self::assertStringContainsString('signed out from another device', $first);

        // Subsequent pumps keep signalling disconnect but never re-write.
        self::assertTrue($this->pump($state));
        self::assertSame('', trim($this->drain()));
    }
}
