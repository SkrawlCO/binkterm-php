<?php

declare(strict_types=1);

namespace BinktermPHP\Crossroads;

use BinktermPHP\Config;

/**
 * DootIdentityBridge
 *
 * L33TEST/Crossroads-owned: mints the short-lived, single-use, HMAC-signed
 * launch assertion that lets the Doot WebDoor gate
 * (public_html/webdoors/doot/index.php) hand an already-authenticated
 * L33TEST caller a real Doot (better-auth) session, with no second Doot
 * account and no visible Doot Log in / Sign up step.
 *
 * The counterpart that verifies this assertion and creates the Doot session
 * is docs/Crossroads/doot-backend -- see that README, and
 * apps/web/server/utils/l33test-bridge-plugin.ts in the Doot deployment
 * itself, for the full trust-boundary writeup. Summary:
 *
 *   - the mapped Doot identity is derived ONLY from the immutable L33TEST
 *     users.id (never the display name, never anything caller-suppliable);
 *   - the token is HMAC-SHA256 signed with DOOT_BRIDGE_SECRET, known only to
 *     BinkTermPHP and the self-hosted Doot deployment -- never sent to, or
 *     readable by, the browser;
 *   - it expires in 60 seconds and carries a single-use `jti` the Doot side
 *     consumes exactly once, so a captured token cannot be replayed;
 *   - DOOT_BRIDGE_SECRET must be identical here and in Doot's own
 *     L33TEST_BRIDGE_SECRET env var, or every launch fails closed (401 from
 *     Doot's bridge endpoint) -- there is no silent partial-trust mode.
 *
 * This class only mints; it never verifies anything (verification happens
 * entirely on the Doot side, by design, since only Doot's own better-auth
 * instance can create a Doot session).
 */
class DootIdentityBridge
{
    private const TOKEN_TTL_SECONDS = 60;

    /**
     * Build the signed launch assertion for the given L33TEST caller.
     *
     * @param int    $userId      Immutable BinkTerm users.id. Never 0/guest --
     *                            callers must check authentication before
     *                            calling this (the WebDoor gate already does).
     * @param string $displayName Shown to the caller inside Doot and used to
     *                            seed their room display name. Cosmetic only
     *                            -- never part of the identity mapping.
     */
    public static function mintLaunchToken(int $userId, string $displayName): string
    {
        $secret = (string)Config::env('DOOT_BRIDGE_SECRET', '');
        if ($userId <= 0) {
            throw new \InvalidArgumentException('DootIdentityBridge requires a real authenticated user id');
        }
        if ($secret === '') {
            throw new \RuntimeException('DOOT_BRIDGE_SECRET is not configured');
        }

        $now = time();
        $payload = [
            'sub' => 'l33test:' . $userId,
            'name' => mb_substr(trim($displayName) !== '' ? $displayName : 'L33TEST caller', 0, 60),
            'iat' => $now,
            'exp' => $now + self::TOKEN_TTL_SECONDS,
            'jti' => bin2hex(random_bytes(12)),
        ];

        $payloadB64 = self::base64UrlEncode((string)json_encode($payload, JSON_UNESCAPED_SLASHES));
        $signature = hash_hmac('sha256', $payloadB64, $secret, true);

        return $payloadB64 . '.' . self::base64UrlEncode($signature);
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
