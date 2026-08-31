<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * M1: the BCR Games Server native-door wrapper (bcr.sh).
 *
 * bcr.sh is the entire integration layer. These tests drive the real script
 * with a FAKE `telnet` on PATH — no real client, no network, no BCR — and
 * assert:
 *
 *   * on launch it execs exactly `telnet -E -K bcrgames.com 31337`;
 *   * it passes NOTHING about the BinkTerm caller (no username, real name,
 *     user id, drop-file data, or -l/--login argument);
 *   * it fails closed with a friendly message when `telnet` is missing;
 *   * the source contains no BinkTerm identity placeholders and does no
 *     transcript / stream logging.
 */
final class BcrGamesLaunchWrapperTest extends TestCase
{
    private const HOST = 'bcrgames.com';
    private const PORT = '31337';

    private string $doorDir;
    private string $wrapper;
    private string $scratch;

    protected function setUp(): void
    {
        $this->doorDir = dirname(__DIR__, 2) . '/native-doors/doors/bcrgames';
        $this->wrapper = $this->doorDir . '/bcr.sh';

        self::assertFileExists($this->wrapper);

        $this->scratch = sys_get_temp_dir() . '/bcr-wrap-' . bin2hex(random_bytes(6));
        mkdir($this->scratch, 0700, true);
        mkdir($this->scratch . '/bin', 0700, true);

        // Fake telnet: record argv + the full environment, then exit cleanly.
        $fake = "#!/bin/bash\n"
            . "printf '%s\\n' \"\$@\" > \"$this->scratch/telnet.argv\"\n"
            . "env > \"$this->scratch/telnet.env\"\n"
            . "exit 0\n";
        file_put_contents($this->scratch . '/bin/telnet', $fake);
        chmod($this->scratch . '/bin/telnet', 0755);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->scratch);
    }

    /**
     * @param array<string,string|false> $env  value false removes the variable
     * @return array{code:int,stdout:string,stderr:string}
     */
    private function runWrapper(array $env = []): array
    {
        // A realistic native-door environment: the bridge sets DOOR_USER_* and
        // DOOR_HOME. The wrapper must ignore every one of them.
        $base = [
            'PATH' => $this->scratch . '/bin:' . (getenv('PATH') ?: '/usr/bin:/bin'),
            'DOOR_USER_NAME' => 'Skrawl',
            'DOOR_USER_REAL_NAME' => 'Matthew Nobody',
            'DOOR_USER_NUMBER' => '42',
            'DOOR_HOME' => $this->scratch . '/home',
            'DOOR_BBS_NAME' => 'L33TEST',
        ];
        $merged = array_merge($base, $env);
        $final = [];
        foreach ($merged as $k => $v) {
            if ($v === false) {
                continue;
            }
            $final[$k] = (string)$v;
        }

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open(['/bin/bash', $this->wrapper], $descriptors, $pipes, $this->scratch, $final);
        self::assertIsResource($proc);
        fclose($pipes[0]);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + 15.0;
        do {
            $stdout .= (string)stream_get_contents($pipes[1]);
            $stderr .= (string)stream_get_contents($pipes[2]);
            $st = proc_get_status($proc);
            if (!$st['running']) {
                break;
            }
            usleep(15000);
        } while (microtime(true) < $deadline);
        $stdout .= (string)stream_get_contents($pipes[1]);
        $stderr .= (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function telnetWasLaunched(): bool
    {
        return is_file($this->scratch . '/telnet.argv');
    }

    /** @return list<string> */
    private function telnetArgv(): array
    {
        $raw = (string)file_get_contents($this->scratch . '/telnet.argv');
        // trailing newline from printf produces one empty element; keep exact order otherwise
        $parts = explode("\n", $raw);
        if (end($parts) === '') {
            array_pop($parts);
        }
        return array_values($parts);
    }

    // ---- successful launch --------------------------------------------

    public function testExecsTelnetWithExactlyDashEDashKHostPort(): void
    {
        $r = $this->runWrapper();

        self::assertSame(0, $r['code'], $r['stderr']);
        self::assertTrue($this->telnetWasLaunched(), 'telnet was not launched');

        self::assertSame(['-E', '-K', self::HOST, self::PORT], $this->telnetArgv());
    }

    public function testLaunchesWithTheApprovedEndpointVerbatim(): void
    {
        $this->runWrapper();
        $argv = $this->telnetArgv();

        self::assertContains('-E', $argv, 'escape character must be disabled');
        self::assertContains('-K', $argv, 'automatic login must be disabled');
        self::assertContains(self::HOST, $argv);
        self::assertContains(self::PORT, $argv);
        // Exactly four args — nothing else is passed.
        self::assertCount(4, $argv);
    }

    public function testPassesNoBinkTermIdentityToTelnet(): void
    {
        $this->runWrapper();
        $argv = $this->telnetArgv();
        $joined = strtolower(implode(' ', $argv));

        // No login/user argument forms.
        self::assertStringNotContainsString('-l', $joined);
        self::assertStringNotContainsString('--login', $joined);
        self::assertStringNotContainsString('-a', $joined); // BSD telnet auto-login attempt

        // No caller identity anywhere in the argv.
        foreach (['skrawl', 'matthew', 'nobody', '42', 'l33test', 'door_user'] as $leak) {
            self::assertStringNotContainsString($leak, $joined, "caller identity leaked into telnet argv: {$leak}");
        }
    }

    public function testForwardsNoDropFilePathToTelnet(): void
    {
        $r = $this->runWrapper([
            'DOOR_DROPFILE' => $this->scratch . '/drops/NODE1/DOOR.SYS',
        ]);
        self::assertSame(0, $r['code']);
        $joined = strtolower(implode(' ', $this->telnetArgv()));
        self::assertStringNotContainsString('door.sys', $joined);
        self::assertStringNotContainsString('dropfile', $joined);
        self::assertStringNotContainsString('/drops/', $joined);
    }

    // ---- failure path ------------------------------------------------

    public function testFailsClosedWithAFriendlyMessageWhenTelnetIsMissing(): void
    {
        // No fake telnet on PATH, and no other telnet reachable.
        $r = $this->runWrapper(['PATH' => $this->scratch . '/empty']);

        self::assertNotSame(0, $r['code']);
        self::assertFalse($this->telnetWasLaunched());
        self::assertStringContainsStringIgnoringCase('telnet', $r['stdout']);
        self::assertStringContainsStringIgnoringCase('sysop', $r['stdout']);
    }

    // ---- source-level guarantees ------------------------------------

    public function testSourceHardcodesTheEndpointAndUsesExecSetEu(): void
    {
        $src = (string)file_get_contents($this->wrapper);

        self::assertMatchesRegularExpression('~^\s*set -eu\b~m', $src);
        self::assertMatchesRegularExpression("~BCR_HOST='bcrgames\\.com'~", $src);
        self::assertMatchesRegularExpression("~BCR_PORT='31337'~", $src);
        self::assertMatchesRegularExpression('~^\s*exec\s+"\$TELNET_BIN"\s+-E\s+-K\s+"\$BCR_HOST"\s+"\$BCR_PORT"~m', $src);
    }

    public function testSourceContainsNoIdentityPlaceholdersAndNoStreamLogging(): void
    {
        $src = (string)file_get_contents($this->wrapper);

        // No native-door identity placeholders and no reads of DOOR_USER_* / drop data.
        foreach (['{user_name}', '{real_name}', '{user_number}', '{homedir}', '{dropfile}',
                  'DOOR_USER_NAME', 'DOOR_USER_REAL_NAME', 'DOOR_USER_NUMBER', 'DOOR_DROPFILE'] as $needle) {
            self::assertStringNotContainsString($needle, $src, "wrapper references caller identity: {$needle}");
        }

        // No transcript / stream capture.
        foreach (['tee ', 'script ', 'ttyrec', 'asciinema', 'scriptreplay', '>>', 'logger '] as $needle) {
            self::assertStringNotContainsString($needle, $src, "wrapper appears to log the stream: {$needle}");
        }

        // Not rlogin.
        self::assertStringNotContainsStringIgnoringCase('rlogin', $src);
    }

    // ---- util -------------------------------------------------------

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
