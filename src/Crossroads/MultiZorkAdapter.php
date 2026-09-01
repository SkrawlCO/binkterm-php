<?php

declare(strict_types=1);

namespace BinktermPHP\Crossroads;

/**
 * L33TEST/Crossroads-owned MultiZork thin adapter.
 *
 * This is the ONLY place in the codebase that knows MultiZork's own prompt
 * vocabulary (its "Hello sailor!" onboarding prompt and access-code
 * mechanics, per the disposable runtime proof). It is called by
 * DoorHandler's generic line-relay
 * ({@see \BinktermPHP\NativeDoorManifest}'s relay_adapter_class /
 * DoorHandler::launchLineRelayDoor()) purely by class-name convention —
 * DoorHandler has no knowledge this class, or MultiZork, exists.
 *
 * Deliberately not a generic "door adapter framework": two static methods,
 * used because Slice 1 genuinely needed them and Slice 3's productionization
 * did not change that shape (Correction 3 in
 * docs/Crossroads/MultiZorkSlice1.md). Do not add speculative hooks for
 * hypothetical future backends here.
 *
 * Scope, deliberately kept narrow through Slice 3 (productionization):
 *  - one fixed expedition (FIXED_EXPEDITION_ID) — proven with two
 *    simultaneous callers in Slice 2, still the only expedition model;
 *  - no expedition naming/invitations/rosters;
 *  - no chat/transcript/game-over UX.
 */
final class MultiZorkAdapter
{
    /**
     * Exactly one fixed expedition exists. This is an L33TEST-owned
     * identifier, never MultiZork's own raw join/access code — see
     * MultiZorkAccessMapping. (Renamed from the Slice 1/2 test-only value
     * 'multizork-slice1-test' when productionized in Slice 3; test-era
     * mappings under the old id are orphaned, not migrated, since they
     * belonged only to disposable test accounts.)
     */
    public const FIXED_EXPEDITION_ID = 'multizork-prime';

    /** Bounded read window so a silent/misbehaving service cannot hang the daemon. */
    private const READ_TIMEOUT_SECONDS = 3.0;

    /**
     * Runs once, directly against the raw private-TCP socket, before the
     * generic transparent line relay begins.
     *
     * If no BinkTerm identity is available, or no stored access code exists
     * yet for this user's expedition, this does nothing but
     * relay MultiZork's own banner to the terminal — the human proceeds
     * through the ordinary, visible create/join/go flow observed in the
     * runtime proof. Only a RETURNING caller with a stored code is
     * automated, and only after the rate limiter allows it.
     *
     * @param resource $conn Telnet client socket
     * @param resource $sock Connected MultiZork TCP socket
     * @param array $state Terminal state (read-only here; carries user_id)
     * @param array{session_id: string, door_id: string} $context
     */
    public static function handshake($conn, $sock, array &$state, array $context): void
    {
        $userId = (int)($state['user_id'] ?? 0);

        $banner = self::readAvailable($sock);

        if ($userId <= 0) {
            self::relay($conn, $banner);
            return;
        }

        $mapping = new MultiZorkAccessMapping();
        $code = $mapping->get($userId, self::FIXED_EXPEDITION_ID);

        if ($code === null) {
            // First run: no stored code. Let the human create/join by hand.
            self::relay($conn, $banner);
            return;
        }

        $limiter = new MultiZorkAccessRateLimit();
        if (!$limiter->check($userId, self::FIXED_EXPEDITION_ID)) {
            // Blocked: never attempt submission while blocked. Fall back to
            // the ordinary human-visible flow instead of failing silently.
            self::relay($conn, $banner);
            return;
        }

        // Submit the stored code invisibly. Never echoed, never logged.
        @fwrite($sock, $code . "\n");
        $response = self::readAvailable($sock);

        if (stripos($response, "can't find a game with that access code") !== false) {
            // multizorkd's own rejection text (stale/invalid stored code).
            // Fall back to the human-visible flow from wherever multizorkd
            // itself leaves the session (it re-prompts at the same step).
            $limiter->recordFailure($userId, self::FIXED_EXPEDITION_ID);
            self::relay($conn, $response);
            return;
        }

        $limiter->recordSuccess($userId, self::FIXED_EXPEDITION_ID);
        self::relay($conn, $response);
    }

    /**
     * Called on every chunk of MultiZork output as it is written to the
     * terminal, purely to watch for the "...access code: 'XXXXXX'" line
     * MultiZork emits once a game starts, and persist it. Cannot alter the
     * relayed stream.
     *
     * @param array{session_id: string, door_id: string} $context
     */
    public static function onOutput(string $chunk, array $state, array $context): void
    {
        $userId = (int)($state['user_id'] ?? 0);
        if ($userId <= 0) {
            return;
        }

        if (preg_match("/access code: '([A-Za-z0-9]{4,16})'/", $chunk, $m) === 1) {
            (new MultiZorkAccessMapping())->save($userId, self::FIXED_EXPEDITION_ID, $m[1]);
        }
    }

    /**
     * Read whatever MultiZork sends within a bounded window. MultiZork's
     * own prompts are short and arrive promptly (confirmed in the runtime
     * proof), so this does not need to be clever — just bounded.
     *
     * @param resource $sock
     */
    private static function readAvailable($sock): string
    {
        $data = '';
        $deadline = microtime(true) + self::READ_TIMEOUT_SECONDS;

        while (microtime(true) < $deadline) {
            $read = [$sock];
            $write = $except = null;
            $remaining = max(0.0, $deadline - microtime(true));
            $sec = (int)floor($remaining);
            $usec = (int)(($remaining - $sec) * 1_000_000);

            $ready = @stream_select($read, $write, $except, $sec, $usec);
            if ($ready === false || $ready === 0) {
                break;
            }

            $chunk = @fread($sock, 4096);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data .= $chunk;

            // Give a fast follow-up write (e.g. a multi-line prompt) a brief
            // chance to arrive before treating this as the complete message,
            // without holding the bounded overall deadline hostage.
            $read = [$sock];
            if (@stream_select($read, $write, $except, 0, 150000) < 1) {
                break;
            }
        }

        return $data;
    }

    /**
     * @param resource $conn
     */
    private static function relay($conn, string $data): void
    {
        if ($data !== '') {
            @fwrite($conn, $data);
        }
    }
}
