<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AsciiRoyaleM3ManifestTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    public function testProofManifestIsPrivateRawUtf8NativeDoor(): void
    {
        $path = self::ROOT . '/native-doors/doors/ascii-royale-m3/nativedoor.json';
        $raw = (string)file_get_contents($path);
        $m = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('nativedoor', $m['type']);
        self::assertSame('raw', $m['door']['terminal_mode']);
        self::assertSame('utf8', $m['door']['output_encoding']);
        self::assertTrue($m['requirements']['admin_only']);
        self::assertFalse($m['config']['enabled']);
        self::assertFalse($m['config']['allow_anonymous']);
        self::assertSame(0, $m['config']['guest_max_sessions']);
        self::assertFalse($m['experience']['featured']);
        self::assertSame(
            '/bin/bash launch-ascii-royale.sh "{user_name}" "{user_number}"',
            $m['door']['launch_command']
        );
        self::assertDoesNotMatchRegularExpression('/[0-9a-f]{64}/', $raw);
        foreach (['--announce', ' serve ', ' play ', ' browse '] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $raw);
        }
    }

    public function testProofManifestLeavesTerminalSizeToDeploymentConfig(): void
    {
        $path = self::ROOT . '/native-doors/doors/ascii-royale-m3/nativedoor.json';
        $m = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        // Terminal size for this proof is a deployment / admin-runtime concern
        // (config/nativedoors.json), not a hardcoded manifest value. The tracked
        // manifest must not pin it in either location.
        self::assertArrayNotHasKey('terminal_size', $m['door']);
        self::assertArrayNotHasKey('terminal_size', $m['config']);
    }
}
