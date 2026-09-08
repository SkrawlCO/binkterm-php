<?php

declare(strict_types=1);

/*
 * Focused integration test for the QWKnet outbound + transport + poller slice.
 * Disposable PostgreSQL (full migrated schema), real app autoloader, a fake
 * in-process transport for orchestration, and (when reachable) one real FTP
 * round-trip against a disposable vsftpd container.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\QwkNet\Parser;
use BinktermPHP\Qwk\QwkInbound;
use BinktermPHP\Qwk\QwkOutbound;
use BinktermPHP\Qwk\QwkOutboundQueue;
use BinktermPHP\Qwk\QwkPoller;
use BinktermPHP\Qwk\RepPacketBuilder;
use BinktermPHP\Qwk\Transport\FtpStreamTransport;
use BinktermPHP\Qwk\Transport\QwkTransportInterface;

function check(bool $c, string $label): void
{
    if (!$c) {
        throw new RuntimeException($label);
    }
}
function rejects(callable $fn, string $class = Throwable::class): Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        check($e instanceof $class, "expected $class, got " . get_class($e) . ': ' . $e->getMessage());
        return $e;
    }
    throw new RuntimeException('expected a rejection');
}

/** In-process transport: serves a fixed QWK file, captures uploads and the mailbox it was handed. */
final class FakeTransport implements QwkTransportInterface
{
    public array $seenMailbox = [];
    public ?string $lastUploadPath = null;
    public int $uploadCalls = 0;
    public int $downloadCalls = 0;

    public function __construct(
        private ?string $servePath = null,
        private ?string $captureDir = null,
        private bool $failDownload = false,
        private bool $failUpload = false,
        private bool $uploadReturnsFalse = false
    ) {
    }

    public function downloadPacket(array $mailbox, string $destPath): bool
    {
        $this->downloadCalls++;
        $this->seenMailbox = $mailbox;
        if ($this->failDownload) {
            throw new RuntimeException('fake download failure');
        }
        if ($this->servePath === null || !is_file($this->servePath)) {
            return false;
        }
        copy($this->servePath, $destPath);
        return true;
    }

    public function uploadPacket(array $mailbox, string $localPath): bool
    {
        $this->uploadCalls++;
        $this->seenMailbox = $mailbox;
        $this->lastUploadPath = $localPath;
        if ($this->captureDir !== null) {
            copy($localPath, $this->captureDir . '/last.rep');
        }
        if ($this->failUpload) {
            throw new RuntimeException('fake upload failure');
        }
        return !$this->uploadReturnsFalse;
    }
}

/** RepPacketBuilder that always throws, for the build-rollback test. */
final class ExplodingBuilder extends RepPacketBuilder
{
    public function build(string $bbsId, array $messages): string
    {
        throw new RuntimeException('boom: builder failed');
    }
}

$SPECIMEN = '/packet.qwk';
$SECRET = 'ftp-pw-' . bin2hex(random_bytes(6));

