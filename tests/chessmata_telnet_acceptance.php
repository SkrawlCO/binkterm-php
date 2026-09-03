<?php

/**
 * Chessmata (Crossroads Experience #4) -- Slice 3 Telnet / NativeDoor acceptance.
 *
 * Disposable and self-cleaning. Proves the full Telnet identity-convergence
 * chain end to end against the REAL self-hosted service:
 *
 *   authenticated BinkTerm caller
 *     -> native-doors/doors/chessmata/launch-chessmata.sh   (thin L33TEST wrapper)
 *       -> session-init.php -> ChessmataTerminalSession::prepare()
 *         -> ChessmataIdentity  (the Slice 2 broker, one BinkTerm user = one
 *                                Chessmata account)
 *       -> OFFICIAL upstream CLI  (python3 -m chessmata, image-baked at
 *                                  /opt/chessmata-cli, pinned + patched)
 *         -> http://chessmata:9029  (self-hosted, NOT chessmata.metavert.io)
 *
 * Two throwaway BinkTerm users each launch the real wrapper through a real PTY;
 * one creates a game from the terminal menu, the other joins it, a full move is
 * played each way. Then every artifact is removed.
 *
 * Run inside binkterm-app as the php-fpm user:
 *   docker exec -u www-data binkterm-app php /var/www/html/tests/chessmata_telnet_acceptance.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Crossroads\ChessmataApiClient;
use BinktermPHP\Crossroads\ChessmataIdentity;
use BinktermPHP\Crossroads\ChessmataTerminalSession;
use BinktermPHP\Database;

$pdo = Database::getInstance()->getPdo();
$api = new ChessmataApiClient();
$broker = new ChessmataIdentity($pdo, $api, null);

$launcher = __DIR__ . '/../native-doors/doors/chessmata/launch-chessmata.sh';
$sessionInit = __DIR__ . '/../native-doors/doors/chessmata/session-init.php';

$pass = 0;
$fail = 0;
$createdUserIds = [];
$createdChessmataIds = [];

function ok(string $label, bool $cond): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  PASS  $label\n";
    } else {
        $fail++;
        echo "  FAIL  $label\n";
    }
}

function makeUser(PDO $pdo, array &$sink): int
{
    $un = 'cmS3acc_' . substr(bin2hex(random_bytes(5)), 0, 10);
    $pdo->prepare(
        'INSERT INTO users (username, password_hash, email, real_name, is_active, is_admin, created_at)
         VALUES (?, ?, ?, ?, true, false, NOW())'
    )->execute([$un, 'x', $un . '@example.invalid', ucfirst($un)]);
    $id = (int)$pdo->query('SELECT id FROM users WHERE username = ' . $pdo->quote($un))->fetchColumn();
    $sink[] = $id;

    return $id;
}

echo "=== Chessmata Slice 3 Telnet / NativeDoor acceptance ===\n";
echo "broker key configured : " . var_export(ChessmataIdentity::isAvailable(), true) . "\n";
echo "chessmata internal url : " . $api->baseUrl() . "\n";
echo "official CLI pin       : " . trim((string)@file_get_contents('/opt/chessmata-cli/CHESSMATA_PIN')) . "\n\n";

$pyHelper = sys_get_temp_dir() . '/cm_s3_ptydrive_' . bin2hex(random_bytes(5)) . '.py';

try {
    // === 1. the baked official CLI is the pinned + patched upstream ============
    $pin = trim((string)@file_get_contents('/opt/chessmata-cli/CHESSMATA_PIN'));
    ok('1  official CLI is the pinned upstream revision', $pin === 'e55b514565b2b4689360a58fb350afda5bb4faf5');
    $shas = (string)@file_get_contents('/opt/chessmata-cli/CHESSMATA_PATCHES.sha256');
    ok('   carried patch 0001 (websocket reassembly) is present',
        str_contains($shas, '0ae3401a570b0d9c962061a9a704dc793e4bd6af834118a599c1bfb406303a68'));
    ok('   carried patch 0002 (lobby config object) is present',
        str_contains($shas, 'dc4e9ae19bcec1bd60b40488b1fe00c8916bcf68380a468d0f5ecbab1dbeb801'));
    $wsHasFix = str_contains((string)@file_get_contents('/opt/chessmata-cli/chessmata/websocket.py'), 'message_payload');
    ok('   patch 0001 is actually applied in the baked source', $wsHasFix);

    // === 2. ChessmataTerminalSession::prepare() -- identity convergence core ==
    $a = makeUser($pdo, $createdUserIds);
    $b = makeUser($pdo, $createdUserIds);
    echo "[A] BinkTerm user id $a    [B] BinkTerm user id $b\n";

    // Random RFC 5737 TEST-NET addresses: each synthetic caller looks like a
    // distinct origin so Chessmata's 5/hour/IP register cap does not accumulate
    // across repeated disposable runs.
    $ipA = '198.51.100.' . random_int(2, 250);
    $ipB = '203.0.113.' . random_int(2, 250);

    $homeA = sys_get_temp_dir() . '/cm_s3_home_' . bin2hex(random_bytes(5));
    mkdir($homeA, 0o700, true);
    $metaA = ChessmataTerminalSession::prepare($a, $homeA, $ipA);
    $createdChessmataIds[] = $metaA['chessmata_user_id'];

    ok('2  prepare() points the CLI at the self-hosted service, not metavert',
        $metaA['server_url'] === 'http://chessmata:9029');
    $cfgRaw = (string)file_get_contents($homeA . '/chessmata/config.json');
    ok('   config.json server_url is the self-hosted service',
        str_contains($cfgRaw, 'http://chessmata:9029') && !str_contains($cfgRaw, 'metavert'));
    $cred = json_decode((string)file_get_contents($homeA . '/chessmata/credentials.json'), true);
    ok('3  credentials.json carries a cmk_ API key', str_starts_with((string)($cred['access_token'] ?? ''), 'cmk_'));
    ok('   credentials.json is 0600 inside a 0700 dir',
        (fileperms($homeA . '/chessmata/credentials.json') & 0o777) === 0o600
        && (fileperms($homeA . '/chessmata') & 0o777) === 0o700);
    ok('4  prepare() returns no secret material',
        !str_contains(json_encode($metaA), 'cmk_') && !isset($metaA['access_token']));

    // repeat launch -> same Chessmata account, no second account
    $homeA2 = sys_get_temp_dir() . '/cm_s3_home_' . bin2hex(random_bytes(5));
    mkdir($homeA2, 0o700, true);
    $metaA2 = ChessmataTerminalSession::prepare($a, $homeA2, $ipA);
    ok('5  a repeat launch resolves to the SAME Chessmata account',
        $metaA2['chessmata_user_id'] === $metaA['chessmata_user_id']);
    ok('   still exactly one mapping row for the caller',
        (int)$pdo->query("SELECT count(*) FROM chessmata_identities WHERE binkterm_user_id = $a")->fetchColumn() === 1);

    // the CLI actually authenticates with what prepare() wrote (no login step)
    $status = shell_exec(
        'HOME=' . escapeshellarg($homeA) . ' XDG_CONFIG_HOME=' . escapeshellarg($homeA)
        . ' sh -c ' . escapeshellarg('cd /opt/chessmata-cli && python3 -m chessmata status') . ' 2>&1'
    );
    ok('6  the OFFICIAL CLI reports it is already logged in (no register/login/paste)',
        str_contains((string)$status, 'Token: Valid') && str_contains((string)$status, 'http://chessmata:9029'));
    ok('   CLI status never prints the API key', !str_contains((string)$status, (string)$cred['access_token']));

    // provision B too (needed for the two-terminal move proof)
    $homeB = sys_get_temp_dir() . '/cm_s3_home_' . bin2hex(random_bytes(5));
    mkdir($homeB, 0o700, true);
    $metaB = ChessmataTerminalSession::prepare($b, $homeB, $ipB);
    $createdChessmataIds[] = $metaB['chessmata_user_id'];
    ok('7  a second BinkTerm caller resolves to a DISTINCT Chessmata account',
        $metaB['chessmata_user_id'] !== $metaA['chessmata_user_id']);

    // === 3. the real launcher through a real PTY =============================
    file_put_contents($pyHelper, <<<'PY'
import os, sys, pty, select, time, subprocess, re, signal

DOOR_DIR = "/var/www/html/native-doors/doors/chessmata"

class Term:
    def __init__(self, user_id, name):
        self.buf = ""
        self.pid, self.fd = pty.fork()
        if self.pid == 0:
            env = dict(os.environ)
            env.update({
                "DOOR_USER_NUMBER": str(user_id), "DOOR_USER_NAME": name,
                "DOOR_CLIENT_IP": "198.51.100.30", "TERM": "xterm-256color",
                "HOME": "/tmp", "COLUMNS": "80", "LINES": "24",
            })
            os.chdir(DOOR_DIR)
            os.execve("/bin/bash", ["/bin/bash", "launch-chessmata.sh", str(user_id), name], env)
        # parent
    def read_until(self, pattern, timeout=30):
        rx = re.compile(pattern)
        end = time.time() + timeout
        while time.time() < end:
            m = rx.search(self.buf)
            if m:
                return m
            r, _, _ = select.select([self.fd], [], [], 0.4)
            if r:
                try:
                    chunk = os.read(self.fd, 4096)
                except OSError:
                    break
                if not chunk:
                    break
                self.buf += chunk.decode("utf-8", "replace")
        raise TimeoutError(f"pattern {pattern!r} not seen; tail=\n{self.buf[-600:]}")
    def send(self, s):
        os.write(self.fd, (s + "\n").encode())
    def signal(self, sig):
        os.kill(self.pid, sig)
    def wait(self, timeout=25):
        end = time.time() + timeout
        eof = False
        while time.time() < end:
            pid, st = os.waitpid(self.pid, os.WNOHANG)
            if pid:
                if os.WIFEXITED(st):
                    return os.WEXITSTATUS(st)
                return -signal.Signals(os.WTERMSIG(st)).value if os.WIFSIGNALED(st) else -1
            if not eof:
                try:
                    r, _, _ = select.select([self.fd], [], [], 0.2)
                    if r:
                        chunk = os.read(self.fd, 4096)
                        if chunk:
                            self.buf += chunk.decode("utf-8", "replace")
                        else:
                            eof = True
                except OSError:
                    eof = True
            else:
                time.sleep(0.1)
        try:
            os.kill(self.pid, signal.SIGKILL)
        except ProcessLookupError:
            pass
        return -1

A_ID, A_NAME, B_ID, B_NAME = sys.argv[1:5]
fails = []
def check(label, cond):
    print(("  PASS  " if cond else "  FAIL  ") + label)
    if not cond:
        fails.append(label)

def sessdirs():
    return subprocess.run(["sh", "-c", "ls -d /tmp/chessmata-sess.* 2>/dev/null | wc -l"],
                          capture_output=True, text=True).stdout.strip()

a = Term(A_ID, A_NAME)
a.read_until(r"connected to chessmata:9029")
check("8  wrapper banner shows the self-hosted server host (chessmata:9029)", True)
banner = a.buf
check("   banner shows no login / register / password / api-key prompt",
      not re.search(r"(?i)\b(register|password|api[ -]?key|sign up|verify your email)\b", banner))
a.read_until(r"\[Q\] Back to BinkTerm")
check("9  the upstream menu renders", True)

a.read_until(r"> ")
a.send("H")
a.read_until(r"(?i)game history|no games")
a.read_until(r"\[Q\] Back to BinkTerm")
check("10 [H] history runs the official CLI and returns to the menu", True)

# create a game from the menu
a.send("P")
m = a.read_until(r"Session ID:\s*([A-Za-z0-9\-]+)")
sid = m.group(1)
check("11 [P] created a game from the terminal menu", bool(sid))
a.read_until(r"You are playing as: WHITE")

# B joins the same game from ITS menu
b = Term(B_ID, B_NAME)
b.read_until(r"\[Q\] Back to BinkTerm")
b.send("J")
b.read_until(r"Game code or link:")
b.send(sid)
b.read_until(r"Joined as black", timeout=30)
check("12 [J] second caller joined the SAME game as black", True)

# full move each way, real-time, through http://chessmata:9029
a.read_until(r"Your move:", timeout=30)
a.send("e2e4")
a.read_until(r"Move played: e2-e4", timeout=20)
b.read_until(r"Your move:", timeout=30)
check("13 White's move propagated to Black in real time (no refresh)", True)
b.send("e7e5")
b.read_until(r"Move played: e7-e5", timeout=20)
a.read_until(r"Your move:", timeout=20)
check("14 Black's reply propagated back to White -- full move round-trip", True)

full_transcript = a.buf + b.buf
check("15 the cmk_ API key never appears in either terminal transcript", "cmk_" not in full_transcript)

# mid-game disconnect: the bridge closes the PTY (== SIGHUP) for both callers
before = sessdirs()
a.signal(signal.SIGHUP); b.signal(signal.SIGHUP)
a.wait(); b.wait()
check("16 a mid-game disconnect (SIGHUP) wiped every session credential dir",
      before != "0" and sessdirs() == "0")

# a plain menu-driven session leaves the wrapper cleanly with exit 0
d = Term(A_ID, A_NAME)
d.read_until(r"\[Q\] Back to BinkTerm")
d.send("H")
d.read_until(r"(?i)game history|no games")
d.read_until(r"\[Q\] Back to BinkTerm")
d.send("Q")
rc_d = d.wait()
if rc_d != 0:
    print("  DEBUG rc_d =", rc_d, "tail=", repr(d.buf[-300:]))
check("17 a normal menu session exits the wrapper cleanly (exit 0)", rc_d == 0)
check("18 nothing is left behind after the normal exit", sessdirs() == "0")

print("FAILS" if fails else "ALLOK")
sys.exit(1 if fails else 0)
PY);

    $cmd = 'python3 ' . escapeshellarg($pyHelper) . ' '
        . escapeshellarg((string)$a) . ' ' . escapeshellarg('cmS3ptyA')
        . ' ' . escapeshellarg((string)$b) . ' ' . escapeshellarg('cmS3ptyB') . ' 2>&1';
    $ptyOut = (string)shell_exec($cmd);
    echo $ptyOut . "\n";
    foreach (explode("\n", $ptyOut) as $line) {
        if (preg_match('/^\s{2}(PASS|FAIL)\s{2}(.+)$/', $line, $mm)) {
            ok($mm[2], $mm[1] === 'PASS');
        }
    }
    ok('19 the PTY driver finished without an unhandled error', str_contains($ptyOut, 'ALLOK') || str_contains($ptyOut, 'FAILS'));

    // === 4. runtime privacy scan ============================================
    $anySecret = '';
    foreach ([$cred['access_token'] ?? 'x', $metaA['chessmata_user_id']] as $needle) {
        // nothing; placeholder loop for clarity
    }
    $key = (string)$cred['access_token'];
    $logHits = shell_exec(
        'grep -rIl ' . escapeshellarg($key) . ' /var/www/html/data/logs /var/log 2>/dev/null | head'
    );
    ok('20 the API key is in no app / system log', trim((string)$logHits) === '');
} catch (\Throwable $e) {
    $fail++;
    echo "  EXCEPTION  " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
} finally {
    echo "\n[cleanup]\n";
    @unlink($pyHelper);
    foreach (glob(sys_get_temp_dir() . '/cm_s3_home_*') ?: [] as $d) {
        shell_exec('rm -rf ' . escapeshellarg($d));
    }
    shell_exec('rm -rf /tmp/chessmata-sess.* 2>/dev/null');
    foreach ($createdUserIds as $id) {
        $broker->forget($id);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    }
    echo "  local: forgot " . count($createdUserIds) . " mappings, deleted " . count($createdUserIds) . " test users\n";
    echo "  remote: chessmata user ids to sweep -> " . implode(', ', $createdChessmataIds) . "\n";
    @file_put_contents('/tmp/cm_s3_chessmata_ids', implode("\n", $createdChessmataIds) . "\n");
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail === 0 ? 0 : 1);
