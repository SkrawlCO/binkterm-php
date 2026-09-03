<?php

/**
 * Chessmata NativeDoor session-init shim (Crossroads Experience #4, Telnet).
 *
 * Called by launch-chessmata.sh:
 *
 *     php session-init.php <binkterm_user_id> <xdg_config_home>
 *
 * Resolves the caller through ChessmataIdentity, writes the OFFICIAL CLI's
 * config.json + credentials.json into <xdg_config_home>/chessmata/ (0600 in a
 * 0700 dir the launcher created), and prints ONE line of safe JSON metadata
 * (no secret). Exit codes: 0 ok, 3 provisioning rate-limited (transient),
 * 2 not authenticated, 1 anything else. stderr carries only a short token.
 */

declare(strict_types=1);

require '/var/www/html/vendor/autoload.php';

use BinktermPHP\Crossroads\ChessmataBrokerUnavailable;
use BinktermPHP\Crossroads\ChessmataProvisioningRateLimited;
use BinktermPHP\Crossroads\ChessmataTerminalSession;

$userId = (int)($argv[1] ?? 0);
$configHome = (string)($argv[2] ?? '');
$clientIp = getenv('DOOR_CLIENT_IP') ?: null;

try {
    $meta = ChessmataTerminalSession::prepare($userId, $configHome, $clientIp ?: null);
    echo json_encode($meta, JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
} catch (\InvalidArgumentException $e) {
    fwrite(STDERR, "NOT_AUTHENTICATED\n");
    exit(2);
} catch (ChessmataProvisioningRateLimited $e) {
    fwrite(STDERR, "RATE_LIMITED\n");
    exit(3);
} catch (ChessmataBrokerUnavailable $e) {
    fwrite(STDERR, "BROKER_UNAVAILABLE\n");
    exit(1);
} catch (\Throwable $e) {
    fwrite(STDERR, "UNAVAILABLE\n");
    exit(1);
}
