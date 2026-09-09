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
require_once __DIR__ . '/../../telnet/src/NetmailHandler.php';
require_once __DIR__ . '/../../telnet/src/EchomailHandler.php';

use BinktermPHP\Database;
use BinktermPHP\I18n\Translator;
use BinktermPHP\MessageHandler;
use BinktermPHP\TelnetServer\BbsSession;
use BinktermPHP\TelnetServer\BufferSink;
use BinktermPHP\TelnetServer\EchomailHandler;
use BinktermPHP\TelnetServer\NetmailHandler;
use BinktermPHP\TelnetServer\TerminalCapabilities;
use BinktermPHP\TelnetServer\TerminalRenderContext;
use PHPUnit\Framework\TestCase;

/**
 * Semantic-equivalence cover for the Terminal API latency corridor: the telnet
 * message-list read path stopped making a serial localhost HTTP round trip per
 * navigation keystroke and now calls the same canonical {@see MessageHandler}
 * service the matching REST route delegates to.
 *
 * These tests pin that the network-free path returns exactly what the route
 * would have returned — same messages, same order, same page count, same
 * per-page slice, same folder/filter and sort mapping — against the live
 * database, and that the echoarea-view activity side effect is still recorded.
 *
 * The tests are read-only against message data. The activity-log write is
 * intercepted through the {@see EchomailHandler::trackAreaView()} seam so no row
 * is inserted.
 */
final class TerminalMessageListDirectFetchTest extends TestCase
{
    private const UID = 3; // Skrawl — a long-standing account with mail history

    private static ?\PDO $db = null;

    private BbsSession $bbs;

    public static function setUpBeforeClass(): void
    {
        // The shared PDO singleton can be left unusable by an earlier test in the
        // full-suite run; force a reconnect before giving up.
        foreach ([false, true] as $reconnect) {
            try {
                if ($reconnect) {
                    Database::reconnect();
                }
                self::$db = Database::getInstance()->getPdo();
                self::$db->query('SELECT 1');
                return;
            } catch (\Throwable $e) {
                self::$db = null;
            }
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
