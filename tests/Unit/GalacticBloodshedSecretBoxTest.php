<?php

declare(strict_types=1);

use BinktermPHP\Crossroads\GalacticBloodshedSecretBox;
use PHPUnit\Framework\TestCase;

final class GalacticBloodshedSecretBoxTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $box = new GalacticBloodshedSecretBox(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $plaintext = 'race-password-' . bin2hex(random_bytes(8));

        $encrypted = $box->encrypt($plaintext);
        $this->assertNotSame($plaintext, $encrypted);
        $this->assertSame($plaintext, $box->decrypt($encrypted));
    }

    public function testCiphertextIsNotDeterministic(): void
    {
        $box = new GalacticBloodshedSecretBox(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $a = $box->encrypt('same-plaintext');
        $b = $box->encrypt('same-plaintext');
        $this->assertNotSame($a, $b, 'nonce must differ per call');
    }

    public function testWrongKeyFailsAuthentication(): void
    {
        $box1 = new GalacticBloodshedSecretBox(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $box2 = new GalacticBloodshedSecretBox(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $encrypted = $box1->encrypt('secret');

        $this->expectException(RuntimeException::class);
        $box2->decrypt($encrypted);
    }

    public function testTamperedCiphertextFailsAuthentication(): void
    {
        $box = new GalacticBloodshedSecretBox(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $encrypted = $box->encrypt('secret');
        $raw = base64_decode($encrypted, true);
        $raw[\strlen($raw) - 1] = chr(ord($raw[\strlen($raw) - 1]) ^ 0xFF);
        $tampered = base64_encode($raw);

        $this->expectException(RuntimeException::class);
        $box->decrypt($tampered);
    }

    public function testMalformedCiphertextIsRejected(): void
    {
        $box = new GalacticBloodshedSecretBox(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));

        $this->expectException(RuntimeException::class);
        $box->decrypt('not-valid-base64-or-too-short');
    }

    public function testConstructorRejectsWrongKeyLength(): void
    {
        $this->expectException(RuntimeException::class);
        new GalacticBloodshedSecretBox('too-short');
    }

    public function testIsConfiguredFalseWithoutKeyMaterial(): void
    {
        $prevFile = $_ENV['GALACTICBLOODSHED_BROKER_KEY_FILE'] ?? null;
        $prevInline = $_ENV['GALACTICBLOODSHED_BROKER_KEY'] ?? null;
        unset($_ENV['GALACTICBLOODSHED_BROKER_KEY_FILE'], $_ENV['GALACTICBLOODSHED_BROKER_KEY']);

        try {
            $this->assertFalse(GalacticBloodshedSecretBox::isConfigured());
        } finally {
            if ($prevFile !== null) {
                $_ENV['GALACTICBLOODSHED_BROKER_KEY_FILE'] = $prevFile;
            }
            if ($prevInline !== null) {
                $_ENV['GALACTICBLOODSHED_BROKER_KEY'] = $prevInline;
            }
        }
    }

    public function testIsConfiguredTrueWithInlineHexKey(): void
    {
        $_ENV['GALACTICBLOODSHED_BROKER_KEY'] = bin2hex(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        unset($_ENV['GALACTICBLOODSHED_BROKER_KEY_FILE']);

        try {
            $this->assertTrue(GalacticBloodshedSecretBox::isConfigured());
            $box = new GalacticBloodshedSecretBox();
            $this->assertSame('hello', $box->decrypt($box->encrypt('hello')));
        } finally {
            unset($_ENV['GALACTICBLOODSHED_BROKER_KEY']);
        }
    }
}
