<?php

/**
 * Security regression tests for BinkP CRAM-MD5 authentication logging.
 *
 * These tests prove two things at once:
 *   1. Authentication material is still computed correctly (protocol behavior
 *      is unchanged), and
 *   2. The debug log output produced along the way never contains the
 *      plaintext password, the password length, the CRAM-MD5 challenge value,
 *      or the HMAC digest value.
 *
 * No real credentials are used anywhere in this file.
 */

use BinktermPHP\Binkp\Protocol\BinkpSession;
use PHPUnit\Framework\TestCase;

class BinkpCramAuthLoggingTest extends TestCase
{
    /** Fake password and challenge — not real credentials. */
    private const FAKE_PASSWORD = 'unit-test-passphrase-1234';
    private const FAKE_CHALLENGE = 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6';

    private $socket;

    /** @var array<int,string> Captured "level|message" log lines. */
    private array $captured = [];

    protected function setUp(): void
    {
        $this->socket = fopen('php://memory', 'r+');
        $this->captured = [];
    }

    protected function tearDown(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }

    private function makeSession(): BinkpSession
    {
        // Minimal config stub — the methods under test do not touch config.
        $config = new class {
        };

        $session = new BinkpSession($this->socket, true, $config);

        $logger = new class($this->captured) {
            private array $sink;
            public function __construct(array &$sink)
            {
                $this->sink = &$sink;
            }
            public function log($level, $message, $context = []): void
            {
                $this->sink[] = $level . '|' . $message;
            }
        };
        $session->setLogger($logger);

        return $session;
    }

    private function allLogText(): string
    {
        return implode("\n", $this->captured);
    }

    private function assertLogsAreClean(string $password, string $challenge, string $digest): void
    {
        $haystack = $this->allLogText();

        $this->assertStringNotContainsString($password, $haystack, 'log leaked plaintext password');
        $this->assertStringNotContainsString($challenge, $haystack, 'log leaked CRAM-MD5 challenge value');
        $this->assertStringNotContainsString($digest, $haystack, 'log leaked HMAC digest value');

        // Password length must not be recoverable from the log.
        $this->assertDoesNotMatchRegularExpression('/password_len\s*=/i', $haystack);
        $this->assertDoesNotMatchRegularExpression('/length\s*=\s*\d/i', $haystack);
        $this->assertDoesNotMatchRegularExpression('/len\s*=\s*\d/i', $haystack);
    }

    public function testComputeCramDigestIsCorrectAndDoesNotLogSecrets(): void
    {
        $session = $this->makeSession();

        $method = new ReflectionMethod(BinkpSession::class, 'computeCramDigest');
        $method->setAccessible(true);

        $digest = $method->invoke($session, self::FAKE_CHALLENGE, self::FAKE_PASSWORD);

        // Protocol behavior unchanged: HMAC-MD5(key=password, msg=binary challenge).
        $expected = hash_hmac('md5', hex2bin(self::FAKE_CHALLENGE), self::FAKE_PASSWORD);
        $this->assertSame($expected, $digest, 'CRAM-MD5 digest calculation changed');

        $this->assertNotEmpty($this->captured, 'expected a safe state log line');
        $this->assertLogsAreClean(self::FAKE_PASSWORD, self::FAKE_CHALLENGE, $digest);
    }

    public function testParseCramChallengeReturnsValueButDoesNotLogIt(): void
    {
        $session = $this->makeSession();

        $method = new ReflectionMethod(BinkpSession::class, 'parseCramChallenge');
        $method->setAccessible(true);

        $parsed = $method->invoke($session, 'OPT CRAM-MD5-' . self::FAKE_CHALLENGE);

        $this->assertSame(self::FAKE_CHALLENGE, $parsed, 'challenge parsing changed');
        $this->assertStringNotContainsString(self::FAKE_CHALLENGE, $this->allLogText());
        $this->assertDoesNotMatchRegularExpression('/len\s*=\s*\d/i', $this->allLogText());
    }

    /**
     * Source-level guardrail: the known unsafe log formulations must not
     * reappear anywhere in BinkpSession.
     */
    public function testBinkpSessionSourceHasNoCredentialLoggingPatterns(): void
    {
        $ref = new ReflectionClass(BinkpSession::class);
        $src = file_get_contents($ref->getFileName());

        $forbidden = [
            'setUplinkPassword: length=',
            'password_len=',
            'CRAM-MD5 HMAC digest:',
            'Parsed CRAM-MD5 challenge: " . $challenge',
            'Sent password (length=',
            '$receivedPreview',
            '$expectedPreview',
        ];

        foreach ($forbidden as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $src,
                "BinkpSession still contains credential-logging pattern: {$needle}"
            );
        }
    }

    public function testSetUplinkPasswordDoesNotLogPasswordOrLength(): void
    {
        $session = $this->makeSession();

        $session->setUplinkPassword(self::FAKE_PASSWORD);

        $haystack = $this->allLogText();
        $this->assertStringNotContainsString(self::FAKE_PASSWORD, $haystack);
        $this->assertStringNotContainsString((string) strlen(self::FAKE_PASSWORD), $haystack);
        $this->assertDoesNotMatchRegularExpression('/length\s*=/i', $haystack);
    }
}
