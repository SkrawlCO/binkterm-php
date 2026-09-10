<?php

namespace BinktermPHP\TelnetServer;

/**
 * Terminal event handler that flags the current {@see BbsSession} for
 * termination when a `session.kick` event for *this* session's auth session
 * arrives on the realtime bus.
 *
 * The poller ({@see TerminalEventPoller}) already restricts delivery to events
 * whose `user_id` is NULL or matches this user, so a kick for a *different*
 * session belonging to the *same* user still reaches this handler — it is
 * filtered here by an exact `session_id` match so only the targeted session
 * ends.
 *
 * Reason handling is deliberately closed: the payload `code` is matched against
 * a small fixed allow-list and anything else collapses to a generic code.
 * Arbitrary server-supplied text is never read and never surfaced to the caller.
 *
 * The `session.kick` type string and the `revoked` / `revoked_all` codes are
 * produced by {@see \BinktermPHP\Security\ActiveSessionService}; they are
 * duplicated as literals here so the terminal daemon does not have to resolve
 * the security namespace at class-load time.
 */
final class SessionKickHandler implements TerminalEventHandlerInterface
{
    /** `sse_events.event_type` this handler acts on. */
    public const EVENT_TYPE = 'session.kick';

    /** Payload codes echoed through as-is; anything else -> CODE_GENERIC. */
    private const KNOWN_CODES = ['revoked', 'revoked_all'];

    /** Used for an unknown/absent payload code and by the time-based validity net. */
    public const CODE_GENERIC = 'terminated';

    private string $authSessionId;
    private ?string $terminationCode = null;

    public function __construct(string $authSessionId)
    {
        $this->authSessionId = $authSessionId;
    }

    public function handleTerminalEvent(string $eventType, array $payload, int $eventId): void
    {
        if ($eventType !== self::EVENT_TYPE) {
            return;
        }

        if ((string) ($payload['session_id'] ?? '') !== $this->authSessionId) {
            return; // a different session (possibly this same user's) — ignore
        }

        $code = (string) ($payload['code'] ?? '');
        $this->terminationCode = in_array($code, self::KNOWN_CODES, true)
            ? $code
            : self::CODE_GENERIC;
    }

    /**
     * Return the pending termination code once (then clear it). Null when no
     * matching kick has been seen.
     */
    public function takeTerminationCode(): ?string
    {
        $code = $this->terminationCode;
        $this->terminationCode = null;
        return $code;
    }
}
