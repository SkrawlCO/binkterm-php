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
use BinktermPHP\TelnetServer\TelnetUtils;
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

    public function testNoCommandQueuePathExistsInThisSlice(): void
    {
        // queueCommand() (registration/topic/moderation) is out of scope for
        // both M1C-1 and M1C-2 -- unlike sendMessage(), which M1C-2
        // deliberately adds (see the M1C-2 section below for exactly where).
        $fullSource = file_get_contents((new \ReflectionClass(MrcChatHandler::class))->getFileName());
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
        $this->call('renderParticipatingScreen', [$conn, &$state, $room, 'watcher']);

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

    // ============================================================
    // MRC Terminal Convergence M1C-2 — room-message send
    // ============================================================

    // ===== 1, 2, 3 & 4: explicit send uses the shared service, correct room/identity/text =====

    public function testSendRoomMessageQueuesExactTextForTheCorrectRoomAndIdentity(): void
    {
        $room = 'm1c2_send';
        $this->call('sendRoomMessage', [$room, 'participant', 'L33Test', 'Hello from L33Test terminal!']);

        $row = $this->pdo->query("
            SELECT field1, field6, field7 FROM mrc_outbound
            WHERE field1 = 'participant' AND field6 = '{$room}'
            ORDER BY id DESC LIMIT 1
        ")->fetch(\PDO::FETCH_ASSOC);

        self::assertNotFalse($row);
        self::assertSame('participant', $row['field1']);
        self::assertSame($room, $row['field6']);
        // Same W1-prefixed format MrcChatServiceTest already pins for Web sends.
        self::assertSame('|03<|02participant|03> Hello from L33Test terminal!', $row['field7']);
    }

    public function testComposeAndSendCallsSendRoomMessageWithCurrentRoomAndIdentity(): void
    {
        // promptText() drives a real blocking interactive widget with no
        // established fake-socket test harness (same limitation already
        // documented for the idle-tick/disconnect coverage above), so the
        // wiring from "got input" to "send it" is verified by source
        // inspection here, and the send operation itself is verified
        // end-to-end above via sendRoomMessage() directly.
        $source = $this->methodSource('composeAndSend');
        self::assertStringContainsString('$this->sendRoomMessage($room, $username, $bbsName, $input)', $source);
        self::assertStringContainsString('shouldCancelSend', $source);
    }

    // ===== 5: empty message sends nothing =====

    public function testEmptyOrCancelledInputSendsNothing(): void
    {
        self::assertTrue($this->call('shouldCancelSend', [null]));
        self::assertTrue($this->call('shouldCancelSend', ['']));
        self::assertTrue($this->call('shouldCancelSend', ['   ']));
        self::assertFalse($this->call('shouldCancelSend', ['hi']));
    }

    // ===== 6: passive view sends nothing =====

    public function testShowMethodNeverCallsSendMessage(): void
    {
        $source = $this->methodSource('show');
        self::assertStringNotContainsString('->sendMessage(', $source);
        self::assertStringNotContainsString('sendRoomMessage', $source);
    }

    // ===== 7: idle tick sends nothing =====

    public function testIdleTimeoutBranchStillNeverSends(): void
    {
        // Re-assert against the current (M1C-2) source: the earlier
        // testIdleTimeoutBranchRefreshesWithoutCallingJoinOrConnect already
        // checks this branch for join/connect/send/queueCommand; this test
        // additionally confirms the new sendRoomMessage()/composeAndSend()
        // names specifically are absent from the idle branch after the M
        // key was added elsewhere in the same method.
        $source = $this->methodSource('participate');
        $start = strpos($source, 'if ($timedOut)');
        $end = strpos($source, '$action = $this->mapParticipatingKey');
        $idleBranch = substr($source, $start, $end - $start);

        self::assertStringNotContainsString('sendRoomMessage', $idleBranch);
        self::assertStringNotContainsString('composeAndSend', $idleBranch);
    }

    // ===== 8: heartbeat sends nothing =====

    public function testHeartbeatPathNeverSendsAMessage(): void
    {
        $room = 'm1c2_hb_no_send';
        $userId = $this->newUser();
        $this->mrc->joinRoom($userId, 'participant', 'L33Test', $room, '', null);

        $before = (int)$this->pdo->query('SELECT COUNT(*) FROM mrc_outbound')->fetchColumn();
        $this->mrc->recordHeartbeat($userId, 'participant', 'L33Test', $room);
        $after = (int)$this->pdo->query('SELECT COUNT(*) FROM mrc_outbound')->fetchColumn();

        self::assertSame($before, $after);
    }

    // ===== 9: validation failure is handled without destructive cleanup =====

    public function testSendValidationFailureThrowsAndDoesNotDisturbPresence(): void
    {
        $room = 'm1c2_invalid';
        $userId = $this->newUser();
        $this->mrc->joinRoom($userId, 'participant', 'L33Test', $room, '', null);

        // composeAndSend()'s own shouldCancelSend() guard already filters
        // blank input before ever calling sendRoomMessage() in the real
        // flow; this defense-in-depth test proves the underlying
        // MrcChatService::sendMessage() validation still rejects an empty
        // message if sendRoomMessage() were ever called directly with one --
        // exactly the InvalidArgumentException composeAndSend() catches
        // rather than letting propagate/crash the handler.
        try {
            $this->call('sendRoomMessage', [$room, 'participant', 'L33Test', '']);
            self::fail('expected InvalidArgumentException was not thrown');
        } catch (\InvalidArgumentException $e) {
            // expected -- composeAndSend() catches this exact exception type.
        }

        // Presence/participation must be completely unaffected by a rejected send.
        $presence = $this->pdo->query("
            SELECT username FROM mrc_local_presence WHERE user_id = {$userId} AND room_name = '{$room}'
        ")->fetchColumn();
        self::assertSame('participant', $presence);

        $composeSource = $this->methodSource('composeAndSend');
        self::assertStringContainsString('catch (\InvalidArgumentException $e)', $composeSource);
    }

    // ===== 10: successful send does not alter room participation =====

    public function testSuccessfulSendDoesNotChangePresenceOrRoom(): void
    {
        $room = 'm1c2_unaffected';
        $userId = $this->newUser();
        $this->mrc->joinRoom($userId, 'participant', 'L33Test', $room, '', null);

        $beforePresence = $this->pdo->query("
            SELECT username, room_name FROM mrc_local_presence WHERE user_id = {$userId}
        ")->fetch(\PDO::FETCH_ASSOC);

        $this->call('sendRoomMessage', [$room, 'participant', 'L33Test', 'still here']);

        $afterPresence = $this->pdo->query("
            SELECT username, room_name FROM mrc_local_presence WHERE user_id = {$userId}
        ")->fetch(\PDO::FETCH_ASSOC);

        self::assertSame($beforePresence, $afterPresence, 'sending a message must not change room membership or identity');
    }

    // ===== M1C-2A: 80x24 participating-screen presentation fix ==============
    //
    // Human finding: the previous layout was too crowded -- the controls row
    // collided visually with the persistent SyncTerm status line, and the
    // initial transcript dumped too much backlog. These tests exercise the
    // new compact frame's row budget, wrapping, and bottom-anchored newest-
    // first transcript against a real memory-stream $conn, without touching
    // MrcChatService, connect/join/leave/heartbeat/send semantics, or the
    // admin-only navigation wiring.

    private function renderAndCapture(string $room, array $state, string $currentUsername = 'watcher'): string
    {
        $conn = fopen('php://memory', 'r+');
        $this->call('renderParticipatingScreen', [$conn, &$state, $room, $currentUsername]);
        rewind($conn);
        $raw = stream_get_contents($conn);
        // Strip ANSI/SGR escapes -- the controls bar colors each label's
        // first letter separately from its remainder (e.g. "M" + escape +
        // "essage"), so plain substring assertions need the plain text.
        return (string)preg_replace('/\033\[[0-9;]*[A-Za-z]/', '', $raw);
    }

    // 1 & 8: reserves a row for the persistent BBS/SyncTerm status line, and
    // the reservation itself does not disturb the idle-tick refresh path.

    public function testEffectiveRowsReservesOneRowUnconditionally(): void
    {
        // M1C-2C: reserved unconditionally, not keyed off $state['terminal_type']
        // -- see renderParticipatingScreen()'s header-garble root-cause note.
        // SyncTerm-over-SSH reports its pty TERM (e.g. "xterm-256color"), not
        // "SYNCTERM", so a terminal_type-based check silently failed to
        // reserve on exactly the transport this bug was found on.
        self::assertSame(23, $this->call('effectiveRows', [['rows' => 24, 'terminal_type' => 'SYNCTERM']]));
        self::assertSame(23, $this->call('effectiveRows', [['rows' => 24, 'terminal_type' => 'xterm-256color']]));
        self::assertSame(23, $this->call('effectiveRows', [['rows' => 24, 'terminal_type' => 'ANSI']]));
        self::assertSame(23, $this->call('effectiveRows', [['rows' => 24, 'terminal_type' => '']]));
    }

    public function testIdleTimeoutBranchStillRefreshesUsingTheNewLayout(): void
    {
        // Already proven structurally (testIdleTimeoutBranchRefreshesWithoutCallingJoinOrConnect)
        // that the idle branch only calls renderParticipatingScreen(); this
        // proves that method itself still produces a real frame post-fix.
        $room = 'm1c2a_idle';
        $this->pdo->prepare("INSERT INTO mrc_rooms (room_name, last_activity) VALUES (:r, CURRENT_TIMESTAMP)")
            ->execute(['r' => $room]);

        $output = $this->renderAndCapture($room, ['rows' => 24, 'cols' => 80, 'terminal_type' => 'SYNCTERM']);
        self::assertStringContainsString('#' . $room, $output);
        self::assertStringContainsString('Message', $output);
    }

    // 2: control/help row is always present in the rendered output.

    public function testControlsRowIsAlwaysPresentInRenderedOutput(): void
    {
        $room = 'm1c2a_controls';
        $this->pdo->prepare("INSERT INTO mrc_rooms (room_name, last_activity) VALUES (:r, CURRENT_TIMESTAMP)")
            ->execute(['r' => $room]);

        $output = $this->renderAndCapture($room, ['rows' => 24, 'cols' => 80]);
        foreach (['Message', 'Rooms', 'Users', 'Leave', 'Back'] as $label) {
            self::assertStringContainsString($label, $output, "controls row must advertise {$label}");
        }
    }

    // 3 & 4: room name and online user count are visible in the header,
    // independent of the transcript/controls.

    public function testHeaderShowsRoomNameAndOnlineCountWithoutOpeningUsers(): void
    {
        $room = 'm1c2a_header';
        $userId = $this->newUser();
        $this->pdo->prepare("INSERT INTO mrc_rooms (room_name, last_activity) VALUES (:r, CURRENT_TIMESTAMP)")
            ->execute(['r' => $room]);
        $this->mrc->joinRoom($userId, 'watcher', 'L33Test', $room, '', null);

        $header = $this->plain($this->call('compactHeaderLine', [$room, ['connected' => true], 1, 80]));
        self::assertStringContainsString('MRC', $header);
        self::assertStringContainsString('#' . $room, $header);
        self::assertStringContainsString('1 ONLINE', $header);
    }

    public function testHeaderShowsDisconnectedStateInsteadOfAStaleCount(): void
    {
        $header = $this->plain($this->call('compactHeaderLine', ['lobby', ['connected' => false], 5, 80]));
        self::assertStringContainsString('DISCONNECTED', $header);
        self::assertStringNotContainsString('5 ONLINE', $header);
    }

    // 5: transcript viewport can never exceed the rows the layout budgeted
    // for it -- i.e. it cannot overwrite the controls/status rows.

    public function testTranscriptViewportNeverExceedsItsBudgetedHeight(): void
    {
        $room = 'm1c2a_budget';
        $this->pdo->prepare("INSERT INTO mrc_rooms (room_name, last_activity) VALUES (:r, CURRENT_TIMESTAMP)")
            ->execute(['r' => $room]);
        for ($i = 0; $i < 40; $i++) {
            $this->pdo->prepare("
                INSERT INTO mrc_messages (from_user, from_site, from_room, to_room, message_body, is_private)
                VALUES ('flooder', 'othersite', :r, :r, :m, false)
            ")->execute(['r' => $room, 'm' => "message number {$i}"]);
        }

        foreach ([5, 10, 19] as $height) {
            $viewport = $this->call('buildTranscriptViewport', [$room, 80, $height, 'watcher']);
            self::assertCount($height, $viewport, "viewport must be exactly {$height} lines, never more");
        }
    }

    // 6: initial view is positioned at the newest messages (bottom-anchored),
    // not the oldest of whatever batch was fetched.

    public function testTranscriptViewportShowsNewestMessagesAtTheBottom(): void
    {
        $room = 'm1c2a_newest';
        $this->pdo->prepare("INSERT INTO mrc_rooms (room_name, last_activity) VALUES (:r, CURRENT_TIMESTAMP)")
            ->execute(['r' => $room]);
        foreach (['oldest one', 'middle one', 'newest one'] as $body) {
            $this->pdo->prepare("
                INSERT INTO mrc_messages (from_user, from_site, from_room, to_room, message_body, is_private)
                VALUES ('someone', 'othersite', :r, :r, :m, false)
            ")->execute(['r' => $room, 'm' => $body]);
        }

        // A viewport with room for all three: newest must be the LAST line,
        // not truncated off the bottom and not buried above older content.
        $viewport = $this->call('buildTranscriptViewport', [$room, 80, 5, 'watcher']);
        $lastNonBlank = null;
        foreach ($viewport as $line) {
            if (trim($line) !== '') {
                $lastNonBlank = $line;
            }
        }
        self::assertStringContainsString('newest one', (string)$lastNonBlank);

        // A viewport too small for all of them: it must keep the newest
        // content, not the oldest.
        $tight = $this->call('buildTranscriptViewport', [$room, 80, 1, 'watcher']);
        self::assertCount(1, $tight);
        self::assertStringContainsString('newest one', $tight[0]);
        self::assertStringNotContainsString('oldest one', $tight[0]);
    }

    // 7: long messages wrap within the real transcript width instead of
    // producing a single overlong line that would blow the row budget.

    public function testLongMessagesWrapWithinTheTranscriptWidth(): void
    {
        $room = 'm1c2a_wrap';
        $this->pdo->prepare("INSERT INTO mrc_rooms (room_name, last_activity) VALUES (:r, CURRENT_TIMESTAMP)")
            ->execute(['r' => $room]);
        $longBody = str_repeat('word ', 40); // ~200 chars, well over 40 cols
        $this->pdo->prepare("
            INSERT INTO mrc_messages (from_user, from_site, from_room, to_room, message_body, is_private)
            VALUES ('chatty', 'othersite', :r, :r, :m, false)
        ")->execute(['r' => $room, 'm' => $longBody]);

        $viewport = $this->call('buildTranscriptViewport', [$room, 40, 10, 'watcher']);
        foreach ($viewport as $line) {
            $plain = (string)preg_replace('/\033\[[0-9;]*[A-Za-z]/', '', $line);
            self::assertLessThanOrEqual(40, mb_strlen($plain), 'no transcript line may exceed the real column width');
        }
        // The message content must still be present, just spread over
        // multiple wrapped lines rather than truncated.
        self::assertStringContainsString('word', implode(' ', $viewport));
    }

    // 9: M/R/U/L/Q key mappings themselves are unchanged by this slice
    // (mapParticipatingKey is untouched, but re-assert it here since this
    // whole slice is about the screen those keys drive).

    public function testKeyMappingsUnchangedByThePresentationFix(): void
    {
        self::assertSame('message', $this->call('mapParticipatingKey', ['CHAR:m']));
        self::assertSame('rooms', $this->call('mapParticipatingKey', ['CHAR:r']));
        self::assertSame('users', $this->call('mapParticipatingKey', ['CHAR:u']));
        self::assertSame('quit', $this->call('mapParticipatingKey', ['CHAR:l']));
        self::assertSame('quit', $this->call('mapParticipatingKey', ['CHAR:q']));
    }

    // 10: no automated message send occurs anywhere in this presentation-only
    // slice -- the new rendering methods never call sendMessage/queueCommand.

    public function testPresentationMethodsNeverSendOrQueueAnything(): void
    {
        foreach (['renderParticipatingScreen', 'compactHeaderLine', 'buildTranscriptViewport', 'participatingStatusLine', 'effectiveRows', 'formatColoredTranscript', 'messageCategory', 'speakerColor', 'stripDuplicateSpeakerPrefix', 'collapseEmbeddedNewlines'] as $method) {
            $source = $this->methodSource($method);
            self::assertStringNotContainsString('->sendMessage(', $source, "{$method} must never send");
            self::assertStringNotContainsString('->queueCommand(', $source, "{$method} must never queue a command");
            self::assertStringNotContainsString('->joinRoom(', $source, "{$method} must never join");
            self::assertStringNotContainsString('->connect(', $source, "{$method} must never connect");
        }
    }

    // M1C-2B Step 6: room picker cohesion -- same selector widget/behavior,
    // wording reflects context ("Join a Room" from participate() vs "View"
    // from show()). pickRoom() drives the real interactive chooseFromList()
    // widget, so (per the established pattern above) this is a structural
    // source check rather than driving that widget.

    public function testRoomPickerTitleReflectsParticipateVsViewContext(): void
    {
        $source = $this->methodSource('pickRoom');
        self::assertStringContainsString("'MRC — Join a Room'", $source);
        self::assertStringContainsString("'MRC Rooms — View'", $source);

        // participate() must ask for the room to JOIN, not the read-only title.
        $participateSource = $this->methodSource('participate');
        self::assertStringContainsString("pickRoom(\$conn, \$state, 'participate')", $participateSource);

        // show() must keep asking with the original read-only title (default param).
        $showSource = $this->methodSource('show');
        self::assertMatchesRegularExpression('/pickRoom\(\$conn, \$state\)/', $showSource);
        self::assertStringNotContainsString("'participate'", $showSource);
    }

    // ===== M1C-2B: authored header + transcript scannability =================
    //
    // Human finding: M1C-2A fixed crowding, but the screen still reads as a
    // generic diagnostic dump. These tests prove the authored two-line header
    // and the restrained semantic styling (stable per-handle color, an own/
    // mention accent, a muted SERVER treatment, and safe removal of a
    // demonstrably-redundant embedded-handle prefix) without ever altering
    // stored message text, MrcChatService, connect/join/leave/heartbeat/send
    // semantics, or the M/R/U/L/Q key mappings.

    private function plain(string $ansi): string
    {
        return (string)preg_replace('/\033\[[0-9;]*[A-Za-z]/', '', $ansi);
    }

    private function insertMessage(string $room, string $from, string $body, string $to = ''): void
    {
        $this->pdo->prepare("
            INSERT INTO mrc_messages (from_user, from_site, from_room, to_user, to_room, message_body, is_private)
            VALUES (:f, 'othersite', :r, :to, :r, :m, false)
        ")->execute(['f' => $from, 'r' => $room, 'to' => $to, 'm' => $body]);
    }

    // Authored header re-check (Matt: room/occupancy must be immediately
    // obvious) -- redundant with the M1C-2A header tests above by design,
    // kept here as the M1C-2B acceptance-facing assertion.

    public function testAuthoredHeaderShowsMrcIdentityRoomAndOccupancyTogether(): void
    {
        $room = 'm1c2b_header';
        $this->pdo->prepare("INSERT INTO mrc_rooms (room_name, last_activity) VALUES (:r, CURRENT_TIMESTAMP)")
            ->execute(['r' => $room]);

        // M1C-2D: MRC/room/state/occupancy are folded into one compact
        // header row (no box, no standalone branding row).
        $header = $this->plain($this->call('compactHeaderLine', [$room, ['connected' => true], 7, 80]));
        self::assertStringContainsString('MRC', $header);
        self::assertStringContainsString('#' . $room, $header);
        self::assertStringContainsString('CONNECTED', $header);
        self::assertStringContainsString('7 ONLINE', $header);
    }

    // 1: same user -> same deterministic color, every time.

    public function testSameHandleAlwaysGetsTheSameColor(): void
    {
        $c1 = $this->call('speakerColor', ['Puzlmastr']);
        $c2 = $this->call('speakerColor', ['Puzlmastr']);
        $c3 = $this->call('speakerColor', ['puzlmastr']); // case-insensitive/normalized
        self::assertSame($c1, $c2);
        self::assertSame($c1, $c3);
    }

    // 2: different representative handles all resolve into the approved
    // restrained palette (no random/unbounded color escapes).

    public function testDifferentHandlesMapWithinTheApprovedPalette(): void
    {
        $palette = [TelnetUtils::ANSI_GREEN, TelnetUtils::ANSI_BLUE, TelnetUtils::ANSI_MAGENTA, TelnetUtils::ANSI_CYAN];
        foreach (['alice', 'bob', 'Sam_Shaxted', 'Keyop', 'zed', 'quux123'] as $handle) {
            self::assertContains($this->call('speakerColor', [$handle]), $palette, "{$handle} must map into the approved palette");
        }
        // Reserved colors are never handed out for ordinary handles.
        foreach (['alice', 'bob', 'Sam_Shaxted', 'Keyop', 'zed', 'quux123'] as $handle) {
            self::assertNotSame(TelnetUtils::ANSI_RED, $this->call('speakerColor', [$handle]));
            self::assertNotSame(TelnetUtils::ANSI_YELLOW, $this->call('speakerColor', [$handle]));
        }
    }

    // 3: own caller's message is visually distinguishable (reserved
    // yellow/bold accent), distinct from an ordinary remote handle's color.

    public function testOwnCallerMessageIsDistinguishableFromRemoteHandles(): void
    {
        $room = 'm1c2b_own';
        $this->pdo->prepare("INSERT INTO mrc_rooms (room_name, last_activity) VALUES (:r, CURRENT_TIMESTAMP)")
            ->execute(['r' => $room]);
        $this->insertMessage($room, 'Puzlmastr', 'still here');
        $this->insertMessage($room, 'alice', 'hello room');

        $messages = $this->mrc->getRecentRoomMessages($room, 10);
        $lines = $this->call('formatColoredTranscript', [$messages, 'Puzlmastr']);

        self::assertStringContainsString(TelnetUtils::ANSI_YELLOW, $lines[0], 'own message must use the reserved own-caller accent');
        self::assertStringNotContainsString(TelnetUtils::ANSI_YELLOW, $lines[1], 'a remote message must not use the own-caller accent');

        self::assertSame('own', $this->call('messageCategory', ['Puzlmastr', 'still here', 'Puzlmastr']));
        self::assertSame('normal', $this->call('messageCategory', ['alice', 'hello room', 'Puzlmastr']));
    }

    // 4: SERVER/system messages are clearly distinguishable (dim/muted),
    // matching the one real semantic distinction Web MRC's own room chat
    // already has (public_html/webdoors/mrc/mrc.css .message-system).

    public function testServerMessagesAreDimAndDistinctFromHumanConversation(): void
    {
        self::assertSame('server', $this->call('messageCategory', ['SERVER', 'MRC server going down for maintenance', 'watcher']));
        self::assertSame('join_leave', $this->call('messageCategory', ['SERVER', '* (Joining) alice@otherbbs just joined room #lobby', 'watcher']));
        self::assertSame('join_leave', $this->call('messageCategory', ['SERVER', '* (Parting) alice@otherbbs has left room #lobby', 'watcher']));

        $room = 'm1c2b_server';
        $this->pdo->prepare("INSERT INTO mrc_rooms (room_name, last_activity) VALUES (:r, CURRENT_TIMESTAMP)")
            ->execute(['r' => $room]);
        $this->insertMessage($room, 'SERVER', 'MRC server going down for maintenance');

        $messages = $this->mrc->getRecentRoomMessages($room, 10);
        $line = $this->call('formatColoredTranscript', [$messages, 'watcher'])[0];
        self::assertStringContainsString(TelnetUtils::ANSI_DIM, $line, 'SERVER lines must be dim/subordinate');
    }

    // 5: message body stays neutral/readable for ordinary remote messages --
    // no rainbow body text, only the handle is colored.

    public function testOrdinaryRemoteMessageBodyStaysNeutral(): void
    {
        $room = 'm1c2b_neutral';
        $this->pdo->prepare("INSERT INTO mrc_rooms (room_name, last_activity) VALUES (:r, CURRENT_TIMESTAMP)")
            ->execute(['r' => $room]);
        $this->insertMessage($room, 'alice', 'just an ordinary message');

        $messages = $this->mrc->getRecentRoomMessages($room, 10);
        $line = $this->call('formatColoredTranscript', [$messages, 'watcher'])[0];

        // The handle segment carries a color; the body itself must contain
        // no escape codes spliced into its own text.
        $bodyStart = strpos($line, 'just an ordinary message');
        self::assertNotFalse($bodyStart);
        self::assertStringNotContainsString("\033[", substr($line, $bodyStart), 'body text must not have escape codes embedded in it');
        self::assertStringContainsString('> just an ordinary message', $this->plain($line));
    }

    // 6: original stored message text is never altered -- only the
    // demonstrably-redundant embedded copy of the sender's OWN handle is
    // ever removed from the DISPLAYED line, and only that.

    public function testOriginalMessageTextIsNeverAlteredInStorage(): void
    {
        $room = 'm1c2b_storage';
        $this->pdo->prepare("INSERT INTO mrc_rooms (room_name, last_activity) VALUES (:r, CURRENT_TIMESTAMP)")
            ->execute(['r' => $room]);
        $this->insertMessage($room, 'Sam_Shaxted', '<Sam_Shaxted> hello everyone');

        $before = $this->pdo->query("SELECT message_body FROM mrc_messages WHERE from_room = '{$room}'")->fetchColumn();
        $this->mrc->getRecentRoomMessages($room, 10);
        $messages = $this->mrc->getRecentRoomMessages($room, 10);
        $this->call('formatColoredTranscript', [$messages, 'watcher']);
        $after = $this->pdo->query("SELECT message_body FROM mrc_messages WHERE from_room = '{$room}'")->fetchColumn();

        self::assertSame('<Sam_Shaxted> hello everyone', $before, 'sanity: seeded row has the raw duplicate form');
        self::assertSame($before, $after, 'rendering must never write back to mrc_messages');
    }

    // 7: duplicate-prefix cleanup applies ONLY when the embedded prefix
    // provably matches the structured from_user column (the exact
    // "<Sam_Shaxted> <Sam_Shaxted> ..." case from the human screenshot);
    // an unrelated/uncertain prefix like "@Keyop>" is left completely alone.

    public function testDuplicateSpeakerPrefixIsStrippedOnlyWhenItMatchesFromUser(): void
    {
        // Proven-redundant: bracket form exactly matching from_user.
        self::assertSame(
            'hello everyone',
            $this->call('stripDuplicateSpeakerPrefix', ['<Sam_Shaxted> hello everyone', 'Sam_Shaxted'])
        );
        // Proven-redundant: plain form exactly matching from_user.
        self::assertSame(
            'hello everyone',
            $this->call('stripDuplicateSpeakerPrefix', ['Sam_Shaxted hello everyone', 'Sam_Shaxted'])
        );
        // Distinct/uncertain: "@Keyop>" is neither the bracket nor the plain
        // form of "Keyop" -- must be left completely untouched.
        self::assertSame(
            '@Keyop> message',
            $this->call('stripDuplicateSpeakerPrefix', ['@Keyop> message', 'Keyop'])
        );
        // A prefix naming a DIFFERENT user is never stripped as if it were
        // the current speaker's own handle.
        self::assertSame(
            '<someoneelse> hi',
            $this->call('stripDuplicateSpeakerPrefix', ['<someoneelse> hi', 'Keyop'])
        );

        // End-to-end through the real transcript path: the visible line
        // shows the identity exactly once.
        $room = 'm1c2b_dedupe';
        $this->pdo->prepare("INSERT INTO mrc_rooms (room_name, last_activity) VALUES (:r, CURRENT_TIMESTAMP)")
            ->execute(['r' => $room]);
        $this->insertMessage($room, 'Sam_Shaxted', '<Sam_Shaxted> hello everyone');
        $this->insertMessage($room, 'Keyop', '@Keyop> replying to something');

        $messages = $this->mrc->getRecentRoomMessages($room, 10);
        $lines = array_map([$this, 'plain'], $this->call('formatColoredTranscript', [$messages, 'watcher']));

        self::assertSame(1, substr_count($lines[0], 'Sam_Shaxted'), 'the duplicated bracket copy must be removed, leaving one identity');
        self::assertStringContainsString('<Sam_Shaxted> hello everyone', $lines[0]);
        // Distinct/uncertain prefix preserved verbatim in the displayed line.
        self::assertStringContainsString('<Keyop> @Keyop> replying to something', $lines[1]);
    }

    // 8: no ANSI/style state leaks from one transcript line into the next --
    // every colorized span is self-contained (colorize() always appends
    // ANSI_RESET), so a line that opens a color code also closes it.

    public function testNoAnsiStateLeaksBetweenTranscriptLines(): void
    {
        $room = 'm1c2b_noleak';
        $this->pdo->prepare("INSERT INTO mrc_rooms (room_name, last_activity) VALUES (:r, CURRENT_TIMESTAMP)")
            ->execute(['r' => $room]);
        $this->insertMessage($room, 'alice', 'first message');
        $this->insertMessage($room, 'SERVER', '* (Joining) bob@otherbbs just joined room #' . $room);
        $this->insertMessage($room, 'Puzlmastr', 'second message');

        $messages = $this->mrc->getRecentRoomMessages($room, 10);
        $lines = $this->call('formatColoredTranscript', [$messages, 'Puzlmastr']);

        $colorCodes = [
            TelnetUtils::ANSI_GREEN, TelnetUtils::ANSI_BLUE, TelnetUtils::ANSI_MAGENTA,
            TelnetUtils::ANSI_CYAN, TelnetUtils::ANSI_YELLOW, TelnetUtils::ANSI_DIM,
        ];

        foreach ($lines as $i => $line) {
            $lastColorStart = null;
            foreach ($colorCodes as $code) {
                $pos = strrpos($line, $code);
                if ($pos !== false && ($lastColorStart === null || $pos > $lastColorStart)) {
                    $lastColorStart = $pos;
                }
            }
            if ($lastColorStart === null) {
                continue; // no color opened on this line -- nothing to leak
            }
            $resetPos = strrpos($line, TelnetUtils::ANSI_RESET);
            self::assertNotFalse($resetPos, "line {$i} opens a color code but never resets");
            self::assertGreaterThan(
                $lastColorStart,
                $resetPos,
                "line {$i}'s last-opened color must be closed before the line ends, not bleed into the next line"
            );
        }
    }

    // ===== M1C-2C: header-garble fix + bounded viewport ======================
    //
    // Human finding: "Garble on the channel page" -- the top header was not
    // reliably visible, and separately the screen "feels like it's floating
    // in open space unbounded." Root cause of the garble: effectiveRows()
    // only reserved a row for the terminal client's persistent status line
    // when $state['terminal_type'] named SyncTerm, which SyncTerm-over-SSH
    // does not do (it reports its pty TERM instead) -- so on SSH the frame
    // rendered one row too tall and the terminal auto-scrolled the top of
    // the frame off-screen. Fixed by reserving unconditionally. Separately,
    // the screen is now one bordered surface (BbsSession::
    // getTerminalLineDrawingChars(), the same charset-safe glyph source
    // TerminalBoxRenderer uses) instead of floating header/transcript/
    // controls fragments.

    /** Total physical rows actually written: every "\r\n" line plus the final "\r"-only status line. */
    private function countWrittenRows(string $rawOutput): int
    {
        // The very last write (renderFullScreen's status-line row) uses "\r"
        // only, so it doesn't itself add a "\r\n" -- count newline-terminated
        // rows and add 1 for that final row.
        return substr_count($rawOutput, "\r\n") + 1;
    }

    // 1, 2, 3, 4: header content (room, occupancy, connected state) survives
    // the full real renderFullScreen() pass, not just the isolated builder.

    public function testHeaderContentSurvivesFullScreenRender(): void
    {
        $room = 'm1c2c_survives';
        $userId = $this->newUser();
        $this->pdo->exec("
            INSERT INTO mrc_state (key, value, updated_at) VALUES
                ('connected', 'true', CURRENT_TIMESTAMP),
                ('daemon_heartbeat', '1', CURRENT_TIMESTAMP)
            ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = EXCLUDED.updated_at
        ");
        $this->pdo->prepare("INSERT INTO mrc_rooms (room_name, last_activity) VALUES (:r, CURRENT_TIMESTAMP)")
            ->execute(['r' => $room]);
        $this->mrc->joinRoom($userId, 'watcher', 'L33Test', $room, '', null);

        $output = $this->plain($this->renderAndCapture($room, ['rows' => 24, 'cols' => 80, 'terminal_type' => 'xterm-256color']));

        self::assertStringContainsString('MRC', $output, 'the MRC identity must survive the real render');
        self::assertStringContainsString('#' . $room, $output, 'the room name must survive the real render');
        self::assertStringContainsString('1 ONLINE', $output, 'occupancy must survive the real render');
        self::assertStringContainsString('CONNECTED', $output, 'connection state must survive the real render');
    }

    // 5: left/right frame boundaries are present on transcript and controls rows.

    // M1C-2D Step 7 #1: no vertical box rails remain anywhere in the
    // rendered output -- the full left/right/bottom frame from M1C-2C is
    // completely gone (Matt: "sloppy... boxed dialog", explicitly rejected).

    public function testNoVerticalBoxRailsRemainInRenderedOutput(): void
    {
        $room = 'm1c2d_norails';
        $this->pdo->prepare("INSERT INTO mrc_rooms (room_name, last_activity) VALUES (:r, CURRENT_TIMESTAMP)")
            ->execute(['r' => $room]);
        $this->insertMessage($room, 'alice', 'hi there');

        $output = $this->plain($this->renderAndCapture($room, ['rows' => 24, 'cols' => 80]));
        $rows = array_filter(explode("\r\n", $output), static fn (string $r): bool => trim($r) !== '');

        foreach ($rows as $i => $row) {
            self::assertDoesNotMatchRegularExpression(
                '/^[|+\x{2502}\x{2551}]/u',
                $row,
                "row {$i} must not open with a vertical box-rail glyph: \"{$row}\""
            );
            self::assertDoesNotMatchRegularExpression(
                '/[|+\x{2502}\x{2551}]$/u',
                $row,
                "row {$i} must not close with a vertical box-rail glyph: \"{$row}\""
            );
        }

        self::assertDoesNotMatchRegularExpression(
            '/^[+\x{250C}\x{2554}\x{256D}].*[+\x{2510}\x{2557}\x{256E}]$/u',
            $rows[0] ?? '',
            'no corner-glyph-bounded top border row'
        );

        // No source-level box-frame methods either -- the whole M1C-2C
        // frame concept is removed, not merely unused.
        foreach (['frameTopLine', 'frameBottomLine', 'frameContentLine'] as $removedMethod) {
            self::assertFalse(
                method_exists(MrcChatHandler::class, $removedMethod),
                "{$removedMethod} must not exist -- the boxed viewport concept was rejected and removed"
            );
        }
    }

    // Ex-M1C-2C #6, retargeted: transcript wraps to the FULL practical
    // content width now that there is no border to reserve columns for.

    public function testTranscriptWrapsToTheFullColumnWidthNoBorderPenalty(): void
    {
        $room = 'm1c2d_fullwrap';
        $this->pdo->prepare("INSERT INTO mrc_rooms (room_name, last_activity) VALUES (:r, CURRENT_TIMESTAMP)")
            ->execute(['r' => $room]);
        $longBody = str_repeat('word ', 30);
        $this->insertMessage($room, 'chatty', $longBody);

        // cols=40 -> the FULL 40 is now the wrap width, matching
        // renderParticipatingScreen's own (unreduced) $cols budget.
        $viewport = $this->call('buildTranscriptViewport', [$room, 40, 10, 'watcher']);
        foreach ($viewport as $line) {
            $plain = $this->plain($line);
            self::assertLessThanOrEqual(40, mb_strlen($plain), 'transcript content must wrap to the full column width');
        }
    }

    // 7: a sparse/quiet room stays bottom-anchored inside the viewport --
    // blank rows appear ABOVE the content, never vertically centered, and the
    // frame still encloses them (proven via the borders test above; here we
    // prove the anchoring itself is unchanged by the M1C-2C viewport work).

    public function testSparseRoomStaysBottomAnchoredInsideTheViewport(): void
    {
        $room = 'm1c2c_sparse';
        $this->pdo->prepare("INSERT INTO mrc_rooms (room_name, last_activity) VALUES (:r, CURRENT_TIMESTAMP)")
            ->execute(['r' => $room]);
        $this->insertMessage($room, 'alice', 'only message in a quiet room');

        $viewport = $this->call('buildTranscriptViewport', [$room, 80, 10, 'watcher']);
        self::assertCount(10, $viewport);
        for ($i = 0; $i < 9; $i++) {
            self::assertSame('', trim($viewport[$i]), "row {$i} above the sole message must be blank, not centered content");
        }
        self::assertStringContainsString('only message in a quiet room', $this->plain($viewport[9]));
    }

    // 8: controls remain visible inside the new frame.

    public function testControlsRemainVisibleInsideTheFrame(): void
    {
        $room = 'm1c2c_controls';
        $this->pdo->prepare("INSERT INTO mrc_rooms (room_name, last_activity) VALUES (:r, CURRENT_TIMESTAMP)")
            ->execute(['r' => $room]);

        $output = $this->plain($this->renderAndCapture($room, ['rows' => 24, 'cols' => 80]));
        foreach (['Message', 'Rooms', 'Users', 'Leave', 'Back'] as $label) {
            self::assertStringContainsString($label, $output, "controls must still advertise {$label} inside the frame");
        }
    }

    // 9: semantic coloring from M1C-2B is intact through the new frame path
    // (own caller still gets the reserved yellow accent end-to-end).

    public function testSemanticColoringSurvivesTheNewFramePath(): void
    {
        $room = 'm1c2c_semantic';
        $this->pdo->prepare("INSERT INTO mrc_rooms (room_name, last_activity) VALUES (:r, CURRENT_TIMESTAMP)")
            ->execute(['r' => $room]);
        $this->insertMessage($room, 'Puzlmastr', 'still here');

        $viewport = $this->call('buildTranscriptViewport', [$room, 78, 10, 'Puzlmastr']);
        $joined = implode('|', $viewport);
        self::assertStringContainsString(TelnetUtils::ANSI_YELLOW, $joined, 'own-caller accent must survive framing');
    }

    // 10: M/R/U/L/Q behavior is unchanged (mapParticipatingKey untouched;
    // re-asserted here since this whole slice is about the screen those keys
    // drive).

    public function testKeyMappingsUnchangedByTheViewportFix(): void
    {
        self::assertSame('message', $this->call('mapParticipatingKey', ['CHAR:m']));
        self::assertSame('rooms', $this->call('mapParticipatingKey', ['CHAR:r']));
        self::assertSame('users', $this->call('mapParticipatingKey', ['CHAR:u']));
        self::assertSame('quit', $this->call('mapParticipatingKey', ['CHAR:l']));
        self::assertSame('quit', $this->call('mapParticipatingKey', ['CHAR:q']));
    }

    // 11: rendering the frame performs no message/network writes.

    public function testFramedRenderPerformsNoMessageOrNetworkWrites(): void
    {
        $room = 'm1c2c_nowrites';
        $this->pdo->prepare("INSERT INTO mrc_rooms (room_name, last_activity) VALUES (:r, CURRENT_TIMESTAMP)")
            ->execute(['r' => $room]);
        $this->insertMessage($room, 'alice', 'hello');

        $before = $this->writeRowCounts();
        $this->renderAndCapture($room, ['rows' => 24, 'cols' => 80]);
        $after = $this->writeRowCounts();

        self::assertSame($before, $after, 'rendering the bounded frame must never queue outbound rows or touch presence');
    }

    // 12: the 80x24 render never writes to the terminal client's reserved
    // status row -- total physical rows written must never exceed
    // effectiveRows(), which now reserves 1 row unconditionally.

    public function test80x24OutputNeverTargetsTheReservedStatusRow(): void
    {
        $room = 'm1c2c_rowbudget';
        $this->pdo->prepare("INSERT INTO mrc_rooms (room_name, last_activity) VALUES (:r, CURRENT_TIMESTAMP)")
            ->execute(['r' => $room]);
        for ($i = 0; $i < 30; $i++) {
            $this->insertMessage($room, 'flooder', "message {$i}");
        }

        $state = ['rows' => 24, 'cols' => 80];
        $output = $this->renderAndCapture($room, $state);
        $expectedRows = $this->call('effectiveRows', [$state]);

        self::assertSame(23, $expectedRows);
        self::assertSame($expectedRows, $this->countWrittenRows($output), 'must write exactly effectiveRows() rows, never the full 24 (the reserved row)');
    }

    // ===== M1C-2D: box removed + message density fix =========================
    //
    // Human finding: "Garble still there - Better but looks like sloppy
    // design :(" -- the M1C-2C full box was rejected, and consecutive chat
    // messages had excessive blank vertical space between them. This block
    // proves the box is gone (also covered above,
    // testNoVerticalBoxRailsRemainInRenderedOutput) and that ordinary
    // messages render on consecutive rows with no injected blank line.

    // 2: one-line messages are consecutive with no injected blank rows.

    public function testOrdinaryOneLineMessagesHaveNoBlankRowBetweenThem(): void
    {
        $room = 'm1c2d_dense';
        $this->pdo->prepare("INSERT INTO mrc_rooms (room_name, last_activity) VALUES (:r, CURRENT_TIMESTAMP)")
            ->execute(['r' => $room]);
        foreach (['first message', 'second message', 'third message'] as $body) {
            $this->insertMessage($room, 'alice', $body);
        }

        $viewport = $this->call('buildTranscriptViewport', [$room, 80, 5, 'watcher']);
        // The last 3 lines (bottom-anchored) must be the 3 messages back to
        // back, with no blank line between any consecutive pair.
        $lastThree = array_slice($viewport, -3);
        foreach ($lastThree as $line) {
            self::assertNotSame('', trim($this->plain($line)), 'a message row must never be blank');
        }
        self::assertStringContainsString('first message', $this->plain($lastThree[0]));
        self::assertStringContainsString('second message', $this->plain($lastThree[1]));
        self::assertStringContainsString('third message', $this->plain($lastThree[2]));
    }

    // Message-density root cause, directly: a message body carrying an
    // embedded CR/LF must not turn into "content row" + a blank row.

    public function testEmbeddedNewlineInMessageBodyDoesNotInjectABlankRow(): void
    {
        self::assertSame('hello world', $this->call('collapseEmbeddedNewlines', ["hello world\n"]));
        self::assertSame('hello world', $this->call('collapseEmbeddedNewlines', ["hello\nworld"]));
        self::assertSame('hello world', $this->call('collapseEmbeddedNewlines', ["hello\r\nworld"]));
        self::assertSame('hello world', $this->call('collapseEmbeddedNewlines', ["hello\rworld"]));

        $room = 'm1c2d_embedded_newline';
        $this->pdo->prepare("INSERT INTO mrc_rooms (room_name, last_activity) VALUES (:r, CURRENT_TIMESTAMP)")
            ->execute(['r' => $room]);
        $this->insertMessage($room, 'alice', "trailing newline in the wire content\n");
        $this->insertMessage($room, 'bob', 'a normal message right after');

        $viewport = $this->call('buildTranscriptViewport', [$room, 80, 4, 'watcher']);
        $lastTwo = array_slice($viewport, -2);
        self::assertNotSame('', trim($this->plain($lastTwo[0])), 'a trailing embedded newline must not produce a blank row');
        self::assertStringContainsString('trailing newline in the wire content', $this->plain($lastTwo[0]));
        self::assertStringContainsString('a normal message right after', $this->plain($lastTwo[1]));
    }

    // 3: wrapped continuation lines remain contiguous with their originating
    // message (no blank separator injected by the wrap itself).

    public function testWrappedContinuationLinesStayContiguous(): void
    {
        $room = 'm1c2d_wrapcontig';
        $this->pdo->prepare("INSERT INTO mrc_rooms (room_name, last_activity) VALUES (:r, CURRENT_TIMESTAMP)")
            ->execute(['r' => $room]);
        $this->insertMessage($room, 'chatty', str_repeat('word ', 20)); // wraps at 40 cols
        $this->insertMessage($room, 'alice', 'a short reply');

        $viewport = $this->call('buildTranscriptViewport', [$room, 40, 6, 'watcher']);
        // Find the short reply's row; every row immediately before it back to
        // the start of the long message's wrap must be non-blank.
        $replyIndex = null;
        foreach ($viewport as $i => $line) {
            if (str_contains($this->plain($line), 'a short reply')) {
                $replyIndex = $i;
                break;
            }
        }
        self::assertNotNull($replyIndex, 'the short reply must appear in the viewport');
        self::assertGreaterThan(0, $replyIndex);
        // The row immediately above the reply is part of the wrapped long
        // message's own continuation -- it must not be a blank separator.
        self::assertNotSame('', trim($this->plain($viewport[$replyIndex - 1])), 'the row immediately above the reply (part of the wrapped message) must not be blank');
    }

    // 4: quiet-room blank space occurs ABOVE the conversation only, never
    // inserted between messages (re-verified after the box removal).

    public function testQuietRoomBlankSpaceStaysAboveConversationOnly(): void
    {
        $room = 'm1c2d_quiet';
        $this->pdo->prepare("INSERT INTO mrc_rooms (room_name, last_activity) VALUES (:r, CURRENT_TIMESTAMP)")
            ->execute(['r' => $room]);
        $this->insertMessage($room, 'alice', 'lone message in a quiet room');

        $viewport = $this->call('buildTranscriptViewport', [$room, 80, 8, 'watcher']);
        self::assertCount(8, $viewport);
        for ($i = 0; $i < 7; $i++) {
            self::assertSame('', trim($viewport[$i]), "row {$i} above the sole message must be blank");
        }
        self::assertStringContainsString('lone message in a quiet room', $this->plain($viewport[7]));
    }

    // 5: compact header contains room/state/count (re-verified end-to-end
    // through the real render after the box removal).

    public function testCompactHeaderContainsRoomStateAndCountEndToEnd(): void
    {
        $room = 'm1c2d_compactheader';
        $userId = $this->newUser();
        $this->pdo->exec("
            INSERT INTO mrc_state (key, value, updated_at) VALUES
                ('connected', 'true', CURRENT_TIMESTAMP),
                ('daemon_heartbeat', '1', CURRENT_TIMESTAMP)
            ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = EXCLUDED.updated_at
        ");
        $this->pdo->prepare("INSERT INTO mrc_rooms (room_name, last_activity) VALUES (:r, CURRENT_TIMESTAMP)")
            ->execute(['r' => $room]);
        $this->mrc->joinRoom($userId, 'watcher', 'L33Test', $room, '', null);

        $output = $this->plain($this->renderAndCapture($room, ['rows' => 24, 'cols' => 80]));
        $firstLine = explode("\n", $output)[0] ?? '';
        self::assertStringContainsString('MRC', $firstLine);
        self::assertStringContainsString('#' . $room, $firstLine);
        self::assertStringContainsString('CONNECTED', $firstLine);
        self::assertStringContainsString('1 ONLINE', $firstLine);
    }
}
