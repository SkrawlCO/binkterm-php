<?php

declare(strict_types=1);

namespace BinktermPHP;

/**
 * SysK — encrypt-at-rest for BinktermPHP-owned service secrets.
 *
 * Forward-ported and adapted from the upstream `qwknet` branch. Its first
 * consumer is {@see \BinktermPHP\Qwk\QwkMailboxManager}, which must never
 * persist a QWK peer's FTP password in plaintext.
 *
 * Cipher: libsodium secretbox (XSalsa20-Poly1305). Stored form is
 * `base64( 24-byte nonce || ciphertext+MAC )`.
 *
 * Key resolution, highest precedence first:
 *   1. `SYSK_KEY` env — 32 raw bytes, 64 hex chars, or base64 of 32 bytes.
 *   2. The key file named by `SYSK_KEY_FILE` env
 *      (default `<app>/data/sysk.dat`), holding 64 hex chars. It is created
 *      with `0600` permissions and a fresh random key on first use.
 *
 * The key is a deployment secret: `data/` is git-ignored (and `data/sysk.dat`
 * is listed explicitly in `.gitignore`), the key is never logged, and it must
 * be excluded from any backup that also contains the database. Rotating the key
 * makes every previously stored value undecryptable; callers must be able to
 * re-enter the affected secrets.
 */
final class SysK
{
    private const KEY_BYTES = SODIUM_CRYPTO_SECRETBOX_KEYBYTES;
    private const ENV_KEY = 'SYSK_KEY';
    private const ENV_KEY_FILE = 'SYSK_KEY_FILE';

    /**
     * Encrypt a UTF-8 string for storage.
     *
     * @throws \RuntimeException if the sodium extension or key material is unavailable.
     */
    public static function encrypt(string $plaintext): string
    {
        $key = self::loadKey();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);
        $encoded = base64_encode($nonce . $ciphertext);

        sodium_memzero($key);
        sodium_memzero($plaintext);

        return $encoded;
    }

    /**
     * Decrypt a value produced by {@see encrypt()}. An empty/blank input
     * decrypts to the empty string so callers can treat "no secret set" and
     * "secret is empty" alike.
     *
     * @throws \RuntimeException on a malformed value, wrong key, or tampering.
     */
    public static function decrypt(?string $encoded): string
    {
        $encoded = trim((string)$encoded);
        if ($encoded === '') {
            return '';
        }

        $raw = base64_decode($encoded, true);
        $minLength = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES;
        if ($raw === false || strlen($raw) < $minLength) {
            throw new \RuntimeException('SysK value is malformed');
        }

        $key = self::loadKey();
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);

        sodium_memzero($key);

        if ($plaintext === false) {
            throw new \RuntimeException('SysK value failed authentication (wrong key or tampered)');
        }

        return $plaintext;
    }

    /**
     * True when a usable key is configured. Lets callers (admin UI, CLI)
     * degrade gracefully instead of throwing mid-request.
     */
    public static function isConfigured(): bool
    {
        try {
            $key = self::loadKey();
            sodium_memzero($key);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @return string raw 32-byte key
     * @throws \RuntimeException
     */
    private static function loadKey(): string
    {
        if (!function_exists('sodium_crypto_secretbox')) {
            throw new \RuntimeException('The PHP sodium extension is required for SysK encrypted secret storage.');
        }

        $inline = trim((string)Config::env(self::ENV_KEY, ''));
        if ($inline !== '') {
            $key = self::decodeKeyMaterial($inline);
            if ($key === null) {
                throw new \RuntimeException(
                    self::ENV_KEY . ' must be 32 raw bytes, 64 hex characters, or base64 of 32 bytes.'
                );
            }
            return $key;
        }

        $key = self::decodeKeyMaterial(self::readOrCreateKeyFile());
        if ($key === null) {
            throw new \RuntimeException('Invalid SysK key material in ' . self::keyFilePath());
        }
        return $key;
    }

    private static function keyFilePath(): string
    {
        $configured = trim((string)Config::env(self::ENV_KEY_FILE, ''));
        return $configured !== '' ? $configured : __DIR__ . '/../data/sysk.dat';
    }

    private static function readOrCreateKeyFile(): string
    {
        $path = self::keyFilePath();
        if (is_file($path)) {
            return trim((string)file_get_contents($path));
        }

        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException('Failed to create directory for the SysK key file: ' . $dir);
        }

        $hex = sodium_bin2hex(random_bytes(self::KEY_BYTES));
        // Create the file with restrictive permissions before writing key bytes.
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            // Another process created it between the is_file() check and now.
            if (is_file($path)) {
                return trim((string)file_get_contents($path));
            }
            throw new \RuntimeException('Failed to create the SysK key file: ' . $path);
        }
        @chmod($path, 0600);
        if (fwrite($handle, $hex) === false) {
            fclose($handle);
            throw new \RuntimeException('Failed to write the SysK key file: ' . $path);
        }
        fclose($handle);
        @chmod($path, 0600);

        return $hex;
    }

    /** Accept 32 raw bytes, 64 hex chars, or standard base64 of 32 bytes. */
    private static function decodeKeyMaterial(string $raw): ?string
    {
        if (strlen($raw) === self::KEY_BYTES) {
            return $raw;
        }
        if (preg_match('/^[0-9a-fA-F]{' . (self::KEY_BYTES * 2) . '}$/', $raw) === 1) {
            $bin = hex2bin($raw);
            return $bin === false ? null : $bin;
        }
        $decoded = base64_decode($raw, true);
        if ($decoded !== false && strlen($decoded) === self::KEY_BYTES) {
            return $decoded;
        }
        return null;
    }
}
