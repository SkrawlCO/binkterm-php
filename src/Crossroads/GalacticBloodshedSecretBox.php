<?php

declare(strict_types=1);

namespace BinktermPHP\Crossroads;

use BinktermPHP\Config;

/**
 * GalacticBloodshedSecretBox
 *
 * L33TEST/Crossroads-owned encrypt-at-rest for the Galactic Bloodshed identity
 * broker's stored race/governor passwords (the opaque credentials
 * GalacticBloodshedIdentity generates and later types into `enrol`/the
 * upstream client on the caller's behalf). Same construction as
 * ChessmataSecretBox (Slice 2) -- deliberately a separate, GB-scoped class
 * rather than a shared dependency, so the two Experiences never couple
 * through a common broker-key file/env var pair; the underlying primitive
 * (PHP's built-in libsodium secretbox) is what's reused, not the class.
 *
 * Format: base64( 24-byte nonce || sodium_crypto_secretbox ciphertext ).
 *
 * Key: 32 raw bytes read once from the file named by
 * GALACTICBLOODSHED_BROKER_KEY_FILE (default
 * /run/secrets/galactic_bloodshed_broker_key), or from
 * GALACTICBLOODSHED_BROKER_KEY (base64 or 64-hex) as a fallback. A deployment
 * secret delivered the same way as Chessmata's broker key; lives only in
 * ./secrets/ in the ops repo, never in git, never in a log.
 *
 * Rotating the key orphans every stored credential. Unlike Chessmata (which
 * can re-login or re-register from a recoverable password), Galactic
 * Bloodshed has no account-recovery API at all -- an orphaned race is
 * unreachable until a sysop manually re-enrols or intervenes in GB's SQLite
 * database directly. Guard this key at least as carefully as the GB universe
 * database itself.
 */
final class GalacticBloodshedSecretBox
{
    private string $key;

    /**
     * @param string|null $key raw 32-byte key; resolved from config when null.
     */
    public function __construct(?string $key = null)
    {
        $this->key = $key ?? self::loadKey();

        if (\strlen($this->key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException(
                'Galactic Bloodshed broker key must be exactly ' . SODIUM_CRYPTO_SECRETBOX_KEYBYTES . ' bytes'
            );
        }
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box = sodium_crypto_secretbox($plaintext, $nonce, $this->key);
        $out = base64_encode($nonce . $box);
        sodium_memzero($plaintext);

        return $out;
    }

    /**
     * @throws \RuntimeException on a tampered / wrong-key / malformed value.
     */
    public function decrypt(string $stored): string
    {
        $raw = base64_decode($stored, true);
        if ($raw === false || \strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Galactic Bloodshed secret is malformed');
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plain = sodium_crypto_secretbox_open($box, $nonce, $this->key);
        if ($plain === false) {
            throw new \RuntimeException('Galactic Bloodshed secret failed authentication (wrong key or tampered)');
        }

        return $plain;
    }

    /**
     * True when a usable key is configured -- lets callers fail cleanly instead
     * of throwing deep in a request.
     */
    public static function isConfigured(): bool
    {
        try {
            self::loadKey();

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function loadKey(): string
    {
        $file = Config::env('GALACTICBLOODSHED_BROKER_KEY_FILE', '/run/secrets/galactic_bloodshed_broker_key');
        if (\is_string($file) && $file !== '' && is_readable($file)) {
            $raw = trim((string)file_get_contents($file));
            $key = self::decodeKeyMaterial($raw);
            if ($key !== null) {
                return $key;
            }
        }

        $inline = Config::env('GALACTICBLOODSHED_BROKER_KEY', '');
        if (\is_string($inline) && $inline !== '') {
            $key = self::decodeKeyMaterial(trim($inline));
            if ($key !== null) {
                return $key;
            }
        }

        throw new \RuntimeException(
            'Galactic Bloodshed broker key not configured '
            . '(GALACTICBLOODSHED_BROKER_KEY_FILE / GALACTICBLOODSHED_BROKER_KEY)'
        );
    }

    /** Accept 32 raw bytes, 64 hex chars, or standard base64 of 32 bytes. */
    private static function decodeKeyMaterial(string $raw): ?string
    {
        if (\strlen($raw) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            return $raw;
        }
        if (preg_match('/^[0-9a-fA-F]{64}$/', $raw) === 1) {
            return (string)hex2bin($raw);
        }
        $b64 = base64_decode($raw, true);
        if ($b64 !== false && \strlen($b64) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            return $b64;
        }

        return null;
    }
}
