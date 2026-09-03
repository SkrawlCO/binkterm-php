<?php

declare(strict_types=1);

use BinktermPHP\Crossroads\ChessmataBrokerUnavailable;
use BinktermPHP\Crossroads\ChessmataIdentity;
use BinktermPHP\Crossroads\ChessmataSecretBox;
use BinktermPHP\Crossroads\ChessmataWebSession;
use BinktermPHP\Database;
use PHPUnit\Framework\TestCase;

// Reuse the scripted Chessmata API double from the Slice 2 broker test.
require_once __DIR__ . '/ChessmataIdentityTest.php';

/**
 * Crossroads Experience #4, Slice 4 (graphical Web surface).
 *
 * ChessmataWebSession::issue() -- resolves the authenticated BinkTerm caller
 * through the SAME broker mapping the Telnet surface uses and returns a
 * browser-side JWT hand-off (webCredential(), NEVER the durable cmk_ key).
 */
final class ChessmataWebSessionTest extends TestCase
{
    private \PDO $db;
    private ChessmataSecretBox $box;
    /** @var list<int> */
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

    private function makeUser(string $prefix = 'cmweb'): int
    {
        $un = $prefix . '_' . substr(bin2hex(random_bytes(5)), 0, 10);
        $this->db->prepare(
            'INSERT INTO users (username, password_hash, email, real_name, is_active, is_admin, created_at)
             VALUES (?, ?, ?, ?, true, false, NOW())'
        )->execute([$un, 'x', $un . '@example.invalid', ucfirst($un)]);
        $id = (int)$this->db->query('SELECT id FROM users WHERE username = ' . $this->db->quote($un))->fetchColumn();
        $this->testUserIds[] = $id;

        return $id;
    }

    private function broker(FakeChessmataApi $api): ChessmataIdentity
    {
        return new ChessmataIdentity($this->db, $api, $this->box);
    }

    public function testIssueRequiresAnAuthenticatedBinkTermAccount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ChessmataWebSession::issue(0, null, $this->broker(new FakeChessmataApi()));
    }

    public function testIssueReturnsAJwtNotTheDurableApiKey(): void
    {
        $issued = ChessmataWebSession::issue($this->makeUser(), '198.51.100.7', $this->broker(new FakeChessmataApi()));

        // FakeChessmataApi mints JWTs as access.<uid>.N and cmk_ keys for API keys.
        $this->assertStringStartsWith('access.', $issued['accessToken']);
        $this->assertStringNotContainsString('cmk_', $issued['accessToken']);
        $this->assertSame('chessmata_auth_token', $issued['storageKey']);
        $this->assertSame('/chessmata/', $issued['clientPath']);
        $this->assertNotSame('', $issued['chessmataUserId']);
    }

    public function testWebAndTerminalResolveToTheSameChessmataAccount(): void
    {
        $uid = $this->makeUser();
        $api = new FakeChessmataApi();
        $broker = $this->broker($api);

        $web = ChessmataWebSession::issue($uid, null, $broker);
        $termKey = $broker->terminalCredential($uid);           // cmk_ ...
        $acct = $broker->existingMapping($uid);

        $this->assertSame($acct['chessmata_user_id'], $web['chessmataUserId']);
        $this->assertStringStartsWith('cmk_', $termKey);          // terminal keeps the API key
        $this->assertStringStartsWith('access.', $web['accessToken']); // web keeps a JWT
        $this->assertSame(1, $api->countCalls('register'));       // one account for both surfaces
    }

    public function testRepeatIssueDoesNotCreateASecondAccount(): void
    {
        $uid = $this->makeUser();
        $api = new FakeChessmataApi();
        $broker = $this->broker($api);

        $a = ChessmataWebSession::issue($uid, null, $broker);
        $b = ChessmataWebSession::issue($uid, null, $broker);

        $this->assertSame($a['chessmataUserId'], $b['chessmataUserId']);
        $this->assertSame(
            1,
            (int)$this->db->query("SELECT count(*) FROM chessmata_identities WHERE binkterm_user_id = $uid")->fetchColumn()
        );
    }

    public function testTwoCallersGetTwoDistinctChessmataAccounts(): void
    {
        $broker = $this->broker(new FakeChessmataApi());
        $a = ChessmataWebSession::issue($this->makeUser(), null, $broker);
        $b = ChessmataWebSession::issue($this->makeUser(), null, $broker);

        $this->assertNotSame($a['chessmataUserId'], $b['chessmataUserId']);
    }

    public function testRedactNeverExposesTheToken(): void
    {
        $issued = ChessmataWebSession::issue($this->makeUser(), null, $this->broker(new FakeChessmataApi()));
        $redacted = ChessmataWebSession::redact($issued);

        $this->assertStringNotContainsString($issued['accessToken'], json_encode($redacted));
        $this->assertStringContainsString('redacted', $redacted['accessToken']);
        $this->assertSame($issued['chessmataUserId'], $redacted['chessmataUserId']);
    }

    public function testBrokerUnavailableSurfacesWhenNoKeyAndNoInjectedBroker(): void
    {
        if (ChessmataSecretBox::isConfigured()) {
            $this->markTestSkipped('encrypt-at-rest key configured on this host');
        }
        $this->expectException(ChessmataBrokerUnavailable::class);
        ChessmataWebSession::issue($this->makeUser());
    }

    public function testStorageKeyMatchesTheUpstreamSpaConstant(): void
    {
        // src/hooks/useAuth.ts: const TOKEN_KEY = 'chessmata_auth_token'
        $this->assertSame('chessmata_auth_token', ChessmataWebSession::SPA_TOKEN_STORAGE_KEY);
        $this->assertSame('/chessmata/', ChessmataWebSession::SPA_CLIENT_PATH);
    }
}
