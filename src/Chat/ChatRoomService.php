<?php

namespace BinktermPHP\Chat;

use BinktermPHP\Database;

class ChatRoomService
{
    private \PDO $db;

    public function __construct(?\PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getPdo();
    }

    /**
     * @return array{id:int,name:string}|null
     */
    public function resolveActiveRoomByName(string $name): ?array
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT id, name
            FROM chat_rooms
            WHERE is_active = TRUE
              AND LOWER(name) = LOWER(?)
            LIMIT 1
        ");
        $stmt->execute([$name]);

        $room = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$room) {
            return null;
        }

        return [
            'id' => (int) $room['id'],
            'name' => (string) $room['name'],
        ];
    }
}
