<?php

declare(strict_types=1);

// Standalone focused harness, usable without application bootstrap or database extensions.
require_once __DIR__ . '/../src/QwkNet/Message.php';
require_once __DIR__ . '/../src/QwkNet/Packet.php';
require_once __DIR__ . '/../src/QwkNet/Parser.php';

use BinktermPHP\QwkNet\Parser;

function check(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

function controlFixture(): string
{
    return "Fixture BBS\r\nSomewhere\r\nPhone\r\nSysop\r\n0000,FIXTURE\r\n09-08-2026,10:15:04\r\nNODE\r\n\r\n0\r\n0\r\n0\r\n77\r\nCustom Area\r\nHELLO\r\nNEWS\r\nBYE\r\n";
}

function recordFixture(string $body = 'Body', int $conference = 77): string
{
    $blocks = 1 + (int)ceil(strlen($body) / 128);
    $h = str_repeat(' ', 128);
    foreach ([1 => '42', 8 => '03-13-26', 16 => '07:30', 21 => 'All', 46 => 'Author', 71 => 'Subject',
        108 => '0', 116 => (string)$blocks, 122 => "\xe1", 123 => pack('v', $conference)] as $offset => $value) {
        $h = substr_replace($h, $value, $offset, strlen($value));
    }
    return $h . str_pad($body, ($blocks - 1) * 128);
}

function componentsFixture(): array
{
    return ['CONTROL.DAT' => controlFixture(), 'MESSAGES.DAT' => str_repeat(' ', 128) . recordFixture()];
}

function withArchive(array $components, callable $test): void
{
    $path = tempnam(sys_get_temp_dir(), 'qwknet-test-');
    if ($path === false) {
        throw new RuntimeException('Cannot create fixture');
    }
    try {
        $zip = new ZipArchive();
        check($zip->open($path, ZipArchive::OVERWRITE) === true, 'Create fixture ZIP');
        foreach ($components as $name => $bytes) {
            check($zip->addFromString($name, $bytes), 'Add fixture component');
        }
        check($zip->close(), 'Close fixture ZIP');
        $test($path);
    } finally {
        unlink($path);
    }
}

function rejected(array $components, string $reason, ?Parser $parser = null): void
{
    withArchive($components, static function (string $path) use ($reason, $parser): void {
        try {
            ($parser ?? new Parser())->parse($path);
        } catch (RuntimeException $error) {
            check(str_contains($error->getMessage(), $reason), 'Unexpected rejection: ' . $error->getMessage());
            return;
        }
        throw new RuntimeException('Expected rejection: ' . $reason);
    });
}

$tests = [];
$tests['conference mapping and exact raw bytes'] = static function (): void {
    $c = componentsFixture();
    withArchive($c, static function (string $path) use ($c): void {
        $p = (new Parser())->parse($path);
        check($p->control['packet_id'] === 'FIXTURE' && $p->control['user'] === 'NODE', 'Identity');
        check($p->messages[0]->conferenceName === 'Custom Area', 'Configured packet name, no hardcoding');
        check($p->messages[0]->header['conference'] === 77, 'Conference number');
        check($p->rawComponents === $c, 'All raw components preserved');
        check($p->messages[0]->writtenTimestamp === null, 'No invented timezone/timestamp');
    });
};
$tests['inline IDs, repeated hops, Ctrl-A and CP437'] = static function (): void {
    $c = componentsFixture();
    $body = "@MSGID: <id>\xe3@REPLY: <parent>\xe3@VIA: HUB/NODE\xe3@TZ: c1e0\xe3\x01hHello \x82!\x01n\xe3";
    $c['MESSAGES.DAT'] = str_repeat(' ', 128) . recordFixture($body);
    $c['HEADERS.DAT'] = "[80]\nMessage-ID: <id>\nIn-Reply-To: <parent>\nConference: 77\nWhenWritten: 20260313073043-0700 c1e0\nExportedFrom: HUB area 42\nExportedFrom: ORIGIN area 2\n";
    withArchive($c, static function (string $path) use ($body): void {
        $m = (new Parser())->parse($path)->messages[0];
        check($m->externalMessageId === '<id>' && $m->externalReplyId === '<parent>', 'IDs');
        check($m->via === ['HUB/NODE'] && $m->timezone['token'] === 'c1e0', 'Path/TZ');
        check($m->writtenTimestamp === '2026-03-13T14:30:43Z', 'UTC conversion');
        check($m->displayBody === 'Hello é!', 'Readable CP437 and attributes');
        check(str_starts_with($m->rawBody, $body), 'Original body bytes');
        check(count($m->extendedHeaders['exportedfrom']) === 2, 'Repeated fields preserved');
    });
};
foreach (['../evil', '/absolute', 'dir/file', 'dir\\file', 'C:evil'] as $name) {
    $tests['unsafe ZIP ' . $name] = static fn() => rejected(componentsFixture() + [$name => 'x'], 'Unsafe archive');
}
$tests['duplicate case-insensitive archive member'] = static fn() => rejected(componentsFixture() + ['control.dat' => 'x'], 'duplicate archive');
$tests['missing required component'] = static fn() => rejected(['MESSAGES.DAT' => str_repeat(' ', 128)], 'Missing required');
$tests['file count bound'] = static fn() => rejected(componentsFixture(), 'file count', new Parser(maxFiles: 1));
$tests['expansion bound'] = static fn() => rejected(componentsFixture(), 'expansion limit', new Parser(maxUncompressedBytes: 200));
$tests['compressed bound'] = static fn() => rejected(componentsFixture(), 'Archive size', new Parser(maxArchiveBytes: 1));
$tests['truncated block'] = static function (): void {
    $c = componentsFixture(); $c['MESSAGES.DAT'] = substr($c['MESSAGES.DAT'], 0, -1);
    rejected($c, '128-byte blocks');
};
foreach (['0     ', '-1    ', '2x    ', '999999'] as $length) {
    $tests['invalid block count ' . trim($length)] = static function () use ($length): void {
        $c = componentsFixture(); $c['MESSAGES.DAT'] = substr_replace($c['MESSAGES.DAT'], $length, 128 + 116, 6);
        rejected($c, str_contains($length, '-') || str_contains($length, 'x') ? 'decimal' : 'block count');
    };
}
$tests['unmapped conference'] = static function (): void {
    $c = componentsFixture(); $c['MESSAGES.DAT'] = str_repeat(' ', 128) . recordFixture('Body', 78);
    rejected($c, 'conference missing');
};
$tests['duplicate conference map'] = static function (): void {
    $c = componentsFixture(); $c['CONTROL.DAT'] = str_replace("0\r\n77\r\nCustom Area", "1\r\n77\r\nCustom Area\r\n77\r\nOther", $c['CONTROL.DAT']);
    rejected($c, 'conference mapping');
};
$tests['truncated conference list'] = static function (): void {
    $c = componentsFixture(); $c['CONTROL.DAT'] = substr($c['CONTROL.DAT'], 0, 20);
    rejected($c, 'Truncated CONTROL');
};
foreach ([
    '[100]' => 'does not point',
    "[80]\nConference: 78" => 'conference disagrees',
    "[80]\n[80]" => 'duplicate HEADERS',
    "[81]" => 'Invalid or duplicate',
    "[80]\nSender: Other" => 'name/subject disagrees',
    "[80]\nMessage-ID: a\nMessage-ID: b" => 'Conflicting singleton',
    "[80]\nWhenWritten: 20260313083043-0700 c1e0" => 'timestamp disagrees',
] as $headers => $reason) {
    $tests['headers rejection ' . $reason] = static fn() => rejected(componentsFixture() + ['HEADERS.DAT' => $headers . "\n"], $reason);
}
$tests['inline/extended ID disagreement'] = static function (): void {
    $c = componentsFixture(); $c['MESSAGES.DAT'] = str_repeat(' ', 128) . recordFixture("@MSGID: <a>\xe3Body");
    $c['HEADERS.DAT'] = "[80]\nMessage-ID: <b>\n";
    rejected($c, 'identity disagree');
};
$tests['message bound'] = static function (): void {
    $c = componentsFixture(); $c['MESSAGES.DAT'] .= recordFixture();
    rejected($c, 'Message count', new Parser(maxMessages: 1));
};
$tests['reject wrappers before IO'] = static function (): void {
    foreach (['https://example.invalid/packet', 'ftp://example.invalid/packet', 'file:///tmp/packet'] as $path) {
        try { (new Parser())->parse($path); } catch (RuntimeException $e) {
            check(str_contains($e->getMessage(), 'local file path'), 'Wrapper rejection'); continue;
        }
        throw new RuntimeException('Wrapper accepted');
    }
};
$tests['malformed ZIP'] = static function (): void {
    $path = tempnam(sys_get_temp_dir(), 'qwknet-test-');
    try {
        file_put_contents($path, 'not a ZIP');
        try { (new Parser())->parse($path); } catch (RuntimeException $e) {
            check(str_contains($e->getMessage(), 'Malformed ZIP'), 'Malformed ZIP rejected'); return;
        }
        throw new RuntimeException('Malformed ZIP accepted');
    } finally { unlink($path); }
};
$tests['diagnostic controls'] = static function (): void {
    $display = Parser::display("\x01hHi\x01n\x1b[31m red\x1b[0m\x07\x01Z");
    check(!preg_match('/[\x00-\x08\x0b-\x1f\x7f]/', $display), 'No terminal controls');
    check(str_contains($display, '[Ctrl-A:5a]'), 'Unknown Ctrl-A visible without executing it');
};
$tests['invalid fixed date without extended headers'] = static function (): void {
    $c = componentsFixture();
    $c['MESSAGES.DAT'] = substr_replace($c['MESSAGES.DAT'], '02-30-26', 128 + 8, 8);
    rejected($c, 'Invalid QWK date/time');
};
$tests['conflicting timezone token'] = static function (): void {
    $c = componentsFixture();
    $c['MESSAGES.DAT'] = str_repeat(' ', 128) . recordFixture("@TZ: ff10\xe3Body");
    $c['HEADERS.DAT'] = "[80]\nWhenWritten: 20260313073043-0700 c1e0\n";
    rejected($c, 'timezone tokens disagree');
};
$tests['ZIP symlink rejected'] = static function (): void {
    withArchive(componentsFixture(), static function (string $path): void {
        $zip = new ZipArchive();
        check($zip->open($path) === true, 'Open fixture');
        $zip->addFromString('LINK', '/etc/passwd');
        $zip->setExternalAttributesName('LINK', 3, 0120777 << 16);
        $zip->close();
        try { (new Parser())->parse($path); } catch (RuntimeException $e) {
            check(str_contains($e->getMessage(), 'Archive links'), 'Link rejected'); return;
        }
        throw new RuntimeException('Link accepted');
    });
};
$tests['corrupt stored entry rejected'] = static function (): void {
    withArchive(componentsFixture() + ['EXTRA.DAT' => 'CRC_SENTINEL'], static function (string $path): void {
        $zip = new ZipArchive();
        check($zip->open($path) === true, 'Open fixture');
        $zip->setCompressionName('EXTRA.DAT', ZipArchive::CM_STORE);
        $zip->close();
        $bytes = file_get_contents($path);
        check(substr_count($bytes, 'CRC_SENTINEL') === 1, 'Locate stored bytes');
        file_put_contents($path, str_replace('CRC_SENTINEL', 'BAD_SENTINEL', $bytes));
        try { (new Parser())->parse($path); } catch (RuntimeException $e) {
            check(str_contains($e->getMessage(), 'ZIP'), 'Corrupt entry rejected'); return;
        }
        throw new RuntimeException('Corrupt entry accepted');
    });
};

if ($argc === 2) {
    $specimen = $argv[1];
    $tests['real specimen acceptance'] = static function () use ($specimen): void {
        $hash = 'c9be27d55e12db7e0f47cd4ce6ee7b063e30c1d3643f89a659f2084e1267b5d1';
        check(hash_file('sha256', $specimen) === $hash, 'Original specimen hash');
        $p = (new Parser())->parse($specimen);
        check($p->sha256 === $hash, 'Parser hash');
        check($p->control['system'] === 'Weed Net' && $p->control['packet_id'] === 'WEEDNET' && $p->control['user'] === 'SKRAWL', 'Real identity');
        check(count($p->control['conferences']) === 39 && $p->control['conferences'][1101] === 'GENERAL', 'Real conference directory');
        check(count($p->messages) === 10, 'Exactly ten messages');
        $offsets = [0x80, 0x500, 0x680, 0x800, 0xb80, 0xe00, 0x1080, 0x1380, 0x1600, 0x1780];
        $rebuilt = substr($p->rawComponents['MESSAGES.DAT'], 0, 128);
        $replies = 0;
        foreach ($p->messages as $i => $m) {
            check($m->header['offset'] === $offsets[$i] && $m->header['number'] === 538 + $i, 'Real record boundary');
            check(str_starts_with($m->rawExtendedHeaders, '[' . dechex($offsets[$i]) . ']'), 'Exact extended offset association');
            check($m->extendedHeaders['conference'] === ['1101'] && $m->header['conference'] === 1101, 'Conference association');
            check($m->externalMessageId !== null && $m->externalMessageId === trim($m->inlineMetadata['msgid'][0]), 'External ID agrees');
            check($m->externalMessageId === $m->extendedHeaders['message-id'][0], 'Extended ID agrees');
            $replies += $m->externalReplyId !== null;
            check($m->via !== [] && $m->timezone['token'] !== null && $m->writtenTimestamp !== null, 'Path and timezone');
            check($m->sender !== '' && $m->recipient !== '' && $m->subject !== '' && $m->displayBody !== '', 'Readable fields');
            check(!preg_match('/[\x00-\x08\x0b-\x1f\x7f]/', $m->displayBody), 'Safe real display');
            check(str_contains($m->rawBody, "\x01"), 'Real Ctrl-A bytes retained');
            $rebuilt .= $m->rawHeader . $m->rawBody;
        }
        check($replies === 6, 'Six actual reply IDs');
        check($p->messages[0]->subject === 'What computers are ya using?', 'Untruncated extended subject');
        check($p->messages[1]->sender === 'phigan' && str_starts_with($p->messages[1]->displayBody, 'It said call out'), 'Credible author/body separation');
        check($rebuilt === $p->rawComponents['MESSAGES.DAT'], 'Lossless record reconstruction');
        check(hash_file('sha256', $specimen) === $hash, 'Specimen unchanged');
        check(!class_exists('BinktermPHP\\Database', false), 'Application database class never loaded');
    };
} elseif ($argc > 2) {
    fwrite(STDERR, "Usage: php tests/QwkNetParserTest.php [/explicit/specimen.qwk]\n");
    exit(2);
}

foreach ($tests as $name => $test) {
    try { $test(); echo 'PASS ', $name, "\n"; }
    catch (Throwable $error) { fwrite(STDERR, 'FAIL ' . $name . ': ' . $error->getMessage() . "\n"); exit(1); }
}
echo count($tests), " focused tests passed\n";
