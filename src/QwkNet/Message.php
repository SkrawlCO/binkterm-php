<?php

declare(strict_types=1);

namespace BinktermPHP\QwkNet;

/** No persistence identity: offsets and message numbers refer only to this packet. */
final class Message
{
    public function __construct(
        public readonly array $header,
        public readonly string $conferenceName,
        public readonly string $sender,
        public readonly string $recipient,
        public readonly string $subject,
        public readonly ?string $writtenTimestamp,
        public readonly string $rawHeader,
        public readonly string $rawBody,
        public readonly string $displayBody,
        public readonly ?string $externalMessageId,
        public readonly ?string $externalReplyId,
        public readonly array $via,
        public readonly array $timezone,
        public readonly array $inlineMetadata,
        public readonly array $extendedHeaders,
        public readonly string $rawExtendedHeaders
    ) {
    }
}
