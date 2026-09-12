<?php

declare(strict_types=1);

require_once __DIR__ . '/../../telnet/src/OutputSink.php';
require_once __DIR__ . '/../../telnet/src/SocketSink.php';
require_once __DIR__ . '/../../telnet/src/BufferSink.php';
require_once __DIR__ . '/../../telnet/src/GlyphPolicy.php';
require_once __DIR__ . '/../../telnet/src/TerminalCapabilities.php';
require_once __DIR__ . '/../../telnet/src/TerminalRenderContext.php';
require_once __DIR__ . '/../../telnet/src/TelnetUtils.php';
require_once __DIR__ . '/../../telnet/src/TerminalMarkupRenderer.php';
require_once __DIR__ . '/../../telnet/src/TerminalBoxRenderer.php';
require_once __DIR__ . '/../../telnet/src/BbsSession.php';
require_once __DIR__ . '/../../telnet/src/TerminalLineEditor.php';
require_once __DIR__ . '/../../telnet/src/TerminalLineHistory.php';
require_once __DIR__ . '/../../telnet/src/MailUtils.php';
require_once __DIR__ . '/../../telnet/src/TerminalMessageService.php';
require_once __DIR__ . '/../../telnet/src/NetmailHandler.php';
require_once __DIR__ . '/../../telnet/src/EchomailHandler.php';
require_once __DIR__ . '/Support/TestDatabase.php';

