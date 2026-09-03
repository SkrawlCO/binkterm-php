<?php

declare(strict_types=1);

namespace BinktermPHP\Crossroads;

/**
 * ChessmataWebSession
 *
 * L33TEST/Crossroads-owned: mints the browser-side authentication hand-off for
 * the Chessmata WebDoor (Crossroads Experience #4, graphical Web surface). The
 * WebDoor endpoint (public_html/webdoors/chessmata/web-credential.php) calls
 * issue() after an authenticated BinkTerm launch; the bootstrap page then seeds
 * the OFFICIAL upstream Chessmata SPA's own localStorage key and enters the SPA.
 *
 * The authenticated BinkTerm user id arrives through the WebDoor boundary
 * (session -> Auth::getCurrentUser()). This class resolves it through
 * ChessmataIdentity -- the SAME "one BinkTerm user, one Chessmata account"
 * mapping the Telnet surface (ChessmataTerminalSession) uses -- and returns a
 * short-lived JWT/access token (webCredential(), NEVER the durable cmk_ API
 * key) plus non-secret metadata.
 *
 * The token is the only secret in the return; the caller must deliver it over a
 * same-origin, no-store, POST response and never log it, never place it in a
 * URL/query string, and never persist it BinkTerm-side.
 *
 * NOT a generic capability; deliberately Chessmata-shaped, and deliberately
 * mirrors ChessmataTerminalSession so the two surfaces stay symmetric.
 */
final class ChessmataWebSession
{
    /**
     * The localStorage key the upstream Chessmata SPA reads on mount
     * (src/hooks/useAuth.ts: const TOKEN_KEY = 'chessmata_auth_token'). Kept
     * here as the single source of truth for the hand-off.
     */
    public const SPA_TOKEN_STORAGE_KEY = 'chessmata_auth_token';

    /**
     * Same-origin path the graphical SPA is served at (Slice 1 reverse-proxy:
     * Cloudflare -> Apache -> binkterm-app Caddy handle_path /chessmata/* ->
     * chessmata:9029). Entering here rather than an absolute URL keeps the
     * hand-off same-origin.
     */
    public const SPA_CLIENT_PATH = '/chessmata/';

    /**
     * @param int                    $binktermUserId authenticated BinkTerm users.id (> 0)
     * @param string|null            $clientIp       caller's real IP, for the broker's
     *                                               X-Forwarded-For on first provision
     * @param ChessmataIdentity|null $broker         test seam; production passes null
     *
     * @return array{
     *     accessToken:string, expiresAt:string, chessmataUserId:string,
     *     displayName:string, storageKey:string, clientPath:string
     * }
     *
     * @throws \InvalidArgumentException        not an authenticated BinkTerm account
     * @throws ChessmataBrokerUnavailable       broker not configured on this host
     * @throws ChessmataProvisioningRateLimited Chessmata register cap hit
     * @throws ChessmataIdentityException       other provisioning / login failure
     */
    public static function issue(
        int $binktermUserId,
        ?string $clientIp = null,
        ?ChessmataIdentity $broker = null
    ): array {
        if ($binktermUserId <= 0) {
            throw new \InvalidArgumentException('Chessmata Web requires an authenticated BinkTerm account');
        }

        if ($broker === null && !ChessmataIdentity::isAvailable()) {
            throw new ChessmataBrokerUnavailable('Chessmata identity broker is not configured on this host');
        }

        $broker ??= new ChessmataIdentity();

        // resolve() provisions once and returns the same account forever;
        // webCredential() returns a valid access (JWT) token for THAT account,
        // transparently refreshing / re-logging in as needed. This is the SAME
        // account the caller's Telnet/CLI surface authenticates as.
        $account = $broker->resolve($binktermUserId, $clientIp);
        $web = $broker->webCredential($binktermUserId, $clientIp);

        if (($web['accessToken'] ?? '') === '') {
            throw new ChessmataIdentityException('Chessmata web credential came back empty');
        }

        return [
            'accessToken'     => $web['accessToken'],
            'expiresAt'       => (string)($web['expiresAt'] ?? ''),
            'chessmataUserId' => $account->chessmataUserId,
            'displayName'     => $account->displayName,
            'storageKey'      => self::SPA_TOKEN_STORAGE_KEY,
            'clientPath'      => self::SPA_CLIENT_PATH,
        ];
    }

    /**
     * Non-secret view for diagnostics / tests: the same payload issue() returns
     * but with the token redacted. Never throws on a missing mapping.
     *
     * @param array<string,mixed> $issued
     * @return array<string,mixed>
     */
    public static function redact(array $issued): array
    {
        $copy = $issued;
        if (isset($copy['accessToken'])) {
            $copy['accessToken'] = '[redacted ' . strlen((string)$copy['accessToken']) . ' bytes]';
        }

        return $copy;
    }
}
