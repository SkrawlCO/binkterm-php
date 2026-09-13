<?php

namespace BinktermPHP\TelnetServer;

/**
 * Terminal event handler that surfaces SysOp Chat lifecycle transitions and
 * new-message nudges to a live Page SysOp flow
 * ({@see \BinktermPHP\TelnetServer\BbsSession} `runPageSysopFlow()` /
 * `runSysopChatModal()}).
 *
 * Filtered by an explicit "expected page id" the flow sets/clears as it
 * creates/leaves a page — unlike {@see SessionKickHandler}'s fixed
 * `session_id` (constant for the life of the auth session), a caller's page
 * id changes across the lifetime of one terminal session, so this is
 * mutable. An event for a stale/other page is ignored, never surfacing to
 * the flow. `null` expected id means "not currently paging" and every
 * `sysop_chat.*` event is ignored — defensive belt-and-braces: server-side
 * targeting already scopes delivery to this exact authenticated user, but
 * S0 explicitly called for the terminal side to also never let a stale
 * page's event hijack the session.
 *
 * Deliberately dumb: records what happened, never renders, never blocks,
 * never reads input — matching {@see TerminalEventHandlerInterface}'s
 * contract exactly. The flow drains it between reads.
 */
final class SysopChatEventHandler implements TerminalEventHandlerInterface
{
    /** Lifecycle transitions the flow needs to react to and exit a loop for. */
    private const TRANSITION_TYPES = [
        'sysop_chat.accepted',
        'sysop_chat.declined',
        'sysop_chat.expired',
        'sysop_chat.completed',
    ];

    private ?int $expectedPageId = null;
    private ?string $pendingTransition = null;
    private bool $hasNewMessage = false;

    /**
     * Set the page id events must match to be surfaced, or `null` to ignore
     * everything (not currently paging / chatting). Also clears any pending
     * state left over from a previous page — a fresh page starts clean.
     */
    public function setExpectedPageId(?int $pageId): void
    {
        $this->expectedPageId = $pageId;
        $this->pendingTransition = null;
        $this->hasNewMessage = false;
    }

    public function handleTerminalEvent(string $eventType, array $payload, int $eventId): void
    {
        if ($this->expectedPageId === null) {
            return;
        }
        if (!str_starts_with($eventType, 'sysop_chat.')) {
            return; // unrelated event type — safe to ignore
        }
        if ((int) ($payload['page_id'] ?? 0) !== $this->expectedPageId) {
            return; // a different (stale or unrelated) page — never hijack this session
        }

        if ($eventType === 'sysop_chat.message') {
            $this->hasNewMessage = true;
            return;
        }

        if (in_array($eventType, self::TRANSITION_TYPES, true)) {
            $this->pendingTransition = $eventType;
        }
    }

    /** Return the pending transition event type once (then clear it). */
    public function takePendingTransition(): ?string
    {
        $t = $this->pendingTransition;
        $this->pendingTransition = null;
        return $t;
    }

    /** Return whether a new message arrived since the last call (then clear it). */
    public function takeHasNewMessage(): bool
    {
        $v = $this->hasNewMessage;
        $this->hasNewMessage = false;
        return $v;
    }
}
