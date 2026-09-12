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
        $this->db->beginTransaction();

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
        if (isset($this->db) && $this->db->inTransaction()) {
            $this->db->rollBack();
        }
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

    public function testSuccessLeavesNoResidualRow(): void
    {
        $limiter = new MultiZorkAccessRateLimit($this->db);

        $limiter->recordFailure($this->userId, $this->expeditionId);
        $limiter->recordFailure($this->userId, $this->expeditionId);
        $limiter->recordSuccess($this->userId, $this->expeditionId);

        // recordSuccess clears the failure rows and must not insert a
        // success marker — the table would otherwise grow one permanent row
        // per game entry with nothing ever removing it.
        $count = $this->db->prepare(
            'SELECT COUNT(*) FROM multizork_access_attempts WHERE expedition_id = ?'
        );
        $count->execute([$this->expeditionId]);
        $this->assertSame(0, (int) $count->fetchColumn());
    }

    public function testOpportunisticCleanupRemovesAgedRows(): void
    {
        $limiter = new MultiZorkAccessRateLimit($this->db);
        $limiter->recordFailure($this->userId, $this->expeditionId);

        // Age the row past the 1h cleanup horizon.
        $this->db->prepare(
            "UPDATE multizork_access_attempts
             SET attempted_at = NOW() - INTERVAL '2 hours'
             WHERE expedition_id = ?"
        )->execute([$this->expeditionId]);

        // A later record call cleans it opportunistically.
        $limiter->recordFailure($this->otherUserId, $this->expeditionId);

        $rows = $this->db->prepare(
            'SELECT user_id FROM multizork_access_attempts WHERE expedition_id = ?'
        );
        $rows->execute([$this->expeditionId]);
        $remaining = $rows->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame([$this->otherUserId], array_map('intval', $remaining));
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
