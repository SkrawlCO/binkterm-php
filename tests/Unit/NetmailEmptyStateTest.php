<?php

declare(strict_types=1);

require_once __DIR__ . '/../../telnet/src/OutputSink.php';
require_once __DIR__ . '/../../telnet/src/SocketSink.php';
require_once __DIR__ . '/../../telnet/src/BufferSink.php';
require_once __DIR__ . '/../../telnet/src/GlyphPolicy.php';
require_once __DIR__ . '/../../telnet/src/TerminalCapabilities.php';
require_once __DIR__ . '/../../telnet/src/TerminalRenderContext.php';
require_once __DIR__ . '/../../telnet/src/TelnetUtils.php';
require_once __DIR__ . '/../../telnet/src/TerminalBoxRenderer.php';
require_once __DIR__ . '/../../telnet/src/BbsSession.php';
require_once __DIR__ . '/../../telnet/src/TerminalLineEditor.php';
require_once __DIR__ . '/../../telnet/src/TerminalLineHistory.php';
require_once __DIR__ . '/../../telnet/src/MailUtils.php';
require_once __DIR__ . '/../../telnet/src/NetmailHandler.php';
require_once __DIR__ . '/../../telnet/src/TerminalShellInterface.php';
require_once __DIR__ . '/../../telnet/src/TuiShell.php';
require_once __DIR__ . '/../../telnet/src/LineShell.php';
require_once __DIR__ . '/../../telnet/src/TerminalShellFactory.php';

use BinktermPHP\I18n\Translator;
use BinktermPHP\TelnetServer\BbsSession;
use BinktermPHP\TelnetServer\NetmailHandler;
use BinktermPHP\TelnetServer\SocketSink;
use BinktermPHP\TelnetServer\TerminalCapabilities;
use BinktermPHP\TelnetServer\TerminalRenderContext;
use PHPUnit\Framework\TestCase;

/**
 * Regression cover for the Netmail empty-state navigation bug: an empty inbox
 * used to write one line and return immediately, so the caller was bounced
 * straight back to the Messages submenu ("flash and return"). The fixed
 * {@see NetmailHandler::show()} presents a stable interactive empty state
 * (Compose / Back) instead.
 *
 * The handler's network seams are substituted through the `protected`
 * fetch/load/save methods; the real {@see BbsSession} key readers and the real
 * TUI shell drive the interaction over a socket pair.
 *
 * Keystroke feeds separate each key with "\n": the menu key reader consumes an
 * immediately-trailing LF as part of the same terminator, which keeps the next
 * key on the wire (rather than in the look-ahead buffer) so a socket-gated
 * reader picks it up without waiting.
 */
final class NetmailEmptyStateTest extends TestCase
{
    /** @var resource */
    private $srv;
    /** @var resource */
    private $cli;
    private BbsSession $bbs;
    private array $state;

    protected function setUp(): void
    {
        [$this->srv, $this->cli] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        stream_set_blocking($this->srv, false);
        stream_set_blocking($this->cli, false);

        $this->bbs = new BbsSession($this->srv, 'http://127.0.0.1', false, false, false, false);
        $caps = TerminalCapabilities::unknown()
            ->withColorSupport(TerminalCapabilities::COLOR_ANSI)
            ->withCharsetSupport(TerminalCapabilities::CHARSET_UTF8);
        $ctx = new TerminalRenderContext(
            new SocketSink($this->srv),
            $caps,
            80,
            24,
            'utf8',
            true,
            false,
            [],
            'en',
            new Translator()
        );
        foreach (['renderContext' => $ctx, 'capabilities' => $caps] as $prop => $val) {
            $r = new \ReflectionProperty($this->bbs, $prop);
            $r->setAccessible(true);
            $r->setValue($this->bbs, $val);
        }

        // Fast idle backstop: a mis-fed test disconnects in a few seconds
        // instead of hanging the suite.
        $this->state = [
            'username' => 'alice', 'locale' => 'en', 'cols' => 80, 'rows' => 24,
            'pushback' => '', 'input_echo' => true, 'term_shell_mode' => 'tui',
            'last_activity' => time(), 'idle_warned' => false,
            'idle_warning_timeout' => 2, 'idle_disconnect_timeout' => 3,
        ];
    }

    protected function tearDown(): void
    {
        @fclose($this->srv);
        @fclose($this->cli);
    }

    /** Feed client keystrokes that the handler's readers will consume. */
    private function feed(string $bytes): void
    {
        fwrite($this->cli, $bytes);
        fflush($this->cli);
    }

    /** Everything the handler has written to the terminal so far, ANSI stripped. */
    private function screen(): string
    {
        $out = '';
        while (($chunk = @fread($this->cli, 8192)) !== false && $chunk !== '') {
            $out .= $chunk;
        }

        return (string) preg_replace('/\033\[[0-9;?]*[A-Za-z]/', '', $out);
    }

    /** Input bytes still queued on the server side (should be empty once consumed). */
    private function pendingInput(): string
    {
        $out = '';
        while (($chunk = @fread($this->srv, 8192)) !== false && $chunk !== '') {
            $out .= $chunk;
        }

        return $out;
    }

