<?php

/*
 * Chessmata WebDoor -- browser auth hand-off endpoint
 * (Crossroads Experience #4, graphical Web surface).
 *
 * Same-origin POST only. Called by bootstrap.js after an authenticated BinkTerm
 * WebDoor launch. Returns a short-lived Chessmata JWT/access token (via
 * ChessmataWebSession::issue() -> ChessmataIdentity::webCredential()) for the
 * caller's existing Chessmata account -- the SAME account their Telnet/CLI
 * surface authenticates as.
 *
 * Security:
 *   - requireAuth(): 401 without a BinkTerm session.
 *   - POST + same-origin (Sec-Fetch-Site / Origin) only: a cross-site page
 *     cannot pull a token even with the victim's cookie.
 *   - the durable cmk_ API key is NEVER issued to the browser -- webCredential()
 *     returns a JWT scoped to the Web surface.
 *   - response is Cache-Control: no-store, not logged, and the token is never
 *     placed in a URL. The token exists browser-side only in the mechanism the
 *     upstream SPA already uses (localStorage 'chessmata_auth_token').
 */

require_once __DIR__ . '/../_doorsdk/php/helpers.php';

use BinktermPHP\Crossroads\ChessmataBrokerUnavailable;
use BinktermPHP\Crossroads\ChessmataProvisioningRateLimited;
use BinktermPHP\Crossroads\ChessmataWebSession;
use BinktermPHP\GameConfig;

const CHESSMATA_GAME_ID = 'chessmata-web';

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');

/** JSON error + WebDoor-log line (never the token), then stop. */
function chessmata_cred_error(string $errorCode, string $logLine, int $status): void
{
    http_response_code($status);
    \WebDoorSDK\log('chessmata-web', 'web-credential: ' . $logLine, $status >= 500 ? 'ERROR' : 'WARNING');
    echo json_encode(['error_code' => $errorCode, 'error' => 'Chessmata is unavailable right now.']);
    exit;
}

// --- method + same-origin ---------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error_code' => 'method_not_allowed', 'error' => 'POST only']);
    exit;
}

$fetchSite = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$expectedOrigin = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://')
    . ($_SERVER['HTTP_HOST'] ?? '');
$sameOrigin = ($fetchSite === 'same-origin' || $fetchSite === 'same-site')
    || ($fetchSite === '' && ($origin === '' || $origin === $expectedOrigin));
if (!$sameOrigin) {
    chessmata_cred_error('cross_origin', 'rejected cross-origin request (site=' . $fetchSite . ')', 403);
}

// --- identity (fail closed) ------------------------------------------------
$user = \WebDoorSDK\requireAuth(); // 401 JSON + exit if unauthenticated
$userId = (int)($user['user_id'] ?? $user['id'] ?? 0);
if ($userId <= 0) {
    chessmata_cred_error('no_identity', 'unresolvable user id', 403);
}

if (!GameConfig::isGameSystemEnabled() || !GameConfig::isEnabled(CHESSMATA_GAME_ID)) {
    chessmata_cred_error('disabled', 'chessmata WebDoor is not enabled', 403);
}

$manifest = json_decode((string)@file_get_contents(__DIR__ . '/webdoor.json'), true);
if (!empty($manifest['requirements']['admin_only']) && empty($user['is_admin'])) {
    chessmata_cred_error('forbidden', 'user ' . $userId . ' is not an administrator (webdoor.json admin_only)', 403);
}

// --- mint the hand-off ----------------------------------------------------
$clientIp = $_SERVER['REMOTE_ADDR'] ?? null; // Slice 2B: already the real CF client IP
if (!is_string($clientIp) || filter_var($clientIp, FILTER_VALIDATE_IP) === false) {
    $clientIp = null;
}

try {
    $issued = ChessmataWebSession::issue($userId, $clientIp);
} catch (ChessmataProvisioningRateLimited $e) {
    chessmata_cred_error('rate_limited', 'provisioning rate limited', 503);
} catch (ChessmataBrokerUnavailable $e) {
    chessmata_cred_error('broker_unavailable', 'identity broker not configured', 503);
} catch (\InvalidArgumentException $e) {
    chessmata_cred_error('no_identity', 'invalid caller: ' . $e->getMessage(), 403);
} catch (\Throwable $e) {
    chessmata_cred_error('unavailable', 'issue() failed: ' . $e->getMessage(), 502);
}

\WebDoorSDK\log(
    'chessmata-web',
    'web hand-off issued for BinkTerm user ' . $userId . ' -> chessmata ' . $issued['chessmataUserId'],
    'INFO'
);

// The token is the only secret here; it goes straight to the SPA's localStorage.
echo json_encode([
    'access_token'      => $issued['accessToken'],
    'expires_at'        => $issued['expiresAt'],
    'chessmata_user_id' => $issued['chessmataUserId'],
    'display_name'      => $issued['displayName'],
    'storage_key'       => $issued['storageKey'],
    'client_path'       => $issued['clientPath'],
], JSON_UNESCAPED_SLASHES);