use BinktermPHP\Database;
use BinktermPHP\I18n\Translator;
use BinktermPHP\MessageHandler;
use BinktermPHP\TelnetServer\BbsSession;
use BinktermPHP\TelnetServer\BufferSink;
use BinktermPHP\TelnetServer\EchomailHandler;
use BinktermPHP\TelnetServer\NetmailHandler;
use BinktermPHP\TelnetServer\TerminalCapabilities;
use BinktermPHP\TelnetServer\TerminalMessageService;
use BinktermPHP\TelnetServer\TerminalRenderContext;
use BinktermPHP\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Semantic-equivalence cover for the Terminal API latency corridor: the telnet
 * message list AND message-detail read paths stopped making a serial localhost
 * HTTP round trip (out to Cloudflare) per navigation and now call the same
 * canonical {@see MessageHandler} the matching REST routes delegate to.
 *
 * These tests pin that the network-free paths return exactly what the routes
 * would have returned — same messages, order, page count, per-page slice,
 * folder/filter and sort mapping for lists; same core fields, REPLYTO
 * enrichment, 404 semantics and response envelope for detail — against the live
 * database.
 *
 * The tests are read-only against message data: they only use messages the test
 * user has already read (so `getMessage()`'s mark-read is a no-op), and the
 * activity-log writes are intercepted through the
 * {@see EchomailHandler::trackAreaView()} / {@see RecordingMessageService}
 * seams so no rows are inserted.
 */
final class TerminalMessageListDirectFetchTest extends TestCase
{
    private const UID = 3; // Skrawl — a long-standing account with mail history

    private static ?\PDO $db = null;

    private BbsSession $bbs;

    public static function setUpBeforeClass(): void
    {
        try {
            self::$db = TestDatabase::pdo();
        } catch (\Throwable $e) {
            self::$db = null;

            return;
        }
        // Install the same isolated PDO into the singleton BEFORE any test
        // constructs BbsSession/MessageHandler/EchoFetchProbe/NetFetchProbe
        // below, all of which internally call Database::getInstance().
        Database::setInstanceForTesting(self::$db);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            Database::resetInstanceForTesting();
        }
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('database not available');
        }

        $conn = fopen('php://temp', 'r+');
        $this->bbs = new BbsSession($conn, 'http://127.0.0.1', false, false, false, false);
        $caps = TerminalCapabilities::unknown();
        $ctx  = new TerminalRenderContext(
            new BufferSink(), $caps, 80, 24, 'utf8', true, false, [], 'en', new Translator()
        );
        foreach (['renderContext' => $ctx, 'capabilities' => $caps] as $prop => $val) {
            $r = new \ReflectionProperty($this->bbs, $prop);
            $r->setAccessible(true);
            $r->setValue($this->bbs, $val);
        }
    }

    private function echoProbe(): EchoFetchProbe
    {
        return new EchoFetchProbe($this->bbs, 'http://127.0.0.1');
    }

    private function netProbe(): NetFetchProbe
    {
        return new NetFetchProbe($this->bbs, 'http://127.0.0.1');
    }

    /** An echoarea tag@domain with at least $min visible messages, or null. */
    private function busyEchoarea(int $min = 5): ?string
    {
        $row = self::$db->query("
            SELECT ea.tag, ea.domain
            FROM echoareas ea
            JOIN echomail em ON em.echoarea_id = ea.id
            WHERE (em.date_written IS NULL OR em.date_written <= (NOW() AT TIME ZONE 'UTC'))
            GROUP BY ea.tag, ea.domain
            HAVING COUNT(em.id) >= {$min}
            ORDER BY COUNT(em.id) DESC
            LIMIT 1
        ")->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }
        $domain = trim((string)($row['domain'] ?? ''));

        return $domain !== '' ? $row['tag'] . '@' . $domain : $row['tag'];
    }

    private static function ids(array $messages): array
    {
        return array_map(static fn(array $m): int => (int)$m['id'], $messages);
    }

    // ---- echomail --------------------------------------------------------

    public function testEchomailListMatchesTheRouteServiceContract(): void
    {
        $area = $this->busyEchoarea();
        if ($area === null) {
            self::markTestSkipped('no populated echoarea');
        }
        [$tag, $domain] = array_pad(explode('@', $area, 2), 2, '');

        [$messages, $pages] = $this->echoProbe()->fetch($area, 1, 25, 'date_desc', self::UID);

        // The route: getEchomail(tag, domain, page, null, uid, 'all', false, false, sort)
        $svc = (new MessageHandler())->getEchomail($tag, $domain, 1, null, self::UID, 'all', false, false, 'date_desc');

        self::assertSame(
            self::ids(array_slice($svc['messages'], 0, 25)),
            self::ids($messages),
            'same message ids in the same order as the REST route'
        );
        self::assertSame((int)($svc['pagination']['pages'] ?? 1), $pages, 'same total page count');
    }

    public function testEchomailPerPageSliceIsApplied(): void
    {
        $area = $this->busyEchoarea();
        if ($area === null) {
            self::markTestSkipped('no populated echoarea');
        }
        [$tag, $domain] = array_pad(explode('@', $area, 2), 2, '');

        [$messages] = $this->echoProbe()->fetch($area, 1, 3, 'date_desc', self::UID);
        self::assertLessThanOrEqual(3, count($messages));

        $svc = (new MessageHandler())->getEchomail($tag, $domain, 1, null, self::UID, 'all', false, false, 'date_desc');
        self::assertSame(self::ids(array_slice($svc['messages'], 0, 3)), self::ids($messages));
    }

    public function testEchomailSortIsPassedThrough(): void
    {
        $area = $this->busyEchoarea(6);
        if ($area === null) {
            self::markTestSkipped('no populated echoarea');
        }
        [$tag, $domain] = array_pad(explode('@', $area, 2), 2, '');

        [$desc] = $this->echoProbe()->fetch($area, 1, 25, 'date_desc', self::UID);
        [$asc]  = $this->echoProbe()->fetch($area, 1, 25, 'date_asc', self::UID);

        // Each mode reproduces the service's ordering for that same mode …
        self::assertSame(
            self::ids(array_slice((new MessageHandler())->getEchomail($tag, $domain, 1, null, self::UID, 'all', false, false, 'date_asc')['messages'], 0, 25)),
            self::ids($asc),
            'date_asc ordering matches the service'
        );
        self::assertSame(
            self::ids(array_slice((new MessageHandler())->getEchomail($tag, $domain, 1, null, self::UID, 'all', false, false, 'date_desc')['messages'], 0, 25)),
            self::ids($desc),
            'date_desc ordering matches the service'
        );
        // … and the sort argument actually changes the result (it is not dropped).
        self::assertNotSame(self::ids($desc), self::ids($asc), 'date_desc and date_asc produce different orderings');
    }

    public function testEchomailAreaViewSideEffectIsRecorded(): void
    {
        $area = $this->busyEchoarea();
        if ($area === null) {
            self::markTestSkipped('no populated echoarea');
        }
        [$tag] = array_pad(explode('@', $area, 2), 2, '');

        $probe = $this->echoProbe();
        $probe->fetch($area, 1, 25, 'date_desc', self::UID);

        self::assertSame(
            [[self::UID, $tag]],
            $probe->areaViews,
            'the echoarea-view activity record the route writes is still emitted (tag only, no domain)'
        );
    }

    public function testUnknownEchoareaReturnsEmptyWithoutError(): void
    {
        [$messages, $pages] = $this->echoProbe()->fetch('NO_SUCH_AREA@nowhere', 1, 25, 'date_desc', self::UID);
        self::assertSame([], $messages);
        self::assertIsInt($pages);
    }

    public function testEchomailNormalisesAnUnknownSort(): void
    {
        $area = $this->busyEchoarea();
        if ($area === null) {
            self::markTestSkipped('no populated echoarea');
        }
        [$tag, $domain] = array_pad(explode('@', $area, 2), 2, '');

        [$messages] = $this->echoProbe()->fetch($area, 1, 25, 'bogus-sort', self::UID);
        $svc = (new MessageHandler())->getEchomail($tag, $domain, 1, null, self::UID, 'all', false, false, 'date_desc');

        self::assertSame(self::ids(array_slice($svc['messages'], 0, 25)), self::ids($messages), 'unknown sort falls back to date_desc');
    }

    // ---- netmail --------------------------------------------------------

    public function testNetmailInboxMatchesTheRouteServiceContract(): void
    {
        [$messages, $pages] = $this->netProbe()->fetch(1, 25, 'inbox', 'date_desc', self::UID);

        // The route for folder=inbox: getNetmail(uid, page, null, 'all', false, sort)
        $svc = (new MessageHandler())->getNetmail(self::UID, 1, null, 'all', false, 'date_desc');

        self::assertSame(self::ids(array_slice($svc['messages'], 0, 25)), self::ids($messages));
        self::assertSame((int)($svc['pagination']['pages'] ?? 1), $pages);
    }

    public function testNetmailSentFolderMapsToTheSentFilter(): void
    {
        [$sentMessages] = $this->netProbe()->fetch(1, 25, 'sent', 'date_desc', self::UID);

        $sentSvc = (new MessageHandler())->getNetmail(self::UID, 1, null, 'sent', false, 'date_desc');
        self::assertSame(self::ids(array_slice($sentSvc['messages'], 0, 25)), self::ids($sentMessages));

        // 'sent' must not be silently treated as the inbox ('all').
        $allSvc = (new MessageHandler())->getNetmail(self::UID, 1, null, 'all', false, 'date_desc');
        if (self::ids($sentSvc['messages']) !== self::ids($allSvc['messages'])) {
            self::assertNotSame(
                self::ids(array_slice($allSvc['messages'], 0, 25)),
                self::ids($sentMessages),
                'the sent folder is a distinct view from the inbox'
            );
        } else {
            self::assertTrue(true, 'account has no divergence between sent and all in this dataset');
        }
    }

    public function testNetmailPerPageSliceIsApplied(): void
    {
        [$messages] = $this->netProbe()->fetch(1, 2, 'inbox', 'date_desc', self::UID);
        self::assertLessThanOrEqual(2, count($messages));
    }

    public function testNetmailForAnUnknownUserReturnsEmpty(): void
    {
        [$messages, $pages] = $this->netProbe()->fetch(1, 25, 'inbox', 'date_desc', 0);
        self::assertSame([], $messages);
        self::assertIsInt($pages);
    }

    // ---- message DETAIL (TerminalMessageService) -----------------------
    //
    // getMessage() marks a message read as a side effect (so does the route it
    // mirrors). To keep these equivalence tests side-effect-free they only use
    // messages the test user has ALREADY read.

    /**
     * @return array{0:int,1:string,2:string}|null  [id, tag, domain] of an
     *         already-read echomail that `getMessage()` still resolves (passes
     *         the ignore / moderation / sysop-only filters).
     */
    private function readEchomail(bool $withReplyTo = false): ?array
    {
        $extra = $withReplyTo ? "AND em.kludge_lines ILIKE '%REPLYTO%'" : '';
        $rows = self::$db->query("
            SELECT em.id, ea.tag, ea.domain
            FROM echomail em
            JOIN echoareas ea ON ea.id = em.echoarea_id
            JOIN message_read_status mrs
              ON mrs.message_id = em.id AND mrs.message_type = 'echomail'
             AND mrs.user_id = " . self::UID . " AND mrs.read_at IS NOT NULL
            WHERE 1=1 {$extra}
            ORDER BY em.id DESC LIMIT 30
        ")->fetchAll(\PDO::FETCH_ASSOC);

        $mh = new MessageHandler();
        foreach ($rows as $row) {
            if ($mh->getMessage((int) $row['id'], 'echomail', self::UID)) {
                return [(int) $row['id'], (string) $row['tag'], (string) ($row['domain'] ?? '')];
            }
        }

        return null;
    }

    /**
     * An already-read netmail as `[userId, messageId]` that `getMessage()` still
     * resolves for that user (so its mark-read is a no-op). The test user has no
     * currently-visible netmail, so this scans whichever account does.
     *
     * @return array{0:int,1:int}|null
     */
    private function readNetmailPair(): ?array
    {
        $rows = self::$db->query("
            SELECT mrs.user_id, mrs.message_id FROM message_read_status mrs
            WHERE mrs.message_type = 'netmail' AND mrs.read_at IS NOT NULL
            ORDER BY mrs.message_id DESC LIMIT 60
        ")->fetchAll(\PDO::FETCH_ASSOC);

        $mh = new MessageHandler();
        foreach ($rows as $row) {
            if ($mh->getMessage((int) $row['message_id'], 'netmail', (int) $row['user_id'])) {
                return [(int) $row['user_id'], (int) $row['message_id']];
            }
        }

        return null;
    }

    /** The core fields the terminal viewers actually read from the detail payload. */
    private const DETAIL_FIELDS = [
        'id', 'message_text', 'subject', 'from_name', 'from_address', 'to_name',
        'message_charset', 'markup_format', 'art_format', 'kludge_lines', 'bottom_kludges',
    ];

    private static function pick(array $m, array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $m[$k] ?? null;
        }

        return $out;
    }

    public function testEchomailDetailMatchesGetMessageAndTheRouteEnvelope(): void
    {
        $target = $this->readEchomail();
        if ($target === null) {
            self::markTestSkipped('no already-read echomail for the test user');
        }
        [$id, $tag, $domain] = $target;
        $area = $domain !== '' ? $tag . '@' . $domain : $tag;

        $detail = (new TerminalMessageService())->echomailDetail($area, $id, self::UID);
        $svc    = (new MessageHandler())->getMessage($id, 'echomail', self::UID);

        self::assertSame(200, $detail['status']);
        self::assertNull($detail['error']);
        self::assertSame(self::pick($svc, self::DETAIL_FIELDS), self::pick($detail['data'], self::DETAIL_FIELDS));
        self::assertArrayNotHasKey('area_allow_media', $detail['data'], 'internal column dropped, like the route');
    }

    public function testEchomailDetailReplyToMatchesTheRouteAlgorithmExactly(): void
    {
        $target = $this->readEchomail(withReplyTo: true) ?? $this->readEchomail();
        if ($target === null) {
            self::markTestSkipped('no already-read echomail for the test user');
        }
        [$id, $tag, $domain] = $target;
        $area = $domain !== '' ? $tag . '@' . $domain : $tag;

        $detail = (new TerminalMessageService())->echomailDetail($area, $id, self::UID);

        // Reproduce the route's exact 2-step enrichment on the same getMessage() output.
        $m = (new MessageHandler())->getMessage($id, 'echomail', self::UID);
        $expectedAddr = $m['replyto_address'] ?? null;
        $expectedName = $m['replyto_name'] ?? null;
        $first = MessageHandler::parseReplyToKludgeText((string) ($m['message_text'] ?? ''));
        if ($first) {
            $expectedAddr = $first['address'];
            $expectedName = $first['name'];
        }
        if (isset($m['kludge_lines'])) {
            $second = MessageHandler::parseReplyToKludgeText((string) $m['kludge_lines']);
            if ($second) {
                $expectedAddr = $second['address'];
                $expectedName = $second['name'];
            }
        }

        self::assertSame($expectedAddr, $detail['data']['replyto_address'] ?? null);
        self::assertSame($expectedName, $detail['data']['replyto_name'] ?? null);
    }

    public function testEchomailDetailWrongAreaIsNotFound(): void
    {
        $target = $this->readEchomail();
        if ($target === null) {
            self::markTestSkipped('no already-read echomail for the test user');
        }
        [$id] = $target;

        $detail = (new TerminalMessageService())->echomailDetail('WRONG_AREA@nowhere', $id, self::UID);
        self::assertSame(404, $detail['status']);
        self::assertSame([], $detail['data']);
    }

    public function testEchomailDetailUnknownIdIsNotFound(): void
    {
        $detail = (new TerminalMessageService())->echomailDetail('WHATEVER@x', 2000000000, self::UID);
        self::assertSame(404, $detail['status']);
        self::assertSame([], $detail['data']);
    }

    public function testNetmailDetailMatchesGetMessageAndCarriesAttachments(): void
    {
        $pair = $this->readNetmailPair();
        if ($pair === null) {
            self::markTestSkipped('no resolvable already-read netmail on any account');
        }
        [$uid, $id] = $pair;

        $detail = (new RecordingMessageService())->netmailDetail($id, $uid);
        $svc    = (new MessageHandler())->getMessage($id, 'netmail', $uid);

        self::assertSame(200, $detail['status']);
        self::assertSame(self::pick($svc, self::DETAIL_FIELDS), self::pick($detail['data'], self::DETAIL_FIELDS));
        self::assertArrayHasKey('attachments', $detail['data']);
        self::assertIsArray($detail['data']['attachments']);
    }

    public function testNetmailDetailReplyToMatchesTheRouteAlgorithm(): void
    {
        $pair = $this->readNetmailPair();
        if ($pair === null) {
            self::markTestSkipped('no resolvable already-read netmail on any account');
        }
        [$uid, $id] = $pair;

        $detail = (new RecordingMessageService())->netmailDetail($id, $uid);

        $m = (new MessageHandler())->getMessage($id, 'netmail', $uid);
        $expectedAddr = null;
        $expectedName = null;
        $first = MessageHandler::parseReplyToKludgeText((string) ($m['message_text'] ?? ''));
        if ($first) {
            $expectedAddr = $first['address'];
            $expectedName = $first['name'];
        }
        if (isset($m['kludge_lines'])) {
            $second = MessageHandler::parseReplyToKludgeText((string) $m['kludge_lines']);
            if ($second) {
                $expectedAddr = $second['address'];
                $expectedName = $second['name'];
            }
        }

        self::assertSame($expectedAddr, $detail['data']['replyto_address'] ?? null);
        self::assertSame($expectedName, $detail['data']['replyto_name'] ?? null);
    }

    public function testNetmailDetailUnknownIdIsNotFound(): void
    {
        $detail = (new RecordingMessageService())->netmailDetail(2000000000, self::UID);
        self::assertSame(404, $detail['status']);
        self::assertSame([], $detail['data']);
    }

    public function testTerminalHandlersNoLongerHttpFetchMessageDetail(): void
    {
        $echo = file_get_contents(__DIR__ . '/../../telnet/src/EchomailHandler.php');
        $net  = file_get_contents(__DIR__ . '/../../telnet/src/NetmailHandler.php');

        self::assertStringContainsString('detailService()->echomailDetail(', $echo);
        self::assertStringContainsString('detailService()->netmailDetail(', $net);

        // The exact bare single-message GETs that were swapped.
        self::assertStringNotContainsString("'/api/messages/echomail/' . urlencode(\$area) . '/' . \$id", $echo);
        self::assertStringNotContainsString("'/api/messages/echomail/' . urlencode(\$area) . '/' . \$reply['id']", $echo);
        self::assertStringNotContainsString("'/api/messages/netmail/' . \$id, null", $net);
        self::assertStringNotContainsString("'/api/messages/netmail/' . \$reply['id']", $net);

        // The file-serving download GET is a different concern and stays HTTP.
        self::assertStringContainsString("/download'", $net);
    }

    // ---- parseReplyToKludgeText (canonical parser) --------------------

    public function testParseReplyToKludgeTextContract(): void
    {
        self::assertNull(MessageHandler::parseReplyToKludgeText(null));
        self::assertNull(MessageHandler::parseReplyToKludgeText(''));
        self::assertNull(MessageHandler::parseReplyToKludgeText("just a body\nno kludges here"));

        self::assertSame(
            ['address' => '2:460/256', 'name' => 'Sysop Name'],
            MessageHandler::parseReplyToKludgeText("\x01MSGID: x\n\x01REPLYTO 2:460/256 Sysop Name\nbody")
        );
        self::assertSame(
            ['address' => '1:234/56', 'name' => null],
            MessageHandler::parseReplyToKludgeText("\x01REPLYTO 1:234/56")
        );
        // Non-FidoNet address is skipped; a later valid line still wins.
        self::assertSame(
            ['address' => '3:1/0', 'name' => null],
            MessageHandler::parseReplyToKludgeText("\x01REPLYTO not-an-address\n\x01REPLYTO 3:1/0")
        );
        // First valid match wins (documents the "first REPLYTO" rule).
        self::assertSame(
            ['address' => '1:1/1', 'name' => 'first'],
            MessageHandler::parseReplyToKludgeText("\x01REPLYTO 1:1/1 first\n\x01REPLYTO 2:2/2 second")
        );
    }

    public function testParseReplyToKludgeTextIsWhatTheGlobalDelegatesTo(): void
    {
        if (!function_exists('parseReplyToKludge')) {
            require_once __DIR__ . '/../../src/functions.php';
        }
        $text = "\x01REPLYTO 2:460/256 Someone\nbody text";
        self::assertSame(
            MessageHandler::parseReplyToKludgeText($text),
            \parseReplyToKludge($text),
            'the parseReplyToKludge() global is a thin delegator'
        );
    }
}

final class EchoFetchProbe extends EchomailHandler
{
    /** @var list<array{0:int,1:string}> */
    public array $areaViews = [];

    public function fetch(string $area, int $page, int $perPage, string $sort, int $userId): array
    {
        return $this->fetchMessagesPage('', $area, $page, $perPage, $sort, $userId);
    }

    protected function trackAreaView(?int $userId, string $tag): void
    {
        $this->areaViews[] = [(int)$userId, $tag];
    }
}

final class NetFetchProbe extends NetmailHandler
{
    public function fetch(int $page, int $perPage, string $folder, string $sort, int $userId): array
    {
        return $this->fetchMessagesPage('', $page, $perPage, $folder, $sort, $userId);
    }
}

/** {@see TerminalMessageService} with the activity-log write stubbed out. */
final class RecordingMessageService extends TerminalMessageService
{
    /** @var list<array{0:?int,1:int}> */
    public array $reads = [];

    protected function trackNetmailRead(?int $userId, int $id): void
    {
        $this->reads[] = [$userId, $id];
    }
}
