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

use BinktermPHP\Database;
use BinktermPHP\TelnetServer\BbsSession;
use PHPUnit\Framework\TestCase;

/**
 * Item 6 — managed-door relay session-liveness guard.
 *
 * DoorHandler::relayLoop() has its own byte-shuttle loop that never calls
 * pumpRealtimeAndCheckSession(), so a caller whose BBS session is revoked
 * while inside a door stays connected unless the kick cascade closes the
 * bridge socket. BbsSession::authSessionStillValid() is the cheap read the
 * relay loop now polls; relayLoop breaks when it returns false.
 */
final class DoorRelayLivenessTest extends TestCase
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
            self::markTestSkipped('database not available: ' . $e->getMessage());
        }
        $this->pdo->beginTransaction();

        $s = strtolower(bin2hex(random_bytes(6)));
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (username, password_hash, real_name, is_active)
             VALUES (?, ?, ?, TRUE) RETURNING id'
        );
        $stmt->execute(["relaylive_$s", password_hash('x', PASSWORD_DEFAULT), "Relay Live $s"]);
        $this->userId = (int) $stmt->fetch(\PDO::FETCH_ASSOC)['id'];

        [$this->srv, $this->cli] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
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

    private function newSession(): string
    {
        $sid = 'relaylive_' . bin2hex(random_bytes(16));
        $this->pdo->prepare(
            "INSERT INTO user_sessions (session_id, user_id, expires_at, service, last_activity)
             VALUES (?, ?, NOW() + INTERVAL '1 hour', 'telnet', NOW())"
        )->execute([$sid, $this->userId]);
        return $sid;
    }

    private function beginWatch(string $sessionId): void
    {
        $m = new \ReflectionMethod(BbsSession::class, 'beginSessionKickWatch');
        $m->setAccessible(true);
        $m->invoke($this->bbs, $sessionId, $this->userId, false);
    }

    public function testPreAuthSessionIsAlwaysConsideredValid(): void
    {
        // No beginWatch() called — authSessionId is null.
        self::assertTrue($this->bbs->authSessionStillValid());
    }

    public function testValidSessionReportsValid(): void
    {
        $this->beginWatch($this->newSession());
        self::assertTrue($this->bbs->authSessionStillValid());
    }

    public function testRevokedSessionReportsInvalid(): void
    {
        $sid = $this->newSession();
        $this->beginWatch($sid);
        $this->pdo->prepare('DELETE FROM user_sessions WHERE session_id = ?')->execute([$sid]);
        self::assertFalse($this->bbs->authSessionStillValid());
    }

    public function testExpiredSessionReportsInvalid(): void
    {
        $sid = $this->newSession();
        $this->beginWatch($sid);
        $this->pdo->prepare(
            "UPDATE user_sessions SET expires_at = NOW() - INTERVAL '1 minute' WHERE session_id = ?"
        )->execute([$sid]);
        self::assertFalse($this->bbs->authSessionStillValid());
    }

    public function testTerminatedSessionReportsInvalidWithoutQuerying(): void
    {
        $sid = $this->newSession();
        $this->beginWatch($sid);

        $p = new \ReflectionProperty(BbsSession::class, 'sessionTerminated');
        $p->setAccessible(true);
        $p->setValue($this->bbs, true);

        self::assertFalse($this->bbs->authSessionStillValid());
    }

    public function testRelayLoopPollsTheLivenessGuardOnAThrottle(): void
    {
        $src = file_get_contents(__DIR__ . '/../../telnet/src/DoorHandler.php');
        self::assertIsString($src);

        $start = strpos($src, 'private function relayLoop(');
        self::assertNotFalse($start);
        $end = strpos($src, 'private function ', $start + 10);
        $body = substr($src, $start, $end - $start);

        self::assertStringContainsString('authSessionStillValid()', $body, 'relayLoop must consult the guard');
        self::assertStringContainsString('$lastLivenessCheckAt', $body, 'the check must be wall-clock throttled');
        self::assertMatchesRegularExpression(
            '/if\s*\(\s*!\s*\$this->server->authSessionStillValid\(\)\s*\)\s*\{\s*break;/',
            $body,
            'an invalid session must break the relay loop'
        );
    }
}
