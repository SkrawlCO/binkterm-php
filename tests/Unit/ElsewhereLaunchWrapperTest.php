<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * M4E-A: the Elsewhere local home-adapter wrapper (launch-elsewhere.sh).
 *
 * The wrapper is the launch-composition seam between BinkTerm's authenticated
 * native-door identity boundary and the reviewed World Gateway primitives.
 * These tests drive the real script with FAKE `world-gateway` and FAKE
 * `pwmangclient` binaries -- no live Tangaria server, no real gateway.
 *
 * Verified: identity fail-closed, resolve -> provision -> prepare-launch
 * ordering + argument shape, prepare failure means no client, session_dir
 * becomes the client HOME, the credential never touches argv/env/output,
 * the client runs as a child (not exec), and cleanup-launch runs exactly once
 * on normal exit and on SIGINT / SIGTERM / SIGHUP.
 */
final class ElsewhereLaunchWrapperTest extends TestCase
{
    private string $wrapper;
    private string $scratch;

    protected function setUp(): void
    {
        $this->wrapper = dirname(__DIR__, 2)
            . '/native-doors/doors/elsewhere/launch-elsewhere.sh';
        self::assertFileExists($this->wrapper);

        $this->scratch = sys_get_temp_dir() . '/elsewhere-wrap-' . bin2hex(random_bytes(6));
        mkdir($this->scratch, 0700, true);
        mkdir($this->scratch . '/rt', 0700, true);
        mkdir($this->scratch . '/bp', 0700, true);
        mkdir($this->scratch . '/client', 0700, true);
        file_put_contents($this->scratch . '/account', "");

        $this->writeFakeGateway();
        $this->writeFakeClient('exit 0');
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->scratch);
    }

    // ---- fakes ----------------------------------------------------------

    private function writeFakeGateway(): void
    {
        // Logs every invocation's argv (one line), emits the JSON each verb is
        // expected to. Honours WGW_FAIL_STEP to force one verb to fail. Writes
        // a decoy "secret" to its OWN stderr to prove the wrapper never
        // surfaces gateway stderr to the player.
        $g = <<<'SH'
#!/bin/bash
printf '%s\n' "$*" >> "$WGW_LOG"
sub=""; skip=0
for a in "$@"; do
  if [ "$skip" = 1 ]; then skip=0; continue; fi
  if [ "$a" = "--db" ]; then skip=1; continue; fi
  sub="$a"; break
done
echo "gateway-stderr decoy account_secret=DEADBEEFDEADBEEF hash=\$1\$XX" 1>&2
if [ -n "${WGW_FAIL_STEP:-}" ] && [ "${WGW_FAIL_STEP}" = "$sub" ]; then
  echo "{\"error\":{\"code\":\"boom\",\"message\":\"forced failure in $sub\"}}" 1>&2
  exit 1
fi
case "$sub" in
  resolve)       echo '{"world_subject_id":"w_stub","created":true,"status":"active"}' ;;
  provision)     echo '{"account_name":"OMITTED","account_confirmed":true,"created":true}' ;;
  prepare-launch)
     if [ -n "${WGW_BAD_TOKEN:-}" ]; then
       tok="$WGW_BAD_TOKEN"
     else
       tok="s_$(head -c18 /dev/urandom | od -An -tx1 | tr -d ' \n' | cut -c1-24)"
     fi
     d="$WGW_RT/$tok"
     mkdir -p "$d"
     printf 'pass=DEADBEEFDEADBEEFDEADBEEF\n' > "$d/.pwmangrc"
     chmod 600 "$d/.pwmangrc"
     echo "{\"session_dir\":\"$d\",\"cleanup_token\":\"$tok\",\"server_endpoint\":\"127.0.0.1:18346\"}" ;;
  cleanup-launch)
     tok=""; take=0
     for a in "$@"; do
       if [ "$take" = 1 ]; then tok="$a"; take=0; fi
       [ "$a" = "--session" ] && take=1
     done
     printf 'CLEANUP %s\n' "$tok" >> "$WGW_LOG"
     [ -n "$tok" ] && rm -rf "$WGW_RT/$tok"
     echo '{"ok":true,"removed":true}' ;;
  *) echo "{}" ;;
