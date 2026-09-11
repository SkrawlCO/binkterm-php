#!/usr/bin/env php
<?php
/** Local launch-scoped JSON-lines facade; only PHP reads database configuration. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || $argc !== 1 ||
    !preg_match('/^[1-9][0-9]{0,9}$/D', getenv('DOOR_USER_NUMBER') ?: '')) {
    echo "{\"success\":false,\"reason\":\"identity\"}\n";
    exit(2);
}
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

$owner = null;
$attempt = null;
try {
    $db = \BinktermPHP\Database::getInstance()->getPdo();
    $caller = (int)getenv('DOOR_USER_NUMBER');
    $check = $db->prepare('SELECT id FROM users WHERE id = ? AND is_active = TRUE');
    $check->execute([$caller]);
    if (!$check->fetchColumn()) {
        echo "{\"success\":false,\"reason\":\"identity\"}\n";
        exit(2);
    }
    $service = new \BinktermPHP\TathamProgressService(new \BinktermPHP\LeasedWebDoorStorage($db, $caller));
    while (($line = fgets(STDIN, 102402)) !== false) {
        try {
            if (!str_ends_with($line, "\n") || strlen($line) > 102400) {
                throw new \InvalidArgumentException();
            }
            $input = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($input) || array_key_exists('user_id', $input) || array_key_exists('owner_token', $input)) {
                throw new \InvalidArgumentException();
            }
            $action = $input['action'] ?? '';
            if ($action === 'acquire') {
                $result = $owner === null ? $service->acquire() : ['success' => false, 'reason' => 'conflict'];
                if ($result['success']) {
                    $owner = $result['owner_token'];
                    $attempt = $result['attempt_id'];
                    unset($result['owner_token']); // Secret stays in this helper process.
                }
            } elseif ($owner === null) {
                $result = ['success' => false, 'reason' => 'ownership'];
            } elseif ($action === 'renew') {
                $result = $service->renew($owner);
            } elseif ($action === 'release') {
                $result = $service->release($owner);
                if ($result['success']) $owner = null;
            } elseif ($action === 'save' && is_int($input['revision'] ?? null) &&
                $input['revision'] >= 0 && is_string($input['payload'] ?? null)) {
                $result = $service->save($owner, $attempt, $input['revision'], $input['payload']);
            } else {
                throw new \InvalidArgumentException();
            }
        } catch (\JsonException | \InvalidArgumentException | \LengthException $error) {
            $result = ['success' => false, 'reason' => 'input'];
        } catch (\Throwable $error) {
            $result = ['success' => false, 'reason' => 'unavailable'];
        }
        echo json_encode($result, JSON_THROW_ON_ERROR), "\n";
        flush();
    }
} catch (\Throwable $error) {
    echo "{\"success\":false,\"reason\":\"unavailable\"}\n";
    exit(1);
}
