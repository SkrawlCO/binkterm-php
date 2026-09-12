<?php
/**
 * Parlour's client-side router (Next.js App Router, static export) needs
 * `location.pathname` to equal its own basePath root exactly
 * ("/webdoors/parlour/assets/") to recognize the current route — serving
 * the shell inline at this URL instead breaks hydration (see
 * shared/parlour/README.md, "Web entry: redirect, not inline shell").
 * So this endpoint only authenticates + authorizes, then redirects to the
 * real static entry with a one-time CSRF token in the query string; the
 * app reads it once on mount and clears it via history.replaceState.
 */
require_once __DIR__ . '/../_doorsdk/php/helpers.php';
$user = (new \BinktermPHP\Auth())->requireAuth();
header('Cache-Control: no-store');
if (!\BinktermPHP\GameConfig::isEnabled('parlour')) { http_response_code(403); exit; }
$csrf = (new \BinktermPHP\UserMeta())->getValue((int)($user['user_id'] ?? $user['id']), 'csrf_token');
header('Location: /webdoors/parlour/assets/?parlourCsrf=' . rawurlencode((string)$csrf));
