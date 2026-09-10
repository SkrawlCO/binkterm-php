<?php

namespace BinktermPHP\Terminal\People;

/**
 * A bounded per-session cache of one People-landing presence read — the live
 * roster plus the Recent Callers line.
 *
 * Structurally identical to {@see \BinktermPHP\Newscan\NewscanSnapshot}: the
 * authored People landing reads the value on every redraw (STATUS projection),
 * but the underlying presence query only runs again when the snapshot is
 * invalidated or the TTL backstop expires — never on cursor movement. The
 * navigation runtime calls {@see invalidate()} at every action boundary, so
 * returning from Who's Online (which may have changed who is present) shows a
 * fresh roster on the next draw; the TTL only bounds staleness for a caller who
 * sits idle on the landing.
 *
 * The provider is a plain `callable(): array{roster: list<array{name:string,
 * activity:string}>|null, recentLine: string|null}`. A `null` roster means the
 * presence read failed and the landing should render a blank STATUS rather than
 * claim the board is quiet. This class performs no queries of its own.
 */
final class PeopleRosterSnapshot
{
    /** @var array{roster: list<array{name:string,activity:string}>|null, recentLine: string|null}|null */
    private ?array $value = null;
    private int $resolvedAt = 0;
    private int $generation = 0;

    /**
     * @param callable():array{roster: list<array{name:string,activity:string}>|null, recentLine: string|null} $provider
     * @param int $ttlSeconds  staleness bound for an idle caller (0 disables it)
     * @param (callable():int)|null $clock  test seam; defaults to time()
     */
    public function __construct(
        private readonly mixed $provider,
        private readonly int $ttlSeconds = 10,
        private readonly mixed $clock = null,
    ) {
    }

    /**
     * @return array{roster: list<array{name:string,activity:string}>|null, recentLine: string|null}
     */
    public function value(): array
    {
        $now = is_callable($this->clock) ? (int) ($this->clock)() : time();
        if ($this->value === null
            || ($this->ttlSeconds > 0 && ($now - $this->resolvedAt) >= $this->ttlSeconds)
        ) {
            $this->value = ($this->provider)();
            $this->resolvedAt = $now;
            $this->generation++;
        }

        return $this->value;
    }

    /**
     * A counter that increments every time {@see value()} actually (re)computes.
     * A consumer memoises its projection against this so repeated reads (cursor
     * movement) never re-project and never re-query.
     */
    public function generation(): int
    {
        return $this->generation;
    }

    /** Drop the cached value; the next {@see value()} call recomputes it. */
    public function invalidate(): void
    {
        $this->value = null;
    }
}
