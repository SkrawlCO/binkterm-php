<?php

namespace BinktermPHP\TelnetServer;

use BinktermPHP\Realtime\StreamService;

/**
 * TerminalEventPoller — a generic, low-cost substrate for consuming realtime
 * events inside a terminal session.
 *
 * It reuses the existing BinkStream event bus ({@see StreamService} over the
 * `sse_events` table): the same rows the web SSE/WebSocket clients read. There
 * is no new event table, no new store, and no presence architecture — this is
 * only a *read* seam so a future terminal feature (a sysop broadcast, a "you
 * have new mail" nudge, a page notification) can react to events without each
 * feature reinventing polling.
 *
 * Design constraints (deliberate):
 *   - **Non-blocking:** {@see poll()} runs one indexed `id > ?` query, capped by
 *     a batch limit, and returns immediately. It is safe to call between
 *     keystrokes.
 *   - **Throttled:** a wall-clock minimum interval keeps the query rate low
 *     regardless of how often the caller loops.
 *   - **History-skipping:** {@see start()} anchors the cursor at the current max
 *     id, so a session only ever sees events emitted *after* it began polling.
 *   - **No side effects:** it never writes, never prunes (the existing hourly
 *     `sse_events` maintenance owns that), and never touches session activity /
 *     idle state. A pruned gap simply means some ids are skipped — the cursor
 *     only moves forward.
 *
 * Its first consumer is the session revocation / kick watch:
 * {@see BbsSession::beginSessionKickWatch()} builds a poller anchored
 * post-login and {@see BbsSession::pumpRealtimeAndCheckSession()} polls it with
 * a {@see SessionKickHandler} at the head of every idle-aware read primitive.
 * Further consumers (a sysop broadcast display, page notifications, MRC) attach
 * the same way with their own {@see TerminalEventHandlerInterface}; that UI is
 * out of scope. See docs/TerminalServerDevGuide.md → "Terminal event substrate".
 */
final class TerminalEventPoller
{
    private StreamService $stream;

    /** @var array<string,mixed> The session user descriptor: user_id / is_admin. */
    private array $user;

    private int $minIntervalSeconds;
    private int $batchLimit;

    private int $lastSeenId = 0;
    private float $lastPollAt = 0.0;
    private bool $started = false;

    /**
     * @param array<string,mixed> $user Must carry `user_id` (or `id`); `is_admin`
     *                                  optional. Anonymous pre-auth sessions can
     *                                  pass `['user_id' => 0]` to receive only
     *                                  broadcast (NULL-user) events.
     */
    public function __construct(
        StreamService $stream,
        array $user,
        int $minIntervalSeconds = 2,
        int $batchLimit = 50
    ) {
        $this->stream            = $stream;
        $this->user              = $user;
        $this->minIntervalSeconds = max(1, $minIntervalSeconds);
        $this->batchLimit        = max(1, $batchLimit);
    }

    /**
     * Anchor the cursor at the newest event so history is skipped. Safe to call
     * more than once; only the first call takes effect.
     */
    public function start(): void
    {
        if ($this->started) {
            return;
        }
        $this->started    = true;
        $this->lastSeenId = $this->stream->getMaxSseId();
    }

    /** The id the next poll will read after. */
    public function cursor(): int
    {
        return $this->lastSeenId;
    }

    /**
     * Dispatch any events newer than the cursor to $handler, respecting the
     * throttle. Returns the number of events dispatched (0 when throttled or
     * idle). Never throws for an empty / pruned bus.
     *
     * @param TerminalEventHandlerInterface|callable(string,array,int):void $handler
     */
    public function poll(TerminalEventHandlerInterface|callable $handler): int
    {
        if (!$this->started) {
            $this->start();
        }

        $now = microtime(true);
        if ($this->lastPollAt !== 0.0 && ($now - $this->lastPollAt) < $this->minIntervalSeconds) {
            return 0;
        }
        $this->lastPollAt = $now;

        $events = $this->stream->fetchEventsSince($this->user, $this->lastSeenId, $this->batchLimit);
        if ($events === []) {
            return 0;
        }

        $dispatched = 0;
        foreach ($events as $event) {
            $id = (int)($event['id'] ?? 0);
            if ($id > $this->lastSeenId) {
                $this->lastSeenId = $id;
            }

            $payload = [];
            if (isset($event['data']) && is_string($event['data']) && $event['data'] !== '') {
                $decoded = json_decode($event['data'], true);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            }

            $type = (string)($event['event'] ?? '');
            if ($handler instanceof TerminalEventHandlerInterface) {
                $handler->handleTerminalEvent($type, $payload, $id);
            } else {
                $handler($type, $payload, $id);
            }
            $dispatched++;
        }

        return $dispatched;
    }
}
