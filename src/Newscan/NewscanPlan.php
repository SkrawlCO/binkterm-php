<?php

namespace BinktermPHP\Newscan;

/**
 * The immutable result of {@see UnifiedNewscanService::plan()} — everything the
 * caller needs to present a "what's new for me?" summary and traverse it,
 * resolved entirely from canonical read state.
 *
 * Constructing or reading a plan performs ZERO read-state writes. Nothing here
 * marks a message read; that only happens when a message is actually opened
 * through the normal message path.
 */
final class NewscanPlan
{
    /**
     * @param int[]         $netmailIds oldest-first unread netmail ids
     * @param NewscanArea[] $areas      echomail areas with new messages, in a
     *                                  deterministic order (tag, then domain)
     * @param int           $bulletinUnread count of unread bulletins (not traversed
     *                                      message-by-message — a summary figure only)
     * @param bool          $truncated true when a per-area / area-count / netmail cap
     *                                 was hit, so the summary can say "N+"
     */
    public function __construct(
        public readonly array $netmailIds,
        public readonly array $areas,
        public readonly int $bulletinUnread = 0,
        public readonly bool $truncated = false,
    ) {
    }

    public static function empty(): self
    {
        return new self([], [], 0, false);
    }

    public function netmailCount(): int
    {
        return count($this->netmailIds);
    }

    public function areaCount(): int
    {
        return count($this->areas);
    }

    public function echomailCount(): int
    {
        $n = 0;
        foreach ($this->areas as $area) {
            $n += $area->count();
        }

        return $n;
    }

    /**
     * Canonical per-area NEW counts, keyed by echoarea id — the same
     * watermark-based "new since I last caught up here" figure that
     * {@see echomailCount()} aggregates. Areas with nothing new are absent
     * (callers treat a missing key as 0). This is the projection the authored
     * Echomail area browser uses; it is NOT `EchoareaManager.unread_count`,
     * which is a different (all-time unread) concept.
     *
     * @return array<int,int> echoareaId => new-message count
     */
    public function areaNewCounts(): array
    {
        $out = [];
        foreach ($this->areas as $area) {
            $out[$area->echoareaId] = $area->count();
        }

        return $out;
    }

    /** Any messages to traverse (netmail or echomail)? Bulletins do not count. */
    public function hasMessages(): bool
    {
        return $this->netmailIds !== [] || $this->areas !== [];
    }

    /** Nothing new at all — no messages and no unread bulletins. */
    public function isEmpty(): bool
    {
        return !$this->hasMessages() && $this->bulletinUnread === 0;
    }
}
