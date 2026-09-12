<?php

declare(strict_types=1);

require_once __DIR__ . '/Support/TestDatabase.php';
require_once __DIR__ . '/../../telnet/src/OutputSink.php';
require_once __DIR__ . '/../../telnet/src/BufferSink.php';
require_once __DIR__ . '/../../telnet/src/SocketSink.php';
require_once __DIR__ . '/../../telnet/src/GlyphPolicy.php';
require_once __DIR__ . '/../../telnet/src/TerminalCapabilities.php';
require_once __DIR__ . '/../../telnet/src/TerminalRenderContext.php';
require_once __DIR__ . '/../../telnet/src/TelnetUtils.php';
require_once __DIR__ . '/../../telnet/src/TerminalMarkupRenderer.php';
require_once __DIR__ . '/../../telnet/src/TerminalLineEditor.php';
require_once __DIR__ . '/../../telnet/src/TerminalLineHistory.php';
require_once __DIR__ . '/../../telnet/src/TerminalBoxRenderer.php';
require_once __DIR__ . '/../../telnet/src/BbsSession.php';
require_once __DIR__ . '/../../telnet/src/MrcChatHandler.php';

use BinktermPHP\Mrc\MrcChatService;
use BinktermPHP\TelnetServer\BbsSession;
use BinktermPHP\TelnetServer\MrcChatHandler;
use BinktermPHP\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * MRC Terminal Convergence M1B — read-only proof.
 *
 * Two levels of coverage:
 *
 *  1. Pure formatting/orchestration, exercised via Reflection on the
 *     private helper methods (the class's only public surface is show(),
 *     which drives a real interactive terminal loop via the shared
 *     TerminalShellInterface widgets -- those widgets, and their own
 *     geometry/exit-key behaviour, are pre-existing and already used in
 *     production by ChatHandler; this slice does not re-test them).
 *
 *  2. The read-only guarantee itself: the exact service calls M1B's
 *     read-only flow makes (status, room list, users, recent messages)
 *     produce zero rows in mrc_outbound / mrc_local_presence /
 *     mrc_local_handles -- run against the isolated binktermphp_test
 *     database inside a transaction that is always rolled back, never
 *     production, and no MRC network traffic is sent (mrc_outbound is only
 *     ever drained by the daemon, never touched here).
 */
final class MrcChatHandlerTest extends TestCase
{
    private \PDO $pdo;
    private MrcChatService $mrc;
    private MrcChatHandler $handler;

