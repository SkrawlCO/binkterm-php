<?php

declare(strict_types=1);

require_once __DIR__ . '/Support/TestDatabase.php';

use BinktermPHP\Database;
use BinktermPHP\Messaging\SylcHydrator;
use BinktermPHP\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * SylcHydrator performs no visibility filtering of its own — it resolves
 * display fields for a small set of ids that ActivityService has already
 * determined the caller may see. These tests prove it does exactly that
 * narrow lookup and nothing more (no unrelated rows leak in, empty input
 * short-circuits without a query).
 */
final class SylcHydratorTest extends TestCase
{
    private PDO $db;
    private SylcHydrator $hydrator;

    protected function setUp(): void
    {
        $this->db = TestDatabase::pdo();
        Database::setInstanceForTesting($this->db);
        $this->db->beginTransaction();
        foreach (['netmail', 'echomail', 'echoareas'] as $table) {
            $this->db->exec("CREATE TEMP TABLE $table (LIKE public.$table INCLUDING DEFAULTS) ON COMMIT DROP");
        }
        $this->db->prepare("INSERT INTO echoareas (id, tag, domain, is_active) VALUES (1, 'PUBLIC', '', TRUE)")->execute();
        $this->db->prepare("
            INSERT INTO netmail (id, from_address, to_address, from_name, to_name, subject, date_received)
            VALUES (100, '1:1/1', '1:1/2', 'Alice', 'Bob', 'Hello', NOW())
        ")->execute();
        $this->db->prepare("
            INSERT INTO echomail (id, echoarea_id, from_address, from_name, subject, date_received)
            VALUES (200, 1, '1:1/1', 'Carol', 'Re: Topic', NOW())
        ")->execute();
        $this->hydrator = new SylcHydrator($this->db);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->inTransaction()) $this->db->rollBack();
        Database::resetInstanceForTesting();
    }

    public function testHydrateNetmailReturnsRequestedFields(): void
    {
        $rows = $this->hydrator->hydrateNetmail([100]);
        self::assertCount(1, $rows);
        self::assertSame(100, $rows[0]['id']);
        self::assertSame('Alice', $rows[0]['from_name']);
        self::assertSame('Hello', $rows[0]['subject']);
        self::assertNotEmpty($rows[0]['date_received']);
    }

    public function testHydrateEchomailRepliesReturnsRequestedFields(): void
    {
        $rows = $this->hydrator->hydrateEchomailReplies([200]);
        self::assertCount(1, $rows);
        self::assertSame(200, $rows[0]['id']);
        self::assertSame('Carol', $rows[0]['from_name']);
    }

    public function testUnrequestedIdsNeverAppear(): void
    {
        $rows = $this->hydrator->hydrateNetmail([100]);
        self::assertCount(1, $rows, 'only the requested id, nothing else in the table, should be returned');
    }

    public function testEmptyIdListShortCircuitsWithNoQuery(): void
    {
        self::assertSame([], $this->hydrator->hydrateNetmail([]));
        self::assertSame([], $this->hydrator->hydrateEchomailReplies([]));
    }
}
