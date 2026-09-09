<?php

namespace BinktermPHP\TelnetServer;

/**
 * SocketSink — an {@see OutputSink} backed by a live connection resource
 * (a Telnet/TLS/SSH channel socket).
 *
 * Byte semantics are identical to the historical `BbsSession::safeWrite()` /
 * `TelnetUtils::safeWrite()` implementations: E_NOTICE is suppressed during the
 * write loop, partial writes are retried until every byte is sent, the loop
 * breaks on a closed/unrecoverable stream, and the stream is flushed after each
 * write. Nothing is thrown.
 *
 * Lifecycle: this sink holds a *reference* to the connection resource. It never
 * opens, closes, or reconfigures it — the owning session (BbsSession / the
 * transport daemon) is solely responsible for connect, TLS handshake, and
 * fclose/disconnect.
 */
final class SocketSink implements OutputSink
{
    /** @var resource|mixed The connection resource; lifecycle owned by the caller. */
    private $conn;

    /** Bytes accumulated while inside a frame scope (see {@see beginFrame()}). */
    private string $frameBuf = '';

    /** Frame-scope nesting depth; only the outermost {@see endFrame()} flushes. */
    private int $frameDepth = 0;

    /**
     * @param resource $conn A bidirectional stream resource. May become invalid
     *                       during the session; every method tolerates that.
     */
    public function __construct($conn)
    {
        $this->conn = $conn;
    }

    public function write(string $bytes): void
    {
        if ($this->frameDepth > 0) {
            $this->frameBuf .= $bytes;
            return;
        }

        $this->rawWrite($bytes);
    }

    /** Unbuffered write with the historical safeWrite() byte semantics. */
    private function rawWrite(string $bytes): void
    {
        if ($bytes === '' || !is_resource($this->conn)) {
            return;
        }

        $prev = error_reporting();
        error_reporting($prev & ~E_NOTICE);

        $total  = strlen($bytes);
        $offset = 0;
        while ($offset < $total) {
            $written = @fwrite($this->conn, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                break; // connection closed or unrecoverable error
            }
            $offset += $written;
        }

        @fflush($this->conn);
        error_reporting($prev);
    }

    public function beginFrame(): void
    {
        $this->frameDepth++;
    }

    public function endFrame(): void
    {
        if ($this->frameDepth === 0) {
            return; // unbalanced endFrame() — ignore
        }
        if (--$this->frameDepth > 0) {
            return; // still nested
        }

        $buf = $this->frameBuf;
        $this->frameBuf = '';
        $this->rawWrite($buf);
    }

    public function flush(): void
    {
        if (is_resource($this->conn)) {
            @fflush($this->conn);
        }
    }

    public function isWritable(): bool
    {
        return is_resource($this->conn);
    }
}
