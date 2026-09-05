#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * gb_identity_cli.php
 *
 * Thin CLI boundary between the Galactic Bloodshed native-door launcher
 * (a Python process -- see docs/Crossroads/galactic-bloodshed-backend/
 * provisioning/gb_launcher.py) and BinktermPHP\Crossroads\GalacticBloodshedIdentity.
 * All crypto and database state live in that PHP class; this script only
 * marshals its calls to/from JSON on stdout so a non-PHP launcher process can
 * drive it. Decrypted credentials are written ONLY to this process's stdout,
 * captured by the launcher over a pipe (never a command-line argument to any
 * other process, never a file, never a log line).
 *
 * Usage:
 *   gb_identity_cli.php resolve <binkterm_user_id>
 *   gb_identity_cli.php confirm <binkterm_user_id> <attempt_token> <gb_playernum>
 *   gb_identity_cli.php fail    <binkterm_user_id> <attempt_token>
 *
 * Exit codes: 0 success, 1 hard error, 2 provisioning already in progress
 * elsewhere (transient -- caller should tell the user to retry shortly).
 * On every path, stdout is exactly one JSON object and nothing else.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/functions.php';

use BinktermPHP\Crossroads\GalacticBloodshedIdentity;
use BinktermPHP\Crossroads\GalacticBloodshedProvisioningInProgress;

function gb_cli_fail(string $message, int $code = 1): never
{
    fwrite(STDOUT, json_encode(['status' => 'error', 'message' => $message], JSON_UNESCAPED_SLASHES) . "\n");
    exit($code);
}

$argv = $_SERVER['argv'];
$command = $argv[1] ?? '';

try {
    $identity = new GalacticBloodshedIdentity();

    switch ($command) {
        case 'resolve':
            $userId = (int)($argv[2] ?? 0);
            if ($userId <= 0) {
                gb_cli_fail('resolve requires a positive binkterm_user_id');
            }
            $result = $identity->resolve($userId);
            echo json_encode($result, JSON_UNESCAPED_SLASHES), "\n";
            break;

        case 'confirm':
            $userId = (int)($argv[2] ?? 0);
            $token = (string)($argv[3] ?? '');
            $playernum = (int)($argv[4] ?? -1);
            if ($userId <= 0 || $token === '' || $playernum < 0) {
                gb_cli_fail('confirm requires <binkterm_user_id> <attempt_token> <gb_playernum>');
            }
            $identity->confirmProvisioned($userId, $token, $playernum);
            echo json_encode(['status' => 'ok'], JSON_UNESCAPED_SLASHES), "\n";
            break;

        case 'fail':
            $userId = (int)($argv[2] ?? 0);
            $token = (string)($argv[3] ?? '');
            if ($userId <= 0 || $token === '') {
                gb_cli_fail('fail requires <binkterm_user_id> <attempt_token>');
            }
            $identity->failProvisioning($userId, $token);
            echo json_encode(['status' => 'ok'], JSON_UNESCAPED_SLASHES), "\n";
            break;

        default:
            gb_cli_fail('usage: gb_identity_cli.php resolve|confirm|fail ...');
    }
} catch (GalacticBloodshedProvisioningInProgress $e) {
    gb_cli_fail($e->getMessage(), 2);
} catch (\Throwable $e) {
    gb_cli_fail($e->getMessage(), 1);
}
