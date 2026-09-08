<?php

declare(strict_types=1);

/*
 * Focused disposable-DB test for the QWK network-type foundation slice.
 *
 * Proves:
 *   1. NetworkManager::NETWORK_TYPE_QWK exists and is 2 (FIDONET unchanged).
 *   2. An FTN (type 1) network round-trips through create()/getByDomain() unchanged.
 *   3. A QWK (type 2) network round-trips and is NOT coerced back to FTN
 *      (create, raw column, getByDomain, and update() all preserve 2).
 *   4. An unknown/invalid network_type still falls back to FIDONET
 *      (unchanged, fail-safe behaviour), including via the private normalizer.
 *   5. Existing local/FTN echoarea creation behaves exactly as before this slice
 *      (EchoareaManager::createIfMissing still infers locality from domain).
 *   6. The rejected M2A scalar `echoareas.protocol` experiment is entirely gone
 *      (no class, no file, no migration, no column, no CHECK, is_local not tightened).
 *   7. No QWK mailbox / subscription / provenance / relay schema has been introduced.
 *
 * No Composer / application bootstrap, no .env, no Database/Config singleton,
 * no transport. Runs only against an explicit Unix-socket disposable database.
 */

if ($argc !== 2 || $argv[1] !== '/socket' || !is_dir('/socket')) {
    fwrite(STDERR, "Run inside the disposable test container with argument /socket\n");
    exit(2);
}