    private function handler(array $pageResult): RecordingNetmailHandler
    {
        return new RecordingNetmailHandler($this->bbs, 'http://127.0.0.1', $pageResult);
    }

    // The first "x\n" answers the netmail.ans "press any key" screen; the rest
    // drives the empty-state / list interaction.

    public function testEmptyInboxPresentsAStableInteractiveEmptyState(): void
    {
        $h = $this->handler([[], 0]);
        $this->feed("x\nq\n");

        $h->show($this->srv, $this->state, 'sess');

        $plain = $this->screen();
        self::assertStringContainsString('No netmail messages.', $plain, 'the empty state is shown');
        self::assertStringContainsString('Compose', $plain, 'Compose is offered from the empty state');
        self::assertStringContainsString('Back', $plain, 'a deliberate way back is offered');
        self::assertSame('', $this->state['pushback'], 'no stray keystroke leaked back to the caller');
        self::assertSame('', $this->pendingInput(), 'the empty state consumed the keystroke rather than flashing past it');
    }

    public function testBackLeavesDeliberatelyWithoutComposing(): void
    {
        $h = $this->handler([[], 0]);
        $this->feed("x\nq\n");

        $h->show($this->srv, $this->state, 'sess');

        self::assertSame(0, $h->composeCalls, 'Back does not compose');
        self::assertSame(['inbox'], array_values(array_unique($h->fetchFolders)), 'only the inbox was queried');
    }

    public function testComposeInvokesComposeOnceAndRePresentsTheDestination(): void
    {
        $h = $this->handler([[], 0]);
        // press-any-key, then Compose, then (empty state re-presented) Back.
        $this->feed("x\nc\nq\n");

        $h->show($this->srv, $this->state, 'sess');

        self::assertSame(1, $h->composeCalls, 'Compose is invoked exactly once');
        self::assertGreaterThanOrEqual(2, count($h->fetchFolders), 'the Netmail destination is re-presented after composing');
    }

    public function testEmptyInboxIsAStableDestinationAcrossRepeatedCompose(): void
    {
        $h = $this->handler([[], 0]);
        $this->feed("x\nc\nc\nq\n");

        $h->show($this->srv, $this->state, 'sess');

        self::assertSame(2, $h->composeCalls, 'the empty state keeps offering Compose, it is not a one-shot');
    }

    public function testPopulatedInboxDoesNotEnterTheEmptyStateBranch(): void
    {
        $h = $this->handler([[$this->message(true)], 1]);
        $this->feed("x\nq\n");

        $h->show($this->srv, $this->state, 'sess');

        $plain = $this->screen();
        self::assertStringContainsString('Netmail Inbox', $plain, 'the message list rendered');
        self::assertStringNotContainsString('No netmail messages.', $plain, 'the empty-state branch was not entered');
        self::assertSame(0, $h->composeCalls);
    }

    /**
     * @dataProvider readStates
     */
    public function testReadStateDoesNotChangeDestinationReachability(bool $isRead): void
    {
        $h = $this->handler([[$this->message($isRead)], 1]);
        $this->feed("x\nq\n");

        $h->show($this->srv, $this->state, 'sess');

        self::assertStringContainsString(
            'Netmail Inbox',
            $this->screen(),
            'the destination is reachable regardless of read state'
        );
        self::assertSame(['inbox'], array_values(array_unique($h->fetchFolders)), 'inbox is always the queried folder');
    }

    /** @return array<string,array{bool}> */
    public static function readStates(): array
    {
        return ['already read' => [true], 'unread' => [false]];
    }

    /** @return array<string,mixed> */
    private function message(bool $isRead): array
    {
        return [
            'id' => 99, 'from_name' => 'Carol', 'to_name' => 'Alice',
            'subject' => 'Note', 'date_written' => '2026-01-03 08:00:00',
            'date_received' => '2026-01-03 08:00:00', 'is_read' => $isRead,
        ];
    }
}

/**
 * NetmailHandler with its network seams replaced by deterministic in-memory
 * behaviour. Only the `protected` fetch/load/save methods and the public
 * compose entry point are overridden.
 */
final class RecordingNetmailHandler extends NetmailHandler
{
    public int $composeCalls = 0;
    /** @var list<string> */
    public array $fetchFolders = [];

    /** @param array{0: list<array<string,mixed>>, 1: int} $pageResult */
    public function __construct(BbsSession $server, string $apiBase, private array $pageResult)
    {
        parent::__construct($server, $apiBase);
    }

    protected function fetchMessagesPage(string $session, int $page, int $perPage, string $folder = 'inbox', string $sort = 'date_desc', int $userId = 0): array
    {
        $this->fetchFolders[] = $folder;

        return $this->pageResult;
    }

    protected function loadSavedListState(string $session): array
    {
        return ['page' => 1, 'selected_message_id' => null, 'folder' => 'inbox', 'sort' => 'date_desc'];
    }

    protected function saveListState(string $session, int $page, ?int $selectedMessageId, string $folder = 'inbox', string $sort = 'date_desc', ?string $csrfToken = null): void
    {
        // no user-meta round-trip in tests
    }

    public function compose($conn, array &$state, string $session, ?array $reply = null): void
    {
        $this->composeCalls++;
    }
}
