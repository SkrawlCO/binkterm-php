<?php
require_once __DIR__ . '/../_doorsdk/php/helpers.php';
$user = (new \BinktermPHP\Auth())->requireAuth();
header('Cache-Control: no-store');
if (!\BinktermPHP\GameConfig::isEnabled('wordwright')) { http_response_code(403); exit; }
$csrf = (new \BinktermPHP\UserMeta())->getValue((int)($user['user_id'] ?? $user['id']), 'csrf_token');
$config = json_encode(['endpoint' => '/webdoors/wordwright/api.php', 'csrfToken' => (string)$csrf], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$shell = file_get_contents(__DIR__ . '/assets/index.html');
$shell = str_replace('<head>', '<head><base href="/webdoors/wordwright/assets/"><script>window.wordwrightHost=' . $config . ';</script><script src="/webdoors/wordwright/host.js"></script>', $shell);
header('Content-Type: text/html; charset=UTF-8');
echo $shell;