    protected function setUp(): void
    {
        try {
            $this->pdo = TestDatabase::pdo();
        } catch (\Throwable $e) {
            self::markTestSkipped('database not available: ' . $e->getMessage());
        }

        $this->pdo->beginTransaction();
        $this->mrc = new MrcChatService($this->pdo);

        $conn = fopen('php://memory', 'r+');
        $bbs = new BbsSession($conn, 'http://127.0.0.1', false, false, false, false);
        $this->handler = new MrcChatHandler($bbs, $this->mrc);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /** @return mixed */
    private function call(string $method, array $args = [])
    {
        $ref = new \ReflectionMethod(MrcChatHandler::class, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($this->handler, $args);
    }

    private function writeRowCounts(): array
    {
        return [
            'outbound'       => (int)$this->pdo->query('SELECT COUNT(*) FROM mrc_outbound')->fetchColumn(),
            'local_presence' => (int)$this->pdo->query('SELECT COUNT(*) FROM mrc_local_presence')->fetchColumn(),
            'local_handles'  => (int)$this->pdo->query('SELECT COUNT(*) FROM mrc_local_handles')->fetchColumn(),
        ];
    }

    // ===== 1 & 2: connection status renders clearly =====

    public function testDisconnectedStatusRendersClearly(): void
    {
        $title = $this->call('panelTitle', ['lobby', ['connected' => false]]);
        self::assertStringContainsString('Disconnected', $title);
        self::assertStringContainsString('#lobby', $title);
    }

    public function testConnectedStatusRendersClearly(): void
    {
        $title = $this->call('panelTitle', ['lobby', ['connected' => true]]);
        self::assertStringContainsString('Connected', $title);
        self::assertStringNotContainsString('Disconnected', $title);
    }

    // ===== 3: room list renders from service data =====

    public function testPickRoomFormatsRoomsFromServiceData(): void
    {
        $this->pdo->prepare("
            INSERT INTO mrc_rooms (room_name, topic, last_activity)
            VALUES ('m1btest', 'Test topic', CURRENT_TIMESTAMP)
        ")->execute();

        $rooms = $this->mrc->getRoomList();
        $names = array_column($rooms, 'room_name');
        self::assertContains('m1btest', $names);

        // The item-formatting logic used inside pickRoom() (extracted here to
        // avoid driving the real interactive chooseFromList() loop).
        $room = $rooms[array_search('m1btest', $names, true)];
        $label = '#' . $room['room_name'] . ' (' . (int)($room['user_count'] ?? 0) . ')';
        $topic = trim((string)($room['topic'] ?? ''));
        $formatted = $topic !== '' ? $label . ' — ' . $topic : $label;
        self::assertSame('#m1btest (0) — Test topic', $formatted);
    }

    // ===== 4 & 8: read-only guarantee — no write operations occur =====

    public function testReadOnlyFlowQueuesNoOutboundOrPresenceRows(): void
    {
        $room = 'm1btest_ro';
        $this->pdo->prepare("INSERT INTO mrc_rooms (room_name, last_activity) VALUES (:r, CURRENT_TIMESTAMP)")
            ->execute(['r' => $room]);
        $this->pdo->prepare("
            INSERT INTO mrc_messages (from_user, from_site, from_room, to_room, message_body, is_private)
            VALUES ('someone', 'othersite', :r, :r, 'hello', false)
        ")->execute(['r' => $room]);

        $before = $this->writeRowCounts();

        // Exactly the service calls MrcChatHandler::show()'s read-only loop
        // makes for one pass: status, room list (pickRoom), users + recent
        // messages (the transcript view). No connect/join/send/heartbeat.
        $this->mrc->getStatus();
        $this->mrc->getRoomList();
        $this->mrc->getUsers($room);
        $messages = $this->mrc->getRecentRoomMessages($room, 100);

        $after = $this->writeRowCounts();

        self::assertSame($before, $after, 'a read-only MRC view must not create any outbound/presence/handle rows');
        self::assertNotEmpty($messages);
    }

    // ===== 5: recent room messages render in order =====

    public function testRecentMessagesFormatOldestFirst(): void
    {
        $room = 'm1btest_order';
        $stmt = $this->pdo->prepare("
            INSERT INTO mrc_messages (from_user, from_site, from_room, to_room, message_body, is_private, received_at)
            VALUES (:u, 'site', :r, :r, :b, false, :t)
        ");
        $stmt->execute(['u' => 'alice', 'r' => $room, 'b' => 'first',  't' => '2026-01-01 10:00:00']);
        $stmt->execute(['u' => 'bob',   'r' => $room, 'b' => 'second', 't' => '2026-01-01 10:00:05']);

        $messages = $this->mrc->getRecentRoomMessages($room, 100);
        $lines = $this->call('formatTranscript', [$messages]);

        self::assertCount(2, $lines);
        self::assertStringContainsString('<alice> first', $lines[0]);
        self::assertStringContainsString('<bob> second', $lines[1]);
    }

    public function testTranscriptStripsMrcPipeColourCodes(): void
    {
        $stripped = $this->call('stripMrcPipeCodes', ['|03<|02skrawl|03> hello']);
        self::assertSame('<skrawl> hello', $stripped);
    }

    public function testEmptyTranscriptShowsPlaceholder(): void
    {
        $lines = $this->call('formatTranscript', [[]]);
        self::assertSame(['(no recent messages in this room)'], $lines);
    }

    // ===== 6: user list/count renders correctly =====

    public function testStatusLineReflectsUserCount(): void
    {
        self::assertSame('0 user(s) — R Rooms  U Users  Q Back', $this->call('statusLine', [0]));
        self::assertSame('3 user(s) — R Rooms  U Users  Q Back', $this->call('statusLine', [3]));
    }

    public function testUsersRenderFromServiceData(): void
    {
        $room = 'm1btest_users';
        $this->pdo->prepare("INSERT INTO mrc_rooms (room_name, last_activity) VALUES (:r, CURRENT_TIMESTAMP)")
            ->execute(['r' => $room]);
        $this->pdo->prepare("
            INSERT INTO mrc_local_presence (user_id, username, bbs_name, room_name, last_seen)
            VALUES (NULL, 'skrawl', 'L33Test', :r, CURRENT_TIMESTAMP)
        ")->execute(['r' => $room]);

        $users = $this->mrc->getUsers($room);
        self::assertCount(1, $users);
        self::assertSame('skrawl', $users[0]['username']);
    }
}
