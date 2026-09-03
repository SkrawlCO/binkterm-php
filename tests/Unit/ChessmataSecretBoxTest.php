<?php

declare(strict_types=1);

use BinktermPHP\Crossroads\ChessmataSecretBox;
use PHPUnit\Framework\TestCase;

/**
 * Encrypt-at-rest for the Chessmata identity broker's stored bearer secrets.
 * BinkTermPHP has no shared encryption facility, so this is a Chessmata-scoped
 * libsodium (crypto_secretbox) wrapper — these tests pin its guarantees.
 */
final class ChessmataSecretBoxTest extends TestCase
{
    private string $key;

    protected function setUp(): void
    {
        $this->key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public function testRoundTrips(): void
    {
        $box = new ChessmataSecretBox($this->key);
        $secret = 'cmk_' . bin2hex(random_bytes(24));

        $this->assertSame($secret, $box->decrypt($box->encrypt($secret)));
    }

    public function testCiphertextDoesNotContainThePlaintext(): void
    {
        $box = new ChessmataSecretBox($this->key);
        $secret = 'refresh-token-value-1234567890';

        $ct = $box->encrypt($secret);

        $this->assertStringNotContainsString($secret, $ct);
        $this->assertStringNotContainsString($secret, base64_decode($ct, true) ?: '');
    }

    public function testEachEncryptionUsesAFreshNonce(): void
    {
        $box = new ChessmataSecretBox($this->key);

        $this->assertNotSame($box->encrypt('same'), $box->encrypt('same'));
    }

    public function testWrongKeyIsRejected(): void
    {
        $ct = (new ChessmataSecretBox($this->key))->encrypt('secret');

        $this->expectException(\RuntimeException::class);
        (new ChessmataSecretBox(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)))->decrypt($ct);
    }

    public function testTamperedCiphertextIsRejected(): void
    {
        $box = new ChessmataSecretBox($this->key);
        $raw = base64_decode($box->encrypt('secret'), true);
        $raw[strlen($raw) - 1] = $raw[strlen($raw) - 1] === "\x00" ? "\x01" : "\x00";

        $this->expectException(\RuntimeException::class);
        $box->decrypt(base64_encode($raw));
    }

    public function testMalformedValueIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        (new ChessmataSecretBox($this->key))->decrypt('not-base64-!!!');
    }

    public function testRejectsWrongKeyLength(): void
    {
        $this->expectException(\RuntimeException::class);
        new ChessmataSecretBox(random_bytes(16));
    }

    public function testAcceptsHexAndBase64KeyMaterialViaEnv(): void
    {
        $raw = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);

        foreach ([bin2hex($raw), base64_encode($raw)] as $encoded) {
            $file = tempnam(sys_get_temp_dir(), 'cmk');
            file_put_contents($file, $encoded);
            $prev = $_ENV['CHESSMATA_BROKER_KEY_FILE'] ?? null;
            $_ENV['CHESSMATA_BROKER_KEY_FILE'] = $file;
            try {
                $box = new ChessmataSecretBox();
                $this->assertSame('x', $box->decrypt($box->encrypt('x')));
            } finally {
                if ($prev === null) {
                    unset($_ENV['CHESSMATA_BROKER_KEY_FILE']);
                } else {
                    $_ENV['CHESSMATA_BROKER_KEY_FILE'] = $prev;
                }
                @unlink($file);
            }
        }
    }
}
