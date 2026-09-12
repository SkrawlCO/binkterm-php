<?php

/*
 * Everest WebDoor entry point.
 *
 * This is the ONLY server-side glue for the Everest WebDoor. It is a thin
 * auth/enablement gate in front of the UNMODIFIED canonical Everest Flutter
 * Web build (mwageringel/everest, pinned revision
 * 9459758dbe3c274e7fa81f68af591035a8cf00ce), served byte-for-byte from
 * assets/. Everest is a single-player puzzle: game state and save progress
 * are owned entirely by the client (its own canonical browser-local
 * persistence) -- there is no server-side game state to bridge, and no
 * host.js hook, because the canonical game code is not modified to call back
 * into a host. The player returns to Parlour via the standard WebDoor launch
 * chrome (templates/webdoor_play.twig), not an in-game control.
 */

require_once __DIR__ . '/../_doorsdk/php/helpers.php';

(new \BinktermPHP\Auth())->requireAuth();

header('Cache-Control: no-store');

if (!\BinktermPHP\GameConfig::isEnabled('everest')) {
    http_response_code(403);
    exit;
}

// Everest's compiled engine (main.dart.js) has a hardcoded default
// canvasKitBaseUrl pointing at https://www.gstatic.com/flutter-canvaskit/...
// -- an external CDN fetch that is unreachable from this deployment and
// leaves the Flutter canvas blank/dark while the surrounding host shell
// still renders fine. The canonical build already bundles CanvasKit locally
// under assets/canvaskit/; only the loader config -- glue we own, not
// upstream game code -- needs to point at it, the same class of fix as the
// already-accepted pinned-font substitution during the reproducible build.
$shell = (string)file_get_contents(__DIR__ . '/assets/index.html');
$shell = str_replace(
    'let config = {};  // auto-choose renderer',
    'let config = {canvasKitBaseUrl: "canvaskit/"};  // auto-choose renderer; local CanvasKit, no external CDN fetch',
    $shell
);

header('Content-Type: text/html; charset=UTF-8');
echo $shell;
