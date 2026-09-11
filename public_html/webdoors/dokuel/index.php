<?php
require_once __DIR__ . '/../_doorsdk/php/helpers.php';
$user = (new \BinktermPHP\Auth())->requireAuth();
header('Cache-Control: no-store');
if (!\BinktermPHP\GameConfig::isEnabled('dokuel')) { http_response_code(403); exit; }
$csrf = (new \BinktermPHP\UserMeta())->getValue((int)($user['user_id'] ?? $user['id']), 'csrf_token');
$config = json_encode(['endpoint' => '/webdoors/dokuel/api.php', 'csrfToken' => (string)$csrf], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$shell = file_get_contents(__DIR__ . '/assets/index.html');
$shell = str_replace('<head>', '<head><base href="/webdoors/dokuel/assets/"><script>window.dokuelHost=' . $config . ';</script><script src="/webdoors/dokuel/host.js"></script>', $shell);
header('Content-Type: text/html; charset=UTF-8');
echo $shell;
