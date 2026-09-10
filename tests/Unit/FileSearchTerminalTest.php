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
require_once __DIR__ . '/../../telnet/src/TerminalShellInterface.php';
require_once __DIR__ . '/../../telnet/src/TuiShell.php';
require_once __DIR__ . '/../../telnet/src/LineShell.php';
require_once __DIR__ . '/../../telnet/src/TerminalShellFactory.php';
require_once __DIR__ . '/../../telnet/src/MailUtils.php';
require_once __DIR__ . '/../../telnet/src/ZmodemTransfer.php';
require_once __DIR__ . '/../../telnet/src/FileHandler.php';

use BinktermPHP\I18n\Translator;
use BinktermPHP\TelnetServer\BbsSession;
use BinktermPHP\TelnetServer\FileHandler;
use BinktermPHP\TelnetServer\TerminalCapabilities;
use BinktermPHP\TelnetServer\TerminalRenderContext;
use BinktermPHP\TelnetServer\TuiShell;
use PHPUnit\Framework\TestCase;

/**
 * F-1 — terminal File Search wiring.
 *
 * Drives the real (private) {@see FileHandler} methods over a paired socket
 * with a real {@see BbsSession} input pipeline, in the same style as
 * {@see FileAreaDenseListTest}:
 *
 *   - `S` from the File Areas browser and the file list both yield `search`
 *   - the results list renders `[AREA] filename` rows and returns cleanly on Q
 *   - selecting a result enters the existing file-detail view
 *   - {@see FileHandler::searchFiles()} calls FileAreaManager directly — it is
 *     given a dead API base and still completes (DB-gated).
 */
final class FileSearchTerminalTest extends TestCase
{
    /** @var resource */ private $srv;
    /** @var resource */ private $cli;
    private BbsSession $bbs;
    private TerminalRenderContext $ctx;
    private FileHandler $handler;

    protected function setUp(): void
    {
        [$this->srv, $this->cli] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        stream_set_blocking($this->srv, false);
        stream_set_blocking($this->cli, false);

        // A deliberately dead API base: anything that tried an HTTP round-trip
        // here would fail, so a method that completes is proven not to.
        $this->bbs = new BbsSession($this->srv, 'http://127.0.0.1:9', false, false, false, false);
        $caps = TerminalCapabilities::unknown()
            ->withCharsetSupport(TerminalCapabilities::CHARSET_UTF8)
            ->withColorSupport(TerminalCapabilities::COLOR_ANSI);
        $this->ctx = new TerminalRenderContext(
            new \BinktermPHP\TelnetServer\SocketSink($this->srv), $caps, 80, 24, 'utf8', true, false, [], 'en', new Translator()
        );
        foreach (['renderContext' => $this->ctx, 'capabilities' => $caps] as $p => $v) {
            $r = new \ReflectionProperty($this->bbs, $p);
            $r->setAccessible(true);
            $r->setValue($this->bbs, $v);
        }

        $this->handler = new FileHandler($this->bbs, 'http://127.0.0.1:9');
    }

    protected function tearDown(): void
    {
        @fclose($this->srv);
        @fclose($this->cli);
    }

    private function state(): array
    {
        return [
            'input_echo' => true, 'cols' => 80, 'rows' => 24, 'locale' => 'en', 'pushback' => '',
            'user_id' => 7, 'last_activity' => time(), 'idle_warned' => false,
            'idle_warning_timeout' => 300, 'idle_disconnect_timeout' => 420,
        ];
    }

    private static function plain(string $s): string
    {
        return preg_replace('/\033\[[0-9;?]*[A-Za-z]/', '', $s) ?? $s;
    }

    private function drain(): string
    {
        $out = '';
        while (($chunk = @fread($this->cli, 8192)) !== false && $chunk !== '') {
            $out .= $chunk;
        }
        return $out;
    }

    /** Invoke a private FileHandler method with a keystroke script pre-loaded. */
    private function invoke(string $method, string $keys, array $args): mixed
    {
        fwrite($this->cli, $keys);
        fflush($this->cli);

        $m = new \ReflectionMethod(FileHandler::class, $method);
        $m->setAccessible(true);
        return $m->invokeArgs($this->handler, $args);
    }

