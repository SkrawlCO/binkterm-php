<?php

declare(strict_types=1);

use BinktermPHP\Crossroads\GalacticBloodshedIdentity;
use BinktermPHP\Crossroads\GalacticBloodshedIdentityException;
use BinktermPHP\Crossroads\GalacticBloodshedProvisioningInProgress;
use BinktermPHP\Crossroads\GalacticBloodshedSecretBox;
use BinktermPHP\Database;
use PHPUnit\Framework\TestCase;

final class GalacticBloodshedIdentityTest extends TestCase
{
    private \PDO $db;
    private GalacticBloodshedSecretBox $box;
    /** @var array<int,int> */
    private array $testUserIds = [];

    protected function setUp(): void
    {
        $this->db = Database::getInstance()->getPdo();
        $this->box = new GalacticBloodshedSecretBox(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    protected function tearDown(): void
    {
        foreach ($this->testUserIds as $id) {
            $this->db->prepare('DELETE FROM galactic_bloodshed_identities WHERE binkterm_user_id = ?')->execute([$id]);
            $this->db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        }
    }

    private function makeUser(): int
    {
        $un = 'gbtest_' . substr(bin2hex(random_bytes(5)), 0, 10);
        $this->db->prepare(
            'INSERT INTO users (username, password_hash, email, real_name, is_active, is_admin, created_at)
             VALUES (?, ?, ?, ?, true, false, NOW())'
        )->execute([$un, 'x', $un . '@example.invalid', ucfirst($un)]);
        $id = (int)$this->db->query('SELECT id FROM users WHERE username = ' . $this->db->quote($un))->fetchColumn();
        $this->testUserIds[] = $id;

        return $id;
    }

    private function broker(): GalacticBloodshedIdentity
    {
        return new GalacticBloodshedIdentity($this->db, $this->box);
    }

    private function backdateAttempt(int $userId, int $secondsAgo): void
    {
        $this->db->prepare(
            "UPDATE galactic_bloodshed_identities SET attempt_started_at = NOW() - (? || ' seconds')::interval
              WHERE binkterm_user_id = ?"
        )->execute([$secondsAgo, $userId]);
    }

    // ---------------------------------------------------------- first launch

    public function testFirstResolveClaimsProvisioningWithOpaqueCredentials(): void
    {
        $uid = $this->makeUser();
        $result = $this->broker()->resolve($uid);

        $this->assertSame('needs_provisioning', $result['status']);
        $this->assertNotEmpty($result['race_password']);
        $this->assertNotEmpty($result['governor_password']);
        $this->assertNotSame($result['race_password'], $result['governor_password']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $result['race_password'], 'expected 160-bit hex, not a derived/predictable value');
        $this->assertNotEmpty($result['attempt_token']);

        $otherUid = $this->makeUser();
        $otherResult = $this->broker()->resolve($otherUid);
        $this->assertNotSame(
            $result['race_password'],
            $otherResult['race_password'],
            'must not be deterministically derived from the user id'
        );

        $row = $this->broker()->existingMapping($uid);
        $this->assertSame('provisioning', $row['status']);
    }

    // -------------------------------------------------------- returning path

    public function testConfirmedProvisioningIsReturnedIdenticallyOnNextResolve(): void
    {
        $uid = $this->makeUser();
        $broker = $this->broker();

        $first = $broker->resolve($uid);
        $broker->confirmProvisioned($uid, $first['attempt_token'], 7);

        $second = $broker->resolve($uid);

        $this->assertSame('provisioned', $second['status']);
        $this->assertSame(7, $second['gb_playernum']);
        $this->assertSame($first['race_password'], $second['race_password']);
        $this->assertSame($first['governor_password'], $second['governor_password']);
    }

    public function testConfirmProvisionedRejectsWrongToken(): void
    {
        $uid = $this->makeUser();
        $broker = $this->broker();
        $broker->resolve($uid);

        $this->expectException(GalacticBloodshedIdentityException::class);
        $broker->confirmProvisioned($uid, 'not-the-real-token', 1);
    }

    // --------------------------------------------------------- enrol failure

    public function testFailedProvisioningResetsAndNextAttemptGetsFreshCredentials(): void
    {
        $uid = $this->makeUser();
        $broker = $this->broker();

        $first = $broker->resolve($uid);
        $broker->failProvisioning($uid, $first['attempt_token']);

        $this->assertSame('pending', $broker->existingMapping($uid)['status']);

        $second = $broker->resolve($uid);
        $this->assertSame('needs_provisioning', $second['status']);
        $this->assertNotSame($first['race_password'], $second['race_password'], 'a failed attempt\'s credentials must not be reused');
    }

    public function testFailProvisioningRejectsWrongToken(): void
    {
        $uid = $this->makeUser();
        $broker = $this->broker();
        $broker->resolve($uid);

        $this->expectException(GalacticBloodshedIdentityException::class);
        $broker->failProvisioning($uid, 'not-the-real-token');
    }

    // --------------------------------------------------- duplicate/concurrent

    public function testFreshInProgressAttemptBlocksASecondResolve(): void
    {
        $uid = $this->makeUser();
        $this->broker()->resolve($uid); // claims; attempt_started_at = NOW()

        $this->expectException(GalacticBloodshedProvisioningInProgress::class);
        $this->broker()->resolve($uid); // a second launcher for the same user
    }

    // ------------------------------------------------- crash reconciliation

    public function testStaleAttemptReconciledAsProvisionedWhenLoginProbeConfirmsIt(): void
    {
        $uid = $this->makeUser();
        $first = $this->broker()->resolve($uid);
        $this->backdateAttempt($uid, 600); // simulate a launcher that crashed 10 minutes ago

        $probeCalls = [];
        $result = $this->broker()->resolve($uid, function (string $race, string $gov) use (&$probeCalls, $first) {
            $probeCalls[] = [$race, $gov];
            $this->assertSame($first['race_password'], $race);
            $this->assertSame($first['governor_password'], $gov);

            return 42; // GB confirms the race exists
        });

        $this->assertCount(1, $probeCalls);
        $this->assertSame('provisioned', $result['status']);
        $this->assertSame(42, $result['gb_playernum']);
        $this->assertSame($first['race_password'], $result['race_password']);
        $this->assertSame('provisioned', $this->broker()->existingMapping($uid)['status']);
    }

    public function testStaleAttemptResetWithFreshCredentialsWhenLoginProbeFindsNothing(): void
    {
        $uid = $this->makeUser();
        $first = $this->broker()->resolve($uid);
        $this->backdateAttempt($uid, 600);

        $result = $this->broker()->resolve($uid, fn (string $race, string $gov): ?int => null);

        $this->assertSame('needs_provisioning', $result['status']);
        $this->assertNotSame($first['race_password'], $result['race_password'], 'a confirmed-never-created credential must not be reissued');
    }

    // --------------------------------------------------- wrong/corrupt secret

    public function testWrongBrokerKeyFailsToDecryptAnExistingProvisionedRow(): void
    {
        $uid = $this->makeUser();
        $broker = $this->broker();
        $first = $broker->resolve($uid);
        $broker->confirmProvisioned($uid, $first['attempt_token'], 1);

        $wrongKeyBroker = new GalacticBloodshedIdentity($this->db, new GalacticBloodshedSecretBox(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));

        $this->expectException(RuntimeException::class);
        $wrongKeyBroker->resolve($uid);
    }

    public function testInvalidUserIdIsRejected(): void
    {
        $this->expectException(GalacticBloodshedIdentityException::class);
        $this->broker()->resolve(0);
    }

    public function testForgetRemovesLocalMappingOnly(): void
    {
        $uid = $this->makeUser();
        $this->broker()->resolve($uid);
        $this->assertNotNull($this->broker()->existingMapping($uid));

        $this->broker()->forget($uid);
        $this->assertNull($this->broker()->existingMapping($uid));
    }
}