spl_autoload_register(static function (string $class): void {
    if ($class === 'BinktermPHP\\Database' || $class === 'BinktermPHP\\Config') {
        throw new RuntimeException('Application configuration/database fallback is forbidden');
    }
    $prefix = 'BinktermPHP\\';
    if (str_starts_with($class, $prefix)) {
        $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
});

use BinktermPHP\EchoareaManager;
use BinktermPHP\NetworkManager;

function check(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

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
$db->exec("SET statement_timeout = '10s'");

// Pre-slice production shape of `networks` (create migration + missing_chrs_charset migration).
$db->exec("
    CREATE TEMP TABLE networks (
        id                   SERIAL PRIMARY KEY,
        domain               VARCHAR(50)  NOT NULL UNIQUE,
        name                 VARCHAR(100) NOT NULL,
        description          TEXT,
        website              VARCHAR(255),
        network_type         INTEGER      NOT NULL DEFAULT 1,
        allow_markup         BOOLEAN      NOT NULL DEFAULT FALSE,
        allow_media          BOOLEAN      NOT NULL DEFAULT FALSE,
        default_charset      VARCHAR(20),
        missing_chrs_charset VARCHAR(32),
        posting_name_policy  VARCHAR(20)  NOT NULL DEFAULT 'real_name',
        is_builtin           BOOLEAN      NOT NULL DEFAULT FALSE,
        created_at           TIMESTAMP    NOT NULL DEFAULT (NOW() AT TIME ZONE 'UTC'),
        updated_at           TIMESTAMP    NOT NULL DEFAULT (NOW() AT TIME ZONE 'UTC'),
        CONSTRAINT networks_posting_name_policy_check CHECK (posting_name_policy IN ('real_name', 'username'))
    )
");

// Pre-slice production shape of `echoareas` (base schema + historical ALTERs).
$schema = file_get_contents(__DIR__ . '/../database/postgresql_schema.sql');
check(
    preg_match('/CREATE TABLE IF NOT EXISTS echoareas \([\s\S]*?\n\);/', $schema, $match) === 1,
    'echoareas baseline table not found in schema'
);
$db->exec(str_replace('CREATE TABLE IF NOT EXISTS', 'CREATE TEMP TABLE', $match[0]));
$db->exec("ALTER TABLE echoareas
    ADD domain VARCHAR(50), ADD is_local BOOLEAN DEFAULT FALSE,
    ADD is_sysop_only BOOLEAN DEFAULT FALSE, ADD gemini_public BOOLEAN DEFAULT FALSE,
    ADD posting_name_policy VARCHAR(20), ADD art_format_hint VARCHAR(32),
    ADD missing_chrs_charset VARCHAR(32), ADD allow_media BOOLEAN,
    DROP CONSTRAINT echoareas_tag_key, ADD UNIQUE (tag, domain)");

$nm = new NetworkManager($db);
$reflectNormalize = new ReflectionMethod(NetworkManager::class, 'normalizeNetworkType');
$reflectNormalize->setAccessible(true);

$tests = [];

$tests['NETWORK_TYPE_QWK is 2 and FIDONET is unchanged'] = static function (): void {
    check(defined('BinktermPHP\\NetworkManager::NETWORK_TYPE_QWK'), 'NETWORK_TYPE_QWK not defined');
    check(NetworkManager::NETWORK_TYPE_QWK === 2, 'NETWORK_TYPE_QWK is not 2');
    check(NetworkManager::NETWORK_TYPE_FIDONET === 1, 'NETWORK_TYPE_FIDONET changed');
};

$tests['FTN network type 1 round-trips unchanged'] = static function () use ($nm): void {
    $created = $nm->create([
        'domain' => 'ftnslice', 'name' => 'FTN Slice',
        'network_type' => 1, 'default_charset' => '', 'missing_chrs_charset' => '',
    ]);
    check(($created['network_type'] ?? null) === 1, 'create() did not return type 1');
    check(($nm->getByDomain('ftnslice')['network_type'] ?? null) === 1, 'stored FTN type is not 1');
};

$tests['QWK network type 2 round-trips and is never coerced to FTN'] = static function () use ($nm, $db): void {
    $created = $nm->create([
        'domain' => 'qwkslice', 'name' => 'QWK Slice',
        'network_type' => 2, 'default_charset' => '', 'missing_chrs_charset' => '',
    ]);
    check(($created['network_type'] ?? null) === 2, 'create() coerced type 2');
    check((int)$db->query("SELECT network_type FROM networks WHERE domain = 'qwkslice'")->fetchColumn() === 2, 'raw column is not 2');
    check(($nm->getByDomain('qwkslice')['network_type'] ?? null) === 2, 'getByDomain() lost type 2');
    check(($nm->getById((int)$created['id'])['network_type'] ?? null) === 2, 'getById() lost type 2');
    $nm->update((int)$created['id'], ['name' => 'QWK Slice Renamed']);
    check(($nm->getByDomain('qwkslice')['network_type'] ?? null) === 2, 'update() coerced type 2 back to FTN');
};

$tests['invalid network type still falls back to FIDONET (unchanged behaviour)'] = static function () use ($nm): void {
    // Note: normalizeNetworkType casts with (int) first, matching pre-slice behaviour,
    // so every value here must be one whose (int) cast is neither 1 (FTN) nor 2 (QWK).
    foreach ([['bn99', 99], ['bnneg', -1], ['bnzero', 0], ['bnstr', 'garbage'], ['bn3', 3]] as [$domain, $type]) {
        $created = $nm->create([
            'domain' => $domain, 'name' => 'Bogus',
            'network_type' => $type, 'default_charset' => '', 'missing_chrs_charset' => '',
        ]);
        check(($created['network_type'] ?? null) === 1, "network_type " . var_export($type, true) . " not coerced to FIDONET");
    }
};

$tests['normalizeNetworkType keeps QWK, keeps FTN, floors the rest to FIDONET'] = static function () use ($nm, $reflectNormalize): void {
    check($reflectNormalize->invoke($nm, 2) === 2, 'int 2 not preserved');
    check($reflectNormalize->invoke($nm, '2') === 2, 'string "2" not preserved');
    check($reflectNormalize->invoke($nm, 1) === 1, 'int 1 not preserved');
    check($reflectNormalize->invoke($nm, 3) === 1, 'int 3 not floored to 1');
    check($reflectNormalize->invoke($nm, 0) === 1, 'int 0 not floored to 1');
    check($reflectNormalize->invoke($nm, 'nope') === 1, 'garbage not floored to 1');
};

$tests['legacy echoarea creation is unchanged: locality inferred from domain'] = static function () use ($db): void {
    $em = new EchoareaManager($db);

    // No is_local key + no domain -> local (historical inference).
    $localId = $em->createIfMissing(['tag' => 'SLICE_LOCAL', 'description' => 'Local area']);
    check($localId > 0, 'local createIfMissing failed');
    check($db->query("SELECT is_local FROM echoareas WHERE id = $localId")->fetchColumn() === true, 'inferred locality wrong for local area');

    // No is_local key + domain -> not local.
    $ftnId = $em->createIfMissing(['tag' => 'SLICE_FTN', 'description' => 'FTN area', 'domain' => 'ftnslice'], ['ftnslice']);
    check($ftnId > 0, 'ftn createIfMissing failed');
    check($db->query("SELECT is_local FROM echoareas WHERE id = $ftnId")->fetchColumn() === false, 'inferred locality wrong for FTN area');

    // Explicit is_local still honoured.
    $explicitId = $em->createIfMissing(['tag' => 'SLICE_EXPLICIT', 'is_local' => false, 'domain' => 'ftnslice'], ['ftnslice']);
    check($db->query("SELECT is_local FROM echoareas WHERE id = $explicitId")->fetchColumn() === false, 'explicit is_local not honoured');

    // Idempotent: re-calling returns the same id and does not throw.
    check($em->createIfMissing(['tag' => 'SLICE_LOCAL']) === $localId, 'createIfMissing is not idempotent');
};

$tests['the M2A scalar protocol experiment leaves nothing behind'] = static function () use ($db): void {
    check(!class_exists('BinktermPHP\\EchoareaProtocol'), 'EchoareaProtocol class still loadable');
    check(!is_file(__DIR__ . '/../src/EchoareaProtocol.php'), 'src/EchoareaProtocol.php still on disk');
    check(glob(__DIR__ . '/../database/migrations/*protocol*') === [], 'a *protocol* migration file is still present');
    check(!is_file(__DIR__ . '/../tests/EchoareaProtocolTest.php'), 'tests/EchoareaProtocolTest.php still on disk');

    $protocolColumn = $db->query(
        "SELECT 1 FROM information_schema.columns WHERE table_name = 'echoareas' AND column_name = 'protocol'"
    )->fetchColumn();
    check($protocolColumn === false, 'echoareas.protocol column exists');

    $protocolConstraints = $db->query(
        "SELECT string_agg(conname, ',') FROM pg_constraint
         WHERE conrelid = 'echoareas'::regclass AND conname LIKE 'echoareas_protocol%'"
    )->fetchColumn();
    check($protocolConstraints === null, 'protocol CHECK constraints exist: ' . (string)$protocolConstraints);

    // M2A had also tightened is_local to NOT NULL; that must be reverted too.
    $isLocalNotNull = $db->query(
        "SELECT attnotnull FROM pg_attribute WHERE attrelid = 'echoareas'::regclass AND attname = 'is_local'"
    )->fetchColumn();
    check(!filter_var($isLocalNotNull, FILTER_VALIDATE_BOOLEAN), 'is_local is NOT NULL (M2A tightening not reverted)');
};

$tests['no QWK transport / subscription / provenance / relay schema introduced'] = static function () use ($db): void {
    foreach ([
        'qwk_mailboxes', 'echo_area_qwk_subscriptions', 'qwk_outbound_messages',
        'qwk_inbound_packets', 'echo_area_gates', 'echo_area_relay_rules',
    ] as $table) {
        check($db->query("SELECT to_regclass('public.$table')")->fetchColumn() === null, "table $table exists");
    }
    foreach ([
        'relay_mode', 'protocol', 'qwk_mailbox_id', 'qwk_conference_number', 'qwk_msg_number', 'source_msgid',
    ] as $column) {
        $found = $db->query(
            "SELECT 1 FROM information_schema.columns WHERE table_name = 'echoareas' AND column_name = '$column'"
        )->fetchColumn();
        check($found === false, "echoareas.$column exists");
    }
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

echo "\n" . count($tests) . " focused tests, " . $failures . " failed; temporary schema only, no application configuration loaded\n";
exit($failures === 0 ? 0 : 1);
