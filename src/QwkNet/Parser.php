<?php

declare(strict_types=1);

namespace BinktermPHP\QwkNet;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use ZipArchive;

/** Pure local QWK reader. No application bootstrap, database, transport or extraction. */
final class Parser
{
    public function __construct(
        private readonly int $maxArchiveBytes = 16777216,
        private readonly int $maxUncompressedBytes = 67108864,
        private readonly int $maxFiles = 256,
        private readonly int $maxMessages = 10000
    ) {
        foreach ([$maxArchiveBytes, $maxUncompressedBytes, $maxFiles, $maxMessages] as $limit) {
            if ($limit < 1) {
                throw new RuntimeException('Parser limits must be positive');
            }
        }
    }

    public function parse(string $path): Packet
    {
        // Reject wrappers before any filesystem operation (including is_file).
        if ($path === '' || str_contains($path, "\0") || preg_match('~^[a-z][a-z0-9+.-]*:~i', $path)) {
            throw new RuntimeException('An explicit local file path is required');
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Packet is not a readable local file');
        }
        $size = filesize($path);
        if ($size === false || $size > $this->maxArchiveBytes) {
            throw new RuntimeException('Archive size limit exceeded');
        }
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            throw new RuntimeException('Malformed ZIP archive');
        }
        $components = [];
        try {
            if ($zip->numFiles > $this->maxFiles) {
                throw new RuntimeException('Archive file count limit exceeded');
            }
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    throw new RuntimeException('Unreadable ZIP directory');
                }
                $name = $stat['name'];
                // M1 accepts root-level packet files only; no paths, links or directories.
                if (!preg_match('/\A[A-Za-z0-9_][A-Za-z0-9_.-]*\z/D', $name) || str_contains($name, '..')) {
                    throw new RuntimeException('Unsafe archive entry name');
                }
                $name = strtoupper($name);
                if (isset($components[$name])) {
                    throw new RuntimeException('Ambiguous duplicate archive entry');
                }
                $opsys = $attributes = 0;
                if ($zip->getExternalAttributesIndex($i, $opsys, $attributes)
                    && $opsys === 3 && (($attributes >> 16) & 0170000) === 0120000) {
                    throw new RuntimeException('Archive links are not supported');
                }
                $total += $stat['size'];
                if ($total > $this->maxUncompressedBytes || ($stat['encryption_method'] ?? 0) !== 0) {
                    throw new RuntimeException('Archive expansion limit exceeded or encrypted entry');
                }
                $bytes = $zip->getFromIndex($i, $stat['size'] + 1);
                if ($bytes === false || strlen($bytes) !== $stat['size']
                    || hash('crc32b', $bytes) !== sprintf('%08x', $stat['crc'])) {
                    throw new RuntimeException('Corrupt ZIP entry');
                }
                $components[$name] = $bytes;
            }
        } finally {
            $zip->close();
        }
        foreach (['CONTROL.DAT', 'MESSAGES.DAT'] as $required) {
            if (!isset($components[$required])) {
                throw new RuntimeException('Missing required component: ' . $required);
            }
        }
        $control = $this->control($components['CONTROL.DAT']);
        $extended = $this->headers($components['HEADERS.DAT'] ?? '');
        $data = $components['MESSAGES.DAT'];
        if (strlen($data) < 128 || strlen($data) % 128 !== 0) {
            throw new RuntimeException('MESSAGES.DAT is not a complete sequence of 128-byte blocks');
        }
        $messages = [];
        for ($offset = 128; $offset < strlen($data); $offset += $blocks * 128) {
            if (count($messages) >= $this->maxMessages) {
                throw new RuntimeException('Message count limit exceeded');
            }
            $rawHeader = substr($data, $offset, 128);
            $blocks = $this->decimal(substr($rawHeader, 116, 6));
            if ($blocks < 1 || $offset + $blocks * 128 > strlen($data)) {
                throw new RuntimeException('Invalid or truncated message block count at ' . $offset);
            }
            $conference = unpack('v', substr($rawHeader, 123, 2))[1];
            if (!array_key_exists($conference, $control['conferences'])) {
                throw new RuntimeException('Message conference missing from CONTROL.DAT');
            }
            $header = [
                'offset' => $offset, 'status' => $rawHeader[0],
                'number' => $this->decimal(substr($rawHeader, 1, 7)),
                'date' => substr($rawHeader, 8, 8), 'time' => substr($rawHeader, 16, 5),
                'to' => rtrim(substr($rawHeader, 21, 25)),
                'from' => rtrim(substr($rawHeader, 46, 25)),
                'subject' => rtrim(substr($rawHeader, 71, 25)),
                'password' => substr($rawHeader, 96, 12),
                'reference' => $this->decimal(substr($rawHeader, 108, 8), true),
                'blocks' => $blocks, 'activity' => ord($rawHeader[122]),
                'conference' => $conference, 'sequence_raw' => substr($rawHeader, 125, 2),
                'network_tag_raw' => $rawHeader[127],
            ];
            // Validate the wall clock without assigning a source timezone or UTC instant.
            $clock = DateTimeImmutable::createFromFormat(
                '!m-d-yH:i', $header['date'] . $header['time'], new DateTimeZone('UTC')
            );
            if (!$clock || $clock->format('m-d-yH:i') !== $header['date'] . $header['time']) {
                throw new RuntimeException('Invalid QWK date/time');
            }
            $body = substr($data, $offset + 128, ($blocks - 1) * 128);
            $section = $extended[$offset] ?? ['fields' => [], 'raw' => ''];
            unset($extended[$offset]);
            $messages[] = $this->message($header, $rawHeader, $body, $section, $control);
        }
        if ($extended !== []) {
            throw new RuntimeException('HEADERS.DAT section does not point to a message header');
        }
        return new Packet(hash_file('sha256', $path), $control, $messages, $components);
    }

    private function decimal(string $value, bool $allowBlank = false): int
    {
        $value = trim($value);
        if ($allowBlank && $value === '') {
            return 0;
        }
        if (!preg_match('/\A[0-9]{1,9}\z/D', $value)) {
            throw new RuntimeException('Invalid decimal field');
        }
        return (int)$value;
    }

    private function control(string $raw): array
    {
        $lines = preg_split('/\r\n|\n|\r/', $raw);
        if (count($lines) < 13) {
            throw new RuntimeException('Truncated CONTROL.DAT');
        }
        $count = $this->decimal($lines[10]) + 1; // QWK stores conference count minus one.
        if ($count > 65536 || count($lines) < 11 + 2 * $count) {
            throw new RuntimeException('Truncated CONTROL.DAT conference list');
        }
        $conferences = [];
        for ($i = 0; $i < $count; $i++) {
            $number = $this->decimal($lines[11 + 2 * $i]);
            $name = $lines[12 + 2 * $i];
            if ($number > 65535 || isset($conferences[$number]) || trim($name) === '') {
                throw new RuntimeException('Ambiguous or invalid conference mapping');
            }
            $conferences[$number] = self::display($name);
        }
        $identity = explode(',', $lines[4], 2);
        if (count($identity) !== 2 || trim($identity[1]) === '') {
            throw new RuntimeException('Missing packet identity');
        }
        return [
            'system' => self::display($lines[0]), 'location' => self::display($lines[1]),
            'sysop' => self::display($lines[3]), 'bbs_number_raw' => $identity[0],
            'packet_id' => self::display(trim($identity[1])),
            'created_raw' => $lines[5], // No offset supplied here: do not invent one.
            'user' => self::display($lines[6]), 'conferences' => $conferences,
        ];
    }

    /** Section names are hexadecimal MESSAGES.DAT byte offsets, not ordinal IDs. */
    private function headers(string $raw): array
    {
        $sections = [];
        $offset = null;
        foreach (preg_split('/(?<=\n)/', $raw) as $line) {
            $text = rtrim($line, "\r\n");
            if (preg_match('/\A\[([a-fA-F0-9]{1,8})\]\z/D', $text, $match)) {
                $offset = (int)hexdec($match[1]);
                if ($offset < 128 || $offset % 128 !== 0 || isset($sections[$offset])) {
                    throw new RuntimeException('Invalid or duplicate HEADERS.DAT offset');
                }
                $sections[$offset] = ['fields' => [], 'raw' => $line];
                continue;
            }
            if ($offset === null) {
                if (trim($text) !== '') {
                    throw new RuntimeException('HEADERS.DAT field without section');
                }
                continue;
            }
            $sections[$offset]['raw'] .= $line;
            if (trim($text) === '') {
                continue;
            }
            if (!preg_match('/\A([A-Za-z][A-Za-z0-9-]*)\s*[:=]\s*(.*)\z/D', $text, $match)) {
                throw new RuntimeException('Unsupported HEADERS.DAT field structure');
            }
            // Lists preserve repeated timestamps and ExportedFrom hops in wire order.
            $sections[$offset]['fields'][strtolower($match[1])][] = $match[2];
        }
        return $sections;
    }

    private function single(array $fields, string $key): ?string
    {
        $values = array_unique(array_map('trim', $fields[$key] ?? []));
        if (count($values) > 1) {
            throw new RuntimeException('Conflicting singleton metadata: ' . $key);
        }
        return $values === [] ? null : reset($values);
    }

    private function message(array $h, string $rawHeader, string $body, array $section, array $control): Message
    {
        $fields = $section['fields'];
        if (($this->single($fields, 'utf8') ?? 'false') !== 'false') {
            throw new RuntimeException('M1 supports CP437 packets only');
        }
        $extConference = $this->single($fields, 'conference');
        if ($extConference !== null && $this->decimal($extConference) !== $h['conference']) {
            throw new RuntimeException('HEADERS.DAT conference disagrees with record');
        }
        $inline = [];
        $displayBody = str_replace("\xe3", "\n", $body);
        // Only the leading metadata prefix is interpreted; quoted body text is left alone.
        while (preg_match('/\A@(MSGID|REPLY|VIA|TZ):([^\n]*)(?:\n|\z)/', $displayBody, $m)) {
            $inline[strtolower($m[1])][] = $m[2];
            $displayBody = substr($displayBody, strlen($m[0]));
        }
        $ids = [];
        foreach (['msgid' => 'message-id', 'reply' => 'in-reply-to'] as $inlineKey => $headerKey) {
            $a = $this->single($inline, $inlineKey);
            $b = $this->single($fields, $headerKey);
            if ($a !== null && $b !== null && $a !== $b) {
                throw new RuntimeException('Inline and extended message identity disagree');
            }
            $ids[$inlineKey] = $b ?? $a;
        }
        $names = [];
        foreach (['sender' => 'from', 'to' => 'to', 'subject' => 'subject'] as $extendedKey => $fixedKey) {
            $value = $this->single($fields, $extendedKey);
            if ($value !== null && rtrim(substr($value, 0, 25)) !== $h[$fixedKey]) {
                throw new RuntimeException('Extended name/subject disagrees with fixed header');
            }
            $names[$fixedKey] = self::display($value ?? $h[$fixedKey]);
        }
        $written = null;
        $tz = ['inline_raw' => $inline['tz'] ?? [], 'token' => $this->single($inline, 'tz'), 'offset' => null];
        $when = $this->single($fields, 'whenwritten');
        if ($when !== null) {
            if (!preg_match('/\A([0-9]{14})([+-][0-9]{4})\s+([0-9a-fA-F]{4})\z/D', $when, $m)) {
                throw new RuntimeException('Unsupported WhenWritten timestamp');
            }
            $date = DateTimeImmutable::createFromFormat('!YmdHisO', $m[1] . $m[2]);
            if (!$date || $date->format('YmdHisO') !== $m[1] . $m[2]
                || $date->format('m-d-yH:i') !== $h['date'] . $h['time']) {
                throw new RuntimeException('Extended timestamp disagrees with QWK date/time');
            }
            if ($tz['token'] !== null && strtolower($tz['token']) !== strtolower($m[3])) {
                throw new RuntimeException('Inline and extended timezone tokens disagree');
            }
            $tz['offset'] = $date->format('P');
            $written = $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        }
        return new Message(
            $h, $control['conferences'][$h['conference']], $names['from'], $names['to'], $names['subject'],
            $written, $rawHeader, $body, rtrim(self::display($displayBody)), $ids['msgid'], $ids['reply'],
            array_map('trim', $inline['via'] ?? []), $tz, $inline, $fields, $section['raw']
        );
    }

    /** CP437 display projection only. Original bytes are never rewritten. */
    public static function display(string $bytes): string
    {
        $bytes = preg_replace_callback('/\x01([\s\S])/', static function (array $m): string {
            return str_contains('kbgcrmyw01234567hin', strtolower($m[1])) ? '' : '[Ctrl-A:' . bin2hex($m[1]) . ']';
        }, $bytes);
        // Remove terminal escape sequences before converting the remaining CP437 text.
        $bytes = preg_replace('/\x1b\][^\x07\x1b]*(?:\x07|\x1b\\\\)|\x1b\[[0-?]*[ -\/]*[@-~]/', '', $bytes);
        $bytes = preg_replace('/[\x00-\x08\x0b-\x1f\x7f]/', '', str_replace("\r\n", "\n", $bytes));
        $text = iconv('CP437', 'UTF-8', $bytes);
        if ($text === false) {
            throw new RuntimeException('CP437 conversion failed');
        }
        return $text;
    }
}
