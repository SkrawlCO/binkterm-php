<?php

declare(strict_types=1);

use BinktermPHP\Crossroads\ChessmataAccount;
use BinktermPHP\Crossroads\ChessmataApiInterface;
use BinktermPHP\Crossroads\ChessmataIdentity;
use BinktermPHP\Crossroads\ChessmataIdentityException;
use BinktermPHP\Crossroads\ChessmataProvisioningRateLimited;
use BinktermPHP\Crossroads\ChessmataSecretBox;
use BinktermPHP\Database;
use PHPUnit\Framework\TestCase;

/**
 * Scripted Chessmata API so the broker's provisioning / re-resolution / token
 * lifecycle is tested without the network. Records every call.
 */
final class FakeChessmataApi implements ChessmataApiInterface
{
    /** @var array<int,array<string,mixed>> */
    public array $calls = [];
    public int $nextUserSeq = 1;
    public bool $registerConflictOnce = false;
    public bool $registerRateLimited = false;
    public bool $registerUnverified = false;
    public bool $refreshFails = false;
    private bool $conflictConsumed = false;

    public function baseUrl(): string
    {
        return 'http://fake';
    }

    public function register(string $email, string $password, string $displayName, ?string $forwardedFor = null): array
    {
        $this->calls[] = ['m' => 'register', 'email' => $email, 'displayName' => $displayName, 'xff' => $forwardedFor];

        if ($this->registerRateLimited) {
            return ['status' => 429, 'data' => ['error' => 'Too many accounts spawned']];
        }
        if ($this->registerConflictOnce && !$this->conflictConsumed) {
            $this->conflictConsumed = true;

            return ['status' => 409, 'data' => ['error' => 'Unable to create account with these details']];
        }

        $uid = 'cmu_' . str_pad((string)$this->nextUserSeq++, 6, '0', STR_PAD_LEFT);

        return [
            'status' => 201,
            'data'   => [
                'accessToken'  => 'access.' . $uid . '.1',
                'refreshToken' => 'refresh.' . $uid,
                'user'         => ['id' => $uid, 'emailVerified' => !$this->registerUnverified, 'displayName' => $displayName],
            ],
        ];
    }

    public function login(string $email, string $password, ?string $forwardedFor = null): array
    {
        $this->calls[] = ['m' => 'login', 'email' => $email];

        return ['status' => 200, 'data' => ['accessToken' => 'access.relogin.' . uniqid(), 'refreshToken' => 'refresh.relogin.' . uniqid()]];
    }

    public function refresh(string $refreshToken): array
    {
        $this->calls[] = ['m' => 'refresh'];
        if ($this->refreshFails) {
            return ['status' => 401, 'data' => ['error' => 'Invalid refresh token']];
        }

        return ['status' => 200, 'data' => ['accessToken' => 'access.refreshed.' . uniqid()]];
    }

    public function createApiKey(string $accessToken, string $name): array
    {
        $this->calls[] = ['m' => 'createApiKey', 'name' => $name];

        return ['status' => 201, 'data' => ['key' => 'cmk_' . bin2hex(random_bytes(16))]];
    }

    public function me(string $bearer): array
    {
        return ['status' => 200, 'data' => []];
    }

    public function countCalls(string $method): int
    {
        return count(array_filter($this->calls, static fn ($c) => $c['m'] === $method));
    }
}

final class ChessmataIdentityTest extends TestCase
{
    private \PDO $db;
    private ChessmataSecretBox $box;
    /** @var array<int,int> */
    private array $testUserIds = [];

