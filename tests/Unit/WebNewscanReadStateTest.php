<?php

declare(strict_types=1);

use BinktermPHP\Database;
use BinktermPHP\MessageHandler;
use BinktermPHP\Newscan\UnifiedNewscanService;
use BinktermPHP\Newscan\WebNewscanSummary;
use PHPUnit\Framework\TestCase;

/** Real canonical queries, transaction-local fixtures, write-rejecting source tables. */
final class WebNewscanReadStateTest extends TestCase
{
    private PDO $db;
    private UnifiedNewscanService $service;
    private const TABLES = ['users','users_meta','netmail','files','saved_messages','message_read_status','echoareas','echomail','user_echoarea_subscriptions','user_echomail_ignore_rules','bulletins','bulletin_reads'];

    protected function setUp(): void
    {
        $this->db = Database::getInstance()->getPdo();
        $this->db->beginTransaction();
        foreach (self::TABLES as $table) {
            $this->db->exec("CREATE TEMP TABLE $table (LIKE public.$table INCLUDING DEFAULTS) ON COMMIT DROP");
            // Never advance a production serial sequence inherited by LIKE.
            $columns = $this->db->query("SELECT a.attname FROM pg_attribute a JOIN pg_attrdef d ON d.adrelid=a.attrelid AND d.adnum=a.attnum WHERE a.attrelid='pg_temp.$table'::regclass AND pg_get_expr(d.adbin,d.adrelid) LIKE 'nextval(%'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($columns as $column) $this->db->exec("ALTER TABLE pg_temp.$table ALTER COLUMN \"$column\" SET DEFAULT 0");
        }
        $this->db->exec("INSERT INTO users (id,username,real_name,password_hash,is_active,is_admin) VALUES (7,'newscan_fixture','Newscan Fixture','unused',TRUE,FALSE)");
        // The global visit snapshot acknowledges every fixture message, but none
        // of the canonical per-area unread state should be consumed by it.
        $this->db->exec("INSERT INTO users_meta (user_id,keyname,valname) VALUES (7,'last_visit_echomail_max_id','99999999')");
        $this->db->exec("INSERT INTO netmail (id,user_id,from_address,to_address,from_name,to_name,subject) VALUES
            (1,7,'999:999/998','999:999/999','Sender','newscan_fixture','Unread'),
            (2,7,'999:999/998','999:999/999','Sender','newscan_fixture','Read'),
            (3,9,'999:999/998','999:999/999','Sender','Someone Else','Other caller')");
        $this->db->exec("INSERT INTO echoareas (id,tag,domain,is_active,is_sysop_only) VALUES
            (1,'VISIBLE','test',TRUE,FALSE), (2,'HIDDEN_SYSOP','test',TRUE,TRUE),
            (3,'UNSUBSCRIBED','test',TRUE,FALSE), (4,'INACTIVE','test',FALSE,FALSE),
            (5,'DISABLED_SUBSCRIPTION','test',TRUE,FALSE)");
        $this->db->exec("INSERT INTO user_echoarea_subscriptions (user_id,echoarea_id,is_active,last_read_id) VALUES
            (7,1,TRUE,100),(7,2,TRUE,0),(7,4,TRUE,0),(7,5,FALSE,0)");
        $this->db->exec("INSERT INTO echomail (id,echoarea_id,from_address,from_name,subject,date_written,moderation_status,user_id) VALUES
            (100,1,'999:999/998','Sender','Below watermark',NOW(),'approved',9),
            (101,1,'999:999/998','Sender','New',NOW(),'approved',9),
            (102,1,'999:999/998','Sender','Read individually',NOW(),'approved',9),
            (103,1,'999:999/998','Sender','Future',NOW()+INTERVAL '1 day','approved',9),
            (104,1,'999:999/998','Sender','Pending other',NOW(),'pending',9),
            (105,1,'999:999/998','Ignored','Ignored sender',NOW(),'approved',9),
            (106,1,'999:999/998','Self','Own pending',NOW(),'pending',7),
            (201,2,'999:999/998','Sender','Sysop',NOW(),'approved',9),
            (301,3,'999:999/998','Sender','Unsubscribed',NOW(),'approved',9),
            (401,4,'999:999/998','Sender','Inactive',NOW(),'approved',9),
            (501,5,'999:999/998','Sender','Disabled subscription',NOW(),'approved',9)");
        $this->db->exec("INSERT INTO user_echomail_ignore_rules (user_id,sender_name,sender_address,subject_contains) VALUES (7,'Ignored','999:999/998','')");
        $this->db->exec("INSERT INTO message_read_status (user_id,message_id,message_type) VALUES (7,2,'netmail'),(7,102,'echomail')");
        $this->db->exec("INSERT INTO bulletins (id,title,body,is_active,active_from) VALUES
            (1,'Unread','Body',TRUE,NULL),(2,'Read','Body',TRUE,NULL),
            (3,'Inactive','Body',FALSE,NULL),(4,'Future','Body',TRUE,NOW()+INTERVAL '1 day')");
        $this->db->exec('INSERT INTO bulletin_reads (user_id,bulletin_id) VALUES (7,2)');
        $this->db->exec("CREATE FUNCTION pg_temp.reject_newscan_write() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''Newscan attempted a write''; END'");
        foreach (self::TABLES as $table) {
            $this->db->exec("CREATE TRIGGER no_newscan_writes BEFORE INSERT OR UPDATE OR DELETE ON pg_temp.$table FOR EACH STATEMENT EXECUTE FUNCTION pg_temp.reject_newscan_write()");
        }
        $messages = new class extends MessageHandler {
            public function netmailMyAddresses(): array { return ['999:999/999']; }
        };
        $this->service = new UnifiedNewscanService($this->db, $messages);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->inTransaction()) $this->db->rollBack();
    }

    private function fingerprint(): array
    {
        $state = [];
        foreach (['message_read_status','user_echoarea_subscriptions','bulletin_reads','users_meta'] as $table) {
            $state[$table] = $this->db->query("SELECT row_to_json(t)::text FROM $table t ORDER BY row_to_json(t)::text")->fetchAll(PDO::FETCH_COLUMN);
        }
        return $state;
    }

    public function testOrdinaryWebSummaryAgreesWithCanonicalPlanAndLeavesAllReadStateUntouched(): void
    {
        $before = $this->fingerprint();
        $user = ['user_id'=>7,'is_admin'=>false];
        $plan = $this->service->plan($user);
        self::assertSame([1], $plan->netmailIds);
        self::assertSame(['VISIBLE'], array_map(fn($area)=>$area->tag, $plan->areas));
        self::assertSame([101,106], $plan->areas[0]->messageIds);
        $summary = WebNewscanSummary::fromPlan($plan);
        self::assertSame(['netmail'=>1,'echomail'=>2,'areas'=>1,'bulletins'=>1,'empty'=>false,'truncated'=>false], $summary);
        self::assertSame($summary, WebNewscanSummary::fromPlan($this->service->plan($user)));
        self::assertSame($before, $this->fingerprint());
    }

    public function testCanonicalAccessFilterIncludesSysopAreaOnlyForAdmin(): void
    {
        $caller = $this->service->plan(['user_id'=>7,'is_admin'=>false]);
        $admin = $this->service->plan(['user_id'=>7,'is_admin'=>true]);
        self::assertSame(1, WebNewscanSummary::fromPlan($caller)['areas']);
        self::assertSame(2, WebNewscanSummary::fromPlan($admin)['areas']);
        self::assertSame(['HIDDEN_SYSOP','VISIBLE'], array_map(fn($area)=>$area->tag, $admin->areas));
    }
}
