<?php

declare(strict_types=1);

require_once __DIR__ . '/../../telnet/src/OutputSink.php';
require_once __DIR__ . '/../../telnet/src/BufferSink.php';
require_once __DIR__ . '/../../telnet/src/SocketSink.php';
require_once __DIR__ . '/../../telnet/src/GlyphPolicy.php';
require_once __DIR__ . '/../../telnet/src/TerminalCapabilities.php';
require_once __DIR__ . '/../../telnet/src/TerminalRenderContext.php';
require_once __DIR__ . '/../../telnet/src/TelnetUtils.php';

use BinktermPHP\I18n\Translator;
use BinktermPHP\TelnetServer\BufferSink;
use BinktermPHP\TelnetServer\SocketSink;
use BinktermPHP\TelnetServer\TelnetUtils;
use BinktermPHP\TelnetServer\TerminalCapabilities;
use BinktermPHP\TelnetServer\TerminalRenderContext;
use PHPUnit\Framework\TestCase;

/**
 * Frame coalescing: a full screen is delivered to the socket in one physical
 * write instead of line by line, with byte-for-byte identical output.
 *
 * The physical write count is observed through a stream wrapper that counts
 * stream_write() calls.
 */
final class TerminalFrameCoalescingTest extends TestCase
{
    protected function setUp(): void
    {
        CountingStreamWrapper::$writes = 0;
        CountingStreamWrapper::$bytes  = '';
        if (in_array('cframe', stream_get_wrappers(), true)) {
            stream_wrapper_unregister('cframe');
        }
        stream_wrapper_register('cframe', CountingStreamWrapper::class);
    }

    protected function tearDown(): void
    {
        @stream_wrapper_unregister('cframe');
        // Leave no dangling frame scope for the next test.
        $ref = new \ReflectionClass(TelnetUtils::class);
        foreach (['frameBuf' => null, 'frameDepth' => 0] as $p => $v) {
            $prop = $ref->getProperty($p);
            $prop->setAccessible(true);
            $prop->setValue(null, $v);
        }
    }

    /** @return resource */
    private function countingConn()
    {
        return fopen('cframe://x', 'w');
    }

    // ===== SocketSink =====

    public function testSocketSinkUnframedWritesGoStraightThrough(): void
    {
        $sink = new SocketSink($this->countingConn());
        $sink->write("a");
        $sink->write("b");
        $sink->write("c");

        self::assertSame(3, CountingStreamWrapper::$writes);
        self::assertSame('abc', CountingStreamWrapper::$bytes);
    }

    public function testSocketSinkFrameScopeCoalescesToOneWrite(): void
    {
        $sink = new SocketSink($this->countingConn());
        $sink->beginFrame();
        $sink->write("line1\r\n");
        $sink->write("line2\r\n");
        $sink->write("line3\r\n");
        self::assertSame(0, CountingStreamWrapper::$writes, 'nothing hits the socket until the frame closes');
        $sink->endFrame();

        self::assertSame(1, CountingStreamWrapper::$writes, 'the whole frame is one physical write');
        self::assertSame("line1\r\nline2\r\nline3\r\n", CountingStreamWrapper::$bytes);
    }

    public function testSocketSinkFrameScopesNestAndOnlyOuterFlushes(): void
    {
        $sink = new SocketSink($this->countingConn());
        $sink->beginFrame();
        $sink->write("x");
        $sink->beginFrame();
        $sink->write("y");
        $sink->endFrame();
        self::assertSame(0, CountingStreamWrapper::$writes, 'inner endFrame does not flush');
        $sink->write("z");
        $sink->endFrame();

        self::assertSame(1, CountingStreamWrapper::$writes);
        self::assertSame('xyz', CountingStreamWrapper::$bytes);
    }

    public function testSocketSinkUnbalancedEndFrameIsHarmless(): void
    {
        $sink = new SocketSink($this->countingConn());
        $sink->endFrame(); // no matching begin
        $sink->write("ok");

        self::assertSame(1, CountingStreamWrapper::$writes);
        self::assertSame('ok', CountingStreamWrapper::$bytes);
    }

    public function testSocketSinkEmptyFrameWritesNothing(): void
    {
        $sink = new SocketSink($this->countingConn());
        $sink->beginFrame();
        $sink->endFrame();

        self::assertSame(0, CountingStreamWrapper::$writes);
    }

    // ===== TelnetUtils::framed / beginFrame / endFrame =====

    public function testTelnetUtilsFramedWrapperCoalescesSafeWrites(): void
    {
        $conn = $this->countingConn();

        $render = TelnetUtils::framed($conn, static function () use ($conn): string {
            TelnetUtils::safeWrite($conn, "\033[2J\033[H");
            TelnetUtils::writeLine($conn, "row one");
            TelnetUtils::writeLine($conn, "row two");
            TelnetUtils::safeWrite($conn, "\033[1;1H");
            return 'done';
        });

        $ret = $render();

        self::assertSame('done', $ret, 'the wrapper is transparent to the return value');
        self::assertSame(1, CountingStreamWrapper::$writes);
        self::assertSame("\033[2J\033[Hrow one\r\nrow two\r\n\033[1;1H", CountingStreamWrapper::$bytes);
    }

