<?php
/** Authenticated shell around checksum-pinned, unmodified upstream Light Up. */
require_once __DIR__ . '/../_doorsdk/php/helpers.php';

$user = (new \BinktermPHP\Auth())->requireAuth();
$manifest = json_decode(file_get_contents(__DIR__ . '/webdoor.json'), true, 512, JSON_THROW_ON_ERROR);
header('Cache-Control: no-store');
if (!\BinktermPHP\GameConfig::isEnabled('tatham-web') ||
    (!empty($manifest['requirements']['admin_only']) && empty($user['is_admin']))) {
    http_response_code(403);
    exit;
}
$release = '20260911.428913c';
$shell = @file_get_contents(__DIR__ . '/assets/' . $release . '/lightup.html');
if ($shell === false) {
    http_response_code(503);
    exit;
}
$csrf = (new \BinktermPHP\UserMeta())->getValue((int)($user['user_id'] ?? $user['id']), 'csrf_token');
$translator = new \BinktermPHP\I18n\Translator();
$labels = [];
foreach (['save' => 'ui.common.save', 'return' => 'ui.webdoors.return',
    'loading' => 'ui.common.loading', 'saving' => 'ui.common.saving', 'saved' => 'ui.common.saved'] as $name => $key) {
    $labels[$name] = $translator->translate($key);
}
$labels['conflict'] = $translator->translate('errors.door.capacity_reached_detail', ['max_nodes' => 1], null, ['errors']);
$labels['error'] = $translator->translate('errors.webdoor.game_unavailable', [], null, ['errors']);
// Keep the upstream artifact intact on disk. Only asset URLs and host glue vary.
$glue = '<meta name="csrf-token" content="' . htmlspecialchars((string)$csrf, ENT_QUOTES, 'UTF-8') . '">'
    . '<meta name="tatham-labels" content="' . htmlspecialchars(json_encode($labels), ENT_QUOTES, 'UTF-8') . '">'
    . '<meta name="viewport" content="width=device-width, initial-scale=1">'
    . '<meta name="tatham-backend" content="lightup">'
    . '<meta name="tatham-puzzle-id" content="7x7:cBd0c1hBe2h1c0d0c">'
    . '<script defer src="tatham.js?v=slice3-1"></script>';
$shell = str_replace('<head>', '<head>' . $glue, $shell);
$shell = str_replace('src="lightup.js"', 'src="assets/' . $release . '/lightup.js"', $shell);
$shell = str_replace('</head>', '<link rel="stylesheet" href="tatham.css?v=slice2-1"></head>', $shell);
header('Content-Type: text/html; charset=UTF-8');
echo $shell;
