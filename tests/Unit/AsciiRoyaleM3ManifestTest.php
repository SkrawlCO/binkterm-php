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
        // M4 Slice 2: opened to ordinary authenticated users. admin_only is the
        // only manifest-authoritative gate for that, so it is now false.
        self::assertFalse($m['requirements']['admin_only']);
        // The manifest is never the enable switch — site-local
        // config/nativedoors.json is. This must stay false.
        self::assertFalse($m['config']['enabled']);
        self::assertFalse($m['config']['allow_anonymous']);
        self::assertSame(0, $m['config']['guest_max_sessions']);
        self::assertFalse($m['experience']['featured']);
        self::assertSame(4, $m['door']['max_nodes']);
        self::assertSame(
            '/bin/bash launch-ascii-royale.sh "{user_name}" "{user_number}"',
            $m['door']['launch_command']
        );
        self::assertDoesNotMatchRegularExpression('/[0-9a-f]{64}/', $raw);
        foreach (['--announce', ' serve ', ' play ', ' browse '] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $raw);
        }
    }

    public function testPublicPresentationCarriesNoProofOrAdminCopy(): void
    {
        $path = self::ROOT . '/native-doors/doors/ascii-royale-m3/nativedoor.json';
        $raw = (string)file_get_contents($path);
        $m = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        // The technical id / directory stays ascii-royale-m3, but nothing a
        // normal Crossroads user sees may carry proof/milestone/admin language.
        self::assertSame('ascii-royale', $m['game']['name']);
        self::assertSame('AR', $m['game']['short_name']);
        self::assertSame(
            'A terminal battle royale — last one standing wins. Fight me next round.',
            $m['game']['description']
        );
        foreach ([$m['game']['name'], $m['game']['short_name'], $m['game']['description']] as $copy) {
            self::assertDoesNotMatchRegularExpression('/\bproof\b/i', $copy);
            self::assertDoesNotMatchRegularExpression('/administrator-only|admin-only|admin only/i', $copy);
            self::assertDoesNotMatchRegularExpression('/\bM3\b/', $copy);
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
