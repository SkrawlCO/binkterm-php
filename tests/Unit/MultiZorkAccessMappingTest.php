<?php

declare(strict_types=1);

use BinktermPHP\Crossroads\MultiZorkAccessMapping;
use BinktermPHP\Database;
use PHPUnit\Framework\TestCase;

/**
 * Credential-safety proof for the Slice 1 MultiZork access-code mapping:
 * captured correctly, stored, retrieved only for the correct
 * (user, expedition) pair, and never returned for a different user.
 */
final class MultiZorkAccessMappingTest extends TestCase
{
    private PDO $db;
    private int $userId;
    private int $otherUserId;
    private string $expeditionId;

    protected function setUp(): void
    {
        $this->db = Database::getInstance()->getPdo();

        $ids = $this->db->query('SELECT id FROM users ORDER BY id ASC LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
        if (count($ids) < 2) {
            $this->markTestSkipped('Need at least two existing users to test mapping isolation.');
        }
        $this->userId = (int)$ids[0];
        $this->otherUserId = (int)$ids[1];
        $this->expeditionId = 'phpunit-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->db->prepare('DELETE FROM multizork_expedition_credentials WHERE expedition_id = ?')
            ->execute([$this->expeditionId]);
    }

    public function testGetReturnsNullWhenNothingStored(): void
    {
        $mapping = new MultiZorkAccessMapping($this->db);

        $this->assertNull($mapping->get($this->userId, $this->expeditionId));
    }

    public function testSaveThenGetRoundTrips(): void
    {
        $mapping = new MultiZorkAccessMapping($this->db);

        $mapping->save($this->userId, $this->expeditionId, 'Ab12Cd');

        $this->assertSame('Ab12Cd', $mapping->get($this->userId, $this->expeditionId));
    }

    public function testSaveTwiceReplacesTheStoredCode(): void
    {
        $mapping = new MultiZorkAccessMapping($this->db);

        $mapping->save($this->userId, $this->expeditionId, 'First1');
        $mapping->save($this->userId, $this->expeditionId, 'Second2');

        $this->assertSame('Second2', $mapping->get($this->userId, $this->expeditionId));
    }

    public function testCodeIsNotVisibleToADifferentUser(): void
    {
        $mapping = new MultiZorkAccessMapping($this->db);

        $mapping->save($this->userId, $this->expeditionId, 'Secret1');

        $this->assertNull($mapping->get($this->otherUserId, $this->expeditionId));
    }

    public function testOrdinaryUserRowShapeDoesNotLeakTheAccessCodeColumn(): void
    {
        // Guards against the mapping ever being joined into a general-purpose
        // user query by accident: the users table itself must carry nothing
        // named like an access/return code.
        $columns = $this->db->query(
            "SELECT column_name FROM information_schema.columns WHERE table_name = 'users'"
        )->fetchAll(PDO::FETCH_COLUMN);

        foreach ($columns as $column) {
            $this->assertStringNotContainsStringIgnoringCase('access_code', (string)$column);
        }
    }
}
