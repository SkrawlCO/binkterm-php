<?php

declare(strict_types=1);

require_once __DIR__ . '/../../telnet/src/OutputSink.php';
require_once __DIR__ . '/../../telnet/src/BufferSink.php';
require_once __DIR__ . '/../../telnet/src/SocketSink.php';

use BinktermPHP\TelnetServer\BufferSink;
use BinktermPHP\TelnetServer\SocketSink;
use PHPUnit\Framework\TestCase;

/**
 * F1 — OutputSink implementations.
 */
final class TerminalOutputSinkTest extends TestCase
{
    public function testBufferSinkAccumulatesRawBytesInOrder(): void
    {
        $s = new BufferSink();
        $s->write("hello ");
        $s->write("\033[31mworld\033[0m");
        $s->write("\r\n");

        self::assertSame("hello \033[31mworld\033[0m\r\n", $s->getBytes());
        self::assertTrue($s->isWritable());
    }

    public function testBufferSinkRetainsEscapeAndControlBytesVerbatim(): void
    {
        $s = new BufferSink();
        $payload = "\033[2J\033[H\x1bPq#0;2;0;0;0\x1b\\\xff\xff";
        $s->write($payload);

        self::assertSame($payload, $s->getBytes());
    }

    public function testBufferSinkClear(): void
    {
        $s = new BufferSink();
        $s->write("abc");
        $s->clear();
        self::assertSame('', $s->getBytes());
        $s->write("def");
        self::assertSame('def', $s->getBytes());
    }

    public function testBufferSinkFlushIsNoop(): void
    {
        $s = new BufferSink();
        $s->write("x");
        $s->flush();
        self::assertSame('x', $s->getBytes());
    }

    public function testSocketSinkWritesAllBytesToTheResource(): void
    {
        $mem = fopen('php://temp', 'r+');
        $sink = new SocketSink($mem);

        $data = str_repeat("A", 100000) . "\033[0m";
        $sink->write($data);

        rewind($mem);
        self::assertSame($data, stream_get_contents($mem));
        self::assertTrue($sink->isWritable());
        fclose($mem);
    }

    public function testSocketSinkNeverClosesTheResource(): void
    {
        $mem = fopen('php://temp', 'r+');
        $sink = new SocketSink($mem);

        $sink->write("data");
        $sink->flush();
        unset($sink);

        self::assertIsResource($mem, 'sink must not close the caller-owned resource');
        fclose($mem);
    }

    public function testSocketSinkSilentOnClosedResource(): void
    {
        $mem = fopen('php://temp', 'r+');
        $sink = new SocketSink($mem);
        fclose($mem);

        // Must not throw / warn.
        $sink->write("ignored");
        $sink->flush();
        self::assertFalse($sink->isWritable());
    }
}
