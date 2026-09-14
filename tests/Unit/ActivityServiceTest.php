<?php

declare(strict_types=1);

require_once __DIR__ . '/Support/TestDatabase.php';

use BinktermPHP\Database;
use BinktermPHP\MessageHandler;
use BinktermPHP\Messaging\ActivityService;
use BinktermPHP\Messaging\VisitTracker;
use BinktermPHP\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/** Real PostgreSQL queries against transaction-local shadow tables, never caller data. */
final class ActivityServiceTest extends TestCase
{
    private PDO $db;
    private ActivityService $service;
    private VisitTracker $tracker;
    /** @var string[] */
    private array $myAddresses;

    protected function setUp(): void
    {
        $this->db = TestDatabase::pdo();
        Database::setInstanceForTesting($this->db);
        $this->db->beginTransaction();

        foreach (['users', 'netmail', 'echomail', 'echoareas', 'user_echoarea_subscriptions', 'message_read_status', 'user_echomail_ignore_rules'] as $table) {
            $this->db->exec("CREATE TEMP TABLE $table (LIKE public.$table INCLUDING DEFAULTS) ON COMMIT DROP");
        }
        $this->db->exec(file_get_contents(dirname(__DIR__, 2) . '/database/migrations/v20260914180250_add_messaging_visit_columns.sql'));

        $hash = password_hash('activity-test-password', PASSWORD_BCRYPT, ['cost' => 4]);
        $stmt = $this->db->prepare('INSERT INTO users (id, username, real_name, password_hash, is_active, is_system) VALUES (?, ?, ?, ?, TRUE, FALSE)');
        $stmt->execute([1, 'caller1', 'Caller One', $hash]);
        $stmt->execute([2, 'other', 'Other User', $hash]);

        // Areas: 10 = subscribed/public, 11 = sysop-only, 12 = exists but not subscribed.
        $areaStmt = $this->db->prepare('INSERT INTO echoareas (id, tag, domain, is_local, is_sysop_only, is_active) VALUES (?, ?, ?, ?, ?, TRUE)');
        $areaStmt->execute([10, 'PUBLIC', '', 'false', 'false']);
        $areaStmt->execute([11, 'SYSOP', '', 'false', 'true']);
        $areaStmt->execute([12, 'UNSUB', '', 'false', 'false']);

        $this->db->prepare('INSERT INTO user_echoarea_subscriptions (id, user_id, echoarea_id, is_active) VALUES (?, ?, ?, TRUE)')
            ->execute([1, 1, 10]);

        $this->service = new ActivityService($this->db, new MessageHandler(), new VisitTracker());
        $this->tracker = new VisitTracker();
        $this->myAddresses = (new MessageHandler())->netmailMyAddresses();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->inTransaction()) $this->db->rollBack();
        Database::resetInstanceForTesting();
    }

    private function user(int $id = 1, bool $isAdmin = false): array
    {
        return ['user_id' => $id, 'is_admin' => $isAdmin];
    }

    /** Freezes a real, previously-closed visit boundary for the given user and returns it. */
    private function establishBoundary(int $userId = 1): string
    {
        $this->db->exec("UPDATE users SET messaging_visit_renewed_at = NOW() - INTERVAL '20 minutes' WHERE id = {$userId}");
        $this->tracker->renew($userId);
        return (string) $this->db->query("SELECT messaging_visit_boundary_at FROM users WHERE id = {$userId}")->fetchColumn();
    }

    private function toAddress(): string
    {
        return $this->myAddresses[0] ?? '999:1/1';
    }

    private function insertNetmail(int $id, array $overrides = []): void
    {
        $defaults = [
            'id' => $id,
            // Matches real BinkdProcessor::storeNetmail() behavior: an inbound
            // message's user_id is resolved to the local recipient.
            'user_id' => 1,
            'from_address' => '999:2/2',
            'to_address' => $this->toAddress(),
            'from_name' => 'Other Sender',
            'to_name' => 'caller1',
            'subject' => 'Subject',
            'message_text' => 'Body',
            'date_received' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            'deleted_by_sender' => 'false',
            'deleted_by_recipient' => 'false',
        ];
        $row = array_merge($defaults, $overrides);
        $cols = implode(',', array_keys($row));
        $ph = implode(',', array_fill(0, count($row), '?'));
        $this->db->prepare("INSERT INTO netmail ($cols) VALUES ($ph)")->execute(array_values($row));
    }

    private function insertEchomail(int $id, array $overrides = []): void
    {
        $defaults = [
            'id' => $id,
            'echoarea_id' => 10,
            'user_id' => null,
            'from_address' => '999:2/2',
            'from_name' => 'Other Sender',
            'to_name' => 'All',
            'subject' => 'Subject',
            'message_text' => 'Body',
            'reply_to_id' => null,
            'date_received' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ];
        $row = array_merge($defaults, $overrides);
        $cols = implode(',', array_keys($row));
        $ph = implode(',', array_fill(0, count($row), '?'));
        $this->db->prepare("INSERT INTO echomail ($cols) VALUES ($ph)")->execute(array_values($row));
    }

    private function since(int $minutesAgo): string
    {
        return (new DateTimeImmutable("-{$minutesAgo} minutes"))->format('Y-m-d H:i:s');
    }

    // ----- VISIT -----

    public function testFirstVisitHasNoPreviousVisitAndNullSections(): void
    {
        $plan = $this->service->plan($this->user());
        self::assertFalse($plan->hasPreviousVisit);
        self::assertNull($plan->boundaryAt);
        self::assertNull($plan->personal);
        self::assertNull($plan->ambient);
        self::assertSame(['netmailUnread' => 0, 'bulletinUnread' => 0], $plan->unread);
    }

    public function testPriorVisitUsesCorrectFrozenBoundary(): void
    {
        $boundary = $this->establishBoundary();
        $plan = $this->service->plan($this->user());
        self::assertTrue($plan->hasPreviousVisit);
        self::assertSame($boundary, $plan->boundaryAt);
        self::assertIsArray($plan->personal);
        self::assertIsArray($plan->ambient);
    }

    public function testReadUnreadIsIndependentOfVisitState(): void
    {
        // Unread is populated identically whether or not a previous visit exists.
        $this->insertNetmail(500, ['user_id' => 1]);
        $firstVisitPlan = $this->service->plan($this->user());
        self::assertSame(1, $firstVisitPlan->unread['netmailUnread']);

        $this->establishBoundary();
        $priorVisitPlan = $this->service->plan($this->user());
        self::assertSame(1, $priorVisitPlan->unread['netmailUnread']);

        // Marking it read changes unread, but not since-boundary personal presence.
        $this->db->prepare("INSERT INTO message_read_status (id, user_id, message_id, message_type, read_at) VALUES (1, 1, 500, 'netmail', NOW())")->execute();
        $plan = $this->service->plan($this->user());
        self::assertSame(0, $plan->unread['netmailUnread']);
        self::assertContains(500, $plan->personal['netmailIds']);
    }

    // ----- PERSONAL: NETMAIL -----

    public function testVisibleNetmailSinceBoundaryIncluded(): void
    {
        $boundary = $this->establishBoundary();
        $this->insertNetmail(600, ['date_received' => $this->since(1)]);
        $plan = $this->service->plan($this->user());
        self::assertSame([600], $plan->personal['netmailIds']);
    }

    public function testNetmailBeforeBoundaryExcluded(): void
    {
        $this->db->exec("UPDATE users SET messaging_visit_renewed_at = NOW() - INTERVAL '20 minutes' WHERE id = 1");
        $this->tracker->renew(1);
        // Insert dated well before the boundary that was just frozen.
        $this->insertNetmail(601, ['date_received' => $this->since(60)]);
        $plan = $this->service->plan($this->user());
        self::assertSame([], $plan->personal['netmailIds']);
    }

    public function testNetmailForAnotherCallerExcluded(): void
    {
        $boundary = $this->establishBoundary();
        // user_id=2 (a different local user) as well as a non-matching to_name/
        // to_address — not addressed to caller1 by any signal.
        $this->insertNetmail(602, ['user_id' => 2, 'to_name' => 'someoneelse', 'to_address' => '999:9/9', 'date_received' => $this->since(1)]);
        $plan = $this->service->plan($this->user());
        self::assertSame([], $plan->personal['netmailIds']);
    }

    public function testSoftDeletedNetmailExcludedButOtherSideDeleteStillShows(): void
    {
        $boundary = $this->establishBoundary();

        // Caller1 deletes their own received copy via the real, unmodified
        // MessageHandler::deleteNetmail() — proves the soft-delete respected
        // by ActivityService is the one the platform's own delete action
        // actually produces, not a hand-picked flag combination.
        $this->insertNetmail(603, ['date_received' => $this->since(1)]);
        (new MessageHandler())->deleteNetmail(603, 1);

        // A different local user (2) as the sender side of a local-to-local
        // message, addressed to caller1; the sender deletes their own copy
        // while the recipient (caller1) has not deleted theirs.
        $this->insertNetmail(604, ['user_id' => 2, 'date_received' => $this->since(1)]);
        (new MessageHandler())->deleteNetmail(604, 2);

        $plan = $this->service->plan($this->user());
        self::assertNotContains(603, $plan->personal['netmailIds']);
        self::assertContains(604, $plan->personal['netmailIds']);
    }

    // ----- PERSONAL: ECHOMAIL REPLIES -----

    public function testValidEchomailReplyToCallerAuthoredMessageIncluded(): void
    {
        $boundary = $this->establishBoundary();
        $this->insertEchomail(700, ['user_id' => 1, 'date_received' => $this->since(30)]);
        $this->insertEchomail(701, ['reply_to_id' => 700, 'date_received' => $this->since(1)]);
        $plan = $this->service->plan($this->user());
        self::assertSame([701], $plan->personal['replyIds']);
    }

    public function testUnrelatedEchomailExcludedFromReplies(): void
    {
        $boundary = $this->establishBoundary();
        $this->insertEchomail(700, ['user_id' => 1, 'date_received' => $this->since(30)]);
        $this->insertEchomail(702, ['reply_to_id' => null, 'date_received' => $this->since(1)]);
        $plan = $this->service->plan($this->user());
        self::assertSame([], $plan->personal['replyIds']);
    }

    public function testIgnoredSenderReplyExcluded(): void
    {
        $boundary = $this->establishBoundary();
        $this->insertEchomail(700, ['user_id' => 1, 'date_received' => $this->since(30)]);
        $this->insertEchomail(703, ['reply_to_id' => 700, 'from_name' => 'Ignored Sender', 'date_received' => $this->since(1)]);
        $this->db->prepare("INSERT INTO user_echomail_ignore_rules (id, user_id, sender_name, sender_address, subject_contains) VALUES (1, 1, 'Ignored Sender', '999:2/2', '')")->execute();
        $plan = $this->service->plan($this->user());
        self::assertSame([], $plan->personal['replyIds']);
    }

    public function testPendingModeratedReplyFromAnotherUserExcluded(): void
    {
        $boundary = $this->establishBoundary();
        $this->insertEchomail(700, ['user_id' => 1, 'date_received' => $this->since(30)]);
        $this->insertEchomail(704, ['reply_to_id' => 700, 'user_id' => 2, 'moderation_status' => 'pending', 'date_received' => $this->since(1)]);
        $plan = $this->service->plan($this->user());
        self::assertSame([], $plan->personal['replyIds']);
    }

    public function testSysopOnlyAreaReplyExcludedForNonAdmin(): void
    {
        $boundary = $this->establishBoundary();
        $this->insertEchomail(710, ['echoarea_id' => 11, 'user_id' => 1, 'date_received' => $this->since(30)]);
        $this->insertEchomail(711, ['echoarea_id' => 11, 'reply_to_id' => 710, 'date_received' => $this->since(1)]);
        $plan = $this->service->plan($this->user(1, false));
        self::assertSame([], $plan->personal['replyIds']);
        $adminPlan = $this->service->plan($this->user(1, true));
        self::assertSame([711], $adminPlan->personal['replyIds']);
    }

    public function testNoNetmailReplyClassificationIsExposed(): void
    {
        // The ActivityPlan/PersonalActivity shape has no netmail-reply field at all.
        $boundary = $this->establishBoundary();
        $plan = $this->service->plan($this->user());
        self::assertSame(['netmailIds', 'netmailTruncated', 'replyIds', 'repliesTruncated'], array_keys($plan->personal));
    }

    // ----- AMBIENT -----

    public function testVisibleSubscribedAreaMessageSinceBoundaryCounted(): void
    {
        $boundary = $this->establishBoundary();
        $this->insertEchomail(800, ['date_received' => $this->since(1)]);
        $plan = $this->service->plan($this->user());
        self::assertCount(1, $plan->ambient['areas']);
        self::assertSame(10, $plan->ambient['areas'][0]['echoareaId']);
        self::assertSame(1, $plan->ambient['areas'][0]['sinceBoundaryCount']);
        self::assertFalse($plan->ambient['areas'][0]['isLocal']);
    }

    public function testMessageBeforeBoundaryNotCounted(): void
    {
        $this->db->exec("UPDATE users SET messaging_visit_renewed_at = NOW() - INTERVAL '20 minutes' WHERE id = 1");
        $this->tracker->renew(1);
        $this->insertEchomail(801, ['date_received' => $this->since(60)]);
        $plan = $this->service->plan($this->user());
        self::assertSame([], $plan->ambient['areas']);
    }

    public function testUnreadAndSinceBoundaryCountsRemainDistinct(): void
    {
        $boundary = $this->establishBoundary();
        // Since-boundary but already read.
        $this->insertEchomail(802, ['date_received' => $this->since(1)]);
        $this->db->prepare("INSERT INTO message_read_status (id, user_id, message_id, message_type, read_at) VALUES (2, 1, 802, 'echomail', NOW())")->execute();
        $plan = $this->service->plan($this->user());
        // Still counted as since-boundary ambient activity...
        self::assertSame(1, $plan->ambient['areas'][0]['sinceBoundaryCount']);
        // ...this service does not expose a per-area unread figure at all (Decision 12:
        // existing surface watermarks/unread stay local to their own existing UX).
        self::assertArrayNotHasKey('unread', $plan->ambient['areas'][0]);
    }

    public function testZeroInaccessibleSysopOnlyAreaContributesZero(): void
    {
        $boundary = $this->establishBoundary();
        // Subscribed in both cases, isolating is_admin as the only variable —
        // a defense-in-depth check that the query itself gates on
        // is_sysop_only, independent of whether a subscription row exists
        // (e.g. a caller demoted from admin after subscribing).
        $this->db->prepare('INSERT INTO user_echoarea_subscriptions (id, user_id, echoarea_id, is_active) VALUES (4, 1, 11, TRUE)')->execute();
        $this->insertEchomail(810, ['echoarea_id' => 11, 'date_received' => $this->since(1)]);
        $plan = $this->service->plan($this->user(1, false));
        self::assertSame([], $plan->ambient['areas']);
        $adminPlan = $this->service->plan($this->user(1, true));
        self::assertCount(1, $adminPlan->ambient['areas']);
        self::assertSame(11, $adminPlan->ambient['areas'][0]['echoareaId']);
    }

    public function testUnsubscribedAreaContributesZeroRegardlessOfContent(): void
    {
        $boundary = $this->establishBoundary();
        $this->insertEchomail(820, ['echoarea_id' => 12, 'date_received' => $this->since(1)]);
        $plan = $this->service->plan($this->user());
        self::assertSame([], $plan->ambient['areas']);
    }

    public function testPendingModeratedContentContributesZero(): void
    {
        $boundary = $this->establishBoundary(1);
        $this->establishBoundary(2);
        $this->db->prepare('INSERT INTO user_echoarea_subscriptions (id, user_id, echoarea_id, is_active) VALUES (5, 2, 10, TRUE)')->execute();
        $this->insertEchomail(830, ['user_id' => 2, 'moderation_status' => 'pending', 'date_received' => $this->since(1)]);
        $plan = $this->service->plan($this->user());
        self::assertSame([], $plan->ambient['areas']);
        // But the author (user 2) does see their own pending post.
        $authorPlan = $this->service->plan($this->user(2, false));
        self::assertCount(1, $authorPlan->ambient['areas']);
    }

    public function testIgnoredSuppressedContentContributesZero(): void
    {
        $boundary = $this->establishBoundary();
        $this->insertEchomail(840, ['from_name' => 'Ignored Sender', 'date_received' => $this->since(1)]);
        $this->db->prepare("INSERT INTO user_echomail_ignore_rules (id, user_id, sender_name, sender_address, subject_contains) VALUES (1, 1, 'Ignored Sender', '999:2/2', '')")->execute();
        $plan = $this->service->plan($this->user());
        self::assertSame([], $plan->ambient['areas']);
    }

    public function testAreaOrderingAndZeroCountAreasAbsent(): void
    {
        $boundary = $this->establishBoundary();
        $this->db->prepare('INSERT INTO echoareas (id, tag, domain, is_active) VALUES (13, ?, ?, TRUE)')->execute(['AAA', '']);
        $this->db->prepare('INSERT INTO user_echoarea_subscriptions (id, user_id, echoarea_id, is_active) VALUES (2, 1, 13, TRUE)')->execute();
        $this->insertEchomail(850, ['echoarea_id' => 10, 'date_received' => $this->since(1)]);
        $this->insertEchomail(851, ['echoarea_id' => 13, 'date_received' => $this->since(1)]);
        // Area 10 tag 'PUBLIC' has no new message beyond the one above; add a
        // second subscribed-but-silent area to prove absence, not a zero row.
        $this->db->prepare('INSERT INTO echoareas (id, tag, domain, is_active) VALUES (14, ?, ?, TRUE)')->execute(['ZZZ', '']);
        $this->db->prepare('INSERT INTO user_echoarea_subscriptions (id, user_id, echoarea_id, is_active) VALUES (3, 1, 14, TRUE)')->execute();

        $plan = $this->service->plan($this->user());
        $tags = array_column($plan->ambient['areas'], 'tag');
        self::assertSame(['AAA', 'PUBLIC'], $tags); // tag ASC; ZZZ (silent) absent entirely
    }

    // ----- CONTRACT -----

    public function testIdsAndCountsOnlyNoMessageBodyHydration(): void
    {
        $boundary = $this->establishBoundary();
        $this->insertNetmail(900, ['date_received' => $this->since(1)]);
        $this->insertEchomail(901, ['date_received' => $this->since(1)]);
        $plan = $this->service->plan($this->user());
        $encoded = json_encode($plan);
        self::assertStringNotContainsString('Body', $encoded);
        self::assertStringNotContainsString('Subject', $encoded);
    }

    public function testServiceDoesNotMutateReadState(): void
    {
        $boundary = $this->establishBoundary();
        $this->insertNetmail(910, ['date_received' => $this->since(1)]);
        $this->service->plan($this->user());
        $count = (int) $this->db->query('SELECT COUNT(*) FROM message_read_status')->fetchColumn();
        self::assertSame(0, $count);
    }

    public function testServiceDoesNotMutateVisitState(): void
    {
        $boundary = $this->establishBoundary();
        $before = $this->db->query('SELECT messaging_visit_boundary_at, messaging_visit_renewed_at FROM users WHERE id = 1')->fetch();
        $this->service->plan($this->user());
        $after = $this->db->query('SELECT messaging_visit_boundary_at, messaging_visit_renewed_at FROM users WHERE id = 1')->fetch();
        self::assertSame($before['messaging_visit_boundary_at'], $after['messaging_visit_boundary_at']);
        self::assertSame($before['messaging_visit_renewed_at'], $after['messaging_visit_renewed_at']);
    }
}
