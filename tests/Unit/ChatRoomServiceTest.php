<?php

declare(strict_types=1);

use BinktermPHP\Chat\ChatRoomService;
use PHPUnit\Framework\TestCase;

final class ChatRoomServiceTest extends TestCase
{
    private \PDO $db;
    private ChatRoomService $rooms;

    protected function setUp(): void
    {
        $this->db = new \PDO('sqlite::memory:');
        $this->db->setAttribute(
            \PDO::ATTR_ERRMODE,
            \PDO::ERRMODE_EXCEPTION
        );

        $this->db->exec("
            CREATE TABLE chat_rooms (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(64) NOT NULL UNIQUE,
                description VARCHAR(255),
                is_active BOOLEAN NOT NULL DEFAULT 1
            )
        ");

        $this->rooms = new ChatRoomService($this->db);
    }

    public function testResolvesActiveRoomCaseInsensitively(): void
    {
        $this->db->exec("
            INSERT INTO chat_rooms (name, is_active)
            VALUES ('Lateania', 1)
        ");

        self::assertSame(
            [
                'id' => 1,
                'name' => 'Lateania',
            ],
            $this->rooms->resolveActiveRoomByName('lateANIA')
        );
    }

    public function testDoesNotResolveInactiveRoom(): void
    {
        $this->db->exec("
            INSERT INTO chat_rooms (name, is_active)
            VALUES ('Lateania', 0)
        ");

        self::assertNull(
            $this->rooms->resolveActiveRoomByName('Lateania')
        );
    }

    public function testDoesNotResolveUnknownRoom(): void
    {
        self::assertNull(
            $this->rooms->resolveActiveRoomByName('Missing Room')
        );
    }

    public function testBlankRoomNameReturnsNullWithoutQuerying(): void
    {
        self::assertNull(
            $this->rooms->resolveActiveRoomByName('   ')
        );
    }
}
