<?php

namespace BinktermPHP\Terminal;

use BinktermPHP\Auth;
use BinktermPHP\Crossroads\TerminalDashboardSignal;
use BinktermPHP\Database;
use BinktermPHP\DashboardStatsService;
use BinktermPHP\EchoareaManager;

/**
 * Network-free equivalents of the terminal main-menu HTTP fetches.
 *
 * The telnet/SSH daemons proxied the Echomail area picker and the main-menu
 * badge counts through `GET /api/echoareas` and `GET /api/dashboard/stats` —
 * each a full round trip out to the site's public URL (Cloudflare), a fresh
 * TLS handshake, and the whole framework request lifecycle, every time the
 * menu or the area list was (re)drawn. These methods call the same shared
 * services the routes delegate to ({@see EchoareaManager::listForUser()},
 * {@see DashboardStatsService::getStats()}, {@see TerminalDashboardSignal})
 * so the terminal behaves identically apart from latency.
 *
 * The return shape matches {@see \BinktermPHP\TelnetServer\TelnetUtils::apiRequest()}:
 * `['status' => int, 'data' => array, 'error' => ?string]`, so existing
 * `$response['data'][...]` / `$response['status']` call sites are untouched.
 * A contained failure returns a non-200 envelope with an empty `data`, letting
 * the caller's existing `?? []` / defaults fallback engage instead of throwing.
 */
class TerminalMenuData
{
    private ?Auth $auth;

    public function __construct(?Auth $auth = null)
    {
        $this->auth = $auth;
    }

    private function auth(): Auth
    {
        return $this->auth ??= new Auth();
    }

    /**
     * Resolve the authenticated user for a terminal session token — the
     * in-process equivalent of the routes' `RouteHelper::requireAuth()`.
     */
    private function resolveUser(string $session): ?array
    {
        $user = $this->auth()->validateSession($session);

        return is_array($user) ? $user : null;
    }

    /**
     * Mirror of `GET /api/echoareas` (optionally `?subscribed_only=true`).
     *
     * @return array{status:int,data:array<string,mixed>,error:?string}
     */
    public function echoareas(string $session, bool $subscribedOnly, string $filter = 'active'): array
    {
        try {
            $user = $this->resolveUser($session);
            if ($user === null) {
                return ['status' => 401, 'data' => [], 'error' => 'unauthenticated'];
            }

            $areas = (new EchoareaManager())->listForUser($user, [
                'filter' => $filter,
                'subscribed_only' => $subscribedOnly,
            ]);

            return ['status' => 200, 'data' => ['echoareas' => $areas], 'error' => null];
        } catch (\Throwable $e) {
            return ['status' => 500, 'data' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Mirror of `GET /api/dashboard/stats`, including the terminal `crossroads`
     * signal the route appends.
     *
     * @return array{status:int,data:array<string,mixed>,error:?string}
     */
    public function dashboardStats(string $session): array
    {
        try {
            $user = $this->resolveUser($session);
            if ($user === null) {
                return ['status' => 401, 'data' => [], 'error' => 'unauthenticated'];
            }

            $stats = (new DashboardStatsService(Database::getInstance()->getPdo()))->getStats($user);
            $stats['crossroads'] = TerminalDashboardSignal::compose($user);

            return ['status' => 200, 'data' => $stats, 'error' => null];
        } catch (\Throwable $e) {
            return ['status' => 500, 'data' => [], 'error' => $e->getMessage()];
        }
    }
}
