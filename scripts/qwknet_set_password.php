#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Interactively set (or replace) the FTP password for a QWK mailbox.
 *
 * The password is read with terminal echo disabled, encrypted immediately with
 * BinktermPHP\SysK (libsodium secretbox), and written to
 * qwk_mailboxes.password. It is never taken from argv, never printed, never
 * logged, and never written anywhere in plaintext.
 *
 *   php scripts/qwknet_set_password.php <mailbox-id>
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\Database;
use BinktermPHP\SysK;

$args = array_slice($argv, 1);
if (count($args) !== 1 || !ctype_digit((string)$args[0]) || (int)$args[0] <= 0) {
    fwrite(STDERR, "Usage: php scripts/qwknet_set_password.php <mailbox-id>\n");
    exit(2);
}
$mailboxId = (int)$args[0];

if (!SysK::isConfigured()) {
    fwrite(STDERR, "SysK is not configured (SYSK_KEY / SYSK_KEY_FILE). Cannot store an encrypted password.\n");
    exit(1);
}

$db = Database::getInstance()->getPdo();
$stmt = $db->prepare('SELECT id, name, bbs_id, host, username FROM qwk_mailboxes WHERE id = ?');
$stmt->execute([$mailboxId]);
$mailbox = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$mailbox) {
    fwrite(STDERR, "QWK mailbox {$mailboxId} not found.\n");
    exit(1);
}

fwrite(STDERR, sprintf(
    "Setting FTP password for mailbox #%d  %s  (bbs_id=%s  host=%s  user=%s)\n",
    $mailbox['id'],
    $mailbox['name'],
    $mailbox['bbs_id'],
    $mailbox['host'],
    $mailbox['username']
));

$readHidden = static function (string $prompt): string {
    fwrite(STDERR, $prompt);
    $usedStty = false;
    if (function_exists('shell_exec') && stripos(PHP_OS, 'WIN') === false && trim((string)@shell_exec('command -v stty')) !== '') {
        @shell_exec('stty -echo 2>/dev/null');
        $usedStty = true;
    }
    $line = fgets(STDIN);
    if ($usedStty) {
        @shell_exec('stty echo 2>/dev/null');
    }
    fwrite(STDERR, "\n");
    if (!$usedStty) {
        fwrite(STDERR, "  (warning: could not disable terminal echo)\n");
    }
    return rtrim((string)$line, "\r\n");
};

$first = $readHidden('New password: ');
if ($first === '') {
    fwrite(STDERR, "Empty password -- aborted, nothing changed.\n");
    exit(1);
}
$second = $readHidden('Confirm password: ');
if ($first !== $second) {
    fwrite(STDERR, "Passwords did not match -- aborted, nothing changed.\n");
    // best-effort wipe
    if (function_exists('sodium_memzero')) {
        sodium_memzero($first);
        sodium_memzero($second);
    }
    exit(1);
}

$encrypted = SysK::encrypt($first);
if (function_exists('sodium_memzero')) {
    sodium_memzero($first);
    sodium_memzero($second);
}

$db->prepare('UPDATE qwk_mailboxes SET password = ?, updated_at = NOW() WHERE id = ?')
    ->execute([$encrypted, $mailboxId]);

fwrite(STDERR, "Stored encrypted password for mailbox #{$mailboxId}.\n");
exit(0);
