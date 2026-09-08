<?php

declare(strict_types=1);

/*
 * Focused disposable-DB test for the QWKnet mailbox / subscription / SysK
 * foundation slice (schema + model only; no polling, transport, import/export).
 *
 * Proves:
 *   SysK
 *     - env key override (SYSK_KEY) encrypt/decrypt round-trip
 *     - wrong key fails authentication
 *     - file-created key round-trips and the file is mode 0600
 *     - malformed / wrong-size key material fails clearly
 *   QwkMailboxManager
 *     - create / read / update / delete
 *     - password column is not plaintext; SysK round-trips it
 *     - default reads never expose the password or a decrypted copy
 *     - blank password on update keeps the stored one
 *     - validation errors
 *   QwkSubscriptionManager
 *     - create for a valid echoarea + mailbox
 *     - UNIQUE (mailbox_id, conference_number) rejected
 *     - UNIQUE (echoarea_id, mailbox_id) rejected
 *     - same conference number on a different mailbox succeeds
 *     - FK violations rejected (manager + raw)
 *     - lookups, and ON DELETE CASCADE from the mailbox
 *     - no auto-create / no createQwkPlaceholderArea behaviour
 *   Schema guards
 *     - echoareas has no `protocol` column
 *     - no outbound-queue / gates / relay / provenance schema introduced
 *     - final column sets for the two new tables
 *     - pre-existing echoarea rows are untouched
 *
 * Runs only against an explicit Unix-socket disposable database. `Database`
 * cannot be autoloaded; `Config` is permitted solely for SYSK_KEY* resolution.
 * A decrypted password is never written to output.
 */

if ($argc !== 2 || $argv[1] !== '/socket' || !is_dir('/socket')) {
    fwrite(STDERR, "Run inside the disposable test container with argument /socket\n");
    exit(2);
}

