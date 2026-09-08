<?php

declare(strict_types=1);

/*
 * Focused integration test for the QWKnet inbound (local-file import) slice.
 *
 * Runs against a disposable PostgreSQL with the full migrated schema, using the
 * real application autoloader (QwkInbound needs echomail/echoareas, and the
 * delete-ownership assertion needs the real MessageHandler). DB_* env points at
 * the disposable database. No production DB, no FTP, no network.
 *
 * The real /root/WEEDNET.qwk specimen is mounted at /packet.qwk for the
 * hash-pinned acceptance path; synthetic packets exercise dedup / reply edges.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Echomail\EchomailSeenBy;
use BinktermPHP\MessageHandler;
use BinktermPHP\QwkNet\Parser;
use BinktermPHP\Qwk\QwkImportException;
use BinktermPHP\Qwk\QwkInbound;

$SPECIMEN = '/packet.qwk';
$SPECIMEN_SHA = 'c9be27d55e12db7e0f47cd4ce6ee7b063e30c1d3643f89a659f2084e1267b5d1';

function check(bool $c, string $label): void
{
    if (!$c) {
        throw new RuntimeException($label);
    }
}

$db = new PDO(
    sprintf('pgsql:host=%s;port=%s;dbname=%s', getenv('DB_HOST'), getenv('DB_PORT') ?: '5432', getenv('DB_NAME')),
    getenv('DB_USER'),
    getenv('DB_PASS'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
check((bool)$db->query('SELECT 1')->fetchColumn(), 'connected to disposable db');
check(getenv('DB_NAME') !== 'binkterm' && getenv('DB_NAME') !== false, 'refusing to run against a non-disposable DB_NAME');

function rejects(callable $fn, string $class): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        check($e instanceof $class, "expected $class, got " . get_class($e) . ': ' . $e->getMessage());
        return;
    }
    throw new RuntimeException('expected a rejection');
}

/**
 * Build a minimal M1-parseable QWK archive: CONTROL.DAT + MESSAGES.DAT only
 * (no HEADERS.DAT; inline @MSGID/@REPLY body prefixes carry identity).
 *
 * @param array<int,string>                                             $confMap  number => name
 * @param array<int,array{num:int,conf:int,from:string,to:string,subject:string,body:string,ref?:int}> $messages
 */
function buildQwkPacket(string $path, string $bbsId, array $confMap, array $messages): void
{
    $LF = "\xe3";
    $lines = ['Test Hub', 'Nowhere', '000-000-0000', 'Test Sysop', '0000,' . $bbsId,
        '09-08-2026,00:00:00', 'TESTUSER', '', '0', '0', (string)(count($confMap) - 1)];
    foreach ($confMap as $num => $name) {
        $lines[] = (string)$num;
        $lines[] = $name;
    }
    $control = implode("\r\n", $lines) . "\r\n";

    $msgData = str_pad($bbsId, 128);
    foreach ($messages as $m) {
        $body = $m['body'] !== '' ? str_replace("\n", $LF, $m['body']) . $LF : $LF;
        $bodyBlocks = max(1, (int)ceil(strlen($body) / 128));
        $totalBlocks = $bodyBlocks + 1;

        $h = substr(' ', 0, 1);
        $h .= str_pad((string)$m['num'], 7);
        $h .= '09-08-26';
        $h .= '00:00';
        $h .= str_pad(substr($m['to'], 0, 25), 25);
        $h .= str_pad(substr($m['from'], 0, 25), 25);
        $h .= str_pad(substr($m['subject'], 0, 25), 25);
        $h .= str_pad('', 12);
        $h .= str_pad((string)($m['ref'] ?? 0), 8, ' ', STR_PAD_LEFT);
        $h .= str_pad((string)$totalBlocks, 6, ' ', STR_PAD_LEFT);
        $h .= chr(0);
        $h .= pack('v', $m['conf']);
        $h .= pack('v', 0);
        $h .= chr(0);
        if (strlen($h) !== 128) {
            throw new RuntimeException('bad synthetic header length ' . strlen($h));
        }
        $msgData .= $h . str_pad($body, $bodyBlocks * 128, "\x00");
    }

    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('cannot create synthetic packet');
    }
    $zip->addFromString('CONTROL.DAT', $control);
    $zip->addFromString('MESSAGES.DAT', $msgData);
    $zip->close();
}