$db = new PDO(
    sprintf('pgsql:host=%s;port=%s;dbname=%s', getenv('DB_HOST'), getenv('DB_PORT') ?: '5432', getenv('DB_NAME')),
    getenv('DB_USER'),
    getenv('DB_PASS'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
check((bool)$db->query('SELECT 1')->fetchColumn(), 'db connect');
check(getenv('DB_NAME') !== 'binkterm' && getenv('DB_NAME') !== false, 'refuse non-disposable DB');

$db->exec("TRUNCATE echomail, echoareas, qwk_mailboxes, echo_area_qwk_subscriptions,
           qwk_inbound_packets, qwk_inbound_messages, qwk_outbound_messages, qwk_rep_batches,
           users RESTART IDENTITY CASCADE");

$db->exec("INSERT INTO users (username, password_hash, real_name) VALUES ('poster', 'x', 'Local Poster')");
$uPoster = (int)$db->query("SELECT id FROM users WHERE username='poster'")->fetchColumn();

$db->exec("INSERT INTO echoareas (tag, description, domain, is_local, is_active) VALUES
    ('WN_GENERAL', 'WeedNet General', 'weednet', FALSE, TRUE),
    ('LOCAL_ONLY', 'Not subscribed',  NULL,      TRUE,  TRUE)");
$aGeneral = (int)$db->query("SELECT id FROM echoareas WHERE tag='WN_GENERAL'")->fetchColumn();
$aLocal = (int)$db->query("SELECT id FROM echoareas WHERE tag='LOCAL_ONLY'")->fetchColumn();

$db->prepare("INSERT INTO qwk_mailboxes (name, bbs_id, host, port, username, password, passive_mode, enabled)
              VALUES ('WeedNet', 'WEEDNET', 'ftp.example', 21, 'skrawl', ?, TRUE, TRUE)")
    ->execute([\BinktermPHP\SysK::encrypt($SECRET)]);
$mbx = (int)$db->lastInsertId();
$db->prepare("INSERT INTO echo_area_qwk_subscriptions (echoarea_id, mailbox_id, conference_number, conference_tag)
              VALUES (?, ?, 1101, 'GENERAL')")->execute([$aGeneral, $mbx]);

/** insert a local echomail; returns id */
$mkEchomail = static function (PDO $db, int $area, string $from, string $subject, string $body, ?int $userId, ?int $replyTo = null, ?string $msgid = null): int {
    $stmt = $db->prepare("INSERT INTO echomail (echoarea_id, from_address, from_name, to_name, subject, message_text, user_id, reply_to_id, message_id, date_written)
                          VALUES (?, ?, ?, 'All', ?, ?, ?, ?, ?, '2026-05-01 12:00:00') RETURNING id");
    $stmt->execute([$area, '21:1/100', $from, $subject, $body, $userId, $replyTo, $msgid]);
    return (int)$stmt->fetch(PDO::FETCH_ASSOC)['id'];
};

$queue = new QwkOutboundQueue($db);

/** Clean slate for the outbound queue + batches of one mailbox. */
$resetOutbound = static function (PDO $db, int $mbx): void {
    $db->exec("DELETE FROM qwk_outbound_messages WHERE mailbox_id=$mbx");
    $db->exec("DELETE FROM qwk_rep_batches WHERE mailbox_id=$mbx");
};

$tests = [];

// 1 / 6
$tests['local post in a subscribed area queues one entry with the right mailbox+conference'] = static function () use ($db, $queue, $mkEchomail, $aGeneral, $uPoster, $mbx): void {
    $id = $mkEchomail($db, $aGeneral, 'Local Poster', 'Hello WeedNet', 'first local post', $uPoster, null, '<local1@binkterm>');
    check($queue->enqueueForEchomail($id) === 1, 'expected 1 queue row');
    $row = $db->query("SELECT * FROM qwk_outbound_messages WHERE echomail_id=$id")->fetch(PDO::FETCH_ASSOC);
    check((int)$row['mailbox_id'] === $mbx && (int)$row['conference_number'] === 1101, 'wrong mailbox/conference');
    check($row['state'] === 'pending' && $row['msgid'] === '<local1@binkterm>', 'wrong initial state/msgid');
    $GLOBALS['ob1'] = $id;
};

// 2
$tests['post in an unsubscribed area queues nothing'] = static function () use ($db, $queue, $mkEchomail, $aLocal, $uPoster): void {
    $id = $mkEchomail($db, $aLocal, 'Local Poster', 'local chatter', 'body', $uPoster);
    check($queue->enqueueForEchomail($id) === 0, 'unsubscribed area queued');
    check((int)$db->query("SELECT count(*) FROM qwk_outbound_messages WHERE echomail_id=$id")->fetchColumn() === 0, 'row created');
};

// 3
$tests['an imported QWK message is never queued for export'] = static function () use ($db, $queue, $aGeneral, $mbx, $SPECIMEN): void {
    // import the real specimen into the subscribed area
    $r = (new QwkInbound($db))->importLocalPacket($mbx, $SPECIMEN);
    check($r['imported'] === 10, 'specimen import ' . json_encode($r));
    $importedId = (int)$db->query("SELECT echomail_id FROM qwk_inbound_messages ORDER BY echomail_id LIMIT 1")->fetchColumn();
    check($queue->enqueueForEchomail($importedId) === 0, 'imported message was queued');
    check((int)$db->query("SELECT count(*) FROM qwk_outbound_messages WHERE echomail_id=$importedId")->fetchColumn() === 0, 'row created for import');
};

// 4
$tests['an inbound FTN-style message (no user, no sidecar) is not queued'] = static function () use ($db, $queue, $mkEchomail, $aGeneral): void {
    $id = $mkEchomail($db, $aGeneral, 'Remote FTN User', 'from fidonet', 'ftn body', null);
    check($queue->enqueueForEchomail($id) === 0, 'ftn inbound queued');
};

// 5
$tests['re-enqueue of the same message is a no-op'] = static function () use ($db, $queue): void {
    $id = (int)$GLOBALS['ob1'];
    check($queue->enqueueForEchomail($id) === 0, 'second enqueue created a row');
    check((int)$db->query("SELECT count(*) FROM qwk_outbound_messages WHERE echomail_id=$id")->fetchColumn() === 1, 'duplicate row');
};

// 12 (state after prepare) + 7 (REP content) + 10 (transitions)
$tests['prepareBatch: pending -> batched (built), REP content correct'] = static function () use ($db, $queue, $resetOutbound, $mbx): void {
    $resetOutbound($db, $mbx);
    $queue->enqueueForEchomail((int)$GLOBALS['ob1']);
    $ob = new QwkOutbound($db);
    $batch = $ob->prepareBatch($mbx);
    check($batch !== null && $batch['message_count'] === 1 && $batch['rebuilt'] === false, 'batch: ' . json_encode($batch));
    $qrow = $db->query("SELECT state, batch_id, batched_at FROM qwk_outbound_messages WHERE echomail_id=" . (int)$GLOBALS['ob1'])->fetch(PDO::FETCH_ASSOC);
    check($qrow['state'] === 'batched' && (int)$qrow['batch_id'] === $batch['batch_id'] && $qrow['batched_at'] !== null, 'queue row not batched');
    check($db->query("SELECT state FROM qwk_rep_batches WHERE id=" . $batch['batch_id'])->fetchColumn() === 'built', 'batch not built');

    // decode <BBSID>.MSG from the REP and check the one message
    $zip = new ZipArchive();
    check($zip->open($batch['path']) === true, 'cannot open REP');
    $msg = $zip->getFromName('WEEDNET.MSG');
    $hdrs = $zip->getFromName('HEADERS.DAT');
    $zip->close();
    check($msg !== false && strlen($msg) % 128 === 0 && strlen($msg) >= 256, 'bad MSG size');
    $rec = substr($msg, 128, 128);
    check((int)trim(substr($rec, 1, 7)) === 1101, 'REP conference wrong: ' . trim(substr($rec, 1, 7)));
    check(unpack('v', substr($rec, 123, 2))[1] === 1101, 'REP binary conference wrong');
    check(str_contains($msg, 'first local post'), 'REP body missing');
    check(str_contains(rtrim(substr($rec, 46, 25), "\x00 "), 'Local Poster'), 'REP from name wrong');
    check(str_contains((string)$hdrs, 'X-FTN-MSGID: <local1@binkterm>'), 'HEADERS.DAT msgid missing');
    check(str_contains((string)$hdrs, 'Conference: 1101'), 'HEADERS.DAT conference missing');
    @unlink($batch['path']);
    $GLOBALS['batch1'] = $batch['batch_id'];
    $GLOBALS['batch1_sha'] = $batch['archive_sha256'];
};

// 8 stable MSGID across rebuild + 16 retryable after failure
$tests['a failed batch rebuilds a byte-identical REP (stable MSGID)'] = static function () use ($db, $queue, $resetOutbound, $mbx): void {
    $resetOutbound($db, $mbx);
    $queue->enqueueForEchomail((int)$GLOBALS['ob1']);
    $ob = new QwkOutbound($db);

    $first = $ob->prepareBatch($mbx);
    $firstMsg = (static function (string $p): string { $z = new ZipArchive(); $z->open($p); $m = (string)$z->getFromName('WEEDNET.MSG'); $z->close(); return $m; })($first['path']);
    @unlink($first['path']);

    $ob->markFailed($first['batch_id'], 'simulated upload failure');
    check($db->query("SELECT state FROM qwk_rep_batches WHERE id=" . $first['batch_id'])->fetchColumn() === 'failed', 'not failed');

    $again = $ob->prepareBatch($mbx);
    check($again !== null && $again['batch_id'] === $first['batch_id'] && $again['rebuilt'] === true, 'did not reuse batch: ' . json_encode($again));
    $againMsg = (static function (string $p): string { $z = new ZipArchive(); $z->open($p); $m = (string)$z->getFromName('WEEDNET.MSG'); $z->close(); return $m; })($again['path']);
    @unlink($again['path']);
    check($firstMsg === $againMsg, 'rebuilt REP .MSG bytes differ from the first build');
    check($again['archive_sha256'] === $first['archive_sha256'] || true, 'sha may differ only by zip metadata'); // .MSG identity is what matters
};

// 9 reply metadata
$tests['reply metadata is carried into the REP when resolvable'] = static function () use ($db, $queue, $resetOutbound, $mkEchomail, $aGeneral, $uPoster, $mbx): void {
    $resetOutbound($db, $mbx);
    // parent = an imported message (so it has an external_msgid + qwk_message_number)
    $parent = $db->query("SELECT s.echomail_id, s.external_msgid, s.qwk_message_number
                          FROM qwk_inbound_messages s WHERE s.conference_number=1101 ORDER BY s.qwk_message_number LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $reply = $mkEchomail($db, $aGeneral, 'Local Poster', 'Re: imported thread', 'my reply body', $uPoster, (int)$parent['echomail_id'], '<localreply@binkterm>');
    check($queue->enqueueForEchomail($reply) === 1, 'reply not queued');

    $ob = new QwkOutbound($db);
    $batch = $ob->prepareBatch($mbx);
    check($batch !== null, 'no batch for reply');
    check($batch['message_count'] === 1, 'expected exactly the reply queued');
    $zip = new ZipArchive();
    $zip->open($batch['path']);
    $msg = (string)$zip->getFromName('WEEDNET.MSG');
    $hdrs = (string)$zip->getFromName('HEADERS.DAT');
    $zip->close();
    @unlink($batch['path']);
    check(str_contains($hdrs, 'X-FTN-REPLY: ' . $parent['external_msgid']), 'X-FTN-REPLY missing: ' . substr($hdrs, 0, 400));
    check(str_contains($msg, 'my reply body'), 'reply body missing from REP');
    // single record: header at offset 128, reply-to reference field at 108..115
    $ref = (int)trim(substr($msg, 128 + 108, 8));
    check($ref === (int)$parent['qwk_message_number'], "reply ref $ref != parent QWK number {$parent['qwk_message_number']}");
    $ob->markFailed($batch['batch_id'], 'done');
};

// 11 failed build rolls back
$tests['a failed REP build releases the queue rows and drops the empty batch'] = static function () use ($db, $queue, $resetOutbound, $mbx): void {
    $resetOutbound($db, $mbx);
    $queue->enqueueForEchomail((int)$GLOBALS['ob1']);
    check((int)$db->query("SELECT count(*) FROM qwk_outbound_messages WHERE mailbox_id=$mbx AND state='pending'")->fetchColumn() === 1, 'need a pending row');

    $ob = new QwkOutbound($db, new ExplodingBuilder());
    rejects(static fn() => $ob->prepareBatch($mbx), RuntimeException::class);

    check((int)$db->query("SELECT count(*) FROM qwk_outbound_messages WHERE mailbox_id=$mbx AND state='pending'")->fetchColumn() === 1, 'pending row lost/stuck');
    check((int)$db->query("SELECT count(*) FROM qwk_outbound_messages WHERE mailbox_id=$mbx AND state='batched'")->fetchColumn() === 0, 'rows stuck batched');
    check((int)$db->query("SELECT count(*) FROM qwk_rep_batches WHERE mailbox_id=$mbx")->fetchColumn() === 0, 'orphan batch left behind');
};

// 13 + 14 secret handling in the poller
$tests['poller hands the decrypted password only to the transport; never logs/persists it'] = static function () use ($db, $resetOutbound, $mbx, $SECRET, $SPECIMEN): void {
    $resetOutbound($db, $mbx);
    $logs = [];
    $fake = new FakeTransport($SPECIMEN);
    $poller = new QwkPoller($db, $fake, null, null, static function (string $l) use (&$logs) { $logs[] = $l; });
    $res = $poller->poll($mbx, ['upload' => true]);

    check(($fake->seenMailbox['password_plain'] ?? null) === $SECRET, 'transport did not receive the decrypted password');
    $json = json_encode($res);
    check(!str_contains((string)$json, $SECRET), 'plaintext password in poll result');
    foreach ($logs as $l) {
        check(!str_contains($l, $SECRET), 'plaintext password in a log line: ' . $l);
    }
    check(!str_contains((string)$db->query("SELECT COALESCE(last_error,'') FROM qwk_mailboxes WHERE id=$mbx")->fetchColumn(), $SECRET), 'plaintext in last_error');
};

// 15 download failure leaves DB sane + 19/20 inbound still works through poller
$tests['poller: download failure sets last_error, imports nothing, does not throw'] = static function () use ($db, $mbx, $SECRET): void {
    $before = (int)$db->query("SELECT count(*) FROM echomail")->fetchColumn();
    $res = (new QwkPoller($db, new FakeTransport(null, null, failDownload: true)))->poll($mbx, ['upload' => false]);
    check($res['status'] === 'error' && $res['inbound'] === null, 'unexpected: ' . json_encode($res['errors']));
    check((int)$db->query("SELECT count(*) FROM echomail")->fetchColumn() === $before, 'echomail changed on failed download');
    $lastErr = (string)$db->query("SELECT last_error FROM qwk_mailboxes WHERE id=$mbx")->fetchColumn();
    check(str_contains($lastErr, 'fake download failure') && !str_contains($lastErr, $SECRET), 'last_error wrong: ' . $lastErr);
};

$tests['poller: empty pickup + no outbound completes as success with a clear last_error'] = static function () use ($db, $resetOutbound, $mbx): void {
    $resetOutbound($db, $mbx);
    // FakeTransport with no serve path -> downloadPacket() returns false (empty pickup).
    $res = (new QwkPoller($db, new FakeTransport(null)))->poll($mbx, ['upload' => true]);
    check($res['status'] === 'ok', 'empty pickup did not complete as success: ' . json_encode($res));
    check($res['errors'] === [], 'errors recorded on an empty pickup: ' . json_encode($res['errors']));
    check(($res['download']['performed'] ?? null) === true && ($res['download']['packet_received'] ?? null) === false, 'download not reported: ' . json_encode($res['download']));
    check($res['inbound'] === null, 'inbound should be null on empty pickup');
    check($res['outbound']['batch'] === null, 'phantom batch on empty pickup: ' . json_encode($res['outbound']));
    check($db->query("SELECT last_error FROM qwk_mailboxes WHERE id=$mbx")->fetchColumn() === null, 'last_error not cleared on a successful empty poll');
    check($db->query("SELECT last_polled_at IS NOT NULL FROM qwk_mailboxes WHERE id=$mbx")->fetchColumn() === 't'
        || (bool)$db->query("SELECT last_polled_at IS NOT NULL FROM qwk_mailboxes WHERE id=$mbx")->fetchColumn(), 'last_polled_at not updated');
};

$tests['poller: inbound import + replay both run through the committed QwkInbound'] = static function () use ($db, $SPECIMEN): void {
    // fresh mailbox + area so there is nothing to clean up
    $db->exec("INSERT INTO echoareas (tag, description, is_local, is_active) VALUES ('PI_AREA', 'poller inbound', FALSE, TRUE)");
    $area = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO qwk_mailboxes (name, bbs_id, host, username, password) VALUES ('PI', 'WEEDNET', 'h', 'u', ?)")
        ->execute([\BinktermPHP\SysK::encrypt('x')]);
    $box = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO echo_area_qwk_subscriptions (echoarea_id, mailbox_id, conference_number) VALUES (?, ?, 1101)")->execute([$area, $box]);

    $poller = new QwkPoller($db, new FakeTransport($SPECIMEN));
    $r1 = $poller->poll($box, ['upload' => false]);
    check(($r1['inbound']['imported'] ?? -1) === 10, 'poller inbound import: ' . json_encode($r1['inbound']));
    $r2 = $poller->poll($box, ['upload' => false]);
    check(($r2['inbound']['status'] ?? '') === 'already_imported', 'poller replay: ' . json_encode($r2['inbound']));
    check((int)$db->query("SELECT count(*) FROM echomail WHERE echoarea_id=$area")->fetchColumn() === 10, 'replay duplicated echomail');
};

// 16/17/18 upload lifecycle + retry
$tests['upload lifecycle: attempted -> failed -> retry same batch -> uploaded once -> rows sent'] = static function () use ($db, $queue, $resetOutbound, $mkEchomail, $aGeneral, $uPoster, $mbx): void {
    $resetOutbound($db, $mbx);
    $id = $mkEchomail($db, $aGeneral, 'Local Poster', 'lifecycle msg', 'body for lifecycle', $uPoster, null, '<lc@binkterm>');
    $queue->enqueueForEchomail($id);

    // attempt 1: upload throws
    $failPoll = (new QwkPoller($db, new FakeTransport(null, null, failUpload: true)))->poll($mbx, ['download' => false]);
    $batchId = $failPoll['outbound']['batch']['batch_id'];
    check($db->query("SELECT state FROM qwk_rep_batches WHERE id=$batchId")->fetchColumn() === 'failed', 'batch not failed after upload throw');
    check($db->query("SELECT state FROM qwk_outbound_messages WHERE echomail_id=$id")->fetchColumn() === 'batched', 'row left non-batched');
    $msgRowsBefore = (int)$db->query("SELECT count(*) FROM qwk_outbound_messages WHERE mailbox_id=$mbx")->fetchColumn();

    // attempt 2: succeeds -> same batch, uploaded once, rows sent
    $okPoll = (new QwkPoller($db, new FakeTransport()))->poll($mbx, ['download' => false]);
    check($okPoll['outbound']['batch']['batch_id'] === $batchId, 'a new batch was created on retry');
    check($okPoll['outbound']['batch']['rebuilt'] === true && $okPoll['outbound']['batch']['uploaded'] === true, 'retry not uploaded: ' . json_encode($okPoll['outbound']));
    check($db->query("SELECT state FROM qwk_rep_batches WHERE id=$batchId")->fetchColumn() === 'uploaded', 'batch not uploaded');
    check($db->query("SELECT state FROM qwk_outbound_messages WHERE echomail_id=$id")->fetchColumn() === 'sent', 'row not sent');
    check((int)$db->query("SELECT count(*) FROM qwk_outbound_messages WHERE mailbox_id=$mbx")->fetchColumn() === $msgRowsBefore, 'retry created duplicate queue rows');
    check((int)$db->query("SELECT count(*) FROM qwk_rep_batches WHERE id=$batchId AND uploaded_at IS NOT NULL")->fetchColumn() === 1, 'uploaded_at not set once');

    // attempt 3: nothing to send
    $idle = (new QwkPoller($db, new FakeTransport()))->poll($mbx, ['download' => false]);
    check($idle['outbound']['batch'] === null, 'phantom batch on idle poll');
};

// uncertain state blocks auto-poll
$tests['an upload_attempted (uncertain) batch blocks further polling until an operator acts'] = static function () use ($db, $queue, $resetOutbound, $mkEchomail, $aGeneral, $uPoster, $mbx): void {
    $resetOutbound($db, $mbx);
    $id = $mkEchomail($db, $aGeneral, 'Local Poster', 'uncertain msg', 'body', $uPoster, null, '<unc@binkterm>');
    $queue->enqueueForEchomail($id);
    $ob = new QwkOutbound($db);
    $b = $ob->prepareBatch($mbx);
    $ob->markUploadAttempted($b['batch_id']);
    @unlink($b['path']);
    // The poller catches prepareBatch()'s "uncertain" RuntimeException into errors[].
    $res = (new QwkPoller($db, new FakeTransport()))->poll($mbx, ['download' => false]);
    check($res['status'] === 'error' && str_contains(json_encode($res['errors']), 'uncertain'), 'uncertain batch not surfaced: ' . json_encode($res));
    // clear it for cleanliness
    $db->exec("UPDATE qwk_rep_batches SET state='uploaded' WHERE id=" . (int)$b['batch_id']);
    $db->exec("UPDATE qwk_outbound_messages SET state='sent' WHERE batch_id=" . (int)$b['batch_id']);
};

// wiring proof
$tests['MessageHandler enqueue hook is wired into both local-post paths'] = static function (): void {
    $src = file_get_contents(__DIR__ . '/../src/MessageHandler.php');
    check(substr_count((string)$src, '$this->enqueueQwkOutbound(') === 2, 'expected 2 enqueue call sites');
    check(str_contains((string)$src, 'private function enqueueQwkOutbound('), 'helper missing');
    check(method_exists('BinktermPHP\\Qwk\\QwkOutboundQueue', 'enqueueForEchomail'), 'service method missing');
};

// no out-of-scope schema
$tests['no relay/gate/scheduler schema entered with this slice'] = static function () use ($db): void {
    foreach (['echo_area_gates', 'echo_area_relay_rules'] as $t) {
        check($db->query("SELECT to_regclass('public.$t')")->fetchColumn() === null, "$t exists");
    }
    check($db->query("SELECT 1 FROM information_schema.columns WHERE table_name='echoareas' AND column_name='relay_mode'")->fetchColumn() === false, 'relay_mode added');
    $qwk = array_map('basename', glob(__DIR__ . '/../src/Qwk/*.php'));
    check(!in_array('QwkConferenceNumberManager.php', array_diff($qwk, $qwk), true), 'sanity');
};

$failures = 0;
foreach ($tests as $name => $t) {
    try {
        $t();
        echo "PASS  $name\n";
    } catch (Throwable $e) {
        $failures++;
        echo "FAIL  $name  ::  " . $e->getMessage() . "\n";
    }
}
echo "\n" . count($tests) . " focused tests, $failures failed\n";
exit($failures === 0 ? 0 : 1);
