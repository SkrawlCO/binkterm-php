<?php

declare(strict_types=1);

namespace BinktermPHP\Crossroads;

use BinktermPHP\Config;
use BinktermPHP\Database;
use PDO;

/**
 * ChessmataIdentity
 *
 * L33TEST/Crossroads-owned identity broker for Crossroads Experience #4
 * (Chessmata, Slice 2):
 *
 *     authenticated BinkTerm user  ->  broker  ->  ordinary HTTP  ->  Chessmata API
 *
 * One BinkTerm user resolves deterministically to exactly one self-hosted
 * Chessmata account, usable from BOTH future surfaces (graphical Web, terminal
 * CLI), preserving Elo / history / leaderboard / matchmaking. Web and Telnet
 * cannot create separate identities for the same user: a UNIQUE constraint on
 * chessmata_identities.binkterm_user_id plus a per-user advisory lock during
 * first-provision serialise concurrent resolves.
 *
 * BinkTerm stays completely ignorant of Chessmata's Mongo schema -- it only
 * consumes the documented HTTP responses. Stored bearer secrets are always
 * encrypted (ChessmataSecretBox); nothing here logs a password, token or key.
 *
 * NOT a generic external-identity capability. Deliberately Chessmata-shaped.
 */
final class ChessmataIdentity
{
    private const EMAIL_DOMAIN   = 'chessmata.invalid'; // RFC 2606 -- never deliverable
    private const API_KEY_NAME   = 'binkterm-crossroads';
    private const ACCESS_TTL_SEC = 30 * 24 * 3600;      // Chessmata jwt.accessTTL
    private const REFRESH_TTL_SEC = 90 * 24 * 3600;     // Chessmata jwt.refreshTTL
    private const ACCESS_SKEW_SEC = 300;                // refresh a little early

    private PDO $db;
    private ChessmataApiInterface $api;
    private ChessmataSecretBox $box;

    public function __construct(?PDO $db = null, ?ChessmataApiInterface $api = null, ?ChessmataSecretBox $box = null)
    {
        $this->db = $db ?? Database::getInstance()->getPdo();
        $this->api = $api ?? new ChessmataApiClient();
        try {
            $this->box = $box ?? new ChessmataSecretBox();
        } catch (\Throwable $e) {
            throw new ChessmataBrokerUnavailable('Chessmata broker key not configured', 0, $e);
        }
    }

    /** True when the broker can operate on this host (key + reachable schema). */
    public static function isAvailable(): bool
    {
        return ChessmataSecretBox::isConfigured();
    }

