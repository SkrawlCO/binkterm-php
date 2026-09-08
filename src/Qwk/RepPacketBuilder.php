<?php

declare(strict_types=1);

namespace BinktermPHP\Qwk;

use ZipArchive;

/**
 * Serializes queued outbound messages into one QWKnet `<BBSID>.REP` archive
 * (`<BBSID>.MSG` 128-byte records + `HEADERS.DAT` extended metadata).
 *
 * Distinct from `RepProcessor`, which imports a local user's offline-reader REP.
 * Binary encoding adapted from the old upstream `src/Qwk/RepPacketBuilder.php`.
 *
 * Deterministic: given the same messages (same conference numbers, names,
 * subjects, bodies, timestamps, and MSGIDs) the produced `<BBSID>.MSG` bytes are
 * identical, so retrying a frozen batch never yields semantically different
 * messages. Only the temp archive path is randomised.
 *
 * @phpstan-type RepMessage array{
 *   conference_number:int, from_name:string, to_name:string, subject:string,
 *   body:string, written_at:string, msgid?:?string, reply_msgid?:?string,
 *   reply_to_num?:?int
 * }
 */
class RepPacketBuilder
{
    private const BLOCK_SIZE = 128;
    private const LINE_TERM = "\xE3";
    private const BODY_CHARSET = 'CP437';

    /**
     * @param array<int,array<string,mixed>> $messages
     * @return string absolute path to the created .REP archive in the system temp dir
     */
    public function build(string $bbsId, array $messages): string
    {
        $normalizedBbsId = self::normalizeBbsId($bbsId);
        if ($normalizedBbsId === '') {
            throw new \InvalidArgumentException('REP packet BBS ID is required');
        }

        $msgData = str_pad($normalizedBbsId, self::BLOCK_SIZE, ' '); // REP block 0
        $headersDat = [];
        $logical = 1;
        $perConferenceLogical = [];

        foreach ($messages as $message) {
            $conf = (int)($message['conference_number'] ?? 0);
            $perConferenceLogical[$conf] = ($perConferenceLogical[$conf] ?? 0) + 1;
            $headersDat[strlen($msgData)] = $this->buildHeadersDatSection($message, $conf);
            $msgData .= $this->encodeMessage($message, $perConferenceLogical[$conf]);
            $logical++;
        }

        $zipPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . $normalizedBbsId . '_' . bin2hex(random_bytes(8)) . '.rep';
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Failed to create REP archive');
        }
        $zip->addFromString($normalizedBbsId . '.MSG', $msgData);
        if ($headersDat !== []) {
            $zip->addFromString('HEADERS.DAT', $this->buildHeadersDat($headersDat));
        }
        $zip->close();

