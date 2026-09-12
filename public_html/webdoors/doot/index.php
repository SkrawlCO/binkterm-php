<?php

/*
 * Doot Games WebDoor entry point (Crossroads Curated Experience).
 *
 * This is the ONLY server-side glue for the Doot Games WebDoor. It is a thin
 * L33TEST bootstrap around the UNMODIFIED canonical Doot Games app (Nuxt/Nitro
 * production build, self-hosted, served by the loopback `doot-app` supervisor
 * program at the same-origin /doot-app/ path -- see
 * docs/Crossroads/doot-backend/README.md). It:
 *
 *   1. FAILS CLOSED on identity. Requires an authenticated caller and the
 *      WebDoor enabled; access level follows webdoor.json
 *      `requirements.admin_only` (the same manifest-authoritative gate
 *      GameCatalog and routes/webdoor-routes.php use). Otherwise HTTP 403 and
 *      no hand-off.
 *   2. Redirects into /doot-app/ -- Doot's own SSR routing takes over from
 *      there (host/join/room/game pages). No BinkTerm route duplicates any
 *      of Doot's pages, and no game logic lives here.
 *
 * Doot itself requires no account to host or join an ordinary room (per its
 * own architecture); this gate is the L33TEST membership wall around
 * *launching* the Experience at all, same as every other WebDoor here.
 */

require_once __DIR__ . '/../_doorsdk/php/helpers.php';

use BinktermPHP\Auth;
use BinktermPHP\GameConfig;
use BinktermPHP\Crossroads\DootIdentityBridge;

const DOOT_GAME_ID = 'doot';

/** Emit a bare 403 and stop. The reason is recorded to the WebDoor's own log;
 *  it is never sent to the client. */
function doot_forbidden(string $why): void
{
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo "Forbidden.\n";
    \WebDoorSDK\log('doot', 'launch refused: ' . $why, 'WARNING');
    exit;
}

$auth = new Auth();
$user = $auth->getCurrentUser();

// Fail closed on identity. Defence in depth over the /games/doot route (which
// already redirects anonymous users and applies the same manifest gate).
if (!is_array($user)) {
    doot_forbidden('no authenticated user');
}
$userId = (int)($user['user_id'] ?? $user['id'] ?? 0);
if ($userId <= 0) {
    doot_forbidden('unresolvable user id');
}
if (!GameConfig::isGameSystemEnabled() || !GameConfig::isEnabled(DOOT_GAME_ID)) {
    doot_forbidden('doot WebDoor is not enabled');
}

$manifest = json_decode((string)@file_get_contents(__DIR__ . '/webdoor.json'), true);
if (!empty($manifest['requirements']['admin_only']) && empty($user['is_admin'])) {
    doot_forbidden('user ' . $userId . ' is not an administrator (webdoor.json admin_only)');
}

\WebDoorSDK\log('doot', 'launch by user ' . $userId, 'INFO');

// Identity convergence: mint a short-lived, single-use, signed launch
// assertion (see DootIdentityBridge) and hand it to the browser, which
// POSTs it same-origin to Doot's bridge endpoint BEFORE navigating into
// /doot-app/. Doot verifies the signature server-side and creates a normal
// better-auth session for the mapped account -- so by the time the SPA
// mounts, the caller is already signed in and never sees Doot's own
// Log in / Sign up UI. If minting fails (misconfigured secret), fall back
// to a plain redirect: the caller still reaches Doot as a full-featured
// guest (hosting/joining/playing never require an account), just without
// the persistent-account features until the misconfiguration is fixed.
try {
    $displayName = (string)($user['username'] ?? 'L33TEST caller');
    $launchToken = DootIdentityBridge::mintLaunchToken($userId, $displayName);
} catch (\Throwable $e) {
    \WebDoorSDK\log('doot', 'bridge token mint failed, falling back to guest launch: ' . $e->getMessage(), 'WARNING');
    header('Location: /doot-app/');
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?><!doctype html>
<html><head><meta charset="utf-8"><title>Doot Games</title></head>
<body style="background:#241910;color:#fff;font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;">
<p>Loading Doot Games&hellip;</p>
<script>
(function () {
  var token = <?= json_encode($launchToken, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
  fetch('/doot-app/api/auth/l33test/bridge', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify({ token: token })
  }).then(function () {
    location.replace('/doot-app/');
  }).catch(function () {
    location.replace('/doot-app/');
  });
})();
</script>
</body></html>
<?php
exit;
