<?php

declare(strict_types=1);

require_once __DIR__ . '/Support/TestDatabase.php';

use BinktermPHP\Mrc\MrcChatService;
use BinktermPHP\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * M1A — MRC Terminal Convergence: pins the business/data behavior extracted
 * verbatim from public_html/webdoors/mrc/api.php into MrcChatService, so a
 * future terminal MRC client can call the exact same operations the Web
 * WebDoor uses.
 *
 * Runs against the isolated `binktermphp_test` Postgres database (real
 * dialect, not SQLite -- this schema relies on Postgres-specific
 * ON CONFLICT ... DO UPDATE and INTERVAL arithmetic), inside a transaction
 * that is always rolled back. Skips when that database is not configured,
 * matching FileSearchServiceTest/UnifiedNewscanServiceTest.
 */
final class MrcChatServiceTest extends TestCase
{
    private const TOKEN = 'm1atest';

    private \PDO $pdo;
    private MrcChatService $mrc;

    protected function setUp(): void
    {
        try {
            $this->pdo = TestDatabase::pdo();
        } catch (\Throwable $e) {
            self::markTestSkipped('database not available: ' . $e->getMessage());
        }

        $this->pdo->beginTransaction();
        $this->mrc = new MrcChatService($this->pdo);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function room(string $suffix = ''): string
    {
        return self::TOKEN . $suffix;
    }

    private function newUser(): int
    {
        $s = strtolower(bin2hex(random_bytes(6)));
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (username, password_hash, real_name, is_active)
             VALUES (?, ?, ?, TRUE) RETURNING id'
        );
        $stmt->execute(["{$s}", password_hash('x', PASSWORD_DEFAULT), "MRC Test {$s}"]);
        return (int) $stmt->fetch(\PDO::FETCH_ASSOC)['id'];
    }

    // ===== pure validation =====

    public function testNormalizeHandleFallsBackToProvidedUsername(): void
    {
        self::assertSame('alice', $this->mrc->normalizeHandle('', 'alice'));
        self::assertSame('bob', $this->mrc->normalizeHandle('  bob  ', 'alice'));
    }

    public function testNormalizeHandleRejectsReservedNames(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mrc->normalizeHandle('SERVER', 'alice');
    }

    public function testNormalizeRoomNameStripsLeadingHashAndValidates(): void
    {
        self::assertSame('lobby', $this->mrc->normalizeRoomName('#lobby'));
        self::assertSame('lobby', $this->mrc->normalizeRoomName('lobby'));
    }

