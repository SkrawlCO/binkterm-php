<?php

declare(strict_types=1);

namespace BinktermPHP\Crossroads;

use BinktermPHP\Config;

/**
 * ChessmataSecretBox
 *
 * L33TEST/Crossroads-owned encrypt-at-rest for the Chessmata identity broker's
 * stored bearer secrets (generated password, cmk_ API key, refresh/access
 * tokens). BinkTermPHP has no general encryption-at-rest facility -- every other
 * stored external-service secret in the codebase is a plaintext column
 * (hub_nodes.session_password, MultiZork access codes). Slice 2 requires these
 * particular secrets NOT be plaintext, so this is the smallest Chessmata-scoped
 * wrapper around PHP's built-in libsodium (already a dependency -- see
 * src/License.php). It is deliberately NOT a generic capability.
 *
 * Format: base64( 24-byte nonce || sodium_crypto_secretbox ciphertext ).
 *
 * Key: 32 raw bytes read once from the file named by CHESSMATA_BROKER_KEY_FILE
 * (default /run/secrets/chessmata_broker_key), or from CHESSMATA_BROKER_KEY
 * (base64 or 64-hex) as a fallback. The key is a deployment secret delivered to
 * the container the same way Chessmata's own JWT secrets are; it lives only in
 * ./secrets/ in the ops repo, never in git, never in a log.
 *
 * Rotating the key orphans every stored credential -- the broker then
 * re-provisions from the password on the next resolve, or re-registers if the
 * password too is unreadable. This is acceptable and documented.
 */
final class ChessmataSecretBox
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
                'Chessmata broker key must be exactly ' . SODIUM_CRYPTO_SECRETBOX_KEYBYTES . ' bytes'
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
            throw new \RuntimeException('Chessmata secret is malformed');
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plain = sodium_crypto_secretbox_open($box, $nonce, $this->key);
        if ($plain === false) {
            throw new \RuntimeException('Chessmata secret failed authentication (wrong key or tampered)');
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
        $file = Config::env('CHESSMATA_BROKER_KEY_FILE', '/run/secrets/chessmata_broker_key');
        if (\is_string($file) && $file !== '' && is_readable($file)) {
            $raw = trim((string)file_get_contents($file));
            $key = self::decodeKeyMaterial($raw);
            if ($key !== null) {
                return $key;
            }
        }

        $inline = Config::env('CHESSMATA_BROKER_KEY', '');
        if (\is_string($inline) && $inline !== '') {
            $key = self::decodeKeyMaterial(trim($inline));
            if ($key !== null) {
                return $key;
            }
        }

        throw new \RuntimeException(
            'Chessmata broker key not configured (CHESSMATA_BROKER_KEY_FILE / CHESSMATA_BROKER_KEY)'
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
