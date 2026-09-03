<?php

declare(strict_types=1);

use BinktermPHP\Crossroads\ChessmataBrokerUnavailable;
use BinktermPHP\Crossroads\ChessmataIdentity;
use BinktermPHP\Crossroads\ChessmataSecretBox;
use BinktermPHP\Crossroads\ChessmataTerminalSession;
use BinktermPHP\Database;
use PHPUnit\Framework\TestCase;

// Reuse the scripted Chessmata API double from the Slice 2 broker test. PHPUnit
// require_once's each test file exactly as we do here, so this is idempotent
// whether this file or ChessmataIdentityTest.php is loaded first.
require_once __DIR__ . '/ChessmataIdentityTest.php';

/**
 * Crossroads Experience #4, Slice 3 (Telnet / NativeDoor surface).
 *
 * Two concerns:
 *   1. ChessmataTerminalSession::prepare() -- resolves the authenticated caller
 *      through the broker and writes the OFFICIAL CLI's config.json /
 *      credentials.json (self-hosted server URL, 0600 in a 0700 dir, the cmk_
 *      key only ever in credentials.json).
 *   2. native-doors/doors/chessmata/launch-chessmata.sh -- the thin wrapper:
 *      identity gate, ephemeral private XDG_CONFIG_HOME, cleanup on every exit
 *      path (normal, SIGTERM, SIGHUP), no secret in argv / output.
 */
final class ChessmataTerminalSessionTest extends TestCase
{
    private \PDO $db;
    private ChessmataSecretBox $box;
    /** @var list<int> */
    private array $testUserIds = [];
    /** @var list<string> */
    private array $tmpPaths = [];

    private string $launcher;

    protected function setUp(): void
    {
        $this->db = Database::getInstance()->getPdo();
        $this->box = new ChessmataSecretBox(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $this->launcher = dirname(__DIR__, 2) . '/native-doors/doors/chessmata/launch-chessmata.sh';
    }

    protected function tearDown(): void
    {
        foreach ($this->testUserIds as $id) {
            $this->db->prepare('DELETE FROM chessmata_identities WHERE binkterm_user_id = ?')->execute([$id]);
            $this->db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        }
        foreach ($this->tmpPaths as $p) {
            $this->rmrf($p);
        }
    }

    // ---------------------------------------------------------------- helpers

    private function makeUser(string $prefix = 'cmterm'): int
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

    private function privateConfigHome(): string
    {
        $dir = sys_get_temp_dir() . '/cmterm-cfg-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o700, true);
        $this->tmpPaths[] = $dir;

        return $dir;
    }

    private function rmrf(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        if (is_dir($path) && !is_link($path)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $e) {
                $e->isDir() && !$e->isLink() ? @rmdir($e->getPathname()) : @unlink($e->getPathname());
            }
            @rmdir($path);

            return;
        }
        @unlink($path);
    }

    // =============================================================== prepare()