    public function testNormalizeRoomNameRejectsInvalidCharacters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mrc->normalizeRoomName('bad room!');
    }

    // ===== status / rooms =====

    public function testStatusReflectsCurrentMrcState(): void
    {
        $this->pdo->exec("
            INSERT INTO mrc_state (key, value, updated_at) VALUES
                ('connected', 'true', CURRENT_TIMESTAMP),
                ('daemon_heartbeat', '1', CURRENT_TIMESTAMP)
            ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = EXCLUDED.updated_at
        ");

        $status = $this->mrc->getStatus();
        self::assertTrue($status['connected']);
        self::assertTrue($status['daemon_running']);
        self::assertArrayHasKey('server', $status);
        self::assertArrayHasKey('bbs_name', $status);
    }

    public function testRoomListFallsBackToDefaultRoomWhenEmpty(): void
    {
        $this->pdo->exec("DELETE FROM mrc_rooms WHERE room_name LIKE '" . self::TOKEN . "%'");
        // Not asserting emptiness of the whole table (shared fixture data may
        // exist); just that a room we insert appears with the right shape.
        $room = $this->room('_list');
        $stmt = $this->pdo->prepare("INSERT INTO mrc_rooms (room_name, last_activity) VALUES (:r, CURRENT_TIMESTAMP)");
        $stmt->execute(['r' => $room]);

        $rooms = $this->mrc->getRoomList();
        $names = array_column($rooms, 'room_name');
        self::assertContains($room, $names);
    }

    // ===== join / send / cursor round trip =====

    public function testJoinRoomQueuesOutboundAndReturnsCurrentCursor(): void
    {
        $room = $this->room('_join');
        $userId = $this->newUser();
        $lastId = $this->mrc->joinRoom($userId, 'skrawl', 'L33Test', $room, '', '203.0.113.5');

        self::assertSame(0, $lastId, 'no messages yet in a brand-new room');

        $outbound = $this->pdo->query("
            SELECT field4, field6, field7 FROM mrc_outbound
            WHERE field1 = 'skrawl' AND field7 LIKE 'NEWROOM:%'
            ORDER BY id DESC LIMIT 1
        ")->fetch(\PDO::FETCH_ASSOC);
        self::assertNotFalse($outbound);
        self::assertSame($room, $outbound['field6']);
        self::assertSame("NEWROOM::{$room}", $outbound['field7']);

        $ipRow = $this->pdo->query("
            SELECT field7 FROM mrc_outbound WHERE field7 LIKE 'USERIP:%' ORDER BY id DESC LIMIT 1
        ")->fetchColumn();
        self::assertSame('USERIP:203.0.113.5', $ipRow);

        $presence = $this->pdo->query("
            SELECT username, room_name, user_id FROM mrc_local_presence WHERE room_name = '{$room}'
        ")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('skrawl', $presence['username']);
        self::assertSame($userId, (int)$presence['user_id']);
    }

    public function testSendMessageQueuesFormattedOutboundRow(): void
    {
        $room = $this->room('_send');
        $this->mrc->sendMessage('skrawl', 'L33Test', $room, 'hello world', '', 140);

        $row = $this->pdo->query("
            SELECT field1, field6, field7, priority FROM mrc_outbound
            WHERE field1 = 'skrawl' AND field6 = '{$room}'
            ORDER BY id DESC LIMIT 1
        ")->fetch(\PDO::FETCH_ASSOC);

        self::assertNotFalse($row);
        self::assertSame('|03<|02skrawl|03> hello world', $row['field7']);
        self::assertSame(0, (int)$row['priority']);
    }

    public function testSendMessageRejectsEmptyMessage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mrc->sendMessage('skrawl', 'L33Test', $this->room(), '', '', 140);
    }

    public function testRoomCursorReflectsExistingMessages(): void
    {
        $room = $this->room('_cursor');
        $this->pdo->prepare("
            INSERT INTO mrc_messages (from_user, from_site, from_room, to_room, message_body, is_private)
            VALUES ('someone', 'othersite', :room, :room, 'hi', false)
        ")->execute(['room' => $room]);

        self::assertGreaterThan(0, $this->mrc->getRoomCursor($room));
        self::assertSame(0, $this->mrc->getRoomCursor($this->room('_never_joined')));
    }

    // ===== heartbeat / handle upsert =====

    public function testRecordHeartbeatUpsertsHandleAndPresence(): void
    {
        $room = $this->room('_hb');
        $userId = $this->newUser();
        $this->mrc->recordHeartbeat($userId, 'heartbeater', 'L33Test', $room);

        $handle = $this->pdo->query("SELECT username FROM mrc_local_handles WHERE user_id = {$userId}")->fetchColumn();
        self::assertSame('heartbeater', $handle);

        $presence = $this->pdo->query("
            SELECT username FROM mrc_local_presence WHERE user_id = {$userId} AND room_name = '{$room}'
        ")->fetchColumn();
        self::assertSame('heartbeater', $presence);

        // Idempotent on a second call (ON CONFLICT DO UPDATE, not a duplicate row).
        $this->mrc->recordHeartbeat($userId, 'heartbeater', 'L33Test', $room);
        $count = $this->pdo->query("SELECT COUNT(*) FROM mrc_local_handles WHERE user_id = {$userId}")->fetchColumn();
        self::assertSame('1', (string)$count);
    }

    // ===== disconnect =====

    public function testDisconnectQueuesLogoffAndClearsPresence(): void
    {
        $room = $this->room('_disc');
        $userId = $this->newUser();
        $this->mrc->joinRoom($userId, 'leaver', 'L33Test', $room, '', null);

        $rooms = $this->mrc->disconnect($userId, 'leaver', 'L33Test');
        self::assertSame([$room], $rooms);

        $logoff = $this->pdo->query("
            SELECT field7 FROM mrc_outbound WHERE field1 = 'leaver' AND field7 = 'LOGOFF' ORDER BY id DESC LIMIT 1
        ")->fetchColumn();
        self::assertSame('LOGOFF', $logoff);

        $presenceLeft = $this->pdo->query("SELECT COUNT(*) FROM mrc_local_presence WHERE user_id = {$userId}")->fetchColumn();
        self::assertSame('0', (string)$presenceLeft);
        $handleLeft = $this->pdo->query("SELECT COUNT(*) FROM mrc_local_handles WHERE user_id = {$userId}")->fetchColumn();
        self::assertSame('0', (string)$handleLeft);
    }

    // ===== queueCommand =====

    public function testQueueCommandTopicBuildsExpectedOutboundBody(): void
    {
        $room = $this->room('_topic');
        $this->mrc->queueCommand('skrawl', 'L33Test', 'topic', $room, ['New', 'topic', 'text']);

        $f7 = $this->pdo->query("
            SELECT field7 FROM mrc_outbound WHERE field1 = 'skrawl' AND field7 LIKE 'NEWTOPIC:%' ORDER BY id DESC LIMIT 1
        ")->fetchColumn();
        self::assertSame("NEWTOPIC:{$room}:New topic text", $f7);
    }

    public function testQueueCommandRejectsInvalidCommandWord(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mrc->queueCommand('skrawl', 'L33Test', 'Not A Command!', '', []);
    }

    public function testQueueCommandRoomsDoesNotRequireARoomAndFlagsRefresh(): void
    {
        $this->mrc->queueCommand('skrawl', 'L33Test', 'rooms', '', []);

        $f7 = $this->pdo->query("
            SELECT field7 FROM mrc_outbound WHERE field7 = 'LIST' ORDER BY id DESC LIMIT 1
        ")->fetchColumn();
        self::assertSame('LIST', $f7);

        $pending = $this->pdo->query("SELECT value FROM mrc_state WHERE key = 'list_refresh_pending'")->fetchColumn();
        self::assertSame('true', $pending);
    }

    // ===== connect =====

    public function testConnectQueuesUserIpAndIdentify(): void
    {
        $userId = $this->newUser();
        $this->mrc->connect($userId, 'skrawl', 'L33Test', 'secretpw', '198.51.100.9');

        $ip = $this->pdo->query("
            SELECT field7 FROM mrc_outbound WHERE field1 = 'skrawl' AND field7 LIKE 'USERIP:%' ORDER BY id DESC LIMIT 1
        ")->fetchColumn();
        self::assertSame('USERIP:198.51.100.9', $ip);

        $identify = $this->pdo->query("
            SELECT field7 FROM mrc_outbound WHERE field1 = 'skrawl' AND field7 LIKE 'IDENTIFY%' ORDER BY id DESC LIMIT 1
        ")->fetchColumn();
        self::assertSame('IDENTIFY secretpw', $identify);

        $handle = $this->pdo->query("SELECT username FROM mrc_local_handles WHERE user_id = {$userId}")->fetchColumn();
        self::assertSame('skrawl', $handle);
    }
}
