<?php

declare(strict_types=1);

use BinktermPHP\Crossroads\MultiZorkAccessRateLimit;
use BinktermPHP\Database;
use PHPUnit\Framework\TestCase;

/**
 * Rate-limit proof for MultiZork access-code submission, mirroring
 * PacketBbsLoginRateLimitTest-style coverage: failures counted, threshold
 * blocks, success clears prior failures, and one user's attempts cannot
 * affect another user's limiter state.
 */
final class MultiZorkAccessRateLimitTest extends TestCase
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
            $this->markTestSkipped('Need at least two existing users to test limiter isolation.');
        }
        $this->userId = (int)$ids[0];
        $this->otherUserId = (int)$ids[1];
        $this->expeditionId = 'phpunit-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->db->prepare('DELETE FROM multizork_access_attempts WHERE expedition_id = ?')
            ->execute([$this->expeditionId]);
    }

    public function testAllowsSubmissionWithNoPriorAttempts(): void
    {
        $limiter = new MultiZorkAccessRateLimit($this->db);

        $this->assertTrue($limiter->check($this->userId, $this->expeditionId));
    }

    public function testBlocksAfterFiveFailures(): void
    {
        $limiter = new MultiZorkAccessRateLimit($this->db);

        for ($i = 0; $i < 5; $i++) {
            $limiter->recordFailure($this->userId, $this->expeditionId);
        }

        $this->assertFalse($limiter->check($this->userId, $this->expeditionId));
    }

    public function testFourFailuresDoNotYetBlock(): void
    {
        $limiter = new MultiZorkAccessRateLimit($this->db);

        for ($i = 0; $i < 4; $i++) {
            $limiter->recordFailure($this->userId, $this->expeditionId);
        }

        $this->assertTrue($limiter->check($this->userId, $this->expeditionId));
    }

    public function testSuccessClearsPriorFailures(): void
    {
        $limiter = new MultiZorkAccessRateLimit($this->db);

        for ($i = 0; $i < 5; $i++) {
            $limiter->recordFailure($this->userId, $this->expeditionId);
        }
        $this->assertFalse($limiter->check($this->userId, $this->expeditionId));

        $limiter->recordSuccess($this->userId, $this->expeditionId);

        $this->assertTrue($limiter->check($this->userId, $this->expeditionId));
    }

    public function testOneUsersFailuresDoNotBlockAnotherUser(): void
    {
        $limiter = new MultiZorkAccessRateLimit($this->db);

        for ($i = 0; $i < 5; $i++) {
            $limiter->recordFailure($this->userId, $this->expeditionId);
        }

        $this->assertFalse($limiter->check($this->userId, $this->expeditionId));
        $this->assertTrue($limiter->check($this->otherUserId, $this->expeditionId));
    }

    public function testAccessCodeIsNeverPersistedInTheAttemptsTable(): void
    {
        $columns = $this->db->query(
            "SELECT column_name FROM information_schema.columns WHERE table_name = 'multizork_access_attempts'"
        )->fetchAll(PDO::FETCH_COLUMN);

        foreach ($columns as $column) {
            $this->assertStringNotContainsStringIgnoringCase('access_code', (string)$column);
            $this->assertStringNotContainsStringIgnoringCase('code', (string)$column);
        }
    }
}
