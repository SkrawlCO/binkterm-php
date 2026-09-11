<?php
/** Staged/unrouted endpoint. The local host must explicitly mount it for review. */
require_once dirname(__DIR__, 3) . '/public_html/webdoors/_doorsdk/php/helpers.php';
header('Cache-Control: no-store');
$user = (new \BinktermPHP\Auth())->requireAuth();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
require_once __DIR__ . '/Storage.php';
try {
    $body = file_get_contents('php://input', false, null, 0, 108001);
    if (strlen($body) > 108000) throw new \LengthException();
    $input = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
    $storage = new \BreakLock\Storage(\WebDoorSDK\getDatabase(), (int)($user['user_id'] ?? $user['id']));
    \WebDoorSDK\jsonResponse($storage->request($input));
} catch (\Throwable $error) {
    \WebDoorSDK\jsonResponse(['success' => false, 'reason' => 'input_or_storage'], 400);
}
