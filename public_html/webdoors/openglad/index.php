<?php

/*
 * OpenGlad WebDoor entry point (L33TEST Crossroads integration, M4 Slice 1E).
 *
 * This is the ONLY server-side glue for the OpenGlad WebDoor. It is a thin
 * wrapper around the UNMODIFIED-except-for-the-tracked-carried-patch OpenGlad
 * Web build (patches/0001-web-persist-namespace.patch, pending
 * openglad/openglad#281). It:
 *
 *   1. FAILS CLOSED. The authenticated multi-user OpenGlad path requires a
 *      resolvable, immutable BinkTerm user id and admin authorization. If either
 *      is missing this returns 403 and serves no game -- it must never fall back
 *      to OpenGlad's shared "/persist" store for this deployment.
 *   2. Derives an opaque, deterministic per-user PERSISTENCE PARTITION token
 *      from the immutable users.id only (NOT from APP_SECRET -- a partition id
 *      must survive a secret rotation), and injects it as
 *      window.__opengladPersistNamespace before play.js can run, so each user
 *      gets an isolated IndexedDB-backed store.
 *   3. Adds crossroads-glue.js (relay base + WebDoor session lifecycle).
 *
 * The token is a partition identifier, never authentication or authorization:
 * nothing server-side consumes it. It is not a secret (it becomes an IndexedDB
 * database name, visible in browser dev-tools) but is not logged needlessly.
 */

require_once __DIR__ . '/../_doorsdk/php/helpers.php';

use BinktermPHP\Auth;
use BinktermPHP\GameConfig;
use BinktermPHP\Crossroads\OpengladPersistNamespace;

const OPENGLAD_GAME_ID = 'openglad';

/** Emit a bare 403 and stop. The reason is recorded to the WebDoor's own log
 *  (data/logs/webdoor_openglad.log) via the SDK; it is never sent to the client. */
function openglad_forbidden(string $why): void
{
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo "Forbidden.\n";
    \WebDoorSDK\log('openglad', 'launch refused: ' . $why, 'WARNING');
    exit;
}

$auth = new Auth();
$user = $auth->getCurrentUser();

// (1) Fail closed. The /games/openglad route already redirects anonymous users
// and 403s non-admins (the requirements.admin_only capability), but this entry
// point is defence in depth and also covers a direct /webdoors/openglad/index.php
// hit.
if (!is_array($user)) {
    openglad_forbidden('no authenticated user');
}
$userId = (int)($user['user_id'] ?? $user['id'] ?? 0);
if ($userId <= 0) {
    openglad_forbidden('unresolvable user id');
}
if (empty($user['is_admin'])) {
    openglad_forbidden('user ' . $userId . ' is not an administrator');
}
if (!GameConfig::isGameSystemEnabled() || !GameConfig::isEnabled(OPENGLAD_GAME_ID)) {
    openglad_forbidden('openglad WebDoor is not enabled');
}

// (2) Opaque per-user persistence-partition token (see OpengladPersistNamespace:
// 40 lowercase hex of sha256("openglad-persist-v1:" || users.id); deterministic
// per user, stable across APP_SECRET rotation, distinct per user).
$persistNamespace = OpengladPersistNamespace::forUser($userId);

// (3) Serve the pinned shell with the namespace injected as a literal (race-free
// -- present before play.js, which is an async script, can run) plus the glue.
$shellPath = __DIR__ . '/play.html';
$shell = @file_get_contents($shellPath);
if ($shell === false) {
    // Build artifact missing -> do not serve a broken page.
    openglad_forbidden('play.html not staged (run build-webdoor.sh)');
}

$inject = '<script>window.__opengladPersistNamespace='
    . json_encode($persistNamespace, JSON_UNESCAPED_SLASHES)
    . ';</script>'
    . '<script src="crossroads-glue.js"></script>';

$pos = stripos($shell, '<head>');
if ($pos === false) {
    openglad_forbidden('play.html has no <head>');
}
$pos += strlen('<head>');
$shell = substr($shell, 0, $pos) . $inject . substr($shell, $pos);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
echo $shell;
