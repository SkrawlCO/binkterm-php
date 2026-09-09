<?php

namespace BinktermPHP\Terminal\Navigation;

/**
 * The runtime facts an {@see AccessExpression} is evaluated against.
 *
 * This deliberately exposes only capabilities BinktermPHP genuinely has today:
 * the binary authenticated / guest / sysop distinction, whether a named platform
 * feature is enabled, whether a registered action is currently available, and a
 * snapshot of terminal capability booleans. It is NOT a role/permission engine —
 * if the roles proposal (docs/proposals/AccessControl_Proposal.md) is
 * implemented later, a `role:` / `flag:` predicate can be added here without
 * changing the expression grammar.
 */
final class AccessContext
{
    /**
     * @param bool                     $authenticated  A real (non-guest) user is logged in.
     * @param bool                     $admin          The user has `is_admin`.
     * @param bool                     $guest          The session is the anonymous guest.
     * @param callable(string):bool    $featureResolver Resolves `feature:<name>` predicates.
     * @param callable(string):bool    $actionResolver  Resolves `action:<id>` predicates (is the action available?).
     * @param array<string,bool>       $capabilities   `color`, `utf8`, `sixel` terminal capability flags.
     */
    public function __construct(
        private readonly bool $authenticated,
        private readonly bool $admin,
        private readonly bool $guest,
        private readonly mixed $featureResolver,
        private readonly mixed $actionResolver,
        private readonly array $capabilities = [],
    ) {
    }

    /**
     * Build a context from a terminal session `$state` array plus resolvers.
     *
     * @param array<string,mixed>    $state
     * @param array<string,bool>     $capabilities
     * @param callable(string):bool  $featureResolver
     * @param callable(string):bool  $actionResolver
     */
    public static function fromTerminalState(
        array $state,
        array $capabilities,
        callable $featureResolver,
        callable $actionResolver
    ): self {
        $userId   = (int)($state['user_id'] ?? 0);
        $username = (string)($state['username'] ?? '');
        $guest    = $userId <= 0 || $username === '' || $username === '_guest';

        return new self(
            authenticated: !$guest,
            admin: !empty($state['is_admin']),
            guest: $guest,
            featureResolver: $featureResolver,
            actionResolver: $actionResolver,
            capabilities: $capabilities,
        );
    }

    public function isAuthenticated(): bool { return $this->authenticated; }

    public function isAdmin(): bool { return $this->admin; }

    public function isGuest(): bool { return $this->guest; }

    public function featureEnabled(string $feature): bool
    {
        return (bool) ($this->featureResolver)($feature);
    }

    public function actionAvailable(string $actionId): bool
    {
        return (bool) ($this->actionResolver)($actionId);
    }

    public function hasCapability(string $name): bool
    {
        return !empty($this->capabilities[$name]);
    }
}
