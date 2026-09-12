#!/usr/bin/env php
<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || $argc !== 1 || !preg_match('/^[1-9][0-9]{0,9}$/D', getenv('DOOR_USER_NUMBER') ?: '')) exit(2);
require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
require_once dirname(__DIR__, 3) . '/src/functions.php';
require_once __DIR__ . '/Storage.php';
$db = \BinktermPHP\Database::getInstance()->getPdo();
$caller = (int)getenv('DOOR_USER_NUMBER');
$query = $db->prepare('SELECT id FROM users WHERE id = ? AND is_active = TRUE');
$query->execute([$caller]);
if (!$query->fetchColumn()) exit(2);
$storage = new \Parlour\Storage($db, $caller);
$owner = null;
try {
    while (($line = fgets(STDIN, 110000)) !== false) {
        try {
            if (!str_ends_with($line, "\n") || strlen($line) > 108000) throw new \InvalidArgumentException();
            $input = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
            $result = $storage->request($input);
            if (($input['action'] ?? '') === 'acquire' && $result['success']) $owner = $result['owner_token'];
            if (($input['action'] ?? '') === 'release' && $result['success']) $owner = null;
        } catch (\Throwable $error) { $result = ['success' => false, 'reason' => 'input_or_storage']; }
        echo json_encode($result, JSON_THROW_ON_ERROR), "\n"; flush();
    }
} finally {
    // EOF releases the last checkpoint; uncatchable process death falls back to lease expiry.
    if ($owner !== null) $storage->request(['action' => 'release', 'owner_token' => $owner]);
}
