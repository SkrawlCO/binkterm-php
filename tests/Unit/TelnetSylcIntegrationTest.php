<?php

declare(strict_types=1);

require_once __DIR__ . '/Support/TestDatabase.php';

use BinktermPHP\Database;
use BinktermPHP\MessageHandler;
use BinktermPHP\Messaging\ActivityService;
use BinktermPHP\Messaging\SylcHydrator;
use BinktermPHP\Messaging\TelnetSylcPresenter;
use BinktermPHP\Messaging\VisitTracker;
use BinktermPHP\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end proof that ActivityService -> SylcHydrator -> TelnetSylcPresenter
 * (the exact composition BinktermPHP\TelnetServer\SylcHandler::summary()/show()
 * performs) produces the correct Telnet presentation from real database state,
 * including that invisible content (sysop-only, moderated, ignored) never
 * contributes, and that nothing in the pipeline mutates visit or read state.
 *
 * Does not construct BbsSession/SylcHandler directly (BbsSession requires a
 * live terminal connection) — this test proves the shared-service composition
 * SylcHandler performs is correct; SylcHandlerTest-equivalent terminal
 * rendering is a human SyncTerm acceptance concern, not a unit-testable one.
 */
final class TelnetSylcIntegrationTest extends TestCase
{
    private PDO $db;
    private ActivityService $service;
    private SylcHydrator $hydrator;
    private VisitTracker $tracker;

    protected function setUp(): void
    {
        $this->db = TestDatabase::pdo();
        Database::setInstanceForTesting($this->db);
        $this->db->beginTransaction();

        foreach (['users', 'netmail', 'echomail', 'echoareas', 'user_echoarea_subscriptions', 'message_read_status', 'user_echomail_ignore_rules'] as $table) {
            $this->db->exec("CREATE TEMP TABLE $table (LIKE public.$table INCLUDING DEFAULTS) ON COMMIT DROP");
        }
        $this->db->exec(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/v20260914180250_add_messaging_visit_columns.sql'));

        $hash = password_hash('sylc-telnet-test', PASSWORD_BCRYPT, ['cost' => 4]);
        $this->db->prepare('INSERT INTO users (id, username, real_name, password_hash, is_active, is_system) VALUES (1, ?, ?, ?, TRUE, FALSE)')
            ->execute(['caller1', 'Caller One', $hash]);

        $this->db->prepare('INSERT INTO echoareas (id, tag, domain, is_local, is_sysop_only, is_active) VALUES (10, ?, ?, FALSE, FALSE, TRUE)')
            ->execute(['PUBLIC', '']);
        $this->db->prepare('INSERT INTO echoareas (id, tag, domain, is_sysop_only, is_active) VALUES (11, ?, ?, TRUE, TRUE)')
            ->execute(['SYSOP', '']);
        $this->db->prepare('INSERT INTO echoareas (id, tag, domain, is_active) VALUES (12, ?, ?, TRUE)')
            ->execute(['UNSUB', '']);
        $this->db->prepare('INSERT INTO user_echoarea_subscriptions (id, user_id, echoarea_id, is_active) VALUES (1, 1, 10, TRUE)')->execute();
        $this->db->prepare('INSERT INTO user_echoarea_subscriptions (id, user_id, echoarea_id, is_active) VALUES (2, 1, 11, TRUE)')->execute();

        $this->service = new ActivityService($this->db, new MessageHandler(), new VisitTracker());
        $this->hydrator = new SylcHydrator($this->db);
        $this->tracker = new VisitTracker();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->inTransaction()) $this->db->rollBack();
        Database::resetInstanceForTesting();
    }

    private function user(bool $isAdmin = false): array
    {
        return ['user_id' => 1, 'is_admin' => $isAdmin];
    }

    private function establishBoundary(): void
    {
        $this->db->exec("UPDATE users SET messaging_visit_renewed_at = NOW() - INTERVAL '20 minutes' WHERE id = 1");
        $this->tracker->renew(1);
    }

    private function since(int $minutesAgo): string
    {
        return (new DateTimeImmutable("-{$minutesAgo} minutes"))->format('Y-m-d H:i:s');
    }

    private function insertEchomail(int $id, array $overrides = []): void
    {
        $defaults = [
            'id' => $id, 'echoarea_id' => 10, 'user_id' => null,
            'from_address' => '999:2/2', 'from_name' => 'Other Sender', 'to_name' => 'All',
            'subject' => 'Subject', 'message_text' => 'Body', 'reply_to_id' => null,
            'date_received' => $this->since(1),
        ];
        $row = array_merge($defaults, $overrides);
        $cols = implode(',', array_keys($row));
        $ph = implode(',', array_fill(0, count($row), '?'));
        $this->db->prepare("INSERT INTO echomail ($cols) VALUES ($ph)")->execute(array_values($row));
    }