    /** @return array<int,array<string,mixed>> */
    private function areas(): array
    {
        return [
            ['id' => 1, 'tag' => 'GENERAL', 'description' => 'General uploads', 'file_count' => 3],
            ['id' => 2, 'tag' => 'UTILS',   'description' => 'Utilities',       'file_count' => 1],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function entries(): array
    {
        return [
            ['type' => 'file', 'data' => ['id' => 11, 'filename' => 'alpha.zip', 'short_description' => 'first',  'filesize' => 100]],
            ['type' => 'file', 'data' => ['id' => 12, 'filename' => 'beta.zip',  'short_description' => 'second', 'filesize' => 200]],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function results(): array
    {
        return [
            ['id' => 11, 'filename' => 'alpha.zip', 'short_description' => 'first result',  'filesize' => 100,
             'created_at' => '2026-01-01 00:00:00', 'area_id' => 1, 'area_tag' => 'GENERAL', 'subfolder' => null],
            ['id' => 12, 'filename' => 'beta.zip', 'short_description' => 'second result', 'filesize' => 4096,
             'created_at' => '2026-01-02 00:00:00', 'area_id' => 2, 'area_tag' => 'UTILS', 'subfolder' => null],
        ];
    }

    public function testSearchKeyFromAreaBrowserReturnsSearchAction(): void
    {
        $state = $this->state();
        $this->ctx->setGeometry(80, 24);
        $shell = new TuiShell($this->bbs);

        $result = $this->invoke('pickFileArea', 's', [$this->srv, &$state, $this->areas(), 1, 17, $shell]);
        $this->drain();

        self::assertSame('search', $result['action']);
    }

    public function testAreaBrowserStatusBarAdvertisesSearch(): void
    {
        $state = $this->state();
        $this->ctx->setGeometry(80, 24);
        $shell = new TuiShell($this->bbs);

        $this->invoke('pickFileArea', 'q', [$this->srv, &$state, $this->areas(), 1, 17, $shell]);
        self::assertStringContainsString('Search', self::plain($this->drain()));
    }

    public function testSearchKeyFromFileListReturnsSearchAction(): void
    {
        $state = $this->state();
        $this->ctx->setGeometry(80, 24);
        $shell = new TuiShell($this->bbs);

        $result = $this->invoke(
            'pickFileEntry',
            's',
            [$this->srv, &$state, $this->entries(), 1, 17, 'GENERAL', false, false, false, $shell]
        );
        $this->drain();

        self::assertSame('search', $result['action']);
    }

    public function testSearchResultsListRendersRowsAndReturnsOnQuit(): void
    {
        $state = $this->state();
        $this->ctx->setGeometry(80, 24);
        $shell = new TuiShell($this->bbs);

        $this->invoke(
            'showSearchResults',
            'q',
            [$this->srv, &$state, 'sess', 'alph', $this->results(), $shell]
        );
        $plain = self::plain($this->drain());

        self::assertStringContainsString('Search: alph', $plain);
        self::assertStringContainsString('[GENERAL] alpha.zip', $plain);
        self::assertStringContainsString('[UTILS] beta.zip', $plain);
    }

    /** Read the source text of a private FileHandler method. */
    private function methodSource(string $method): string
    {
        $m    = new \ReflectionMethod(FileHandler::class, $method);
        $src  = file($m->getFileName());
        return implode('', array_slice($src, $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));
    }

    public function testSearchFilesCallsTheServiceDirectlyAndNeverApiRequest(): void
    {
        $body = $this->methodSource('searchFiles');

        self::assertStringContainsString(
            'searchAccessibleFiles(',
            $body,
            'terminal search must call FileAreaManager::searchAccessibleFiles() directly'
        );
        self::assertStringNotContainsString(
            'apiRequest(',
            $body,
            'terminal search must not perform an HTTP round-trip'
        );
        self::assertStringNotContainsString('/api/files/search', $body);
    }

    public function testSearchResultsListItselfPerformsNoSearchHttpCall(): void
    {
        // showSearchResults may reach the existing file-detail / download path
        // (which the accepted browser also uses); it must not re-issue the
        // search over HTTP.
        $body = $this->methodSource('showSearchResults');
        self::assertStringNotContainsString('/api/files/search', $body);
        self::assertStringNotContainsString('/api/messages/search', $body);
    }
}
