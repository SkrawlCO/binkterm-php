<?php

namespace BinktermPHP\TelnetServer;

/**
 * A consumer of realtime events delivered to a terminal session by
 * {@see TerminalEventPoller}.
 *
 * Implementations must be cheap and non-blocking — the poller runs them inline
 * on the session's input loop between keystrokes. An implementation that needs
 * to draw must defer to the session's normal redraw path; it must not read
 * input, block on I/O, or touch idle / activity timers.
 */
interface TerminalEventHandlerInterface
{
    /**
     * Handle one event.
     *
     * @param string               $eventType The `sse_events.event_type` value.
     * @param array<string,mixed>   $payload   Decoded `sse_events.payload`.
     * @param int                   $eventId   The `sse_events.id` (monotonic).
     */
    public function handleTerminalEvent(string $eventType, array $payload, int $eventId): void;
}
