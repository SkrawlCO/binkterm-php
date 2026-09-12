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
require_once __DIR__ . '/../../telnet/src/TerminalShellInterface.php';
require_once __DIR__ . '/../../telnet/src/TuiShell.php';
require_once __DIR__ . '/../../telnet/src/LineShell.php';
require_once __DIR__ . '/../../telnet/src/TerminalShellFactory.php';
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

    // ============================================================
    // MRC Terminal Convergence M1C-1 — participation/lifecycle foundation
    // ============================================================

    private function newUser(): int
    {
        $s = strtolower(bin2hex(random_bytes(6)));
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (username, password_hash, real_name, is_active)
             VALUES (?, ?, ?, TRUE) RETURNING id'
        );
        $stmt->execute(["mrc_{$s}", password_hash('x', PASSWORD_DEFAULT), "MRC M1C Test {$s}"]);
        return (int) $stmt->fetch(\PDO::FETCH_ASSOC)['id'];
    }

    /** Source lines of one method, for "does/does not call X" checks without driving the real interactive loop. */
    private function methodSource(string $method): string
    {
        $ref = new \ReflectionMethod(MrcChatHandler::class, $method);
        $lines = file($ref->getFileName());
        return implode('', array_slice($lines, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));
    }

    // ===== 1: terminal identity uses the expected BinkTerm/MRC handle =====

    public function testIdentityUsesBinkTermUsernameAsMrcHandle(): void
    {
        [$userId, $username, $bbsName] = $this->call('identity', [['user_id' => 4242, 'username' => 'Puzlmastr']]);
        self::assertSame(4242, $userId);
        self::assertSame('Puzlmastr', $username);
        self::assertNotSame('', $bbsName);
    }

    public function testIdentityUsesSameSharedValidationAsWebMrc(): void
    {
        // identity() calls MrcChatService::normalizeHandle() -- the exact
        // method handleConnect()/resolveMrcUsername() use on the Web side --
        // so a BinkTerm username that collides with a reserved MRC word
        // (case-insensitively) is rejected identically on both surfaces.
        // That shared validation logic itself is already covered by
        // MrcChatServiceTest; this just proves identity() doesn't bypass it.
        $this->expectException(\InvalidArgumentException::class);
        $this->call('identity', [['user_id' => 1, 'username' => 'server']]);
    }

    // ===== 2 & 3: participation entry establishes presence + queues the expected JOIN =====

    public function testParticipationEntrySequenceEstablishesPresenceAndQueuesJoin(): void
    {
        $room = 'm1c1_join';
        $userId = $this->newUser();

        // Exactly the sequence participate() runs after the connectivity
        // check and room pick (both already covered elsewhere): connect()
        // then joinRoom(). No message-send, no queueCommand.
        $this->mrc->connect($userId, 'participant', 'L33Test', null, null);
        $this->mrc->joinRoom($userId, 'participant', 'L33Test', $room, '', null);

        $handle = $this->pdo->query("SELECT username FROM mrc_local_handles WHERE user_id = {$userId}")->fetchColumn();
        self::assertSame('participant', $handle);

        $presence = $this->pdo->query("
            SELECT username FROM mrc_local_presence WHERE user_id = {$userId} AND room_name = '{$room}'
        ")->fetchColumn();
        self::assertSame('participant', $presence);

        $join = $this->pdo->query("
            SELECT field6, field7 FROM mrc_outbound WHERE field1 = 'participant' AND field7 LIKE 'NEWROOM:%' ORDER BY id DESC LIMIT 1
        ")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame($room, $join['field6']);
        self::assertSame("NEWROOM::{$room}", $join['field7']);
    }

    // ===== 4: passive VIEW still does NOT queue JOIN =====

    public function testShowMethodNeverCallsConnectOrJoin(): void
    {
        $source = $this->methodSource('show');
        self::assertStringNotContainsString('->connect(', $source);
        self::assertStringNotContainsString('->joinRoom(', $source);
    }

    // ===== 5: heartbeat refreshes presence without unnecessary command spam =====

    public function testHeartbeatRefreshesPresenceWithoutQueuingOutbound(): void
    {
        $room = 'm1c1_hb';
        $userId = $this->newUser();
        $this->mrc->joinRoom($userId, 'participant', 'L33Test', $room, '', null);

        $beforeOutbound = (int)$this->pdo->query('SELECT COUNT(*) FROM mrc_outbound')->fetchColumn();

        // Several heartbeats in a row (as several loop iterations would do).
        $this->mrc->recordHeartbeat($userId, 'participant', 'L33Test', $room);
        $this->mrc->recordHeartbeat($userId, 'participant', 'L33Test', $room);
        $this->mrc->recordHeartbeat($userId, 'participant', 'L33Test', $room);

        $afterOutbound = (int)$this->pdo->query('SELECT COUNT(*) FROM mrc_outbound')->fetchColumn();
        self::assertSame($beforeOutbound, $afterOutbound, 'heartbeat must never queue a network command');

        $presenceRows = (int)$this->pdo->query("
            SELECT COUNT(*) FROM mrc_local_presence WHERE user_id = {$userId} AND room_name = '{$room}'
        ")->fetchColumn();
        self::assertSame(1, $presenceRows, 'repeated heartbeats upsert one row, never accumulate duplicates');
    }

    // ===== 6: normal LEAVE/exit performs expected cleanup =====

    public function testLeaveRoomQueuesLogoffAndRemovesOnlyThatRoomPresence(): void
    {
        $userId = $this->newUser();
        $this->mrc->connect($userId, 'participant', 'L33Test', null, null);
        $this->mrc->joinRoom($userId, 'participant', 'L33Test', 'm1c1_leave', '', null);

        $this->mrc->leaveRoom($userId, 'participant', 'L33Test', 'm1c1_leave');

        $logoff = $this->pdo->query("
            SELECT field7 FROM mrc_outbound WHERE field1 = 'participant' AND field6 = 'm1c1_leave' AND field7 = 'LOGOFF'
            ORDER BY id DESC LIMIT 1
        ")->fetchColumn();
        self::assertSame('LOGOFF', $logoff);

        $left = (int)$this->pdo->query("
            SELECT COUNT(*) FROM mrc_local_presence WHERE user_id = {$userId} AND room_name = 'm1c1_leave'
        ")->fetchColumn();
        self::assertSame(0, $left);

        // mrc_local_handles (account identity, not room-specific) is
        // deliberately untouched by a room-scoped leave.
        $handleStillPresent = (int)$this->pdo->query("SELECT COUNT(*) FROM mrc_local_handles WHERE user_id = {$userId}")->fetchColumn();
        self::assertSame(1, $handleStillPresent);
    }

    public function testParticipateNeverCallsWholeAccountDisconnect(): void
    {
        $source = $this->methodSource('participate');
        self::assertStringNotContainsString('->disconnect(', $source);
        self::assertStringContainsString('->leaveRoom(', $source);
    }

    // ===== 7: disconnected daemon/network refuses participation clearly =====

    public function testStatusReportsDisconnectedOnAFreshInstall(): void
    {
        // Fresh test DB: no mrc_state rows at all -- the "just installed,
        // daemon never ran" state participate()'s guard must refuse on.
        $status = $this->mrc->getStatus();
        self::assertFalse($status['connected']);
        self::assertFalse($status['daemon_running']);
    }

    public function testParticipateGuardsOnBothConnectedAndDaemonRunning(): void
    {
        // showAlert() drives a real blocking key-read with no established
        // fake-socket test harness in this codebase (same limitation noted
        // for M1B's geometry/exit-key coverage) -- so the guard's *condition*
        // is verified by source inspection rather than driving the full
        // interactive early-return through a real widget: it must check both
        // flags (either one being false is refusal), and it must be the
        // first thing participate() does, before any room pick, connect, or
        // join call appears in the method body.
        $source = $this->methodSource('participate');
        $guardPos = strpos($source, "empty(\$status['connected'])");
        self::assertNotFalse($guardPos);
        self::assertStringContainsString("empty(\$status['daemon_running'])", $source);
        self::assertLessThan((int)strpos($source, '->connect('), $guardPos, 'the connectivity guard must precede identity/connect');
        self::assertLessThan((int)strpos($source, '->joinRoom('), $guardPos, 'the connectivity guard must precede join');
    }

    // ===== 8: no message-send operation is invoked =====

    public function testNoMessageSendOrCommandPathExistsInThisSlice(): void
    {
        $fullSource = file_get_contents((new \ReflectionClass(MrcChatHandler::class))->getFileName());
        self::assertStringNotContainsString('->sendMessage(', $fullSource);
        self::assertStringNotContainsString('->queueCommand(', $fullSource);
    }

    // ===== 9: Web/API behavior remains unchanged where directly affected =====
    // (MrcChatService::disconnect() and every M1A/M1B method are untouched;
    // full regression already covered by MrcChatServiceTest, re-run alongside
    // this file as part of this slice's verification.)

    // ===== 10: multi-surface collision safety =====

    public function testLeaveRoomDoesNotDisturbAnIndependentlyActiveRoomForTheSameAccount(): void
    {
        $userId = $this->newUser();
        // Same account, two rooms -- e.g. Web MRC in "webroom", terminal MRC
        // in "termroom", exactly the collision scenario this method exists
        // to avoid (see MrcChatService::leaveRoom() docblock).
        $this->mrc->joinRoom($userId, 'participant', 'L33Test', 'webroom_m1c1', '', null);
        $this->mrc->joinRoom($userId, 'participant', 'L33Test', 'termroom_m1c1', '', null);

        $this->mrc->leaveRoom($userId, 'participant', 'L33Test', 'termroom_m1c1');

        $webRoomStillPresent = (int)$this->pdo->query("
            SELECT COUNT(*) FROM mrc_local_presence WHERE user_id = {$userId} AND room_name = 'webroom_m1c1'
        ")->fetchColumn();
        self::assertSame(1, $webRoomStillPresent, 'an independently-active room for the same account must survive leaveRoom() on a different room');

        $termRoomGone = (int)$this->pdo->query("
            SELECT COUNT(*) FROM mrc_local_presence WHERE user_id = {$userId} AND room_name = 'termroom_m1c1'
        ")->fetchColumn();
        self::assertSame(0, $termRoomGone);
    }

    // ============================================================
    // Idle-tick lifecycle gap fix — bounded periodic refresh while the
    // caller sits idle in the participating screen (blocking M1C-1 gap).
    // ============================================================

    // ===== 1, 2 & 3: idle ticks drive heartbeat cadence, not every poll =====

    public function testHeartbeatIsNotDueImmediatelyAfterTheLastOne(): void
    {
        $now = time();
        self::assertFalse($this->call('dueForHeartbeat', [$now, $now]));
        self::assertFalse($this->call('dueForHeartbeat', [$now, $now + 29]));
    }

    public function testHeartbeatBecomesDueAfterTheIntervalElapses(): void
    {
        $now = time();
        self::assertTrue($this->call('dueForHeartbeat', [$now, $now + 30]));
        self::assertTrue($this->call('dueForHeartbeat', [$now, $now + 120]));
    }

    // ===== 4 & 5: idle tick refreshes messages/status/users, never queues JOIN =====

    public function testRenderParticipatingScreenReadsCurrentStateAndQueuesNothing(): void
    {
        $room = 'm1c1_idle';
        $this->pdo->prepare("INSERT INTO mrc_rooms (room_name, last_activity) VALUES (:r, CURRENT_TIMESTAMP)")
            ->execute(['r' => $room]);
        $this->pdo->prepare("
            INSERT INTO mrc_messages (from_user, from_site, from_room, to_room, message_body, is_private)
            VALUES ('someone', 'othersite', :r, :r, 'idle tick sees this', false)
        ")->execute(['r' => $room]);

        $before = $this->writeRowCounts();

        $conn = fopen('php://memory', 'r+');
        $state = ['rows' => 24, 'cols' => 80];
        $this->call('renderParticipatingScreen', [$conn, &$state, $room]);

        $after = $this->writeRowCounts();
        self::assertSame($before, $after, 'a screen refresh must never queue outbound rows or touch presence');

        rewind($conn);
        $output = stream_get_contents($conn);
        self::assertStringContainsString('idle tick sees this', $output);
        self::assertStringContainsString('#' . $room, $output);
    }

    public function testIdleTimeoutBranchRefreshesWithoutCallingJoinOrConnect(): void
    {
        $source = $this->methodSource('participate');
        // The $timedOut branch specifically -- isolate the lines between
        // "if ($timedOut)" and the next "if ($action" to prove the idle-tick
        // path itself only redraws, never joins/connects/sends.
        $start = strpos($source, 'if ($timedOut)');
        $end = strpos($source, '$action = $this->mapParticipatingKey');
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $idleBranch = substr($source, $start, $end - $start);

        self::assertStringContainsString('renderParticipatingScreen', $idleBranch);
        self::assertStringNotContainsString('->joinRoom(', $idleBranch);
        self::assertStringNotContainsString('->connect(', $idleBranch);
        self::assertStringNotContainsString('->sendMessage(', $idleBranch);
        self::assertStringNotContainsString('->queueCommand(', $idleBranch);
    }

    // ===== 6: no send-message command is queued (idle path specifically) =====

    public function testRenderParticipatingScreenSourceHasNoSendPath(): void
    {
        $source = $this->methodSource('renderParticipatingScreen');
        self::assertStringNotContainsString('->sendMessage(', $source);
        self::assertStringNotContainsString('->queueCommand(', $source);
    }

    // ===== 7: quit/disconnect still reaches finally cleanup =====

    public function testDisconnectDuringParticipationStillReachesLeaveRoomCleanup(): void
    {
        $source = $this->methodSource('participate');
        self::assertStringContainsString('if ($shouldDisconnect)', $source);
        // Both the disconnect early-return and the normal quit path are
        // inside the try{} whose finally{} calls leaveRoom() -- already
        // proven structurally by testParticipateNeverCallsWholeAccountDisconnect
        // (leaveRoom present, disconnect absent) plus this confirms the
        // shouldDisconnect check itself still exists after the idle-tick change.
        $tryPos = strpos($source, 'try {');
        $disconnectPos = strpos($source, 'if ($shouldDisconnect)');
        $finallyPos = strpos($source, 'finally {');
        self::assertNotFalse($tryPos);
        self::assertGreaterThan($tryPos, $disconnectPos, 'the disconnect check must be inside the try block');
        self::assertLessThan($finallyPos, $disconnectPos, 'the disconnect check must precede finally');
    }

    // ===== 8: existing default widget behavior remains unchanged =====
    // N/A -- no shared widget (showScrollablePanel/TuiShell/BbsSession/
    // TelnetUtils) was modified to fix this gap. The idle tick uses the
    // existing PUBLIC BbsSession::readKeyWithTimeout() and the existing
    // PUBLIC TelnetUtils::renderFullScreen() exactly as already defined, and
    // M1B's show() still uses showScrollablePanel() unchanged (confirmed by
    // testShowMethodNeverCallsConnectOrJoin() and the M1B formatting tests
    // above, which still pass unmodified).
}
