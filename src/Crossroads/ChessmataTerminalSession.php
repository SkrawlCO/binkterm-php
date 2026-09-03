<?php

declare(strict_types=1);

namespace BinktermPHP\Crossroads;

use BinktermPHP\Config;

/**
 * ChessmataTerminalSession
 *
 * L33TEST/Crossroads-owned: prepares the per-session credential/config material
 * for the Chessmata NativeDoor (Crossroads Experience #4, Telnet surface). The
 * NativeDoor launcher (native-doors/doors/chessmata/launch-chessmata.sh) calls
 * session-init.php, which calls prepare(), then execs the OFFICIAL upstream
 * Chessmata CLI (python3 -m chessmata) with XDG_CONFIG_HOME pointed here.
 *
 * The authenticated BinkTerm user id arrives through the existing NativeDoor
 * boundary (door_sessions.user_id -> {user_number} / DOOR_USER_NUMBER). This
 * class resolves it through ChessmataIdentity -- one BinkTerm user, one
 * Chessmata account -- and writes the CLI's own credential/config files:
 *
 *   <configHome>/chessmata/config.json      { server_url, email }
 *   <configHome>/chessmata/credentials.json { access_token = the cmk_ API key, ... }
 *
 * The API key is written ONLY to credentials.json (0600, inside a 0700 dir the
 * launcher created ephemerally with mktemp and removes on every exit path). It
 * never appears in argv, stdout, a log, or a shell variable. prepare() returns
 * only non-secret metadata.
 *
 * NOT a generic capability; deliberately Chessmata-shaped.
 */
final class ChessmataTerminalSession
{
    /**
     * @param int    $binktermUserId authenticated BinkTerm users.id (> 0)
     * @param string $configHome     an existing, private (0700) directory to be
     *                               used as XDG_CONFIG_HOME for this one session
     * @param string|null $clientIp   the caller's real IP (DOOR_CLIENT_IP), for
     *                               the broker's X-Forwarded-For on first provision
     * @param ChessmataIdentity|null $broker test seam; production passes null and
     *                               a real broker is constructed
     *
     * @return array{ok:true, display_name:string, chessmata_user_id:string, server_url:string}
     *
     * @throws \InvalidArgumentException          not authenticated / bad config dir
     * @throws ChessmataBrokerUnavailable         broker not configured on this host
     * @throws ChessmataProvisioningRateLimited   Chessmata register cap hit
     * @throws ChessmataIdentityException         other provisioning failure
     */
    public static function prepare(
        int $binktermUserId,
        string $configHome,
        ?string $clientIp = null,
        ?ChessmataIdentity $broker = null
    ): array {
        if ($binktermUserId <= 0) {
            throw new \InvalidArgumentException('Chessmata Telnet requires an authenticated BinkTerm account');
        }
        if ($configHome === '' || !is_dir($configHome)) {
            throw new \InvalidArgumentException('Chessmata Telnet session config directory does not exist');
        }
        // Refuse a world/group-accessible session dir -- the launcher makes it 0700.
        $perms = fileperms($configHome) & 0o777;
        if (($perms & 0o077) !== 0) {
            throw new \InvalidArgumentException('Chessmata Telnet session config directory is not private (0700)');
        }

        if ($broker === null && !ChessmataIdentity::isAvailable()) {
            throw new ChessmataBrokerUnavailable('Chessmata identity broker is not configured on this host');
        }

        $serverUrl = rtrim((string)Config::env('CHESSMATA_INTERNAL_URL', 'http://chessmata:9029'), '/');

        $broker ??= new ChessmataIdentity();
        $account = $broker->resolve($binktermUserId, $clientIp);          // provisions once; same account forever
        $apiKey = $broker->terminalCredential($binktermUserId, $clientIp); // durable cmk_ key for THIS account

        $dir = $configHome . '/chessmata';
        if (!is_dir($dir) && !mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new ChessmataIdentityException('could not create the Chessmata CLI config directory');
        }
        @chmod($dir, 0o700);

        self::writeJson($dir . '/config.json', [
            'server_url' => $serverUrl,
            'email'      => $account->email,
        ]);
        self::writeJson($dir . '/credentials.json', [
            'access_token' => $apiKey,                 // the CLI sends this as `Authorization: Bearer`; cmk_ -> API-key auth
            'user_id'      => $account->chessmataUserId,
            'email'        => $account->email,
            'display_name' => $account->displayName,
            'elo_rating'   => null,
        ]);

        return [
            'ok'                => true,
            'display_name'      => $account->displayName,
            'chessmata_user_id' => $account->chessmataUserId,
            'server_url'        => $serverUrl,
        ];
    }

    /** @param array<string,mixed> $data */
    private static function writeJson(string $path, array $data): void
    {
        // Create with 0600 from the start -- never briefly world-readable.
        $fh = fopen($path, 'wb');
        if ($fh === false) {
            throw new ChessmataIdentityException('could not open a Chessmata CLI config file for writing');
        }
        @chmod($path, 0o600);
        fwrite($fh, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        fclose($fh);
        @chmod($path, 0o600);
    }
}
