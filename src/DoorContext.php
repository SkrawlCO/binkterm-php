<?php

namespace BinktermPHP;

/**
 * Immutable context describing a user's entry into a door.
 *
 * DoorContext represents identity and launch context only.
 * Runtime resources such as nodes, ports, processes, WebSocket tokens,
 * expiration, and door_sessions remain owned by DoorSessionManager.
 */
final class DoorContext
{
    public function __construct(
        public readonly int $userId,
        public readonly string $username,
        public readonly string $displayName,
        public readonly string $doorId,
        public readonly string $surface,
        public readonly ?string $authSessionId = null,
    ) {
    }

    /**
     * Create a door context from BinkTerm's authenticated user record.
     *
     * This translates the existing user representation into the canonical
     * immutable DoorContext without taking ownership of runtime resources.
     */
    public static function fromUser(
        array $user,
        string $doorId,
        string $surface,
        ?string $authSessionId = null
    ): self {
        return new self(
            userId: (int)($user['user_id'] ?? $user['id'] ?? 0),
            username: (string)($user['username'] ?? ''),
            displayName: (string)(
                $user['real_name']
                    ?? $user['display_name']
                    ?? $user['username']
                    ?? ''
            ),
            doorId: $doorId,
            surface: $surface,
            authSessionId: $authSessionId
        );
    }
}
