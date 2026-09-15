<?php

declare(strict_types=1);

require_once __DIR__ . '/Support/TestDatabase.php';

use BinktermPHP\Database;
use BinktermPHP\MessageHandler;
use BinktermPHP\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Real PostgreSQL queries against transaction-local shadow tables — proves
 * Messaging Evolution Phase 1's `echomail.root_id` resolve/propagate
 * lifecycle is deterministic and eventually-correct under out-of-order
 * arrival, mirroring the exact write/backfill-event design in
 * /root/L33TEST_Messaging_Phase1_Personal_Relevance_Design_2026-09-14.md
 * (Track B) and its implementation report.
 */
final class EchomailRootIdLifecycleTest extends TestCase
{
    private PDO $db;
    private MessageHandler $handler;

    protected function setUp(): void
    {
        $this->db = TestDatabase::pdo();
        Database::setInstanceForTesting($this->db);
        $this->db->beginTransaction();

        foreach (['echoareas', 'echomail'] as $table) {
            $this->db->exec("CREATE TEMP TABLE $table (LIKE public.$table INCLUDING DEFAULTS) ON COMMIT DROP");
        }

        $this->db->prepare('INSERT INTO echoareas (id, tag, domain, is_active) VALUES (1, ?, ?, TRUE)')->execute(['TEST', '']);

        $this->handler = new MessageHandler();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->inTransaction()) $this->db->rollBack();
        Database::resetInstanceForTesting();
    }

    private function insertEchomail(int $id, ?int $replyToId, ?string $kludgeReply = null): void
    {
        $stmt = $this->db->prepare('
            INSERT INTO echomail (id, echoarea_id, from_address, from_name, to_name, subject, message_text, reply_to_id, kludge_lines, date_received)
            VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?, NOW())
        ');
        $stmt->execute([
            $id, '999:2/2', 'Sender', 'All', 'Subject', 'Body', $replyToId,
            $kludgeReply !== null ? "\x01REPLY: {$kludgeReply}" : null,
        ]);
    }

    private function rootIdOf(int $id): ?int
    {
        $v = $this->db->query("SELECT root_id FROM echomail WHERE id = {$id}")->fetchColumn();
        return $v === false || $v === null ? null : (int) $v;
    }

    // ----- basic resolution -----

    public function testGenuineRootSelfAssignsRootId(): void
    {
        $this->insertEchomail(1, null);
        $rootId = $this->handler->resolveEchomailRootId(1, null, false);
        self::assertSame(1, $rootId);
        self::assertSame(1, $this->rootIdOf(1));
    }

    public function testChildInheritsResolvedParentRootId(): void
    {
        $this->insertEchomail(1, null);
        $this->handler->resolveEchomailRootId(1, null, false);

        $this->insertEchomail(2, 1);
        $rootId = $this->handler->resolveEchomailRootId(2, 1, false);
        self::assertSame(1, $rootId);
        self::assertSame(1, $this->rootIdOf(2));
    }

    public function testUnresolvedParentReferenceLeavesRootIdNullNotSelfAssigned(): void
    {
        // A message carrying a REPLY reference whose target hasn't arrived
        // yet must NOT be treated as a genuine root — root_id stays NULL
        // until the real parent arrives (see Track B's orphan-vs-root
        // distinction).
        $this->insertEchomail(2, null, 'MSGID-NOT-YET-ARRIVED');
        $rootId = $this->handler->resolveEchomailRootId(2, null, true);
        self::assertNull($rootId);
        self::assertNull($this->rootIdOf(2));
    }

    public function testParentExistsButItsOwnRootUnresolvedLeavesChildUnresolvedToo(): void
    {
        // Parent row exists (so reply_to_id could be resolved) but the
        // parent's own root_id is still NULL (it is itself mid-chain,
        // waiting on a still-missing ancestor).
        $this->insertEchomail(1, null, 'GRANDPARENT-NOT-YET-ARRIVED'); // parent: itself an unresolved orphan
        $this->handler->resolveEchomailRootId(1, null, true); // stays NULL

        $this->insertEchomail(2, 1);
        $rootId = $this->handler->resolveEchomailRootId(2, 1, false);
        self::assertNull($rootId);
        self::assertNull($this->rootIdOf(2));
    }

    // ----- out-of-order: child before parent -----

    public function testChildArrivesBeforeParentThenResolvesOnParentArrival(): void
    {
        // Child arrives first, referencing a parent MSGID not yet present.
        $this->insertEchomail(2, null, 'PARENT-MSGID');
        $childRoot = $this->handler->resolveEchomailRootId(2, null, true);
        self::assertNull($childRoot);
        self::assertNull($this->rootIdOf(2));

        // Parent now arrives. Mirrors BinkdProcessor's own existing
        // reply_to_id backfill: the caller finds the orphan and backfills
        // reply_to_id = parent's id BEFORE calling resolve/propagate.
        $this->insertEchomail(1, null);
        $this->db->prepare('UPDATE echomail SET reply_to_id = 1 WHERE id = 2 AND reply_to_id IS NULL')->execute();

        $rootId = $this->handler->resolveEchomailRootId(1, null, false);
        self::assertSame(1, $rootId);
        $propagated = $this->handler->propagateEchomailRootId(1, $rootId);

        self::assertSame(1, $propagated);
        self::assertSame(1, $this->rootIdOf(1));
        self::assertSame(1, $this->rootIdOf(2)); // corrected from its earlier NULL, not left wrong
    }

    // ----- out-of-order: parent before root (multi-level cascade) -----

    public function testMultiLevelChainResolvesWhenRootFinallyArrivesLast(): void
    {
        // Grandchild (3) arrives referencing child (2), which hasn't arrived.
        $this->insertEchomail(3, null, 'CHILD-MSGID');
        $this->handler->resolveEchomailRootId(3, null, true); // stays NULL

        // Child (2) arrives next, referencing root (1), which hasn't arrived
        // either. Backfill wires grandchild (3) -> child (2).
        $this->insertEchomail(2, null, 'ROOT-MSGID');
        $this->db->prepare('UPDATE echomail SET reply_to_id = 2 WHERE id = 3 AND reply_to_id IS NULL')->execute();
        $childRoot = $this->handler->resolveEchomailRootId(2, null, true); // still unresolved -- root (1) hasn't arrived
        self::assertNull($childRoot);
        self::assertNull($this->rootIdOf(2));
        self::assertNull($this->rootIdOf(3)); // never touched -- nothing to propagate yet

        // Root (1) finally arrives last. Backfill wires child (2) -> root (1).
        $this->insertEchomail(1, null);
        $this->db->prepare('UPDATE echomail SET reply_to_id = 1 WHERE id = 2 AND reply_to_id IS NULL')->execute();
        $rootId = $this->handler->resolveEchomailRootId(1, null, false);
        self::assertSame(1, $rootId);

        // One propagation call from the anchor (root, id 1) must cascade
        // through BOTH levels in a single bounded recursive walk -- not
        // require the caller to manually chase each descendant.
        $propagated = $this->handler->propagateEchomailRootId(1, $rootId);
        self::assertSame(2, $propagated); // both id 2 and id 3 corrected

        self::assertSame(1, $this->rootIdOf(1));
        self::assertSame(1, $this->rootIdOf(2));
        self::assertSame(1, $this->rootIdOf(3));
    }

    public function testPropagationNeverOverwritesAlreadyResolvedDescendant(): void
    {
        // A descendant whose root_id is already correctly resolved must be
        // left untouched by a later propagation call for the same anchor
        // (idempotence / no accidental double-write).
        $this->insertEchomail(1, null);
        $this->handler->resolveEchomailRootId(1, null, false);
        $this->insertEchomail(2, 1);
        $this->handler->resolveEchomailRootId(2, 1, false);

        $propagatedAgain = $this->handler->propagateEchomailRootId(1, 1);
        self::assertSame(0, $propagatedAgain); // nothing left to fix
        self::assertSame(1, $this->rootIdOf(2));
    }

    public function testMissingAncestryNeverForcesAnIncorrectGuess(): void
    {
        // An orphan whose parent never arrives (within this test's scope)
        // must simply stay NULL forever -- never guess a wrong root
        // (e.g. self-assign) merely because ancestry is incomplete.
        $this->insertEchomail(5, null, 'NEVER-ARRIVES');
        $this->handler->resolveEchomailRootId(5, null, true);
        self::assertNull($this->rootIdOf(5));
    }
}
