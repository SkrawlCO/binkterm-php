<?php

/*
 * Chessmata WebDoor entry point (Crossroads Experience #4, graphical Web surface).
 *
 * This is the ONLY server-rendered glue for the Chessmata WebDoor. It is a thin
 * L33TEST bootstrap around the UNMODIFIED official upstream Chessmata SPA (served
 * by the sibling `chessmata` container at the same-origin /chessmata/ path). It:
 *
 *   1. FAILS CLOSED on identity. Requires an authenticated caller with a
 *      resolvable BinkTerm user id, the game system enabled, and the WebDoor
 *      enabled; access level follows webdoor.json `requirements.admin_only`
 *      (the same manifest-authoritative gate GameCatalog and
 *      routes/webdoor-routes.php use). Otherwise HTTP 403 and no hand-off.
 *   2. Serves a tiny TOKEN-FREE bootstrap page. The browser JS then does a
 *      same-origin POST to web-credential.php for a short-lived Chessmata
 *      JWT (never the durable API key, never in this HTML, never in a URL),
 *      writes it into the upstream SPA's own localStorage key, and enters the
 *      SPA at /chessmata/ -- which comes up already authenticated as the
 *      caller's existing Chessmata account (the ChessmataIdentity mapping the
 *      Telnet surface also uses).
 *   3. Joins the normal BinkTermPHP WebDoor session lifecycle (Crossroads
 *      presence) via the shared bootstrap script.
 *
 * The graphical board, menus and gameplay are 100% the upstream Chessmata SPA.
 * Nothing here is a BinkTerm chess UI.
 */

require_once __DIR__ . '/../_doorsdk/php/helpers.php';

use BinktermPHP\Auth;
use BinktermPHP\GameConfig;

const CHESSMATA_GAME_ID = 'chessmata-web';

/** Emit a bare 403 and stop. The reason is recorded to the WebDoor's own log;
 *  it is never sent to the client. */
function chessmata_forbidden(string $why): void
{
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo "Forbidden.\n";
    \WebDoorSDK\log('chessmata-web', 'web launch refused: ' . $why, 'WARNING');
    exit;
}

$auth = new Auth();
$user = $auth->getCurrentUser();

// (1) Fail closed on identity. Defence in depth over the /games/chessmata route
// (which already redirects anonymous users and applies the same manifest gate).
if (!is_array($user)) {
    chessmata_forbidden('no authenticated user');
}
$userId = (int)($user['user_id'] ?? $user['id'] ?? 0);
if ($userId <= 0) {
    chessmata_forbidden('unresolvable user id');
}
if (!GameConfig::isGameSystemEnabled() || !GameConfig::isEnabled(CHESSMATA_GAME_ID)) {
    chessmata_forbidden('chessmata WebDoor is not enabled');
}

$manifest = json_decode((string)@file_get_contents(__DIR__ . '/webdoor.json'), true);
if (!empty($manifest['requirements']['admin_only']) && empty($user['is_admin'])) {
    chessmata_forbidden('user ' . $userId . ' is not an administrator (webdoor.json admin_only)');
}

// (2) Serve the token-free bootstrap page.
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="referrer" content="no-referrer">
<title>Chessmata</title>
<style>
  html,body{height:100%;margin:0;background:#0f1115;color:#c9d1d9;
    font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
  .wrap{height:100%;display:flex;align-items:center;justify-content:center;text-align:center}
  .card{max-width:34rem;padding:2rem}
  .spinner{width:2.25rem;height:2.25rem;margin:0 auto 1rem;border:3px solid #30363d;
    border-top-color:#58a6ff;border-radius:50%;animation:spin .9s linear infinite}
  @keyframes spin{to{transform:rotate(360deg)}}
  .err{color:#f85149}
  a{color:#58a6ff}
</style>
</head>
<body>
<div class="wrap">
  <div class="card" id="status">
    <div class="spinner"></div>
    <p>Connecting you to Chessmata&hellip;</p>
  </div>
</div>
<script src="bootstrap.js"></script>
</body>
</html>
