<?php

namespace BinktermPHP\Newscan;

/**
 * A bounded per-session cache of one {@see NewscanPlan}.
 *
 * The authored terminal Messages landing reads the plan on every redraw (STATUS
 * summary + per-destination badges), but the plan is only *recomputed* when the
 * snapshot is invalidated or the TTL backstop expires — never on cursor
 * movement. The navigation runtime calls {@see invalidate()} at every action
 * boundary, so returning from a destination that may have marked mail read
 * shows fresh counts on the next draw; the TTL only bounds staleness for a
 * caller who sits idle on the landing.
 *
 * The provider is a plain `callable(): NewscanPlan`. This class performs no
 * queries and no error handling of its own — a provider that fails is the
 * caller's concern.
 */
final class NewscanSnapshot
{
    private ?NewscanPlan $plan = null;
    private int $resolvedAt = 0;
    private int $generation = 0;

    /**
     * @param callable():NewscanPlan $provider  resolves a fresh plan
     * @param int $ttlSeconds  staleness bound for an idle caller (0 disables it)
     * @param (callable():int)|null $clock  test seam; defaults to time()
     */
    public function __construct(
        private readonly mixed $provider,
        private readonly int $ttlSeconds = 90,
        private readonly mixed $clock = null,
    ) {
    }

    public function plan(): NewscanPlan
    {
        $now = is_callable($this->clock) ? (int) ($this->clock)() : time();
        if ($this->plan === null
            || ($this->ttlSeconds > 0 && ($now - $this->resolvedAt) >= $this->ttlSeconds)
        ) {
            $this->plan = ($this->provider)();
            $this->resolvedAt = $now;
            $this->generation++;
        }

        return $this->plan;
    }

    /**
     * A counter that increments every time {@see plan()} actually (re)computes.
     * A consumer that derives its own value from the plan compares this to
     * decide whether its derived value is still current — it does not change on
     * a cache hit, so cursor movement never forces a recompute downstream.
     */
    public function generation(): int
    {
        return $this->generation;
    }

    /** Drop the cached plan; the next {@see plan()} call recomputes it. */
    public function invalidate(): void
    {
        $this->plan = null;
    }
}
