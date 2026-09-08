<?php

declare(strict_types=1);

namespace BinktermPHP\QwkNet;

/** A local packet; rawComponents retain every archive member byte-for-byte. */
final class Packet
{
    /** @param Message[] $messages */
    public function __construct(
        public readonly string $sha256,
        public readonly array $control,
        public readonly array $messages,
        public readonly array $rawComponents
    ) {
    }
}