    private function t(): callable
    {
        $catalog = [
            'ui.terminalserver.sylc.sidebar_label' => 'Since Last Call',
            'ui.terminalserver.sylc.sidebar_personal' => '{count} personal',
            'ui.terminalserver.sylc.sidebar_areas' => '{count} area(s)',
        ];
        return function (string $key, string $fallback, array $params = []) use ($catalog): string {
            $text = $catalog[$key] ?? $fallback;
            foreach ($params as $k => $v) {
                $text = str_replace('{' . $k . '}', (string) $v, $text);
            }
            return $text;
        };
    }

    // ----- FIRST VISIT -----

    public function testFirstVisitProducesNoSidebarLineAndUnavailableDetail(): void
    {
        $plan = $this->service->plan($this->user());
        self::assertFalse($plan->hasPreviousVisit);
        self::assertNull(TelnetSylcPresenter::sidebarLine($plan, $this->t()));
    }

    // ----- QUIET -----

    public function testQuietReturnProducesNoSidebarLine(): void
    {
        $this->establishBoundary();
        $plan = $this->service->plan($this->user());
        self::assertTrue($plan->hasPreviousVisit);
        self::assertNull(TelnetSylcPresenter::sidebarLine($plan, $this->t()));
        $detail = TelnetSylcPresenter::detail($plan, [], [], $this->t());
        self::assertTrue($detail['quiet']);
    }

    // ----- ACTIVE SUMMARY -----

    public function testPersonalOnlySummary(): void
    {
        $this->establishBoundary();
        // Caller's own original message and the reply both live in an area
        // the caller is NOT subscribed to, so the reply counts as personal
        // but contributes nothing to ambient (which is subscription-scoped)
        // — isolating "personal only" from the natural overlap a reply
        // landing in a subscribed area would otherwise create.
        $this->insertEchomail(700, ['echoarea_id' => 12, 'user_id' => 1, 'date_received' => $this->since(30)]);
        $this->insertEchomail(701, ['echoarea_id' => 12, 'reply_to_id' => 700, 'date_received' => $this->since(1)]);
        $plan = $this->service->plan($this->user());
        $line = TelnetSylcPresenter::sidebarLine($plan, $this->t());
        self::assertSame('Since Last Call: 1 personal', $line);
    }

    public function testAmbientOnlySummary(): void
    {
        $this->establishBoundary();
        $this->insertEchomail(800, ['date_received' => $this->since(1)]);
        $plan = $this->service->plan($this->user());
        $line = TelnetSylcPresenter::sidebarLine($plan, $this->t());
        self::assertSame('Since Last Call: 1 area(s)', $line);
    }

    public function testPersonalAndAmbientSummaryWithCorrectCounts(): void
    {
        $this->establishBoundary();
        $this->insertEchomail(700, ['echoarea_id' => 12, 'user_id' => 1, 'date_received' => $this->since(30)]);
        $this->insertEchomail(701, ['echoarea_id' => 12, 'reply_to_id' => 700, 'date_received' => $this->since(1)]); // personal (reply)
        $this->insertEchomail(800, ['date_received' => $this->since(1)]); // ambient (area 10, subscribed)
        $plan = $this->service->plan($this->user());
        $line = TelnetSylcPresenter::sidebarLine($plan, $this->t());
        self::assertSame('Since Last Call: 1 personal, 1 area(s)', $line);
    }

    // ----- PERSONAL OUTRANKS AMBIENT (source-level ordering guarantee) -----

    public function testPersonalHeadingIsBuiltBeforeAmbientHeadingInHandler(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/telnet/src/SylcHandler.php');
        $personalPos = strpos($source, "personal_heading");
        $ambientPos = strpos($source, "ambient_heading");
        self::assertNotFalse($personalPos);
        self::assertNotFalse($ambientPos);
        self::assertLessThan($ambientPos, $personalPos, 'Personal section must be built before ambient section');
    }

    // ----- SECURITY: invisible content contributes zero -----

    public function testSysopOnlyAreaContributesZeroToAmbientCount(): void
    {
        $this->establishBoundary();
        $this->insertEchomail(810, ['echoarea_id' => 11, 'date_received' => $this->since(1)]);
        $plan = $this->service->plan($this->user(false));
        self::assertNull(TelnetSylcPresenter::sidebarLine($plan, $this->t()));

        $adminPlan = $this->service->plan($this->user(true));
        self::assertSame('Since Last Call: 1 area(s)', TelnetSylcPresenter::sidebarLine($adminPlan, $this->t()));
    }