spl_autoload_register(static function (string $class): void {
    if ($class === 'BinktermPHP\\Database') {
        throw new RuntimeException('Database singleton is forbidden in this test');
    }
    $prefix = 'BinktermPHP\\';
    if (str_starts_with($class, $prefix)) {
        $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
});

use BinktermPHP\Qwk\QwkMailboxManager;
use BinktermPHP\Qwk\QwkSubscriptionManager;
use BinktermPHP\SysK;

function check(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

/** Assert that $action throws, optionally of a given class / PDO SQLSTATE. */
function rejects(callable $action, ?string $expect = null): void
{
    try {
        $action();
    } catch (Throwable $e) {
        if ($expect === null) {
            return;
        }
        if ($expect === 'InvalidArgumentException') {
            check($e instanceof InvalidArgumentException, 'Expected InvalidArgumentException, got ' . get_class($e));
            return;
        }
        if ($expect === 'RuntimeException') {
            check($e instanceof RuntimeException && !($e instanceof InvalidArgumentException),
                'Expected RuntimeException, got ' . get_class($e));
            return;
        }
        // Treat as a PDO SQLSTATE code.
        check($e instanceof PDOException && $e->getCode() === $expect,
            'Expected PDOException ' . $expect . ', got ' . get_class($e) . ' (' . $e->getCode() . ')');
        return;
    }
    throw new RuntimeException('Expected a rejection but none was thrown');
}

$keyRoot = getenv('SYSK_TEST_KEYROOT') ?: '/keys';
check(is_dir($keyRoot) && is_writable($keyRoot), 'A writable key-file root is required at ' . $keyRoot);

$db = new PDO(
    'pgsql:host=/socket;dbname=qwknet_slice_test',
    'slice',
    '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
check(
    (bool)$db->query("SELECT current_database() = 'qwknet_slice_test' AND inet_server_addr() IS NULL")->fetchColumn(),
    'Disposable Unix-socket DB only'
);
$db->exec("SET statement_timeout = '15s'");

// Minimal real `echoareas` so the new foreign keys have a target.
$db->exec("
    CREATE TABLE echoareas (
        id             SERIAL PRIMARY KEY,
        tag            VARCHAR(50) NOT NULL,
        description    TEXT,
        domain         VARCHAR(50),
        uplink_address VARCHAR(20),
        is_active      BOOLEAN DEFAULT TRUE,
        is_local       BOOLEAN DEFAULT FALSE,
        UNIQUE (tag, domain)
    )
");
$db->exec("
    INSERT INTO echoareas (tag, description, domain, is_local) VALUES
        ('SEED_LOCAL', 'Pre-existing local area', NULL, TRUE),
        ('SEED_FTN',   'Pre-existing FTN area',   'fidonet', FALSE)
");
$seededBefore = $db->query("SELECT tag || '|' || COALESCE(domain,'') || '|' || is_local FROM echoareas ORDER BY tag")
    ->fetchAll(PDO::FETCH_COLUMN);

// Apply the actual migration under test.
$migration = __DIR__ . '/../database/migrations/v20260908190751_add_qwk_mailboxes_and_echoarea_conference_subscriptions.sql';
check(is_file($migration), 'Migration file not found: ' . basename($migration));
$db->exec(file_get_contents($migration));

$area1 = (int)$db->query("SELECT id FROM echoareas WHERE tag = 'SEED_LOCAL'")->fetchColumn();
$area2 = (int)$db->query("SELECT id FROM echoareas WHERE tag = 'SEED_FTN'")->fetchColumn();

$keyA = str_repeat('a1', 32); // 64 hex chars
$keyB = str_repeat('b2', 32);

$tests = [];

// ---------- SysK ----------

$tests['SysK env key round-trips and produces non-plaintext base64'] = static function () use ($keyA): void {
    $_ENV['SYSK_KEY'] = $keyA;
    unset($_ENV['SYSK_KEY_FILE']);
    $secret = 'pw_' . bin2hex(random_bytes(8));
    $encoded = SysK::encrypt($secret);
    check($encoded !== $secret, 'ciphertext equals plaintext');
    check(!str_contains($encoded, $secret), 'plaintext leaks into ciphertext');
    check(base64_decode($encoded, true) !== false, 'ciphertext is not valid base64');
    check(SysK::decrypt($encoded) === $secret, 'round-trip mismatch');
    check(SysK::decrypt('') === '' && SysK::decrypt(null) === '', 'empty input should decrypt to empty string');
    check(SysK::isConfigured(), 'isConfigured() false with a valid env key');
};

$tests['SysK decrypt fails under a different key'] = static function () use ($keyA, $keyB): void {
    $_ENV['SYSK_KEY'] = $keyA;
    $encoded = SysK::encrypt('pw_' . bin2hex(random_bytes(8)));
    $_ENV['SYSK_KEY'] = $keyB;
    rejects(static fn() => SysK::decrypt($encoded), 'RuntimeException');
    rejects(static fn() => SysK::decrypt('####not base64####'), 'RuntimeException');
};

$tests['SysK file-created key round-trips and is mode 0600'] = static function () use ($keyRoot): void {
    unset($_ENV['SYSK_KEY']);
    $keyFile = $keyRoot . '/nested/' . bin2hex(random_bytes(4)) . '/sysk.dat';
    $_ENV['SYSK_KEY_FILE'] = $keyFile;

    $secret = 'pw_' . bin2hex(random_bytes(8));
    $encoded = SysK::encrypt($secret);
    check(is_file($keyFile), 'key file was not created');
    check((fileperms($keyFile) & 0777) === 0600, 'key file mode is ' . decoct(fileperms($keyFile) & 0777) . ', expected 600');
    check(preg_match('/^[0-9a-f]{64}$/', trim((string)file_get_contents($keyFile))) === 1, 'key file is not 64 hex chars');
    check(SysK::decrypt($encoded) === $secret, 'file-key round-trip mismatch');
    // Second call reuses the same file, so it still decrypts.
    check(SysK::decrypt(SysK::encrypt($secret)) === $secret, 'key file not reused on second call');

    unset($_ENV['SYSK_KEY_FILE']);
};

$tests['SysK rejects malformed key material clearly'] = static function (): void {
    foreach (['too-short', str_repeat('z', 64), str_repeat('a', 63), 'not+valid+base64+='] as $bad) {
        $_ENV['SYSK_KEY'] = $bad;
        rejects(static fn() => SysK::encrypt('x'), 'RuntimeException');
    }
    unset($_ENV['SYSK_KEY']);
};

// ---------- QwkMailboxManager ----------
$ctx = new stdClass();
$ctx->mbx1 = 0;
$ctx->mbx2 = 0;
$mailboxes = new QwkMailboxManager($db);
$mailboxSecret = 'ftp_' . bin2hex(random_bytes(10));

// The malformed-key test above cleared SYSK_KEY; restore a working key for the
// remaining sections (env mutations persist for the rest of the run).
$tests['(arrange) a working SysK key is configured for the manager sections'] = static function () use ($keyA): void {
    $_ENV['SYSK_KEY'] = $keyA;
    unset($_ENV['SYSK_KEY_FILE']);
    check(SysK::isConfigured(), 'SysK not configured after arrange');
};

$tests['mailbox create returns an id'] = static function () use ($mailboxes, $mailboxSecret, $db, $ctx): void {
    $id = $mailboxes->save([
        'name' => 'Test Peer', 'bbs_id' => 'testpeer', 'host' => 'qwk.example.org',
        'port' => 2121, 'username' => 'tester', 'password' => $mailboxSecret,
        'ftp_remote_path' => '/qnet', 'passive_mode' => true, 'enabled' => true,
    ]);
    check($id > 0, 'save() did not return an id');
    $ctx->mbx1 = $id;
    check($db->query("SELECT bbs_id FROM qwk_mailboxes WHERE id = $id")->fetchColumn() === 'TESTPEER', 'bbs_id not normalised');
};

$tests['mailbox password is stored encrypted, not plaintext'] = static function () use ($db, $mailboxes, $mailboxSecret, $ctx): void {
    $stored = (string)$db->query("SELECT password FROM qwk_mailboxes WHERE id = {$ctx->mbx1}")->fetchColumn();
    check($stored !== '' && $stored !== $mailboxSecret, 'password column holds plaintext');
    check(!str_contains($stored, $mailboxSecret), 'plaintext substring present in password column');
    check($mailboxes->decryptPassword($stored) === $mailboxSecret, 'stored password does not decrypt to the original');
};

$tests['default mailbox reads never expose the secret'] = static function () use ($mailboxes, $ctx): void {
    $one = $mailboxes->getById($ctx->mbx1);
    check($one !== null, 'getById returned null');
    check(!array_key_exists('password', $one) && !array_key_exists('password_plain', $one), 'getById leaked a secret key');
    foreach ($mailboxes->getAll() as $row) {
        check(!array_key_exists('password', $row) && !array_key_exists('password_plain', $row), 'getAll leaked a secret key');
    }
};

$tests['explicit includeSecret returns the decrypted password only'] = static function () use ($mailboxes, $mailboxSecret, $ctx): void {
    $row = $mailboxes->getById($ctx->mbx1, true);
    check(($row['password_plain'] ?? null) === $mailboxSecret, 'password_plain wrong');
    check(!array_key_exists('password', $row), 'ciphertext returned alongside password_plain');
};

$tests['mailbox update: blank password keeps the stored one, new password replaces it'] = static function () use ($mailboxes, $mailboxSecret, $ctx): void {
    $id = $ctx->mbx1;
    $mailboxes->save(['name' => 'Renamed Peer', 'bbs_id' => 'testpeer', 'host' => 'qwk.example.org',
        'port' => 2121, 'username' => 'tester', 'password' => ''], $id);
    check($mailboxes->getById($id)['name'] === 'Renamed Peer', 'update did not persist');
    check($mailboxes->getById($id, true)['password_plain'] === $mailboxSecret, 'blank password did not preserve the secret');

    $next = 'ftp2_' . bin2hex(random_bytes(8));
    $mailboxes->save(['name' => 'Renamed Peer', 'bbs_id' => 'testpeer', 'host' => 'qwk.example.org',
        'port' => 2121, 'username' => 'tester', 'password' => $next], $id);
    check($mailboxes->getById($id, true)['password_plain'] === $next, 'new password was not stored');
    // restore for later sections
    $mailboxes->save(['name' => 'Renamed Peer', 'bbs_id' => 'testpeer', 'host' => 'qwk.example.org',
        'port' => 2121, 'username' => 'tester', 'password' => $mailboxSecret], $id);
};

$tests['mailbox validation rejects bad input'] = static function () use ($mailboxes): void {
    rejects(static fn() => $mailboxes->save(['bbs_id' => 'x', 'host' => 'h', 'username' => 'u', 'password' => 'p']), 'InvalidArgumentException');
    rejects(static fn() => $mailboxes->save(['name' => 'n', 'host' => 'h', 'username' => 'u', 'password' => 'p']), 'InvalidArgumentException');
    rejects(static fn() => $mailboxes->save(['name' => 'n', 'bbs_id' => 'x', 'host' => 'h', 'username' => 'u', 'password' => 'p', 'port' => 0]), 'InvalidArgumentException');
    rejects(static fn() => $mailboxes->save(['name' => 'n', 'bbs_id' => 'x', 'host' => 'h', 'username' => 'u']), 'InvalidArgumentException');
    rejects(static fn() => $mailboxes->save(['name' => 'n', 'bbs_id' => 'x', 'host' => 'h', 'username' => 'u', 'password' => 'p'], 999999), 'InvalidArgumentException');
};

$tests['second mailbox for cross-peer tests'] = static function () use ($mailboxes, $ctx): void {
    $ctx->mbx2 = $mailboxes->save([
        'name' => 'Second Peer', 'bbs_id' => 'peer2', 'host' => 'qwk2.example.org',
        'username' => 'tester2', 'password' => 'pw_' . bin2hex(random_bytes(8)),
    ]);
    check($ctx->mbx2 > 0, 'second mailbox not created');
};

// ---------- QwkSubscriptionManager ----------
$subs = new QwkSubscriptionManager($db);

$tests['subscription create for a valid echoarea + mailbox'] = static function () use ($subs, $area1, $ctx): void {
    $id = $subs->create($area1, $ctx->mbx1, 1003, 'General');
    check($id > 0, 'create() returned no id');
    $ctx->sub1 = $id;
    $row = $subs->getById($id);
    check((int)$row['conference_number'] === 1003 && $row['conference_tag'] === 'General', 'row stored wrong');
};

$tests['duplicate mailbox + conference number is rejected'] = static function () use ($subs, $area2, $ctx): void {
    rejects(static fn() => $subs->create($area2, $ctx->mbx1, 1003, 'General dup'), 'InvalidArgumentException');
};

$tests['duplicate echoarea + mailbox is rejected'] = static function () use ($subs, $area1, $ctx): void {
    rejects(static fn() => $subs->create($area1, $ctx->mbx1, 1099, 'Another conf'), 'InvalidArgumentException');
};

$tests['same conference number on a different mailbox succeeds'] = static function () use ($subs, $area2, $ctx): void {
    $id = $subs->create($area2, $ctx->mbx2, 1003, 'General on peer 2');
    check($id > 0, 'cross-peer same conference number failed');
};

$tests['FK violations are rejected (manager and raw)'] = static function () use ($subs, $db, $area1, $area2, $ctx): void {
    rejects(static fn() => $subs->create(999999, $ctx->mbx1, 5, null), 'InvalidArgumentException');
    rejects(static fn() => $subs->create($area1, 888888, 6, null), 'InvalidArgumentException');
    rejects(static function () use ($db) {
        $db->prepare('INSERT INTO echo_area_qwk_subscriptions (echoarea_id, mailbox_id, conference_number) VALUES (?, ?, ?)')
            ->execute([777777, 666666, 1]);
    }, '23503');
    rejects(static function () use ($db, $area2, $ctx) {
        // area2/mbx1 is otherwise unused: the only violated constraint is the CHECK.
        $db->prepare('INSERT INTO echo_area_qwk_subscriptions (echoarea_id, mailbox_id, conference_number) VALUES (?, ?, ?)')
            ->execute([$area2, $ctx->mbx1, -1]);
    }, '23514');
};

$tests['subscription lookups work'] = static function () use ($subs, $area1, $ctx): void {
    check($subs->getSubscriptionForConference($ctx->mbx1, 1003)['tag'] === 'SEED_LOCAL', 'conference lookup join wrong');
    check(count($subs->getSubscriptionsForArea($area1)) === 1, 'getSubscriptionsForArea count wrong');
    check(count($subs->getSubscriptionsForMailbox($ctx->mbx1)) === 1, 'getSubscriptionsForMailbox count wrong');
};

$tests['deleting a mailbox cascades to its subscriptions'] = static function () use ($subs, $db, $mailboxes, $ctx): void {
    $mbx2 = $ctx->mbx2;
    check(count($subs->getSubscriptionsForMailbox($mbx2)) === 1, 'precondition: mbx2 has one subscription');
    check($mailboxes->delete($mbx2), 'delete() returned false');
    check((int)$db->query("SELECT count(*) FROM echo_area_qwk_subscriptions WHERE mailbox_id = $mbx2")->fetchColumn() === 0, 'subscriptions not cascaded');
};

$tests['no auto-create / placeholder-area behaviour exists'] = static function () use ($subs, $ctx): void {
    check(!method_exists($subs, 'getOrCreateSubscriptionForConference'), 'getOrCreateSubscriptionForConference still present');
    check(!method_exists('BinktermPHP\\EchoareaManager', 'createQwkPlaceholderArea'), 'EchoareaManager::createQwkPlaceholderArea reintroduced');
    check(!class_exists('BinktermPHP\\EchoareaProtocol'), 'EchoareaProtocol reintroduced');
    // Creating a subscription for a missing echoarea must fail, never create one.
    rejects(static fn() => $subs->create(424242, $ctx->mbx1, 7, 'x'), 'InvalidArgumentException');
};

// ---------- schema guards ----------

$tests['echoareas has no protocol column'] = static function () use ($db): void {
    $col = $db->query("SELECT 1 FROM information_schema.columns WHERE table_name = 'echoareas' AND column_name = 'protocol'")->fetchColumn();
    check($col === false, 'echoareas.protocol exists');
};

$tests['no out-of-scope QWK schema was introduced'] = static function () use ($db): void {
    foreach (['qwk_outbound_messages', 'qwk_inbound_packets', 'echo_area_gates', 'echo_area_relay_rules'] as $table) {
        check($db->query("SELECT to_regclass('public.$table')")->fetchColumn() === null, "table $table exists");
    }
    foreach (['relay_mode', 'qwk_mailbox_id', 'qwk_conference_number', 'source_msgid'] as $column) {
        $found = $db->query("SELECT 1 FROM information_schema.columns WHERE table_name = 'echoareas' AND column_name = '$column'")->fetchColumn();
        check($found === false, "echoareas.$column exists");
    }
};

$tests['qwk_mailboxes has exactly the expected columns'] = static function () use ($db): void {
    $cols = $db->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'qwk_mailboxes' ORDER BY column_name")
        ->fetchAll(PDO::FETCH_COLUMN);
    sort($cols);
    $expected = ['bbs_id', 'created_at', 'enabled', 'ftp_remote_path', 'host', 'id', 'last_error', 'last_polled_at',
        'name', 'passive_mode', 'password', 'poll_schedule', 'port', 'updated_at', 'username'];
    sort($expected);
    check($cols === $expected, 'qwk_mailboxes columns are ' . implode(',', $cols));
};

$tests['echo_area_qwk_subscriptions shape matches the design'] = static function () use ($db): void {
    $cols = $db->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'echo_area_qwk_subscriptions' ORDER BY column_name")
        ->fetchAll(PDO::FETCH_COLUMN);
    sort($cols);
    $expected = ['conference_number', 'conference_tag', 'created_at', 'echoarea_id', 'id', 'mailbox_id'];
    sort($expected);
    check($cols === $expected, 'columns are ' . implode(',', $cols));
    check(!in_array('auto_created', $cols, true), 'auto_created column should have been dropped');

    $nullable = $db->query("SELECT is_nullable FROM information_schema.columns WHERE table_name = 'echo_area_qwk_subscriptions' AND column_name = 'conference_tag'")->fetchColumn();
    check($nullable === 'YES', 'conference_tag should be nullable');

    $uniques = $db->query("
        SELECT string_agg(conname, ',' ORDER BY conname) FROM pg_constraint
        WHERE conrelid = 'echo_area_qwk_subscriptions'::regclass AND contype = 'u'
    ")->fetchColumn();
    check(str_contains((string)$uniques, 'mailbox_conf_key') && str_contains((string)$uniques, 'area_mailbox_key'),
        'expected both UNIQUE constraints, got ' . (string)$uniques);
};

$tests['pre-existing echoarea rows are untouched'] = static function () use ($db, $seededBefore): void {
    $after = $db->query("SELECT tag || '|' || COALESCE(domain,'') || '|' || is_local FROM echoareas ORDER BY tag")
        ->fetchAll(PDO::FETCH_COLUMN);
    check($after === $seededBefore, 'echoareas rows changed across the migration');
};

$failures = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo "PASS  $name\n";
    } catch (Throwable $e) {
        $failures++;
        echo "FAIL  $name  ::  " . $e->getMessage() . "\n";
    }
}

echo "\n" . count($tests) . " focused tests, " . $failures . " failed; disposable schema only, no application configuration loaded\n";
exit($failures === 0 ? 0 : 1);
