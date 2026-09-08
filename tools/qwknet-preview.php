<?php

declare(strict_types=1);

// Intentionally no Composer/application bootstrap: this tool only loads pure parser classes.
require_once __DIR__ . '/../src/QwkNet/Message.php';
require_once __DIR__ . '/../src/QwkNet/Packet.php';
require_once __DIR__ . '/../src/QwkNet/Parser.php';

use BinktermPHP\QwkNet\Parser;

if ($argc < 2 || $argc > 3 || ($argc === 3 && $argv[2] !== '--details')) {
    fwrite(STDERR, "Usage: php tools/qwknet-preview.php /explicit/packet.qwk [--details]\n");
    exit(2);
}

try {
    $packet = (new Parser())->parse($argv[1]);
    // JSON escapes terminal controls even in metadata. Password and sender IP/host are omitted.
    $print = static function (array $value): void {
        echo json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
    };
    $print([
        'system' => $packet->control['system'], 'packet_id' => $packet->control['packet_id'],
        'user' => $packet->control['user'], 'created_raw' => $packet->control['created_raw'],
        'sha256' => $packet->sha256, 'conferences' => count($packet->control['conferences']),
        'messages' => count($packet->messages),
    ]);
    $counts = ['message_ids' => 0, 'reply_ids' => 0, 'via' => 0, 'tz' => 0, 'headers' => 0, 'ctrl_a_messages' => 0];
    foreach ($packet->messages as $message) {
        $h = $message->header;
        $counts['message_ids'] += $message->externalMessageId !== null;
        $counts['reply_ids'] += $message->externalReplyId !== null;
        $counts['via'] += $message->via !== [];
        $counts['tz'] += $message->timezone['token'] !== null;
        $counts['headers'] += $message->rawExtendedHeaders !== '';
        $counts['ctrl_a_messages'] += str_contains($message->rawBody, "\x01");
        $print([
            'number' => $h['number'], 'offset_hex' => dechex($h['offset']),
            'conference' => $h['conference'], 'area' => $message->conferenceName,
            'from' => $message->sender, 'to' => $message->recipient, 'subject' => $message->subject,
            'written_utc' => $message->writtenTimestamp, 'body_bytes' => strlen($message->rawBody),
            'message_id' => $message->externalMessageId, 'reply_id' => $message->externalReplyId,
            'via' => $message->via, 'timezone' => $message->timezone,
            'body_preview' => implode(' ', array_slice(explode("\n", $message->displayBody), 0, 2)),
        ]);
        if ($argc === 3) {
            $print([
                'number' => $h['number'], 'body' => $message->displayBody,
                'raw_body_base64' => base64_encode($message->rawBody),
                'inline' => $message->inlineMetadata,
                'headers' => array_intersect_key($message->extendedHeaders, array_flip([
                    'message-id', 'in-reply-to', 'whenwritten', 'whenimported', 'whenexported',
                    'exportedfrom', 'conference', 'sendernetaddr', 'organization', 'utf8',
                ])),
            ]);
        }
    }
    $print(['coverage' => $counts]);
} catch (Throwable $error) {
    fwrite(STDERR, 'QWK parse failed: ' . json_encode($error->getMessage()) . "\n");
    exit(1);
}