    public function testPrepareRequiresAnAuthenticatedBinkTermAccount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ChessmataTerminalSession::prepare(0, $this->privateConfigHome(), null, $this->broker(new FakeChessmataApi()));
    }

    public function testPrepareRejectsANonPrivateConfigDirectory(): void
    {
        $dir = $this->privateConfigHome();
        chmod($dir, 0o755); // group/other now have access

        $this->expectException(\InvalidArgumentException::class);
        ChessmataTerminalSession::prepare($this->makeUser(), $dir, null, $this->broker(new FakeChessmataApi()));
    }

    public function testPrepareWritesTheOfficialCliConfigPointingAtTheSelfHostedServer(): void
    {
        $home = $this->privateConfigHome();
        $meta = ChessmataTerminalSession::prepare($this->makeUser(), $home, null, $this->broker(new FakeChessmataApi()));

        $cfg = json_decode((string)file_get_contents($home . '/chessmata/config.json'), true);
        $this->assertSame('http://chessmata:9029', $cfg['server_url']);
        $this->assertStringNotContainsString('metavert.io', (string)file_get_contents($home . '/chessmata/config.json'));
        $this->assertSame($cfg['server_url'], $meta['server_url']);
        $this->assertTrue($meta['ok']);
    }

    public function testPrepareStoresTheApiKeyOnlyInCredentialsJsonAt0600(): void
    {
        $home = $this->privateConfigHome();
        $api = new FakeChessmataApi();
        ChessmataTerminalSession::prepare($this->makeUser(), $home, null, $this->broker($api));

        $credPath = $home . '/chessmata/credentials.json';
        $cred = json_decode((string)file_get_contents($credPath), true);

        $this->assertStringStartsWith('cmk_', $cred['access_token']);
        $this->assertSame(0o600, fileperms($credPath) & 0o777);
        $this->assertSame(0o600, fileperms($home . '/chessmata/config.json') & 0o777);
        $this->assertSame(0o700, fileperms($home . '/chessmata') & 0o777);

        // the key is nowhere in config.json
        $this->assertStringNotContainsString($cred['access_token'], (string)file_get_contents($home . '/chessmata/config.json'));
    }

    public function testPrepareReturnsNoSecretInItsMetadata(): void
    {
        $home = $this->privateConfigHome();
        $meta = ChessmataTerminalSession::prepare($this->makeUser(), $home, null, $this->broker(new FakeChessmataApi()));

        $flat = json_encode($meta);
        $this->assertStringNotContainsString('cmk_', $flat);
        $this->assertArrayNotHasKey('access_token', $meta);
        $this->assertArrayNotHasKey('api_key', $meta);
    }

    public function testRepeatedPrepareResolvesToTheSameChessmataAccount(): void
    {
        $uid = $this->makeUser();
        $api = new FakeChessmataApi();
        $broker = $this->broker($api);

        $m1 = ChessmataTerminalSession::prepare($uid, $this->privateConfigHome(), null, $broker);
        $m2 = ChessmataTerminalSession::prepare($uid, $this->privateConfigHome(), null, $broker);

        $this->assertSame($m1['chessmata_user_id'], $m2['chessmata_user_id']);
        $this->assertSame(1, $api->countCalls('register'));
        $this->assertSame(
            1,
            (int)$this->db->query("SELECT count(*) FROM chessmata_identities WHERE binkterm_user_id = $uid")->fetchColumn()
        );
    }

    public function testTwoCallersGetTwoDistinctChessmataIdentities(): void
    {
        $api = new FakeChessmataApi();
        $broker = $this->broker($api);

        $a = ChessmataTerminalSession::prepare($this->makeUser(), $this->privateConfigHome(), null, $broker);
        $b = ChessmataTerminalSession::prepare($this->makeUser(), $this->privateConfigHome(), null, $broker);

        $this->assertNotSame($a['chessmata_user_id'], $b['chessmata_user_id']);
    }

    public function testPrepareSurfacesBrokerUnavailableWhenNoKeyAndNoInjectedBroker(): void
    {
        // No injected broker -> the real availability gate runs. On a host where
        // the encrypt-at-rest key IS configured this cannot throw, so only assert
        // the negative case when the key is genuinely absent.
        if (ChessmataSecretBox::isConfigured()) {
            $this->markTestSkipped('encrypt-at-rest key configured on this host; unavailable-path covered by unit logic');
        }
        $this->expectException(ChessmataBrokerUnavailable::class);
        ChessmataTerminalSession::prepare($this->makeUser(), $this->privateConfigHome());
    }

    // ====================================================== launch-chessmata.sh

    /**
     * Run the real launcher with a fake session-init and a fake CLI package so
     * the shell behaviour is exercised without the broker or the network.
     *
     * @param array<string,string> $env
     * @return array{code:int, out:string, sessionDirsAfter:int}
     */
    private function runLauncher(string $userId, string $stdin, array $env = [], ?int $signalAfterMs = null, ?int $signal = null): array
    {
        $fakeRoot = sys_get_temp_dir() . '/cmterm-fake-' . bin2hex(random_bytes(6));
        $this->tmpPaths[] = $fakeRoot;
        mkdir($fakeRoot . '/cli/chessmata', 0o755, true);
        mkdir($fakeRoot . '/bin', 0o755, true);
        $sessionParent = $fakeRoot . '/tmp';
        mkdir($sessionParent, 0o700, true);

        // fake `python3 -m chessmata`: echo argv + the isolation-critical env,
        // read one stdin line (proves fd 3 passthrough), then exit.
        file_put_contents($fakeRoot . '/cli/chessmata/__main__.py', <<<'PY'
import os, sys
print("FAKECLI argv=" + repr(sys.argv[1:]))
print("FAKECLI HOME=" + os.environ.get("HOME", ""))
print("FAKECLI XDG=" + os.environ.get("XDG_CONFIG_HOME", ""))
sys.stdout.flush()
# Only the create/play path consumes a line -- proves fd 3 (real PTY) is passed
# through, not bash's implicit </dev/null for an async command. The other menu
# actions must NOT touch stdin or they would eat the next menu choice.
if sys.argv[1:] == ['play']:
    line = sys.stdin.readline()
    print("FAKECLI stdin=" + repr(line))
    sys.stdout.flush()
PY);

        // fake session-init.php shim (plain bash, invoked as "$PHP_BIN $INIT_PHP …")
        file_put_contents($fakeRoot . '/bin/php', <<<'SH'
#!/bin/bash
# $1 = the "INIT_PHP" path (ignored), $2 = user_id, $3 = xdg_config_home
shift  # drop the script-path arg
uid="$1"; xdg="$2"
if [[ -n "${FAKE_INIT_RC:-}" && "$FAKE_INIT_RC" != "0" ]]; then
    printf '%s\n' "${FAKE_INIT_REASON:-UNAVAILABLE}" >&2
    exit "$FAKE_INIT_RC"
fi
mkdir -p -m 700 "$xdg/chessmata"
umask 077
printf '{\n  "server_url": "http://chessmata:9029",\n  "email": "bt-%s@chessmata.invalid"\n}\n' "$uid" > "$xdg/chessmata/config.json"
printf '{\n  "access_token": "cmk_FAKEKEYFAKEKEYFAKEKEY"\n}\n' > "$xdg/chessmata/credentials.json"
chmod 600 "$xdg/chessmata/config.json" "$xdg/chessmata/credentials.json"
printf '{"ok":true,"display_name":"stubby","chessmata_user_id":"stub0001","server_url":"http://chessmata:9029"}\n'
exit 0
SH);
        chmod($fakeRoot . '/bin/php', 0o755);

        $fullEnv = array_merge([
            'PATH'                  => getenv('PATH'),
            'TMPDIR'                => $sessionParent,
            'CHESSMATA_CLI_ROOT'    => $fakeRoot . '/cli',
            'CHESSMATA_SESSION_INIT' => $fakeRoot . '/init.php',
            'CHESSMATA_PHP_BIN'     => $fakeRoot . '/bin/php',
            'DOOR_CLIENT_IP'        => '198.51.100.5',
        ], $env);

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open(
            ['/bin/bash', $this->launcher, $userId, 'CmTermCaller'],
            $descriptors,
            $pipes,
            dirname($this->launcher),
            $fullEnv
        );
        $this->assertIsResource($proc);

        fwrite($pipes[0], $stdin);
        if ($signalAfterMs === null) {
            fclose($pipes[0]);
        }

        if ($signalAfterMs !== null) {
            usleep($signalAfterMs * 1000);
            $status = proc_get_status($proc);
            posix_kill($status['pid'], $signal ?? SIGTERM);
            usleep(300_000);
            @fclose($pipes[0]);
        }

        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        $dirsAfter = glob($sessionParent . '/chessmata-sess.*') ?: [];

        return ['code' => $code, 'out' => $out, 'sessionDirsAfter' => count($dirsAfter)];
    }

    public function testLauncherRejectsAMissingOrBadCallerId(): void
    {
        foreach (['0', '-1', 'abc', '999999999999'] as $bad) {
            $r = $this->runLauncher($bad, "Q\n");
            $this->assertSame(1, $r['code'], "bad id '$bad' should exit 1");
            $this->assertStringContainsString('logged-in BinkTerm account', $r['out']);
            $this->assertStringNotContainsString('FAKECLI', $r['out'], 'must not reach the CLI');
        }
    }

    public function testLauncherRunsTheCliWithAnIsolatedPrivateHomeAndPassesStdin(): void
    {
        $r = $this->runLauncher('4242', "P\nknight to e5\nQ\n");
        $this->assertSame(0, $r['code'], $r['out']);
        $this->assertStringContainsString("FAKECLI argv=['play']", $r['out']);
        // HOME and XDG both under a fresh unguessable mktemp dir
        $this->assertMatchesRegularExpression('#FAKECLI HOME=.*/chessmata-sess\.[A-Za-z0-9]{10}$#m', $r['out']);
        $this->assertMatchesRegularExpression('#FAKECLI XDG=.*/chessmata-sess\.[A-Za-z0-9]{10}/config$#m', $r['out']);
        // the stdin line reached the CLI (fd 3 passthrough, not /dev/null)
        $this->assertStringContainsString("FAKECLI stdin='knight to e5\\n'", $r['out']);
    }

    public function testLauncherWipesTheSessionDirectoryOnNormalExit(): void
    {
        $r = $this->runLauncher('4242', "L\nG\nH\nQ\n");
        $this->assertSame(0, $r['code'], $r['out']);
        $this->assertSame(0, $r['sessionDirsAfter'], 'session dir must be gone after a normal quit');
    }

    public function testLauncherWipesTheSessionDirectoryOnSigterm(): void
    {
        // stall inside the fake CLI (it blocks on stdin readline) then SIGTERM
        $r = $this->runLauncher('4242', "P\n", [], 400, SIGTERM);
        $this->assertSame(0, $r['sessionDirsAfter'], 'session dir must be gone after SIGTERM');
    }

    public function testLauncherWipesTheSessionDirectoryOnSighup(): void
    {
        $r = $this->runLauncher('4242', "P\n", [], 400, SIGHUP);
        $this->assertSame(0, $r['sessionDirsAfter'], 'session dir must be gone after SIGHUP (bridge closed the PTY)');
    }

    public function testLauncherNeverPrintsTheCredentialFileContents(): void
    {
        $r = $this->runLauncher('4242', "P\nQ\n");
        $this->assertStringNotContainsString('cmk_FAKEKEYFAKEKEYFAKEKEY', $r['out']);
    }

    public function testLauncherShowsATransientMessageWhenProvisioningIsRateLimited(): void
    {
        $r = $this->runLauncher('4242', "Q\n", ['FAKE_INIT_RC' => '3', 'FAKE_INIT_REASON' => 'RATE_LIMITED']);
        $this->assertSame(1, $r['code']);
        $this->assertStringContainsString('try again', strtolower($r['out']));
        $this->assertStringNotContainsString('FAKECLI', $r['out']);
    }

    public function testLauncherFailsClosedWhenSessionInitErrors(): void
    {
        $r = $this->runLauncher('4242', "Q\n", ['FAKE_INIT_RC' => '1', 'FAKE_INIT_REASON' => 'UNAVAILABLE']);
        $this->assertSame(1, $r['code']);
        $this->assertStringContainsString('temporarily unavailable', $r['out']);
        $this->assertSame(0, $r['sessionDirsAfter']);
    }
}
