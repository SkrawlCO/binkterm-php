<?php

namespace BinktermPHP\Newscan;

/**
 * One echomail area that has unread/new messages for a caller, as resolved by
 * {@see UnifiedNewscanService}. Immutable; carries no behaviour and no
 * database handle.
 *
 * `messageIds` is the bounded, deterministic traversal order for the area
 * (oldest first). It is derived purely from canonical read state — building
 * this object marks nothing read.
 */
final class NewscanArea
{
    /**
     * @param int[] $messageIds oldest-first echomail ids that are new for the caller
     */
    public function __construct(
        public readonly int $echoareaId,
        public readonly string $tag,
        public readonly string $domain,
        public readonly string $description,
        public readonly array $messageIds,
    ) {
    }

    public function count(): int
    {
        return count($this->messageIds);
    }

    /**
     * The `TAG` or `TAG@domain` identifier the terminal echomail handlers use.
     */
    public function identifier(): string
    {
        return $this->domain === '' ? $this->tag : $this->tag . '@' . $this->domain;
    }
}