    public function testTelnetUtilsFramedFlushesEvenWhenTheRenderThrows(): void
    {
        $conn = $this->countingConn();

        $render = TelnetUtils::framed($conn, static function () use ($conn): void {
            TelnetUtils::safeWrite($conn, "partial");
            throw new \RuntimeException('boom');
        });

        try {
            $render();
            self::fail('exception should propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertSame(1, CountingStreamWrapper::$writes, 'the frame still flushed');
        self::assertSame('partial', CountingStreamWrapper::$bytes);

        // Framing mode must be cleared so the next write is not swallowed.
        TelnetUtils::safeWrite($conn, "!");
        self::assertSame("partial!", CountingStreamWrapper::$bytes);
    }

    public function testTelnetUtilsUnframedSafeWriteIsUnchanged(): void
    {
        $conn = $this->countingConn();
        TelnetUtils::safeWrite($conn, "a");
        TelnetUtils::safeWrite($conn, "b");

        self::assertSame(2, CountingStreamWrapper::$writes);
        self::assertSame('ab', CountingStreamWrapper::$bytes);
    }

    // ===== byte-equivalence across sinks =====

    public function testFramedAndUnframedRenderProduceIdenticalBytes(): void
    {
        // Same sequence of writes, once line-by-line and once inside a frame.
        $seq = static function ($conn): void {
            TelnetUtils::safeWrite($conn, "\033[2J\033[H");
            for ($i = 1; $i <= 20; $i++) {
                TelnetUtils::writeLine($conn, "item {$i}  \033[36m*\033[0m");
            }
            TelnetUtils::safeWrite($conn, "\033[24;1H> ");
        };

        $plain = $this->countingConn();
        $seq($plain);
        $plainBytes = CountingStreamWrapper::$bytes;
        $plainWrites = CountingStreamWrapper::$writes;

        CountingStreamWrapper::$writes = 0;
        CountingStreamWrapper::$bytes  = '';

        $framedConn = $this->countingConn();
        TelnetUtils::framed($framedConn, static function () use ($seq, $framedConn): void {
            $seq($framedConn);
        })();
        $framedBytes = CountingStreamWrapper::$bytes;
        $framedWrites = CountingStreamWrapper::$writes;

        self::assertSame($plainBytes, $framedBytes, 'coalescing changes delivery, never content');
        self::assertGreaterThan(20, $plainWrites);
        self::assertSame(1, $framedWrites);
    }

    // ===== NavigationScreenRenderer path: byte-identical, one write =====

    public function testNavigationRenderIsByteIdenticalAcrossSinksAndOneSocketWrite(): void
    {
        require_once __DIR__ . '/../../src/Terminal/Navigation/NavigationScreenRenderer.php';

        $caps = TerminalCapabilities::unknown()
            ->withColorSupport(TerminalCapabilities::COLOR_ANSI)
            ->withCharsetSupport(TerminalCapabilities::CHARSET_UTF8);

        $screen = (new \BinktermPHP\Terminal\Navigation\NavigationScreenBuilder(
            \BinktermPHP\Terminal\Navigation\TerminalActionCatalog::defaultRegistry(),
            static fn (?string $k, string $fallback, string $l) => $fallback,
        ))->build(
            \BinktermPHP\Terminal\Navigation\NavigationDefinition::fromArray([
                'schema' => 1, 'id' => 'x', 'root' => 'main',
                'nodes' => [['id' => 'main', 'label_fallback' => 'Home', 'items' => [
                    ['id' => 'a', 'label_fallback' => 'Netmail', 'hotkey' => 'n', 'action' => 'netmail',
                     'description_fallback' => 'Private mail'],
                    ['id' => 'b', 'label_fallback' => 'Echomail', 'hotkey' => 'e', 'action' => 'echomail',
                     'description_fallback' => 'Public areas'],
                    ['id' => 'c', 'label_fallback' => 'Log Off', 'hotkey' => 'q', 'action' => 'quit'],
                ]]],
            ]),
            new \BinktermPHP\Terminal\Navigation\AccessContext(true, false, false, fn () => true, fn () => true, []),
            \BinktermPHP\Terminal\Navigation\NavigationPath::root('main', 'Home'),
        );
        $renderer = new \BinktermPHP\Terminal\Navigation\NavigationScreenRenderer();

        foreach ([[80, 24], [132, 36]] as [$cols, $rows]) {
            $buf = new BufferSink();
            $renderer->render(
                new TerminalRenderContext($buf, $caps, $cols, $rows, 'utf8', true, false, [], 'en', new Translator()),
                $screen,
                ['cursor' => 0]
            );

            CountingStreamWrapper::$writes = 0;
            CountingStreamWrapper::$bytes  = '';
            $socket = new SocketSink($this->countingConn());
            $renderer->render(
                new TerminalRenderContext($socket, $caps, $cols, $rows, 'utf8', true, false, [], 'en', new Translator()),
                $screen,
                ['cursor' => 0]
            );

            self::assertSame($buf->getBytes(), CountingStreamWrapper::$bytes, "{$cols}x{$rows} bytes identical");
            self::assertSame(1, CountingStreamWrapper::$writes, "{$cols}x{$rows} paints in one physical write");
        }
    }
}

/**
 * A stream wrapper that records every stream_write() call and the bytes.
 */
final class CountingStreamWrapper
{
    public static int $writes = 0;
    public static string $bytes = '';

    /** @var resource */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        self::$writes++;
        self::$bytes .= $data;

        return strlen($data);
    }

    public function stream_eof(): bool
    {
        return false;
    }

    public function stream_flush(): bool
    {
        return true;
    }

    public function stream_close(): void
    {
    }

    public function stream_set_option(int $option, int $arg1, int $arg2): bool
    {
        return false;
    }
}