esac
SH;
        file_put_contents($this->scratch . '/world-gateway', $g);
        chmod($this->scratch . '/world-gateway', 0755);
    }

    private function writeFakeClient(string $body): void
    {
        $c = "#!/bin/bash\n"
            . "printf 'HOME=%s\\n' \"\$HOME\" >> \"\$CLIENT_LOG\"\n"
            . "printf 'TERM=%s\\n' \"\$TERM\" >> \"\$CLIENT_LOG\"\n"
            . "printf 'ESCDELAY=%s\\n' \"\$ESCDELAY\" >> \"\$CLIENT_LOG\"\n"
            . "printf 'PPID=%s\\n' \"\$PPID\" >> \"\$CLIENT_LOG\"\n"
            . "env >> \"\$CLIENT_ENV\"\n"
            . "printf 'started\\n' > \"\$CLIENT_STARTED\"\n"
            . $body . "\n";
        file_put_contents($this->scratch . '/client/pwmangclient', $c);
        chmod($this->scratch . '/client/pwmangclient', 0755);
    }

    // ---- runner --------------------------------------------------------

    /**
     * @param array<string,string> $extraEnv
     * @param int|null $signalAfterStart  signal to send once the client is up
     * @return array{code:int,stdout:string,stderr:string}
     */
    private function runWrapper(array $extraEnv = [], ?int $signalAfterStart = null): array
    {
        $env = array_merge([
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'TERM' => 'xterm-256color',
            'DOOR_USER_NUMBER' => '42',
            'DOOR_USER_NAME' => 'Skrawl',
            'DOOR_USER_REAL_NAME' => 'Matthew Nobody',
            'WORLD_GATEWAY_BIN' => $this->scratch . '/world-gateway',
            'WORLD_GATEWAY_DB' => $this->scratch . '/gateway.db',
            'WORLD_GATEWAY_RUNTIME_ROOT' => $this->scratch . '/rt',
            'ELSEWHERE_ACCOUNT_FILE' => $this->scratch . '/account',
            'ELSEWHERE_CLIENT_DIR' => $this->scratch . '/client',
            'ELSEWHERE_BIRTH_PREF_DIR' => $this->scratch . '/bp',
            'WGW_LOG' => $this->scratch . '/wgw.log',
            'WGW_RT' => $this->scratch . '/rt',
            'CLIENT_LOG' => $this->scratch . '/client.log',
            'CLIENT_ENV' => $this->scratch . '/client.env',
            'CLIENT_STARTED' => $this->scratch . '/client.started',
        ], $extraEnv);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open(
            ['/bin/bash', $this->wrapper],
            $descriptors,
            $pipes,
            $this->scratch,
            $env
        );
        self::assertIsResource($proc);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        if ($signalAfterStart !== null) {
            $startedFile = $env['CLIENT_STARTED'];
            $deadline = microtime(true) + 5.0;
            while (!file_exists($startedFile) && microtime(true) < $deadline) {
                usleep(20000);
            }
            self::assertFileExists($startedFile, 'fake client never started');
            usleep(50000);
            proc_terminate($proc, $signalAfterStart);
        }

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + 20.0;
        do {
            $stdout .= (string)stream_get_contents($pipes[1]);
            $stderr .= (string)stream_get_contents($pipes[2]);
            $status = proc_get_status($proc);
            if (!$status['running']) {
                break;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);

        $stdout .= (string)stream_get_contents($pipes[1]);
        $stderr .= (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function gatewayLog(): string
    {
        return (string)@file_get_contents($this->scratch . '/wgw.log');
    }

    private function clientLog(): string
    {
        return (string)@file_get_contents($this->scratch . '/client.log');
    }

    /** @return list<string> non-empty argv lines from the gateway log */
    private function gatewayCalls(): array
    {
        return array_values(array_filter(
            explode("\n", trim($this->gatewayLog())),
            static fn (string $l): bool => $l !== '' && !str_starts_with($l, 'CLEANUP ')
        ));
    }

    // ---- tests --------------------------------------------------------

    public function testRefusesMissingIdentity(): void
    {
        $r = $this->runWrapper(['DOOR_USER_NUMBER' => '']);
        self::assertNotSame(0, $r['code']);
        self::assertSame('', $this->gatewayLog(), 'gateway must not be called without identity');
        self::assertStringContainsString('signed in', $r['stdout']);
    }

    public function testRefusesMalformedIdentity(): void
    {
        foreach (['abc', '4x2', '-7', '0', '  '] as $bad) {
            $r = $this->runWrapper(['DOOR_USER_NUMBER' => $bad]);
            self::assertNotSame(0, $r['code'], "should refuse DOOR_USER_NUMBER='{$bad}'");
            self::assertSame('', $this->gatewayLog(), "gateway called for bad id '{$bad}'");
            @unlink($this->scratch . '/wgw.log');
        }
    }

    public function testRefusesConfiguredSharedGuestUserId(): void
    {
        $r = $this->runWrapper(['DOOR_USER_NUMBER' => '9', 'ELSEWHERE_GUEST_USER_ID' => '9']);
        self::assertNotSame(0, $r['code']);
        self::assertSame('', $this->gatewayLog());
        self::assertStringContainsString('L33TEST account', $r['stdout']);
    }

    public function testFailsClosedWhenDeploymentConfigMissing(): void
    {
        $r = $this->runWrapper(['WORLD_GATEWAY_BIN' => '']);
        self::assertNotSame(0, $r['code']);
        self::assertSame('', $this->gatewayLog());

        $r = $this->runWrapper(['ELSEWHERE_BIRTH_PREF_DIR' => '']);
        self::assertNotSame(0, $r['code']);
    }

    public function testComposesResolveThenProvisionThenPrepareLaunch(): void
    {
        $r = $this->runWrapper();
        self::assertSame(0, $r['code'], $r['stderr']);

        $calls = $this->gatewayCalls();
        self::assertCount(4, $calls, 'expected resolve, provision, prepare-launch, cleanup-launch');

        self::assertStringContainsString('resolve ', $calls[0]);
        self::assertStringContainsString('provision ', $calls[1]);
        self::assertStringContainsString('prepare-launch ', $calls[2]);
        self::assertStringContainsString('cleanup-launch ', $calls[3]);
    }

    public function testGatewayCalledWithGenericNormalizedSubject(): void
    {
        $this->runWrapper();

        foreach (['resolve', 'provision', 'prepare-launch'] as $i => $verb) {
            $line = $this->gatewayCalls()[$i];
            self::assertStringContainsString('--world elsewhere', $line, $verb);
            self::assertStringContainsString('--home-bbs local', $line, $verb);
            self::assertStringContainsString('--home-user 42', $line, $verb);
        }
    }

    public function testPrepareLaunchReceivesExplicitBirthPrefDirAndLoopbackEndpoint(): void
    {
        $this->runWrapper();
        $prep = $this->gatewayCalls()[2];

        self::assertStringContainsString('--birth-pref-dir ' . $this->scratch . '/bp', $prep);
        self::assertStringContainsString('--runtime-root ' . $this->scratch . '/rt', $prep);
        self::assertStringContainsString('--server-host 127.0.0.1', $prep);
        self::assertStringContainsString('--server-port 18346', $prep);
    }

    public function testNoUsernameOrDisplayNameReachesTheGateway(): void
    {
        $this->runWrapper([
            'DOOR_USER_NAME' => 'Skrawl',
            'DOOR_USER_REAL_NAME' => 'Matthew Nobody',
        ]);

        $log = $this->gatewayLog();
        self::assertStringNotContainsString('Skrawl', $log);
        self::assertStringNotContainsString('Matthew', $log);
        self::assertStringNotContainsString('Nobody', $log);
        // durable identity is strictly the numeric users.id
        self::assertStringContainsString('--home-user 42', $log);
    }

    public function testNoBinkTermVocabularyLeaksIntoGatewayCalls(): void
    {
        $this->runWrapper();
        $log = strtolower($this->gatewayLog());

        foreach ([
            'door_user', 'binkterm', 'dropfile', 'door.sys', 'node_number',
            'auth_session', 'real_name', 'username', 'users.id', 'surface',
        ] as $needle) {
            self::assertStringNotContainsString($needle, $log, "gateway argv leaked: {$needle}");
        }
    }

    public function testPrepareFailureMeansTheClientNeverStarts(): void
    {
        $r = $this->runWrapper(['WGW_FAIL_STEP' => 'prepare-launch']);
        self::assertNotSame(0, $r['code']);
        self::assertFileDoesNotExist($this->scratch . '/client.started');
        self::assertStringContainsString('temporarily unavailable', $r['stdout']);
        // resolve + provision happened, prepare-launch attempted, no cleanup of
        // a session that was never created
        self::assertStringNotContainsString('CLEANUP', $this->gatewayLog());
    }

    public function testProvisionFailureMeansTheClientNeverStarts(): void
    {
        $r = $this->runWrapper(['WGW_FAIL_STEP' => 'provision']);
        self::assertNotSame(0, $r['code']);
        self::assertFileDoesNotExist($this->scratch . '/client.started');
        $calls = $this->gatewayCalls();
        self::assertStringContainsString('resolve ', $calls[0]);
        self::assertStringContainsString('provision ', $calls[1]);
        self::assertArrayNotHasKey(2, $calls, 'prepare-launch must not run after provision fails');
    }

    public function testSessionDirBecomesTheClientHome(): void
    {
        $r = $this->runWrapper();
        self::assertSame(0, $r['code'], $r['stderr']);

        $clientLog = $this->clientLog();
        self::assertMatchesRegularExpression(
            '~^HOME=' . preg_quote($this->scratch . '/rt/s_', '~') . '[0-9A-Za-z]+$~m',
            $clientLog
        );
        self::assertStringContainsString('TERM=xterm-256color', $clientLog);
        self::assertStringContainsString('ESCDELAY=20', $clientLog);
    }

    public function testClientRunsAsChildNotExec(): void
    {
        // If the wrapper exec'd the client, the trap would be lost and
        // cleanup-launch would never run. It runs -> the client was a child.
        $r = $this->runWrapper();
        self::assertSame(0, $r['code']);
        self::assertStringContainsString('CLEANUP', $this->gatewayLog());

        // Source-level: no `exec` of the client.
        $src = (string)file_get_contents($this->wrapper);
        self::assertDoesNotMatchRegularExpression('~^\s*exec\b~m', $src);
    }

    public function testCredentialNeverAppearsInArgvEnvOrPlayerOutput(): void
    {
        $r = $this->runWrapper();
        self::assertSame(0, $r['code']);

        $decoys = ['DEADBEEF', 'account_secret', 'account_hash', '.pwmangrc', 'pass='];

        // gateway argv log
        foreach ($decoys as $d) {
            self::assertStringNotContainsString($d, $this->gatewayLog(), "argv leak: {$d}");
        }
        // player-facing streams
        foreach ($decoys as $d) {
            self::assertStringNotContainsString($d, $r['stdout'], "stdout leak: {$d}");
            self::assertStringNotContainsString($d, $r['stderr'], "stderr leak: {$d}");
        }
        // client environment
        $clientEnv = (string)file_get_contents($this->scratch . '/client.env');
        foreach (['DEADBEEF', 'account_secret', 'account_hash', 'pass='] as $d) {
            self::assertStringNotContainsString($d, $clientEnv, "client env leak: {$d}");
        }
        // the only place the credential lives is the private .pwmangrc
        self::assertStringContainsString('HOME=', $clientEnv);
    }

    public function testCleanupRunsExactlyOnceOnNormalExit(): void
    {
        $r = $this->runWrapper();
        self::assertSame(0, $r['code']);
        self::assertSame(1, substr_count($this->gatewayLog(), "\nCLEANUP ") + (str_starts_with($this->gatewayLog(), 'CLEANUP ') ? 1 : 0));
        // session dir removed
        self::assertSame([], glob($this->scratch . '/rt/s_*') ?: []);
    }

    public function testCleanupRunsExactlyOnceOnSigterm(): void
    {
        $this->writeFakeClient('sleep 20');
        $r = $this->runWrapper([], 15); // SIGTERM
        self::assertNotSame(0, $r['code']);
        self::assertSame(1, substr_count($this->gatewayLog(), 'CLEANUP '));
        self::assertSame([], glob($this->scratch . '/rt/s_*') ?: []);
    }

    public function testCleanupRunsExactlyOnceOnSighup(): void
    {
        $this->writeFakeClient('sleep 20');
        $r = $this->runWrapper([], 1); // SIGHUP (node-pty default on browser close)
        self::assertNotSame(0, $r['code']);
        self::assertSame(1, substr_count($this->gatewayLog(), 'CLEANUP '));
        self::assertSame([], glob($this->scratch . '/rt/s_*') ?: []);
    }

    public function testWrapperKeepsShellTracingOffAndInputStrict(): void
    {
        $src = (string)file_get_contents($this->wrapper);
        self::assertStringContainsString('set -u', $src);
        self::assertStringContainsString('set +x', $src);
        self::assertDoesNotMatchRegularExpression('~^\s*set\s+-x~m', $src);
        // JSON is parsed with python3, not grep/sed/awk hacks
        self::assertStringContainsString('python3', $src);
        self::assertDoesNotMatchRegularExpression('~(grep|sed|awk)[^\n]*session_dir~', $src);
    }

    public function testWrapperHasNoDeploymentEnvFileSourcingSurface(): void
    {
        // M4E-A correction FIX 1: deployment config is inherited from the
        // trusted process environment only. The wrapper must not `source` /
        // `. <file>` any deployment configuration, and ELSEWHERE_ENV_FILE is
        // gone entirely.
        $src = (string)file_get_contents($this->wrapper);

        self::assertStringNotContainsString('ELSEWHERE_ENV_FILE', $src);
        // no line whose first token is the POSIX dot-source or the bash
        // `source` builtin (comments are '#'-led and cannot match)
        self::assertDoesNotMatchRegularExpression('~^\s*\.\s~m', $src);
        self::assertDoesNotMatchRegularExpression('~^\s*source\s~m', $src);
    }

    public function testCanonicalizesHomeUserId(): void
    {
        // FIX 2: "007" must reach the gateway as "7" -- the durable subject key
        // never varies by leading-zero formatting.
        $r = $this->runWrapper(['DOOR_USER_NUMBER' => '007']);
        self::assertSame(0, $r['code'], $r['stderr']);

        $log = $this->gatewayLog();
        self::assertStringNotContainsString('--home-user 007', $log, 'raw leading-zero id reached the gateway');
        foreach (['resolve', 'provision', 'prepare-launch'] as $i => $verb) {
            self::assertMatchesRegularExpression(
                '~--home-user 7(?: |$)~m',
                $this->gatewayCalls()[$i],
                "{$verb} did not receive the canonicalized id"
            );
        }
    }

    public function testRefusesMalformedEscdelay(): void
    {
        // FIX 3: ESCDELAY is deployment-controlled but must be a non-negative
        // decimal integer; garbage fails closed before the client launches.
        foreach (['abc', '2x', '-5', ' '] as $bad) {
            $r = $this->runWrapper(['ELSEWHERE_ESCDELAY' => $bad]);
            self::assertNotSame(0, $r['code'], "should refuse ESCDELAY='{$bad}'");
            self::assertSame('', $this->gatewayLog(), "gateway called for bad ESCDELAY '{$bad}'");
            self::assertFileDoesNotExist($this->scratch . '/client.started');
            @unlink($this->scratch . '/wgw.log');
        }

        // a well-formed value still reaches the client unchanged
        $r = $this->runWrapper(['ELSEWHERE_ESCDELAY' => '40']);
        self::assertSame(0, $r['code'], $r['stderr']);
        self::assertStringContainsString('ESCDELAY=40', $this->clientLog());
    }

    public function testDiagLogPathIsAbsolutizedBeforeCwdChange(): void
    {
        // FIX 4: a relative ELSEWHERE_DIAG_LOG must be resolved to an absolute
        // path before the wrapper cd's into the client dir, so post-cd operator
        // diagnostics land in the SAME file as the earlier ones.
        $rel = 'op-diag.log';
        $r = $this->runWrapper(['ELSEWHERE_DIAG_LOG' => $rel]);
        self::assertSame(0, $r['code'], $r['stderr']);

        // proc cwd is $this->scratch; the wrapper cd's to $this->scratch/client
        self::assertFileExists($this->scratch . '/' . $rel);
        self::assertFileDoesNotExist(
            $this->scratch . '/client/' . $rel,
            'post-cd diag write escaped to a cwd-relative file'
        );

        $diag = (string)file_get_contents($this->scratch . '/' . $rel);
        // the "launching client" line is emitted AFTER the cd
        self::assertStringContainsString('launching client for user 42', $diag);

        // secret-safety is unchanged: nothing the credential test forbids may
        // reach the player streams even with the diag log enabled
        foreach (['DEADBEEF', 'pass='] as $d) {
            self::assertStringNotContainsString($d, $r['stdout'], "stdout leak: {$d}");
            self::assertStringNotContainsString($d, $r['stderr'], "stderr leak: {$d}");
        }
    }

    public function testAcceptsWellFormedCleanupToken(): void
    {
        // FIX 5: an exact s_ + 24 [0-9A-Za-z] token is accepted and forwarded
        // to cleanup-launch verbatim.
        $tok = 's_ABCDEFGHIJKLMNOPQRSTUVWX'; // s_ + 24 chars
        $r = $this->runWrapper(['WGW_BAD_TOKEN' => $tok]);
        self::assertSame(0, $r['code'], $r['stderr']);
        self::assertSame(1, substr_count($this->gatewayLog(), 'CLEANUP '));
        self::assertStringContainsString('CLEANUP ' . $tok, $this->gatewayLog());
    }

    public function testRejectsMalformedCleanupToken(): void
    {
        // FIX 5: too short / too long / illegal char are all rejected at the
        // wrapper boundary and NEVER reach cleanup-launch.
        $bad = [
            'short'    => 's_ABCDEFGHIJKLMNOPQRSTUVW',      // 23 chars
            'long'     => 's_ABCDEFGHIJKLMNOPQRSTUVWXYZ',   // 26 chars
            'badchar'  => 's_ABCDEFGHIJKLMNOPQRSTUV-X',     // '-' not allowed
            'noprefix' => 'x_ABCDEFGHIJKLMNOPQRSTUVWX',
        ];
        foreach ($bad as $label => $tok) {
            $r = $this->runWrapper(['WGW_BAD_TOKEN' => $tok]);
            self::assertNotSame(0, $r['code'], "token '{$label}' should be rejected");
            self::assertFileDoesNotExist(
                $this->scratch . '/client.started',
                "client started on rejected token '{$label}'"
            );
            self::assertStringNotContainsString(
                'CLEANUP ',
                $this->gatewayLog(),
                "rejected token '{$label}' reached cleanup-launch"
            );
            self::assertStringContainsString('temporarily unavailable', $r['stdout']);
            @unlink($this->scratch . '/wgw.log');
            @unlink($this->scratch . '/client.started');
        }
    }

    public function testCleanupRunsExactlyOnceOnSigint(): void
    {
        // FIX 6: SIGINT parity with the SIGTERM / SIGHUP cases.
        $this->writeFakeClient('sleep 20');
        $r = $this->runWrapper([], 2); // SIGINT
        self::assertNotSame(0, $r['code']);
        self::assertSame(1, substr_count($this->gatewayLog(), 'CLEANUP '));
        self::assertSame([], glob($this->scratch . '/rt/s_*') ?: []);
    }

    // ---- util --------------------------------------------------------

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $p = $dir . '/' . $e;
            is_dir($p) && !is_link($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
