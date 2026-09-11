<?php
/** Door-local, authenticated and CSRF-protected progress operations only. */
require_once __DIR__ . '/../_doorsdk/php/helpers.php';

header('Cache-Control: no-store');
$user = (new \BinktermPHP\Auth())->requireAuth(); // Includes CSRF validation on POST.
$respondError = static function (int $status, string $reason): never {
    $code = 'errors.webdoor.game_unavailable';
    \WebDoorSDK\jsonResponse(['success' => false, 'error_code' => $code,
        'error' => (new \BinktermPHP\I18n\Translator())->translate($code, [], null, ['errors']),
        'reason' => $reason], $status);
};
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    $respondError(405, 'method');
}
if (!\BinktermPHP\GameConfig::isEnabled('tatham-web')) {
    $respondError(403, 'unavailable');
}
try {
    $body = file_get_contents('php://input', false, null, 0, 102401);
    if (strlen($body) > 102400) {
        $respondError(413, 'size');
    }
    $input = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($input) || !is_string($input['action'] ?? null)) {
        $respondError(400, 'input');
    }
    $service = new \BinktermPHP\TathamProgressService(new \BinktermPHP\LeasedWebDoorStorage(
        \WebDoorSDK\getDatabase(), (int)($user['user_id'] ?? $user['id'])
    ));
    $action = $input['action'];
    if ($action !== 'acquire' && (!is_string($input['owner_token'] ?? null) ||
        !preg_match('/^[a-f0-9]{64}$/D', $input['owner_token']))) {
        $respondError(400, 'owner');
    }
    if ($action === 'save' && (!is_string($input['attempt_id'] ?? null) ||
        !is_int($input['revision'] ?? null) || $input['revision'] < 0 ||
        !is_string($input['payload'] ?? null))) {
        $respondError(400, 'input');
    }
    $result = match ($action) {
        'acquire' => $service->acquire(),
        'renew' => $service->renew($input['owner_token']),
        'release' => $service->release($input['owner_token']),
        'save' => $service->save($input['owner_token'], $input['attempt_id'], $input['revision'], $input['payload']),
        default => null,
    };
    if ($result === null) {
        $respondError(400, 'action');
    }
    if (!$result['success']) {
        $respondError(409, 'conflict');
    }
    \WebDoorSDK\jsonResponse($result);
} catch (\JsonException | \InvalidArgumentException | \LengthException $error) {
    $respondError(422, 'invalid_save');
} catch (\Throwable $error) {
    $respondError(503, 'unavailable');
}
