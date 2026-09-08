#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\Database;
use BinktermPHP\Qwk\QwkImportException;
use BinktermPHP\Qwk\QwkInbound;

$args = array_values(array_filter(array_slice($argv, 1), static fn($a) => $a !== ''));

if (in_array('--help', $args, true) || in_array('-h', $args, true) || count($args) !== 2) {
    fwrite(STDERR, <<<TXT
Usage: php scripts/qwknet_import.php <mailbox-id> <local-packet.qwk>

Imports a QWK archive that has already been obtained by other means into the
echo areas explicitly mapped to that mailbox's conferences.

  - local file only: no FTP, no polling, no scheduler
  - the packet's CONTROL.DAT BBS ID must match the mailbox's bbs_id
  - re-importing a byte-identical packet is a safe no-op
  - messages in unmapped conferences are counted and skipped, never auto-created
  - never prints or requires the mailbox password

Prints a JSON result to stdout. Exit 0 on success (including "already_imported"),
1 on failure, 2 on usage error.

TXT);
    exit(2);
}

[$mailboxIdArg, $packetPath] = $args;
if (!ctype_digit($mailboxIdArg) || (int)$mailboxIdArg <= 0) {
    fwrite(STDERR, "mailbox-id must be a positive integer\n");
    exit(2);
}

try {
    $result = (new QwkInbound(Database::getInstance()->getPdo()))
        ->importLocalPacket((int)$mailboxIdArg, $packetPath);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
} catch (QwkImportException $e) {
    fwrite(STDERR, 'QWK import refused: ' . $e->getMessage() . "\n");
    exit(1);
} catch (\Throwable $e) {
    fwrite(STDERR, 'QWK import failed: ' . $e->getMessage() . "\n");
    exit(1);
}