    protected function setUp(): void
    {
        $this->db = Database::getInstance()->getPdo();
        $this->box = new ChessmataSecretBox(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    protected function tearDown(): void
    {
        foreach ($this->testUserIds as $id) {
            $this->db->prepare('DELETE FROM chessmata_identities WHERE binkterm_user_id = ?')->execute([$id]);
            $this->db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        }
    }

    private function makeUser(string $prefix = 'cmtest'): int
    {
        $un = $prefix . '_' . substr(bin2hex(random_bytes(5)), 0, 10);
        $this->db->prepare(
            'INSERT INTO users (username, password_hash, email, real_name, is_active, is_admin, created_at)
             VALUES (?, ?, ?, ?, true, false, NOW())'
        )->execute([$un, 'x', $un . '@example.invalid', ucfirst($un)]);
        $id = (int)$this->db->query("SELECT id FROM users WHERE username = " . $this->db->quote($un))->fetchColumn();
        $this->testUserIds[] = $id;

        return $id;
    }

    private function broker(FakeChessmataApi $api): ChessmataIdentity
    {
        return new ChessmataIdentity($this->db, $api, $this->box);
    }

    public function testFirstResolveCreatesExactlyOneAccountAndMapping(): void
    {
        $api = new FakeChessmataApi();
        $uid = $this->makeUser();

        $acct = $this->broker($api)->resolve($uid, '198.51.100.9');

        $this->assertInstanceOf(ChessmataAccount::class, $acct);
        $this->assertSame('bt-' . $uid . '@chessmata.invalid', $acct->email);
        $this->assertSame(1, $api->countCalls('register'));
        $this->assertSame(1, $api->countCalls('createApiKey'));
        $this->assertSame('198.51.100.9', $api->calls[0]['xff']);

        $row = $this->db->query("SELECT count(*) FROM chessmata_identities WHERE binkterm_user_id = $uid")->fetchColumn();
        $this->assertSame(1, (int)$row);
    }

    public function testSecondResolveReusesTheSameAccount(): void
    {
        $api = new FakeChessmataApi();
        $uid = $this->makeUser();
        $b = $this->broker($api);

        $a1 = $b->resolve($uid);
        $a2 = $b->resolve($uid);

        $this->assertSame($a1->chessmataUserId, $a2->chessmataUserId);
        $this->assertSame(1, $api->countCalls('register')); // NOT called again
    }

    public function testTwoBinkTermUsersGetTwoDistinctChessmataAccounts(): void
    {
        $api = new FakeChessmataApi();
        $b = $this->broker($api);

        $a = $b->resolve($this->makeUser());
        $c = $b->resolve($this->makeUser());

        $this->assertNotSame($a->chessmataUserId, $c->chessmataUserId);
        $this->assertNotSame($a->email, $c->email);
        $this->assertSame(2, $api->countCalls('register'));
    }

    public function testRateLimitedRegistrationThrowsRetryableException(): void
    {
        $api = new FakeChessmataApi();
        $api->registerRateLimited = true;

        $this->expectException(ChessmataProvisioningRateLimited::class);
        $this->broker($api)->resolve($this->makeUser());
    }

    public function testRateLimitedProvisioningLeavesNoPartialMapping(): void
    {
        $api = new FakeChessmataApi();
        $api->registerRateLimited = true;
        $uid = $this->makeUser();

        try {
            $this->broker($api)->resolve($uid);
        } catch (ChessmataProvisioningRateLimited) {
            // expected
        }

        $this->assertNull($this->broker(new FakeChessmataApi())->existingMapping($uid));
    }

    public function testUnverifiedAccountIsRejected(): void
    {
        $api = new FakeChessmataApi();
        $api->registerUnverified = true;

        $this->expectException(ChessmataIdentityException::class);
        $this->broker($api)->resolve($this->makeUser());
    }

    public function testDisplayNameConflictFallsBackToAUniqueName(): void
    {
        $api = new FakeChessmataApi();
        $api->registerConflictOnce = true;
        $uid = $this->makeUser();

        $this->broker($api)->resolve($uid);

        $this->assertSame(2, $api->countCalls('register'));
        $this->assertStringEndsWith('-' . $uid, $api->calls[1]['displayName']);
    }

    public function testTerminalCredentialReturnsTheStoredApiKey(): void
    {
        $api = new FakeChessmataApi();
        $uid = $this->makeUser();
        $b = $this->broker($api);

        $key = $b->terminalCredential($uid);

        $this->assertStringStartsWith('cmk_', $key);
        $this->assertSame($key, $b->terminalCredential($uid)); // stable, no new key minted
        $this->assertSame(1, $api->countCalls('createApiKey'));
    }

    public function testWebCredentialUsesCachedTokenThenRefreshesThenRelogs(): void
    {
        $api = new FakeChessmataApi();
        $uid = $this->makeUser();
        $b = $this->broker($api);

        // 1. fresh from provisioning -> cached, no refresh/login call
        $c1 = $b->webCredential($uid);
        $this->assertStringStartsWith('access.', $c1['accessToken']);
        $this->assertSame(0, $api->countCalls('refresh'));

        // 2. force the cached token stale -> refresh path
        $this->db->prepare(
            "UPDATE chessmata_identities SET access_token_expires_at = NOW() - INTERVAL '1 hour' WHERE binkterm_user_id = ?"
        )->execute([$uid]);
        $c2 = $b->webCredential($uid);
        $this->assertStringContainsString('refreshed', $c2['accessToken']);
        $this->assertSame(1, $api->countCalls('refresh'));
        $this->assertSame(0, $api->countCalls('login'));

        // 3. stale token AND refresh fails -> full re-login from stored password
        $api->refreshFails = true;
        $this->db->prepare(
            "UPDATE chessmata_identities SET access_token_expires_at = NOW() - INTERVAL '1 hour' WHERE binkterm_user_id = ?"
        )->execute([$uid]);
        $c3 = $b->webCredential($uid);
        $this->assertStringContainsString('relogin', $c3['accessToken']);
        $this->assertSame(1, $api->countCalls('login'));
    }

    public function testExistingMappingNeverExposesEncryptedColumns(): void
    {
        $api = new FakeChessmataApi();
        $uid = $this->makeUser();
        $this->broker($api)->resolve($uid);

        $m = $this->broker($api)->existingMapping($uid);

        $this->assertIsArray($m);
        foreach (array_keys($m) as $k) {
            $this->assertStringNotContainsString('_enc', (string)$k);
        }
        $this->assertArrayHasKey('has_api_key', $m);
    }

    public function testStoredSecretsAreCiphertextNotPlaintext(): void
    {
        $api = new FakeChessmataApi();
        $uid = $this->makeUser();
        $this->broker($api)->resolve($uid);

        $row = $this->db->query(
            "SELECT password_enc, api_key_enc, refresh_token_enc, access_token_enc
               FROM chessmata_identities WHERE binkterm_user_id = $uid"
        )->fetch(\PDO::FETCH_ASSOC);

        foreach ($row as $col => $val) {
            $this->assertNotSame('', (string)$val, "$col should be set");
            $this->assertStringNotContainsString('cmk_', (string)$val, "$col must not hold a raw API key");
            $this->assertStringNotContainsString('access.', (string)$val, "$col must not hold a raw token");
            $this->assertStringNotContainsString('refresh.', (string)$val, "$col must not hold a raw token");
            // decrypts back to something non-empty
            $this->assertNotSame('', $this->box->decrypt((string)$val));
        }
    }

    public function testForgetRemovesOnlyTheLocalMapping(): void
    {
        $api = new FakeChessmataApi();
        $uid = $this->makeUser();
        $b = $this->broker($api);
        $b->resolve($uid);

        $b->forget($uid);

        $this->assertNull($b->existingMapping($uid));
        // user row untouched
        $this->assertSame(1, (int)$this->db->query("SELECT count(*) FROM users WHERE id = $uid")->fetchColumn());
    }
}
