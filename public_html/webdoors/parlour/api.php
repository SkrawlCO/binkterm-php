<?php
require_once __DIR__ . '/../_doorsdk/php/helpers.php';
header('Cache-Control: no-store');
if (!\BinktermPHP\GameConfig::isEnabled('parlour')) { http_response_code(403); exit; }
require dirname(__DIR__, 3) . '/shared/parlour/persistence/api.php';
