<?php

declare(strict_types=1);

namespace BinktermPHP\Crossroads;

/**
 * Immutable, secret-free descriptor of the Chessmata account a BinkTerm user
 * resolves to. Safe to log and to hand to presentation code. Bearer credentials
 * are obtained separately via ChessmataIdentity::terminalCredential() /
 * webCredential().
 */
final class ChessmataAccount
{
    public function __construct(
        public readonly int $binktermUserId,
        public readonly string $chessmataUserId,
        public readonly string $email,
        public readonly string $displayName,
        public readonly \DateTimeImmutable $provisionedAt,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'binkterm_user_id'  => $this->binktermUserId,
            'chessmata_user_id' => $this->chessmataUserId,
            'email'             => $this->email,
            'display_name'      => $this->displayName,
            'provisioned_at'    => $this->provisionedAt->format(\DATE_ATOM),
        ];
    }
}