// --- fresh fixtures ---------------------------------------------------------
$db->exec("TRUNCATE echomail, echoareas, qwk_mailboxes, echo_area_qwk_subscriptions,
           qwk_inbound_packets, qwk_inbound_messages, users RESTART IDENTITY CASCADE");

$db->exec("INSERT INTO users (username, password_hash, real_name, is_admin) VALUES
    ('alice',  'x', 'Alice Example', FALSE),
    ('phigan', 'x', 'Phil Gunn',     FALSE),
    ('admin1', 'x', 'The Admin',     TRUE)");
$uAlice = (int)$db->query("SELECT id FROM users WHERE username='alice'")->fetchColumn();
$uPhigan = (int)$db->query("SELECT id FROM users WHERE username='phigan'")->fetchColumn();
$uAdmin = (int)$db->query("SELECT id FROM users WHERE username='admin1'")->fetchColumn();

$db->exec("INSERT INTO echoareas (tag, description, domain, is_local, is_active) VALUES
    ('WN_GENERAL', 'WeedNet General', 'weednet', FALSE, TRUE),
    ('WN_HUMOR',   'WeedNet Humor',   'weednet', FALSE, TRUE),
    ('FTN_TEST',   'A plain FTN area', 'fidonet', FALSE, TRUE)");
$aGeneral = (int)$db->query("SELECT id FROM echoareas WHERE tag='WN_GENERAL'")->fetchColumn();
$aHumor = (int)$db->query("SELECT id FROM echoareas WHERE tag='WN_HUMOR'")->fetchColumn();
$aFtn = (int)$db->query("SELECT id FROM echoareas WHERE tag='FTN_TEST'")->fetchColumn();

// A pre-existing FTN echomail authored locally by alice (has user_id).
$db->exec("INSERT INTO echomail (echoarea_id, from_address, from_name, to_name, subject, message_text, user_id)
           VALUES ($aFtn, '21:1/100', 'Alice Example', 'All', 'FTN post', 'hi from ftn', $uAlice)");
$ftnMsgId = (int)$db->query("SELECT id FROM echomail WHERE subject='FTN post'")->fetchColumn();

$mkMailbox = static function (PDO $db, string $name, string $bbsId): int {
    $db->prepare("INSERT INTO qwk_mailboxes (name, bbs_id, host, username, password) VALUES (?, ?, 'h', 'u', 'enc')")
        ->execute([$name, $bbsId]);
    return (int)$db->lastInsertId();
};
$mWeednet = $mkMailbox($db, 'WeedNet', 'WEEDNET');
$mOther = $mkMailbox($db, 'Other Hub', 'OTHERBBS');
$mNoMap = $mkMailbox($db, 'WeedNet NoMap', 'WEEDNET');

$db->prepare("INSERT INTO echo_area_qwk_subscriptions (echoarea_id, mailbox_id, conference_number, conference_tag)
              VALUES (?, ?, 1101, 'GENERAL'), (?, ?, 1105, 'HUMOR')")
    ->execute([$aGeneral, $mWeednet, $aHumor, $mWeednet]);

$echoareaCountBefore = (int)$db->query("SELECT count(*) FROM echoareas")->fetchColumn();

$tests = [];

// 1
$tests['M1 parser re-verifies the specimen'] = static function () use ($SPECIMEN, $SPECIMEN_SHA): void {
    $p = (new Parser())->parse($SPECIMEN);
    check($p->sha256 === $SPECIMEN_SHA, 'sha mismatch');
    check(($p->control['packet_id'] ?? '') === 'WEEDNET', 'packet id');
    check(count($p->messages) === 10, 'message count ' . count($p->messages));
    foreach ($p->messages as $m) {
        check((int)$m->header['conference'] === 1101, 'conf ' . $m->header['conference']);
    }
};

// 2 + 4 + acceptance
$tests['expected BBS ID matches: mapped conference imports all 10'] = static function () use ($db, $SPECIMEN, $mWeednet, $aGeneral): void {
    $r = (new QwkInbound($db))->importLocalPacket($mWeednet, $SPECIMEN);
    check($r['status'] === 'imported', 'status ' . $r['status']);
    check($r['imported'] === 10, 'imported ' . $r['imported']);
    check($r['skipped'] === 0, 'skipped ' . $r['skipped']);
    check((int)$db->query("SELECT count(*) FROM echomail WHERE echoarea_id=$aGeneral")->fetchColumn() === 10, 'echomail rows');
    check((int)$db->query("SELECT count(*) FROM qwk_inbound_messages")->fetchColumn() === 10, 'sidecar rows');
    check((int)$db->query("SELECT message_count FROM echoareas WHERE id=$aGeneral")->fetchColumn() === 10, 'area count');
};

// 6
$tests['exact same packet twice creates no duplicates'] = static function () use ($db, $SPECIMEN, $mWeednet, $aGeneral): void {
    $r = (new QwkInbound($db))->importLocalPacket($mWeednet, $SPECIMEN);
    check($r['status'] === 'already_imported', 'status ' . $r['status']);
    check($r['imported'] === 0, 'imported ' . $r['imported']);
    check((int)$db->query("SELECT count(*) FROM echomail WHERE echoarea_id=$aGeneral")->fetchColumn() === 10, 'no dupes');
    check((int)$db->query("SELECT count(*) FROM qwk_inbound_packets WHERE mailbox_id=$mWeednet")->fetchColumn() === 1, 'one ledger row');
};

// 9 + 11 + 12
$tests['imported messages have no local user, no FTN spool, non-FTN sender'] = static function () use ($db, $aGeneral): void {
    check((int)$db->query("SELECT count(*) FROM echomail WHERE echoarea_id=$aGeneral AND user_id IS NOT NULL")->fetchColumn() === 0, 'user_id set');
    check((int)$db->query("SELECT count(*) FROM echomail WHERE echoarea_id=$aGeneral AND spooled_at IS NOT NULL")->fetchColumn() === 0, 'spooled_at set');
    check((int)$db->query("SELECT count(*) FROM echomail WHERE echoarea_id=$aGeneral AND from_address <> 'qwk:WEEDNET'")->fetchColumn() === 0, 'from_address');
    check((int)$db->query("SELECT count(*) FROM hub_node_outbound WHERE echomail_id IN (SELECT id FROM echomail WHERE echoarea_id=$aGeneral)")->fetchColumn() === 0, 'hub_node_outbound rows');
    $parts = EchomailSeenBy::parseFtnAddressParts('qwk:WEEDNET');
    check($parts['zone'] === 0 && $parts['net'] === 0 && $parts['node'] === 0, 'qwk:WEEDNET parsed as a real FTN address');
};

// 13 + 16
$tests['same-conference reply links; sidecar provenance is correct'] = static function () use ($db, $mWeednet, $aGeneral): void {
    $child = $db->query("SELECT id, reply_to_id FROM echomail WHERE echoarea_id=$aGeneral AND subject='Re: Bummer' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $parent = $db->query("SELECT id FROM echomail WHERE echoarea_id=$aGeneral AND subject='Bummer' LIMIT 1")->fetchColumn();
    check((int)$child['reply_to_id'] === (int)$parent, 'reply_to_id not linked to in-packet parent');

    $side = $db->prepare("SELECT * FROM qwk_inbound_messages WHERE echomail_id = ?");
    $side->execute([$child['id']]);
    $s = $side->fetch(PDO::FETCH_ASSOC);
    check((int)$s['mailbox_id'] === $mWeednet, 'sidecar mailbox_id');
    check((int)$s['conference_number'] === 1101, 'sidecar conference');
    check((int)$db->query("SELECT count(*) FROM qwk_inbound_packets WHERE id=" . (int)$s['inbound_packet_id'])->fetchColumn() === 1, 'sidecar packet id dangling');
    check(str_starts_with((string)$s['dedupe_key'], 'msgid:<'), 'dedupe key form: ' . $s['dedupe_key']);
    check($s['external_reply_id'] !== null && str_contains((string)$s['external_reply_id'], '@tacopronto.bbs.io'), 'external reply id');
    check((int)$s['qwk_message_number'] === 541, 'qwk message number ' . $s['qwk_message_number']);
};

// 3
$tests['BBS ID mismatch rejects the whole packet, zero inserts'] = static function () use ($db, $SPECIMEN, $mOther): void {
    $before = (int)$db->query("SELECT count(*) FROM echomail")->fetchColumn();
    rejects(static fn() => (new QwkInbound($db))->importLocalPacket($mOther, $SPECIMEN), QwkImportException::class);
    check((int)$db->query("SELECT count(*) FROM echomail")->fetchColumn() === $before, 'echomail changed on mismatch');
    check((int)$db->query("SELECT count(*) FROM qwk_inbound_packets WHERE mailbox_id=$mOther")->fetchColumn() === 0, 'ledger row on mismatch');
};

// 5
$tests['unmapped conference is skipped, nothing auto-created'] = static function () use ($db, $SPECIMEN, $mNoMap, $echoareaCountBefore): void {
    $r = (new QwkInbound($db))->importLocalPacket($mNoMap, $SPECIMEN);
    check($r['status'] === 'imported', 'status');
    check($r['imported'] === 0, 'imported ' . $r['imported']);
    check($r['skipped_unmapped'] === 10, 'skipped_unmapped ' . $r['skipped_unmapped']);
    check(($r['unmapped_conferences'][1101] ?? 0) === 10, 'unmapped_conferences');
    check((int)$db->query("SELECT count(*) FROM echoareas")->fetchColumn() === $echoareaCountBefore, 'echoarea auto-created');
    check((int)$db->query("SELECT count(*) FROM echo_area_qwk_subscriptions")->fetchColumn() === 2, 'subscription auto-created');
};

// 7
$tests['external MSGID dedup within mailbox+conference'] = static function () use ($db, $mWeednet, $aGeneral): void {
    $path = tempnam(sys_get_temp_dir(), 'qwk') . '.qwk';
    // One message reusing an in-DB MSGID (#546), one genuinely new.
    buildQwkPacket($path, 'WEEDNET', [1101 => 'GENERAL'], [
        ['num' => 546, 'conf' => 1101, 'from' => 'phigan', 'to' => 'All', 'subject' => 'July',
            'body' => "@MSGID: <6A60CE27.248.weednet_general@tacopronto.bbs.io>\ndupe body"],
        ['num' => 900, 'conf' => 1101, 'from' => 'newguy', 'to' => 'All', 'subject' => 'Brand new',
            'body' => "@MSGID: <new900@example.org>\nfresh"],
    ]);
    $before = (int)$db->query("SELECT count(*) FROM echomail WHERE echoarea_id=$aGeneral")->fetchColumn();
    $r = (new QwkInbound($db))->importLocalPacket($mWeednet, $path);
    unlink($path);
    check($r['imported'] === 1, 'imported ' . $r['imported']);
    check($r['skipped_duplicate'] === 1, 'skipped_duplicate ' . $r['skipped_duplicate']);
    check((int)$db->query("SELECT count(*) FROM echomail WHERE echoarea_id=$aGeneral")->fetchColumn() === $before + 1, 'row delta');
};

// 8
$tests['deterministic fallback dedup for messages without a MSGID'] = static function () use ($db, $mWeednet): void {
    $mkPath = static function (): string { return tempnam(sys_get_temp_dir(), 'qwk') . '.qwk'; };
    $m = ['num' => 950, 'conf' => 1101, 'from' => 'nomsgid', 'to' => 'All', 'subject' => 'No id here', 'body' => 'plain body no kludges'];
    $a = $mkPath();
    buildQwkPacket($a, 'WEEDNET', [1101 => 'GENERAL'], [$m]);
    $b = $mkPath();
    buildQwkPacket($b, 'WEEDNET', [1101 => 'GENERAL'], [$m, ['num' => 951, 'conf' => 1101, 'from' => 'x', 'to' => 'All', 'subject' => 'sibling', 'body' => 'other']]);

    $r1 = (new QwkInbound($db))->importLocalPacket($mWeednet, $a);
    $r2 = (new QwkInbound($db))->importLocalPacket($mWeednet, $b);
    unlink($a);
    unlink($b);
    check($r1['imported'] === 1, 'first fallback import ' . $r1['imported']);
    check($r2['imported'] === 1 && $r2['skipped_duplicate'] === 1, 'fallback dedup: ' . json_encode($r2));
    $key = $db->query("SELECT dedupe_key FROM qwk_inbound_messages WHERE qwk_message_number=950")->fetchColumn();
    check($key === 'qwknum:950', 'fallback key form: ' . $key);
};

// 14
$tests['child-before-parent is linked by the bounded backfill pass'] = static function () use ($db): void {
    // fresh mailbox + area so ordering is deterministic
    $db->prepare("INSERT INTO qwk_mailboxes (name, bbs_id, host, username, password) VALUES ('BF','BFHUB','h','u','e')")->execute();
    $mbx = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO echoareas (tag, description, is_local, is_active) VALUES ('BF_AREA','bf',FALSE,TRUE)")->execute();
    $area = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO echo_area_qwk_subscriptions (echoarea_id, mailbox_id, conference_number) VALUES (?, ?, 7)")->execute([$area, $mbx]);

    $path = tempnam(sys_get_temp_dir(), 'qwk') . '.qwk';
    buildQwkPacket($path, 'BFHUB', [7 => 'BF'], [
        ['num' => 2, 'conf' => 7, 'from' => 'child', 'to' => 'All', 'subject' => 'reply first',
            'body' => "@MSGID: <child2@bf>\n@REPLY: <parent1@bf>\ni replied before my parent existed"],
        ['num' => 1, 'conf' => 7, 'from' => 'parent', 'to' => 'All', 'subject' => 'the parent',
            'body' => "@MSGID: <parent1@bf>\ni am the parent"],
    ]);
    $r = (new QwkInbound($db))->importLocalPacket($mbx, $path);
    unlink($path);
    check($r['imported'] === 2, 'imported ' . $r['imported']);
    check($r['backfilled'] === 1, 'backfilled ' . $r['backfilled']);
    $childReply = $db->query("SELECT reply_to_id FROM echomail WHERE echoarea_id=$area AND subject='reply first'")->fetchColumn();
    $parentId = $db->query("SELECT id FROM echomail WHERE echoarea_id=$area AND subject='the parent'")->fetchColumn();
    check((int)$childReply === (int)$parentId, 'backfill did not link child to parent');
};

// 15
$tests['cross-conference reply does not link'] = static function () use ($db, $mWeednet): void {
    $path = tempnam(sys_get_temp_dir(), 'qwk') . '.qwk';
    buildQwkPacket($path, 'WEEDNET', [1101 => 'GENERAL', 1105 => 'HUMOR'], [
        ['num' => 700, 'conf' => 1105, 'from' => 'a', 'to' => 'All', 'subject' => 'humor parent',
            'body' => "@MSGID: <xconf-parent@h>\nin humor"],
        ['num' => 701, 'conf' => 1101, 'from' => 'b', 'to' => 'All', 'subject' => 'general child',
            'body' => "@MSGID: <xconf-child@g>\n@REPLY: <xconf-parent@h>\nreplying across conferences"],
    ]);
    $r = (new QwkInbound($db))->importLocalPacket($mWeednet, $path);
    unlink($path);
    check($r['imported'] === 2, 'imported ' . $r['imported']);
    check($r['reply_links'] === 0 && $r['backfilled'] === 0, 'a cross-conference link was made: ' . json_encode($r));
    $childReply = $db->query("SELECT reply_to_id FROM echomail WHERE subject='general child'")->fetchColumn();
    check($childReply === null, 'cross-conference reply_to_id set');
};

// 10 + 18
$tests['delete ownership: display-name coincidence blocked for QWK, unchanged for FTN/local'] = static function () use ($db, $aGeneral, $uPhigan, $uAdmin, $uAlice, $ftnMsgId): void {
    $mh = new MessageHandler();
    // A leaf message (no replies) so the pre-existing reply_to_id FK on echomail
    // does not get in the way of exercising the ownership check itself.
    $qwkFromPhigan = (int)$db->query("
        SELECT e.id FROM echomail e
        WHERE e.echoarea_id=$aGeneral AND e.from_name='phigan'
          AND NOT EXISTS (SELECT 1 FROM echomail c WHERE c.reply_to_id = e.id)
        ORDER BY e.id LIMIT 1
    ")->fetchColumn();
    check($qwkFromPhigan > 0, 'no leaf qwk message from phigan to test with');

    // local user "phigan" must NOT be able to delete an imported message just by name
    $res = $mh->deleteEchomail([$qwkFromPhigan], $uPhigan);
    check($res['deleted'] === 0 && $res['failed'] === 1, 'name coincidence deleted a QWK message: ' . json_encode($res));
    check((int)$db->query("SELECT count(*) FROM echomail WHERE id=$qwkFromPhigan")->fetchColumn() === 1, 'qwk message was deleted');

    // admin still can
    check($mh->deleteEchomail([$qwkFromPhigan], $uAdmin)['deleted'] === 1, 'admin cannot delete QWK message');

    // FTN/local: alice authored FTN_TEST post (user_id set) -> still deletable by her
    check($mh->deleteEchomail([$ftnMsgId], $uAlice)['deleted'] === 1, 'FTN author lost delete rights (regression)');
};

// 17
$tests['injected mid-import failure rolls back: no receipt, no partial rows'] = static function () use ($db, $SPECIMEN): void {
    $db->prepare("INSERT INTO qwk_mailboxes (name, bbs_id, host, username, password) VALUES ('RB','WEEDNET','h','u','e')")->execute();
    $mbx = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO echoareas (tag, description, is_local, is_active) VALUES ('RB_AREA','rb',FALSE,TRUE)")->execute();
    $area = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO echo_area_qwk_subscriptions (echoarea_id, mailbox_id, conference_number) VALUES (?, ?, 1101)")->execute([$area, $mbx]);

    // NOT VALID: enforced for new rows only, so pre-existing 'News shmews' rows
    // from earlier tests do not block adding it. The 10th specimen message has
    // that subject and will fail its INSERT mid-transaction.
    $db->exec("ALTER TABLE echomail ADD CONSTRAINT tmp_qwk_fail CHECK (subject <> 'News shmews') NOT VALID");
    try {
        rejects(static fn() => (new QwkInbound($db))->importLocalPacket($mbx, $SPECIMEN), PDOException::class);
    } finally {
        $db->exec("ALTER TABLE echomail DROP CONSTRAINT tmp_qwk_fail");
    }
    check((int)$db->query("SELECT count(*) FROM qwk_inbound_packets WHERE mailbox_id=$mbx")->fetchColumn() === 0, 'ledger row survived rollback');
    check((int)$db->query("SELECT count(*) FROM echomail WHERE echoarea_id=$area")->fetchColumn() === 0, 'partial echomail survived rollback');
    check((int)$db->query("SELECT count(*) FROM qwk_inbound_messages WHERE mailbox_id=$mbx")->fetchColumn() === 0, 'partial sidecar survived rollback');
};

// 19
$tests['no out-of-scope transport/outbound/relay schema or code introduced'] = static function () use ($db): void {
    foreach (['qwk_outbound_messages', 'qwk_rep_batches', 'echo_area_gates', 'echo_area_relay_rules'] as $t) {
        check($db->query("SELECT to_regclass('public.$t')")->fetchColumn() === null, "table $t exists");
    }
    check($db->query("SELECT 1 FROM information_schema.columns WHERE table_name='echoareas' AND column_name='relay_mode'")->fetchColumn() === false, 'echoareas.relay_mode added');
    $qwkFiles = array_map('basename', glob(__DIR__ . '/../src/Qwk/*.php'));
    sort($qwkFiles);
    $expected = ['QwkBuilder.php', 'QwkConferenceNumberManager.php', 'QwkHttpController.php', 'QwkImportException.php',
        'QwkInbound.php', 'QwkMailboxManager.php', 'QwkSubscriptionManager.php', 'RepProcessor.php'];
    sort($expected);
    check($qwkFiles === $expected, 'unexpected src/Qwk files: ' . implode(',', $qwkFiles));
    check(!is_file(__DIR__ . '/../src/Qwk/QwkOutbound.php'), 'QwkOutbound.php present');
    check(!is_file(__DIR__ . '/../src/Qwk/QwkPoller.php'), 'QwkPoller.php present');
    check(!is_dir(__DIR__ . '/../src/Qwk/Transport'), 'src/Qwk/Transport present');
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
