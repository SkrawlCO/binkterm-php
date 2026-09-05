<?php

declare(strict_types=1);

namespace BinktermPHP\Crossroads;

use BinktermPHP\Database;
use PDO;

/**
 * GalacticBloodshedIdentity
 *
 * L33TEST/Crossroads-owned identity broker for the Galactic Bloodshed
 * candidate Experience:
 *
 *     authenticated BinkTerm user -> broker -> opaque race/governor
 *     credential -> (launcher runs `enrol` once, then the upstream client)
 *     -> persistent shared GB universe
 *
 * Deliberately NOT shaped like ChessmataIdentity (Slice 2), even though the
 * job -- "one BinkTerm user, one external-service identity" -- looks the
 * same on paper. Chessmata provisions over HTTP inside one fast DB
 * transaction; Galactic Bloodshed provisions by shelling out to `enrol`
 * against its own SQLite universe, an operation that can block for an
 * arbitrarily long time on a live human answering real race-design prompts
 * (see docs/Crossroads/galactic-bloodshed-backend/ for the exact enrol
 * prompt sequence and which of those are gameplay-meaningful). Holding a
 * Postgres transaction/advisory lock across that would be wrong. Instead:
 *
 *   1. resolve() claims a provisioning attempt in ONE short transaction:
 *      generate a fresh opaque race/governor password pair, persist them
 *      encrypted with status='provisioning' + a random attempt_token, and
 *      return them to the caller (a launcher process). This happens BEFORE
 *      `enrol` is ever invoked.
 *   2. The launcher runs `enrol` (outside any BinkTerm DB transaction,
 *      possibly waiting minutes on a human) and calls back:
 *        - confirmProvisioned() on enrol's confirmed success, or
 *        - failProvisioning() on a confirmed failure.
 *   3. If the launcher process dies in between (crash, killed session) and
 *      never calls back, the row is stuck 'provisioning'. Because the exact
 *      credential that was attempted is already persisted, a later
 *      resolve() call detects the stale attempt and reconciles it by trying
 *      to LOG IN to the live GB server with that saved credential: success
 *      means the race really was created (promote to 'provisioned' -- this
 *      is the only way to recover the gb_playernum after a crash, since GB
 *      has no "look up my race by password" API); a refusal means it never
 *      was (reset to 'pending' for a clean retry with fresh credentials).
 *      This is the resolution to the two-database boundary problem: there
 *      is no two-phase commit between BinkTerm's Postgres and GB's SQLite,
 *      so the login handshake itself is used as the reconciliation oracle.
 *
 * Stored secrets are always GalacticBloodshedSecretBox ciphertext; nothing
 * here logs a race/governor password. Generated passwords are
 * random_bytes-derived opaque tokens, never anything deterministic from the
 * BinkTerm user id or handle.
 */
final class GalacticBloodshedIdentity
{
    /** A 'provisioning' row older than this is presumed abandoned/crashed. */
    private const STALE_ATTEMPT_SEC = 300;

    private PDO $db;
    private GalacticBloodshedSecretBox $box;

    public function __construct(?PDO $db = null, ?GalacticBloodshedSecretBox $box = null)
    {
        $this->db = $db ?? Database::getInstance()->getPdo();
        try {
            $this->box = $box ?? new GalacticBloodshedSecretBox();
        } catch (\Throwable $e) {
            throw new GalacticBloodshedIdentityException('Galactic Bloodshed broker key not configured', 0, $e);
        }
    }

    public static function isAvailable(): bool
    {
        return GalacticBloodshedSecretBox::isConfigured();
    }

