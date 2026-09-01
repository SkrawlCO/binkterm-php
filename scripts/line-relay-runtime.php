#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\LineRelayRuntime;

if ($argc !== 4) {
    fwrite(STDERR, "Usage: line-relay-runtime.php <door-id> <user-id> <session-id>\n");
    exit(64);
}

try {
    exit((new LineRelayRuntime())->run($argv[1], (int)$argv[2], $argv[3], STDIN, STDOUT));
} catch (Throwable $e) {
    fwrite(STDERR, 'Line relay failed: ' . $e->getMessage() . "\n");
    exit(1);
}
