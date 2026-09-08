<?php

declare(strict_types=1);

/*
 * Real FTP round-trip for BinktermPHP\Qwk\Transport\FtpStreamTransport.
 *
 * Requires a reachable FTP server (a disposable vsftpd is fine) described by:
 *   QWK_FTP_HOST, QWK_FTP_PORT, QWK_FTP_USER, QWK_FTP_PASS, QWK_FTP_PATH (default /)
 * The account's home must be writable and pre-seeded with WEEDNET.QWK.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Qwk\Transport\FtpStreamTransport;

function check(bool $c, string $label): void
{
    if (!$c) {
        throw new RuntimeException($label);
    }
}

$mailbox = [
    'bbs_id' => 'WEEDNET',
    'host' => getenv('QWK_FTP_HOST') ?: '127.0.0.1',
    'port' => (int)(getenv('QWK_FTP_PORT') ?: 21),
    'username' => getenv('QWK_FTP_USER') ?: 'qwk',
    'password_plain' => getenv('QWK_FTP_PASS') ?: 'qwkpass',
    'ftp_remote_path' => getenv('QWK_FTP_PATH') ?: '/',
    'passive_mode' => true,
];

$logs = [];
$t = new FtpStreamTransport(static function (string $l) use (&$logs) { $logs[] = $l; }, 20);

$tests = [];

$tests['upload a REP, then download it back byte-identical'] = static function () use ($t, $mailbox, &$logs): void {
    $local = tempnam(sys_get_temp_dir(), 'rep') . '.rep';
    $payload = "REP-\x00\x01\x02" . str_repeat(bin2hex(random_bytes(20)), 8); // binary-ish, > 128 bytes
    file_put_contents($local, $payload);
    check($t->uploadPacket($mailbox, $local) === true, 'uploadPacket returned false');
    unlink($local);

    // The server now holds WEEDNET.REP; a download targeting bbs_id "WEEDNET" and
    // extension .REP is not what downloadPacket() fetches (it wants .QWK), so read
    // it back through a mailbox whose bbs_id is "WEEDNET" and confirm via a raw
    // wrapper read of the .REP we just stored.
    $url = rtrim(preg_replace('#^(ftp://[^/]+)(.*)$#', '$1', ''), '');
    $back = tempnam(sys_get_temp_dir(), 'dl');
    // fetch WEEDNET.REP by momentarily treating .QWK lookups as .REP via a copy on the server side is not possible;
    // instead verify with a direct fopen read of the stored object.
    $readUrl = sprintf(
        'ftp://%s:%s@%s:%d%s/WEEDNET.REP',
        rawurlencode($mailbox['username']),
        rawurlencode($mailbox['password_plain']),
        $mailbox['host'],
        $mailbox['port'],
        rtrim('/' . trim($mailbox['ftp_remote_path'], '/'), '/')
    );
    $fh = fopen($readUrl, 'rb', false, stream_context_create(['ftp' => ['overwrite' => true]]));
    check($fh !== false, 'could not reopen the uploaded REP');
    $got = stream_get_contents($fh);
    fclose($fh);
    @unlink($back);
    check($got === $payload, 'round-tripped REP bytes differ (' . strlen((string)$got) . ' vs ' . strlen($payload) . ')');

    foreach ($logs as $l) {
        check(!str_contains($l, $mailbox['password_plain']), 'password leaked into a log line: ' . $l);
        if (str_contains($l, '@' . $mailbox['host'])) {
            check(str_contains($l, ':***@'), 'credentials not redacted in log line: ' . $l);
        }
    }
};

$tests['download a pre-seeded WEEDNET.QWK (case-insensitive match)'] = static function () use ($t, $mailbox): void {
    $dest = tempnam(sys_get_temp_dir(), 'qwk');
    $got = $t->downloadPacket($mailbox, $dest);
    check($got === true, 'downloadPacket did not fetch the seeded packet');
    check(filesize($dest) > 100, 'downloaded packet is suspiciously small');
    @unlink($dest);
};

$tests['a mailbox with no packet returns false, not an error'] = static function () use ($t, $mailbox): void {
    $dest = tempnam(sys_get_temp_dir(), 'qwk');
    $got = $t->downloadPacket(array_merge($mailbox, ['bbs_id' => 'NOSUCH']), $dest);
    check($got === false, 'expected false for a missing packet');
    @unlink($dest);
};

$tests['passive_mode=false is rejected'] = static function () use ($t, $mailbox): void {
    try {
        $t->downloadPacket(array_merge($mailbox, ['passive_mode' => false]), tempnam(sys_get_temp_dir(), 'x'));
        throw new RuntimeException('expected a rejection');
    } catch (RuntimeException $e) {
        check(str_contains($e->getMessage(), 'passive'), 'wrong error: ' . $e->getMessage());
    }
};

$tests['bad credentials surface as a connection error, not a silent false'] = static function () use ($t, $mailbox): void {
    try {
        $t->downloadPacket(array_merge($mailbox, ['password_plain' => 'wrong-' . bin2hex(random_bytes(4))]), tempnam(sys_get_temp_dir(), 'x'));
        throw new RuntimeException('expected an auth failure');
    } catch (RuntimeException $e) {
        check(str_contains($e->getMessage(), 'login failed') || str_contains($e->getMessage(), 'inaccessible'),
            'wrong error: ' . $e->getMessage());
    }
};

$failures = 0;
foreach ($tests as $name => $fn) {
    try {
        $fn();
        echo "PASS  $name\n";
    } catch (Throwable $e) {
        $failures++;
        echo "FAIL  $name  ::  " . $e->getMessage() . "\n";
    }
}
echo "\n" . count($tests) . " FTP round-trip tests, $failures failed\n";
exit($failures === 0 ? 0 : 1);