    /**
     * Resolve this user's GB identity, claiming a provisioning attempt if
     * none is in flight and none exists yet.
     *
     * @param callable|null $loginProbe (string $racePassword, string $governorPassword): ?int
     *                                  Used ONLY to reconcile a stale
     *                                  'provisioning' row. Returns the GB
     *                                  playernum on a successful login, or
     *                                  null if refused/unreachable. Defaults
     *                                  to a real TCP login against
     *                                  GALACTICBLOODSHED_HOST/_PORT; inject a
     *                                  fake in tests.
     *
     * @return array{status:string, race_password?:string, governor_password?:string,
     *               gb_playernum?:int, attempt_token?:string}
     *         status is one of:
     *           'provisioned'        -- ready to auto-login; credentials + gb_playernum present
     *           'needs_provisioning' -- caller must run enrol with the returned
     *                                   credentials, then confirmProvisioned()/failProvisioning()
     *
     * @throws GalacticBloodshedProvisioningInProgress another live attempt owns this user right now
     */
    public function resolve(int $binktermUserId, ?callable $loginProbe = null): array
    {
        $this->assertUserId($binktermUserId);
        $loginProbe ??= [$this, 'defaultLoginProbe'];

        $this->db->beginTransaction();
        try {
            $this->db->prepare('SELECT pg_advisory_xact_lock(?)')->execute([$this->advisoryKey($binktermUserId)]);

            $row = $this->loadRow($binktermUserId);

            if ($row === null || \in_array($row['status'], ['pending', 'failed'], true)) {
                $result = $this->claim($binktermUserId, $row !== null);
                $this->db->commit();

                return $result;
            }

            if ($row['status'] === 'provisioned') {
                $this->db->commit();

                return $this->provisionedResult($row);
            }

            // status === 'provisioning'
            $ageSec = $row['attempt_started_at'] !== null
                ? (time() - strtotime((string)$row['attempt_started_at']))
                : PHP_INT_MAX;

            if ($ageSec < self::STALE_ATTEMPT_SEC) {
                $this->db->commit(); // release the lock; nothing to change

                throw new GalacticBloodshedProvisioningInProgress(
                    "Galactic Bloodshed provisioning already in progress for user $binktermUserId"
                );
            }

            // Stale attempt: reconcile via the login-probe oracle (see class docblock).
            $racePw = $this->box->decrypt((string)$row['race_password_enc']);
            $govPw = $this->box->decrypt((string)$row['governor_password_enc']);
            $playernum = $loginProbe($racePw, $govPw);

            if ($playernum !== null) {
                $this->markProvisioned($binktermUserId, $playernum);
                $this->db->commit();

                return [
                    'status' => 'provisioned',
                    'race_password' => $racePw,
                    'governor_password' => $govPw,
                    'gb_playernum' => $playernum,
                ];
            }

            sodium_memzero($racePw);
            sodium_memzero($govPw);
            $result = $this->claim($binktermUserId, true); // confirmed never-created: fresh credentials
            $this->db->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Launcher callback: `enrol` confirmed success (its own "You are player
     * N" output was observed). Only transitions a row this exact token
     * still owns, so a stale/superseded caller can't clobber a newer attempt.
     */
    public function confirmProvisioned(int $binktermUserId, string $attemptToken, int $gbPlayernum): void
    {
        try {
            $stmt = $this->db->prepare(
                "UPDATE galactic_bloodshed_identities
                    SET status = 'provisioned', gb_playernum = ?, provisioned_at = NOW(),
                        attempt_token = NULL, attempt_started_at = NULL, updated_at = NOW()
                  WHERE binkterm_user_id = ? AND attempt_token = ? AND status = 'provisioning'"
            );
            $stmt->execute([$gbPlayernum, $binktermUserId, $attemptToken]);
            $matched = $stmt->rowCount();
        } catch (\PDOException $e) {
            $matched = $this->treatAsNoMatch($e);
        }

        if ($matched === 0) {
            throw new GalacticBloodshedIdentityException(
                "confirmProvisioned: no matching in-flight attempt for user $binktermUserId "
                . '(token stale, superseded, malformed, or already resolved)'
            );
        }
    }

    /**
     * Launcher callback: `enrol` confirmed failure (nonzero exit / a
     * recognized failure line, e.g. universe full) BEFORE creating a race.
     * Resets to 'pending' -- credentials are discarded so a retry generates
     * fresh ones rather than reusing ones GB is known never to have accepted.
     */
    public function failProvisioning(int $binktermUserId, string $attemptToken): void
    {
        try {
            $stmt = $this->db->prepare(
                "UPDATE galactic_bloodshed_identities
                    SET status = 'pending', race_password_enc = NULL, governor_password_enc = NULL,
                        attempt_token = NULL, attempt_started_at = NULL, updated_at = NOW()
                  WHERE binkterm_user_id = ? AND attempt_token = ? AND status = 'provisioning'"
            );
            $stmt->execute([$binktermUserId, $attemptToken]);
            $matched = $stmt->rowCount();
        } catch (\PDOException $e) {
            $matched = $this->treatAsNoMatch($e);
        }

        if ($matched === 0) {
            throw new GalacticBloodshedIdentityException(
                "failProvisioning: no matching in-flight attempt for user $binktermUserId "
                . '(token stale, superseded, malformed, or already resolved)'
            );
        }
    }

    /**
     * A caller-supplied attempt_token that isn't valid UUID syntax at all
     * (e.g. a forged/garbled value) is indistinguishable in intent from "no
     * matching attempt" -- treat it that way rather than leaking a raw
     * PDOException, but re-throw anything else (a real connectivity/schema
     * problem should still surface).
     */
    private function treatAsNoMatch(\PDOException $e): int
    {
        if ($e->getCode() === '22P02') { // invalid_text_representation
            return 0;
        }
        throw $e;
    }

    /**
     * Secret-free view of an existing mapping (or null). Diagnostics /
     * acceptance tooling only -- never returns an *_enc column.
     *
     * @return array<string,mixed>|null
     */
    public function existingMapping(int $binktermUserId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT binkterm_user_id, status, gb_playernum,
                    (race_password_enc IS NOT NULL) AS has_race_password,
                    (governor_password_enc IS NOT NULL) AS has_governor_password,
                    attempt_started_at, provisioned_at, updated_at
               FROM galactic_bloodshed_identities WHERE binkterm_user_id = ?'
        );
        $stmt->execute([$binktermUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** Delete the LOCAL mapping only. GB has no delete-race API to call. */
    public function forget(int $binktermUserId): void
    {
        $this->db->prepare('DELETE FROM galactic_bloodshed_identities WHERE binkterm_user_id = ?')
            ->execute([$binktermUserId]);
    }

    // ---------------------------------------------------------------- internal

    /** Must be called with the advisory lock already held. */
    private function claim(int $binktermUserId, bool $rowExists): array
    {
        $racePw = $this->generateOpaquePassword();
        $govPw = $this->generateOpaquePassword();
        $token = $this->generateToken();

        if ($rowExists) {
            $this->db->prepare(
                "UPDATE galactic_bloodshed_identities
                    SET status = 'provisioning', race_password_enc = ?, governor_password_enc = ?,
                        gb_playernum = NULL, attempt_token = ?, attempt_started_at = NOW(), updated_at = NOW()
                  WHERE binkterm_user_id = ?"
            )->execute([$this->box->encrypt($racePw), $this->box->encrypt($govPw), $token, $binktermUserId]);
        } else {
            $this->db->prepare(
                "INSERT INTO galactic_bloodshed_identities
                    (binkterm_user_id, status, race_password_enc, governor_password_enc,
                     attempt_token, attempt_started_at)
                 VALUES (?, 'provisioning', ?, ?, ?, NOW())"
            )->execute([$binktermUserId, $this->box->encrypt($racePw), $this->box->encrypt($govPw), $token]);
        }

        return [
            'status' => 'needs_provisioning',
            'race_password' => $racePw,
            'governor_password' => $govPw,
            'attempt_token' => $token,
        ];
    }

    private function markProvisioned(int $binktermUserId, int $gbPlayernum): void
    {
        $this->db->prepare(
            "UPDATE galactic_bloodshed_identities
                SET status = 'provisioned', gb_playernum = ?, provisioned_at = NOW(),
                    attempt_token = NULL, attempt_started_at = NULL, updated_at = NOW()
              WHERE binkterm_user_id = ?"
        )->execute([$gbPlayernum, $binktermUserId]);
    }

    private function provisionedResult(array $row): array
    {
        return [
            'status' => 'provisioned',
            'race_password' => $this->box->decrypt((string)$row['race_password_enc']),
            'governor_password' => $this->box->decrypt((string)$row['governor_password_enc']),
            'gb_playernum' => (int)$row['gb_playernum'],
        ];
    }

    private function loadRow(int $binktermUserId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM galactic_bloodshed_identities WHERE binkterm_user_id = ?');
        $stmt->execute([$binktermUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function advisoryKey(int $binktermUserId): int
    {
        // Stable 63-bit key from a namespaced string, distinct from Chessmata's
        // ('chessmata:provision:...') so the two brokers never contend.
        $h = substr(hash('sha256', 'galactic-bloodshed:provision:' . $binktermUserId, true), 0, 8);
        $n = unpack('J', $h)[1];

        return $n;
    }

    /** 160 bits of entropy, hex-encoded so it can never contain a space or
     *  newline -- GB's wire login line is "race_password governor_password". */
    private function generateOpaquePassword(): string
    {
        return bin2hex(random_bytes(20));
    }

    private function generateToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Default reconciliation login probe: a raw TCP connection to the live
     * GB server, sending "racePw govPw" as GB's login line expects (see
     * gb/server/auth.cc parse_connect()) and inspecting the response.
     * Config: GALACTICBLOODSHED_HOST (default 127.0.0.1),
     * GALACTICBLOODSHED_PORT (default 2010).
     */
    private function defaultLoginProbe(string $racePassword, string $governorPassword): ?int
    {
        $host = \BinktermPHP\Config::env('GALACTICBLOODSHED_HOST', '127.0.0.1');
        $port = (int)\BinktermPHP\Config::env('GALACTICBLOODSHED_PORT', '2010');

        $fp = @fsockopen($host, $port, $errno, $errstr, 5.0);
        if ($fp === false) {
            return null; // server unreachable -- cannot confirm; treat as not-created
        }

        try {
            stream_set_timeout($fp, 5);
            fwrite($fp, $racePassword . ' ' . $governorPassword . "\r\n");
            sodium_memzero($racePassword);
            sodium_memzero($governorPassword);

            $banner = '';
            $deadline = microtime(true) + 5.0;
            while (!feof($fp) && microtime(true) < $deadline) {
                $chunk = fread($fp, 4096);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $banner .= $chunk;
                if (str_contains($banner, 'logged on.') || str_contains($banner, 'Connection refused.')) {
                    break;
                }
            }

            if (preg_match('/\[(\d+),\d+\]\s+logged on\./', $banner, $m) === 1) {
                return (int)$m[1];
            }

            return null;
        } finally {
            fclose($fp);
        }
    }

    private function assertUserId(int $binktermUserId): void
    {
        if ($binktermUserId <= 0) {
            throw new GalacticBloodshedIdentityException("Invalid BinkTerm user id: $binktermUserId");
        }
    }
}