    /**
     * Ensure this BinkTerm user has a Chessmata account; provision one on first
     * call. Idempotent and concurrency-safe.
     *
     * @param int         $binktermUserId immutable users.id (> 0)
     * @param string|null $clientIp       genuine caller address, forwarded to
     *                                    Chessmata so its per-IP register limit
     *                                    applies per real caller
     */
    public function resolve(int $binktermUserId, ?string $clientIp = null): ChessmataAccount
    {
        $this->assertUserId($binktermUserId);

        $row = $this->loadRow($binktermUserId);
        if ($row !== null) {
            return $this->accountFromRow($row);
        }

        // First resolve: serialise concurrent provisioners for this user.
        $this->db->beginTransaction();
        try {
            $lockKey = $this->advisoryKey($binktermUserId);
            $this->db->prepare('SELECT pg_advisory_xact_lock(?)')->execute([$lockKey]);

            $row = $this->loadRow($binktermUserId); // re-check under the lock
            if ($row !== null) {
                $this->db->commit();

                return $this->accountFromRow($row);
            }

            $account = $this->provision($binktermUserId, $clientIp);
            $this->db->commit();

            return $account;
        } catch (\PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            // 23505 = unique_violation on binkterm_user_id: another resolver won
            // the race (belt-and-suspenders behind the advisory lock). Their row
            // is committed now -- return it instead of failing.
            if (($e->getCode() === '23505') && ($row = $this->loadRow($binktermUserId)) !== null) {
                return $this->accountFromRow($row);
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Durable terminal credential for the official Chessmata CLI: the account's
     * cmk_ API key (no expiry). Provisions on first call.
     */
    public function terminalCredential(int $binktermUserId, ?string $clientIp = null): string
    {
        $this->resolve($binktermUserId, $clientIp);
        $row = $this->loadRow($binktermUserId);

        if ($row !== null && !empty($row['api_key_enc'])) {
            return $this->box->decrypt((string)$row['api_key_enc']);
        }

        // Defensive: provisioning always mints a key, but recover if it is gone.
        $access = $this->webCredential($binktermUserId, $clientIp)['accessToken'];
        $key = $this->mintApiKey($access);
        $this->db->prepare(
            'UPDATE chessmata_identities SET api_key_enc = ?, updated_at = NOW() WHERE binkterm_user_id = ?'
        )->execute([$this->box->encrypt($key), $binktermUserId]);

        return $key;
    }

    /**
     * Web credential for the SAME Chessmata account: a valid access (JWT) token.
     * Uses the cached token, then the refresh token, then a full re-login from
     * the stored password -- the recovery root, because Chessmata refresh
     * tokens expire (90d) and do not rotate into a new refresh lifetime.
     *
     * @return array{accessToken:string, expiresAt:string}
     */
    public function webCredential(int $binktermUserId, ?string $clientIp = null): array
    {
        $this->resolve($binktermUserId, $clientIp);
        $row = $this->loadRow($binktermUserId);
        if ($row === null) {
            throw new ChessmataIdentityException('mapping vanished during webCredential');
        }

        $now = time();

        // 1. cached access token still comfortably valid?
        if (!empty($row['access_token_enc']) && $row['access_token_expires_at'] !== null) {
            $exp = strtotime((string)$row['access_token_expires_at']);
            if ($exp !== false && $exp - self::ACCESS_SKEW_SEC > $now) {
                return [
                    'accessToken' => $this->box->decrypt((string)$row['access_token_enc']),
                    'expiresAt'   => (string)$row['access_token_expires_at'],
                ];
            }
        }

        // 2. refresh from the refresh token
        if (!empty($row['refresh_token_enc'])) {
            $refreshExp = $row['refresh_token_expires_at'] !== null
                ? strtotime((string)$row['refresh_token_expires_at'])
                : false;
            if ($refreshExp === false || $refreshExp > $now) {
                try {
                    $refresh = $this->box->decrypt((string)$row['refresh_token_enc']);
                    $res = $this->api->refresh($refresh);
                    if ($res['status'] === 200 && !empty($res['data']['accessToken'])) {
                        return $this->storeAccessToken($binktermUserId, (string)$res['data']['accessToken']);
                    }
                } catch (\Throwable $e) {
                    // fall through to re-login
                }
            }
        }

        // 3. full re-login from the stored password
        $password = $this->box->decrypt((string)$row['password_enc']);
        $res = $this->api->login((string)$row['chessmata_email'], $password, $clientIp);
        sodium_memzero($password);
        if ($res['status'] !== 200 || empty($res['data']['accessToken']) || empty($res['data']['refreshToken'])) {
            throw new ChessmataIdentityException('Chessmata re-login failed (status ' . $res['status'] . ')');
        }

        $this->db->prepare(
            'UPDATE chessmata_identities
                SET refresh_token_enc = ?, refresh_token_expires_at = NOW() + INTERVAL \'90 days\', updated_at = NOW()
              WHERE binkterm_user_id = ?'
        )->execute([$this->box->encrypt((string)$res['data']['refreshToken']), $binktermUserId]);

        return $this->storeAccessToken($binktermUserId, (string)$res['data']['accessToken']);
    }

    /**
     * Secret-free view of an existing mapping (or null). For diagnostics /
     * acceptance tooling -- never returns an *_enc column.
     *
     * @return array<string,mixed>|null
     */
    public function existingMapping(int $binktermUserId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT binkterm_user_id, chessmata_user_id, chessmata_email, chessmata_display_name,
                    (api_key_enc IS NOT NULL)      AS has_api_key,
                    (refresh_token_enc IS NOT NULL) AS has_refresh_token,
                    (access_token_enc IS NOT NULL)  AS has_access_token,
                    access_token_expires_at, refresh_token_expires_at, provisioned_at, updated_at
               FROM chessmata_identities WHERE binkterm_user_id = ?'
        );
        $stmt->execute([$binktermUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Delete the LOCAL mapping only (the remote Chessmata account is left
     * intact -- there is no delete-account endpoint). Used by acceptance
     * cleanup; a real caller keeps their identity forever.
     */
    public function forget(int $binktermUserId): void
    {
        $this->db->prepare('DELETE FROM chessmata_identities WHERE binkterm_user_id = ?')
            ->execute([$binktermUserId]);
    }

    // ---------------------------------------------------------------- internal

    private function provision(int $binktermUserId, ?string $clientIp): ChessmataAccount
    {
        $email = 'bt-' . $binktermUserId . '@' . self::EMAIL_DOMAIN;
        $baseName = $this->deriveDisplayName($binktermUserId);
        $password = $this->generatePassword();

        $displayName = $baseName;
        $res = $this->api->register($email, $password, $displayName, $clientIp);

        if ($res['status'] === 409) {
            // display name (or, impossibly, the email) already taken -> a
            // guaranteed-unique fallback.
            $displayName = substr($baseName, 0, 55) . '-' . $binktermUserId;
            $res = $this->api->register($email, $password, $displayName, $clientIp);
        }

        if ($res['status'] === 429) {
            sodium_memzero($password);
            throw new ChessmataProvisioningRateLimited(
                'Chessmata account-creation rate limit hit; retry on the caller\'s next launch'
            );
        }

        if ($res['status'] !== 201) {
            sodium_memzero($password);
            $msg = isset($res['data']['error']) ? (string)$res['data']['error'] : ('status ' . $res['status']);
            throw new ChessmataIdentityException('Chessmata registration failed: ' . $msg);
        }

        $user = \is_array($res['data']['user'] ?? null) ? $res['data']['user'] : [];
        $chessmataUserId = (string)($user['id'] ?? '');
        $accessToken = (string)($res['data']['accessToken'] ?? '');
        $refreshToken = (string)($res['data']['refreshToken'] ?? '');

        if ($chessmataUserId === '' || $accessToken === '' || $refreshToken === '') {
            sodium_memzero($password);
            throw new ChessmataIdentityException('Chessmata registration response missing id/tokens');
        }

        // Patch 0003 opt-in must be in effect: a broker account is useless
        // unverified (CreateGame/JoinGame/matchmaking would 403).
        if (($user['emailVerified'] ?? false) !== true) {
            sodium_memzero($password);
            throw new ChessmataIdentityException(
                'Chessmata account created unverified -- auth.autoVerifyEmail is not enabled on the service'
            );
        }

        $apiKey = $this->mintApiKey($accessToken);

        $now = new \DateTimeImmutable('now');
        $stmt = $this->db->prepare(
            'INSERT INTO chessmata_identities
                (binkterm_user_id, chessmata_user_id, chessmata_email, chessmata_display_name,
                 password_enc, api_key_enc, refresh_token_enc, access_token_enc,
                 access_token_expires_at, refresh_token_expires_at, provisioned_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?,
                     NOW() + INTERVAL \'30 days\', NOW() + INTERVAL \'90 days\', NOW(), NOW())'
        );
        $stmt->execute([
            $binktermUserId,
            $chessmataUserId,
            $email,
            $displayName,
            $this->box->encrypt($password),
            $this->box->encrypt($apiKey),
            $this->box->encrypt($refreshToken),
            $this->box->encrypt($accessToken),
        ]);

        sodium_memzero($password);

        return new ChessmataAccount($binktermUserId, $chessmataUserId, $email, $displayName, $now);
    }

    private function mintApiKey(string $accessToken): string
    {
        $res = $this->api->createApiKey($accessToken, self::API_KEY_NAME);
        if ($res['status'] !== 201 || empty($res['data']['key'])) {
            throw new ChessmataIdentityException('Chessmata API-key creation failed (status ' . $res['status'] . ')');
        }
        $key = (string)$res['data']['key'];
        if (!str_starts_with($key, 'cmk_')) {
            throw new ChessmataIdentityException('Chessmata returned an unexpected API-key shape');
        }

        return $key;
    }

    /** @return array{accessToken:string, expiresAt:string} */
    private function storeAccessToken(int $binktermUserId, string $accessToken): array
    {
        $stmt = $this->db->prepare(
            'UPDATE chessmata_identities
                SET access_token_enc = ?, access_token_expires_at = NOW() + INTERVAL \'30 days\', updated_at = NOW()
              WHERE binkterm_user_id = ?
          RETURNING access_token_expires_at'
        );
        $stmt->execute([$this->box->encrypt($accessToken), $binktermUserId]);
        $expiresAt = (string)$stmt->fetchColumn();

        return ['accessToken' => $accessToken, 'expiresAt' => $expiresAt];
    }

    private function deriveDisplayName(int $binktermUserId): string
    {
        $stmt = $this->db->prepare('SELECT username FROM users WHERE id = ?');
        $stmt->execute([$binktermUserId]);
        $username = (string)($stmt->fetchColumn() ?: '');

        $clean = preg_replace('/[^A-Za-z0-9_-]/', '', $username) ?? '';
        $clean = trim($clean, '-_');
        $clean = substr($clean, 0, 24);

        return $clean !== '' ? $clean : ('bt' . $binktermUserId);
    }

    /** Strong password that always satisfies Chessmata's policy (>=10, U+l+d+special). */
    private function generatePassword(): string
    {
        return rtrim(base64_encode(random_bytes(24)), '=') . 'Aa1!';
    }

    private function advisoryKey(int $binktermUserId): int
    {
        // Stable 63-bit key from a namespaced string; collisions only cost a
        // brief extra wait, never correctness.
        $h = substr(hash('sha256', 'chessmata:provision:' . $binktermUserId, true), 0, 8);
        $n = unpack('J', $h)[1];

        return $n & 0x7fffffffffffffff;
    }

    /** @param array<string,mixed> $row */
    private function accountFromRow(array $row): ChessmataAccount
    {
        return new ChessmataAccount(
            (int)$row['binkterm_user_id'],
            (string)$row['chessmata_user_id'],
            (string)$row['chessmata_email'],
            (string)$row['chessmata_display_name'],
            new \DateTimeImmutable((string)$row['provisioned_at']),
        );
    }

    /** @return array<string,mixed>|null */
    private function loadRow(int $binktermUserId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM chessmata_identities WHERE binkterm_user_id = ?');
        $stmt->execute([$binktermUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function assertUserId(int $binktermUserId): void
    {
        if ($binktermUserId <= 0) {
            throw new \InvalidArgumentException('ChessmataIdentity requires a positive BinkTerm user id');
        }
    }
}
