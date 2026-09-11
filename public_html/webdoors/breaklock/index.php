<?php
/** Authenticated production shell; the bundle consumes the shared round module. */
require_once __DIR__ . '/../_doorsdk/php/helpers.php';
$user = (new \BinktermPHP\Auth())->requireAuth();
header('Cache-Control: no-store');
if (!\BinktermPHP\GameConfig::isEnabled('breaklock')) { http_response_code(403); exit; }
$csrf = (new \BinktermPHP\UserMeta())->getValue((int)($user['user_id'] ?? $user['id']), 'csrf_token');
$shell = file_get_contents(__DIR__ . '/assets/index.html');
$config = json_encode(['endpoint' => '/webdoors/breaklock/api.php', 'csrfToken' => (string)$csrf], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$shell = str_replace('<base href="./">', '<base href="/webdoors/breaklock/assets/">', $shell);
$shell = str_replace('<script src="app.js">', '<script>window.breaklockStorage=' . $config . ';</script><script src="/webdoors/breaklock/host.js"></script><script src="app.js">', $shell);
header('Content-Type: text/html; charset=UTF-8');
echo $shell;
