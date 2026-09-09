<?php

namespace BinktermPHP\TelnetServer;

/**
 * BufferSink — an in-memory {@see OutputSink} for deterministic render tests
 * and the future sysop preview.
 *
 * It accumulates raw bytes exactly as they were written — escape sequences,
 * Sixel payloads, and control bytes are retained verbatim and never
 * interpreted. Tests retrieve the accumulated output with {@see getBytes()}
 * and reset between renders with {@see clear()}.
 *
 * F1 scope: raw bytes only. A decoded-text view or semantic-operation
 * recording would be added by a later slice only if a test genuinely needs it.
 */
final class BufferSink implements OutputSink
{
    private string $buffer = '';

    public function write(string $bytes): void
    {
        $this->buffer .= $bytes;
    }

    public function flush(): void
    {
        // No downstream buffer.
    }

    public function beginFrame(): void
    {
        // Already an in-memory accumulator — framing changes nothing.
    }

    public function endFrame(): void
    {
        // See beginFrame().
    }

    public function isWritable(): bool
    {
        return true;
    }

    /**
     * The exact bytes written so far, in order.
     */
    public function getBytes(): string
    {
        return $this->buffer;
    }

    /**
     * Discard everything written so far (e.g. between two capability profiles
     * in one test).
     */
    public function clear(): void
    {
        $this->buffer = '';
    }
}
