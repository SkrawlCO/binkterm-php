#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\Database;
use BinktermPHP\Qwk\QwkPoller;

$flags = [];
$positional = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        $flags[$arg] = true;
    } else {
        $positional[] = $arg;
    }
}

if (isset($flags['--help']) || count($positional) !== 1 || !ctype_digit((string)$positional[0]) || (int)$positional[0] <= 0) {
    fwrite(STDERR, <<<TXT
Usage: php scripts/qwknet_poll.php <mailbox-id> [--dry-run] [--no-download] [--no-upload]

Runs one QWKnet exchange for the mailbox: download <BBSID>.QWK and import it,
then build and upload the outbound <BBSID>.REP for anything queued.

  --dry-run       build the REP if there is pending outbound, but do not upload
  --no-download   skip the inbound download/import step
  --no-upload     alias for --dry-run

Prints a JSON summary (counts and states only -- never the mailbox password).
Exit 0 on success, 1 if any step reported an error.

TXT);
    exit(2);
}

$mailboxId = (int)$positional[0];
$upload = !isset($flags['--dry-run']) && !isset($flags['--no-upload']);
$download = !isset($flags['--no-download']);

try {
    $logger = static function (string $line): void {
        fwrite(STDERR, $line . "\n");
    };
    $result = (new QwkPoller(Database::getInstance()->getPdo(), null, null, null, $logger))
        ->poll($mailboxId, ['download' => $download, 'upload' => $upload]);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit($result['status'] === 'ok' ? 0 : 1);
} catch (\Throwable $e) {
    fwrite(STDERR, 'QWK poll failed: ' . $e->getMessage() . "\n");
    exit(1);
}