        return $zipPath;
    }

    public static function normalizeBbsId(string $bbsId): string
    {
        return strtoupper(substr((string)preg_replace('/[^A-Za-z0-9]/', '', trim($bbsId)), 0, 8));
    }

    /**
     * @param array<string,mixed> $message
     */
    private function encodeMessage(array $message, int $conferenceLogicalNumber): string
    {
        $body = rtrim($this->toCp437((string)($message['body'] ?? '')));
        $body = str_replace(["\r\n", "\r", "\n"], self::LINE_TERM, $body) . self::LINE_TERM;

        $bodyBlocks = max(1, (int)ceil(strlen($body) / self::BLOCK_SIZE));
        $totalBlocks = $bodyBlocks + 1;
        $conf = (int)($message['conference_number'] ?? 0);
        $when = $this->messageDate((string)($message['written_at'] ?? ''));
        $replyRef = (int)($message['reply_to_num'] ?? 0);

        // Standard 128-byte QWK/REP record header. Field widths per the QWK spec:
        //   0     status
        //   1-7   message number (REP convention: the destination conference
        //         number as left-justified ASCII, which Synchronet atol()-parses)
        //   8-15  date  MM-DD-YY
        //   16-20 time  HH:MM
        //   21-45 to        (25)
        //   46-70 from      (25)
        //   71-95 subject   (25)
        //   96-107  password (12)
        //   108-115 reply-to reference (8)
        //   116-121 number of 128-byte blocks incl. header (6)
        //   122     active flag (0xE1 = active)
        //   123-124 conference number as little-endian uint16
        //   125-126 logical message number within the conference
        //   127     net tag
        $header = ($conf === 0 ? '+' : ' ');
        $header .= str_pad((string)$conf, 7, ' ', STR_PAD_RIGHT);
        $header .= str_pad($when->format('m-d-y'), 8, "\x00");
        $header .= str_pad($when->format('H:i'), 5, "\x00");
        $header .= str_pad(substr($this->toCp437((string)($message['to_name'] ?? 'All')), 0, 25), 25, "\x00");
        $header .= str_pad(substr($this->toCp437((string)($message['from_name'] ?? 'Unknown')), 0, 25), 25, "\x00");
        $header .= str_pad(substr($this->toCp437((string)($message['subject'] ?? '')), 0, 25), 25, "\x00");
        $header .= str_pad('', 12, "\x00");                                       // password (12)
        $header .= str_pad($replyRef > 0 ? (string)$replyRef : '', 8, ' ');       // reply-to reference (8)
        $header .= str_pad((string)$totalBlocks, 6, ' ', STR_PAD_LEFT);           // block count (6)
        $header .= chr(0xE1);                                                     // active flag
        $header .= pack('v', $conf);                                              // conference (LE uint16)
        $header .= pack('v', $conferenceLogicalNumber);                           // logical number
        $header .= "\x00";                                                        // net tag

        if (strlen($header) !== self::BLOCK_SIZE) {
            throw new \LogicException('REP header block is ' . strlen($header) . ' bytes, expected 128');
        }

        return $header . str_pad($body, $bodyBlocks * self::BLOCK_SIZE, "\x00");
    }

    /**
     * @param array<string,mixed> $message
     */
    private function buildHeadersDatSection(array $message, int $conferenceNumber): string
    {
        $lines = [
            'Conference: ' . $conferenceNumber,
            'Sender: ' . $this->headerValue((string)($message['from_name'] ?? 'Unknown')),
            'To: ' . $this->headerValue((string)($message['to_name'] ?? 'All')),
            'Subject: ' . $this->headerValue((string)($message['subject'] ?? '(no subject)')),
        ];

        $msgid = trim((string)($message['msgid'] ?? ''));
        if ($msgid !== '') {
            $lines[] = 'X-FTN-MSGID: ' . $this->headerValue($msgid);
        }
        $replyMsgid = trim((string)($message['reply_msgid'] ?? ''));
        if ($replyMsgid !== '') {
            $lines[] = 'X-FTN-REPLY: ' . $this->headerValue($replyMsgid);
        }
        $lines[] = 'X-FTN-CHRS: ' . self::BODY_CHARSET . ' 2';

        return implode("\r\n", $lines);
    }

    /**
     * @param array<int,string> $sections keyed by MESSAGES.DAT byte offset
     */
    private function buildHeadersDat(array $sections): string
    {
        $out = [];
        foreach ($sections as $offset => $body) {
            $out[] = '[' . strtolower(dechex($offset)) . ']';
            $out[] = $body;
            $out[] = '';
        }

        return implode("\r\n", $out);
    }

    private function messageDate(string $iso): \DateTimeImmutable
    {
        if ($iso !== '') {
            try {
                return new \DateTimeImmutable($iso, new \DateTimeZone('UTC'));
            } catch (\Throwable $e) {
                // fall through
            }
        }

        return new \DateTimeImmutable('@0');
    }

    private function toCp437(string $value): string
    {
        $out = @iconv('UTF-8', self::BODY_CHARSET . '//TRANSLIT//IGNORE', $value);

        return ($out !== false && $out !== '') ? $out : $value;
    }

    private function headerValue(string $value): string
    {
        return $this->toCp437(str_replace(["\r\n", "\r", "\n"], ' ', trim($value)));
    }
}
