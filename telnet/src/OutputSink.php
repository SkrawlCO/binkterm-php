<?php

namespace BinktermPHP\TelnetServer;

/**
 * OutputSink — the single write target for terminal rendering.
 *
 * Terminal rendering code (widgets, box renderers, future declarative screen
 * renderers) emits bytes through an {@see OutputSink} rather than writing to a
 * socket resource directly. This is what lets one rendering path serve three
 * consumers:
 *
 *   - the live Telnet/SSH session   ({@see SocketSink})
 *   - deterministic render tests     ({@see BufferSink})
 *   - the future sysop preview        ({@see BufferSink})
 *
 * F1 scope: this interface is deliberately writes-only. Semantic terminal
 * operations (clear screen, cursor moves, synchronized-output frame markers)
 * are NOT part of the F1 contract — existing code already emits those as
 * literal escape strings through {@see write()}, and a semantic API is only
 * justified when frame coalescing / synchronized output is implemented
 * (a later stage). Do not add speculative methods here.
 *
 * An implementation must never throw on a broken/closed connection; it returns
 * silently, matching the historical `safeWrite()` behaviour.
 */
interface OutputSink
{
    /**
     * Append raw bytes to the output. The bytes may contain ANSI escape
     * sequences, Sixel data, or IAC-escaped payload; the sink does not
     * interpret them.
     */
    public function write(string $bytes): void;

    /**
     * Flush any buffered bytes toward their destination. A no-op for sinks
     * that have no downstream buffer (e.g. {@see BufferSink}).
     */
    public function flush(): void;

    /**
     * Whether the sink can currently accept writes. A closed socket returns
     * false; an in-memory buffer always returns true. Callers may skip
     * rendering work when this is false, but calling {@see write()} on a
     * non-writable sink is still safe (it is a silent no-op).
     */
    public function isWritable(): bool;
}