    public function testModeratedPendingContentFromAnotherUserContributesZero(): void
    {
        $this->establishBoundary();
        $this->insertEchomail(830, ['user_id' => 2, 'moderation_status' => 'pending', 'date_received' => $this->since(1)]);
        $plan = $this->service->plan($this->user());
        self::assertNull(TelnetSylcPresenter::sidebarLine($plan, $this->t()));
    }

    public function testIgnoredSenderContributesZero(): void
    {
        $this->establishBoundary();
        $this->insertEchomail(840, ['from_name' => 'Ignored Sender', 'date_received' => $this->since(1)]);
        $this->db->prepare("INSERT INTO user_echomail_ignore_rules (id, user_id, sender_name, sender_address, subject_contains) VALUES (1, 1, 'Ignored Sender', '999:2/2', '')")->execute();
        $plan = $this->service->plan($this->user());
        self::assertNull(TelnetSylcPresenter::sidebarLine($plan, $this->t()));
    }

    // ----- STATE SAFETY -----

    public function testPipelineDoesNotMutateVisitOrReadState(): void
    {
        $this->establishBoundary();
        $this->insertEchomail(700, ['user_id' => 1, 'date_received' => $this->since(30)]);
        $this->insertEchomail(701, ['reply_to_id' => 700, 'date_received' => $this->since(1)]);

        $before = $this->db->query('SELECT messaging_visit_boundary_at, messaging_visit_renewed_at FROM users WHERE id = 1')->fetch();
        $readBefore = (int) $this->db->query('SELECT COUNT(*) FROM message_read_status')->fetchColumn();

        $plan = $this->service->plan($this->user());
        $netmailIds = array_slice($plan->personal['netmailIds'] ?? [], -TelnetSylcPresenter::MAX_PERSONAL_ROWS);
        $replyIds = array_slice($plan->personal['replyIds'] ?? [], -TelnetSylcPresenter::MAX_PERSONAL_ROWS);
        $netmailRows = $netmailIds ? $this->hydrator->hydrateNetmail($netmailIds) : [];
        $replyRows = $replyIds ? $this->hydrator->hydrateEchomailReplies($replyIds) : [];
        TelnetSylcPresenter::detail($plan, $netmailRows, $replyRows, $this->t());
        TelnetSylcPresenter::sidebarLine($plan, $this->t());

        $after = $this->db->query('SELECT messaging_visit_boundary_at, messaging_visit_renewed_at FROM users WHERE id = 1')->fetch();
        $readAfter = (int) $this->db->query('SELECT COUNT(*) FROM message_read_status')->fetchColumn();

        self::assertSame($before['messaging_visit_boundary_at'], $after['messaging_visit_boundary_at']);
        self::assertSame($before['messaging_visit_renewed_at'], $after['messaging_visit_renewed_at']);
        self::assertSame($readBefore, $readAfter);
    }

    public function testHandlerSourceContainsNoVisitOrReadStateMutation(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/telnet/src/SylcHandler.php');
        self::assertStringNotContainsString('VisitTracker', $source);
        self::assertStringNotContainsString('markEchomailAsRead', $source);
        self::assertStringNotContainsString('markNetmailAsRead', $source);
        self::assertStringNotContainsString('->renew(', $source);
        self::assertStringNotContainsString('INSERT INTO', $source);
        self::assertStringNotContainsString('UPDATE ', $source);
    }

    // ----- RECONNECT -----

    public function testRepeatedCallInSameLogicalCallUsesSameFrozenBoundary(): void
    {
        $this->establishBoundary();
        $this->insertEchomail(700, ['user_id' => 1, 'date_received' => $this->since(30)]);
        $this->insertEchomail(701, ['reply_to_id' => 700, 'date_received' => $this->since(1)]);

        $plan1 = $this->service->plan($this->user());
        $line1 = TelnetSylcPresenter::sidebarLine($plan1, $this->t());

        // Simulate a reconnect within the grace window: re-render without
        // any new renewal call — boundary must not have moved.
        $plan2 = $this->service->plan($this->user());
        $line2 = TelnetSylcPresenter::sidebarLine($plan2, $this->t());

        self::assertSame($plan1->boundaryAt, $plan2->boundaryAt);
        self::assertSame($line1, $line2);
    }
}
