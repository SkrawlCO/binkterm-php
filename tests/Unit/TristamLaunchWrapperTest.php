<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * M1: the Tristam Island native-door wrapper (run.sh).
 *
 * run.sh is the entire integration layer between BinktermPHP's authenticated
 * native-door bridge and the unmodified Frotz Z-machine interpreter. These
 * tests drive the real script with a FAKE `dfrotz` on PATH — no real
 * interpreter, no BBS — and assert:
 *
 *   * it fails closed on a missing / malformed numeric identity, before the
 *     interpreter is ever launched;
 *   * it refuses to run without a per-user $DOOR_HOME;
 *   * on a valid launch it execs the interpreter in restricted-I/O mode
 *     (`dfrotz -R <DOOR_HOME>/saves`) so every in-game SAVE / RESTORE / SCRIPT
 *     is confined to the caller's own private directory;
 *   * the save directory is keyed off the numeric $DOOR_HOME, never a username;
 *   * the story file it loads is the bundled CC0 release.
 */
final class TristamLaunchWrapperTest extends TestCase
{
    private string $doorDir;
    private string $wrapper;
    private string $story;
    private string $scratch;

    protected function setUp(): void
    {
        $this->doorDir = dirname(__DIR__, 2) . '/native-doors/doors/tristam';
        $this->wrapper = $this->doorDir . '/run.sh';
        $this->story   = $this->doorDir . '/story/tristam-en.z3';

        self::assertFileExists($this->wrapper);

        $this->scratch = sys_get_temp_dir() . '/tristam-wrap-' . bin2hex(random_bytes(6));
        mkdir($this->scratch, 0700, true);
        mkdir($this->scratch . '/bin', 0700, true);

        // Fake dfrotz: record argv + cwd, then exit cleanly (as `quit` would).
        $fake = "#!/bin/bash\n"
            . "printf '%s\\n' \"\$@\" > \"$this->scratch/dfrotz.argv\"\n"
            . "printf '%s\\n' \"\$PWD\" > \"$this->scratch/dfrotz.cwd\"\n"
            . "exit 0\n";
        file_put_contents($this->scratch . '/bin/dfrotz', $fake);
        chmod($this->scratch . '/bin/dfrotz', 0755);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->scratch);
    }

    /**
     * @param array<string,string|false> $env  value false removes the variable
     * @return array{code:int,stdout:string,stderr:string}
     */
    private function runWrapper(array $env): array
    {
        $base = [
            'PATH' => $this->scratch . '/bin:' . (getenv('PATH') ?: '/usr/bin:/bin'),
            'DOOR_USER_NUMBER' => '42',
            'DOOR_HOME' => $this->scratch . '/home',
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

    private function interpreterWasLaunched(): bool
    {
        return is_file($this->scratch . '/dfrotz.argv');
    }

    /** @return list<string> */
    private function interpreterArgv(): array
    {
        $raw = (string)file_get_contents($this->scratch . '/dfrotz.argv');
        return array_values(array_filter(explode("\n", $raw), static fn ($l) => $l !== ''));
    }

    // ---- identity gate -------------------------------------------------

    public function testRefusesMissingIdentity(): void
    {
        $r = $this->runWrapper(['DOOR_USER_NUMBER' => false]);

        self::assertNotSame(0, $r['code']);
        self::assertFalse($this->interpreterWasLaunched(), 'interpreter launched without an identity');
        self::assertStringContainsString('registered members only', $r['stdout']);
    }

    public function testRefusesMalformedOrZeroIdentity(): void
    {
        foreach (['abc', '4x2', '0', '-7', '3.5', ' 12', ''] as $bad) {
            $r = $this->runWrapper(['DOOR_USER_NUMBER' => $bad]);
            self::assertNotSame(0, $r['code'], "should refuse DOOR_USER_NUMBER='{$bad}'");
            self::assertFalse(
                $this->interpreterWasLaunched(),
                "interpreter launched for bad DOOR_USER_NUMBER='{$bad}'"
            );
            @unlink($this->scratch . '/dfrotz.argv');
        }
    }

    public function testRefusesToRunWithoutAPerUserDoorHome(): void
    {
        $r = $this->runWrapper(['DOOR_HOME' => false]);

        self::assertNotSame(0, $r['code']);
        self::assertFalse($this->interpreterWasLaunched(), 'interpreter launched without $DOOR_HOME');
    }

    // ---- successful launch ------------------------------------------------

    public function testLaunchesInterpreterWithRestrictedIoIntoPerUserSaveDir(): void
    {
        $home = $this->scratch . '/home';
        $r = $this->runWrapper(['DOOR_USER_NUMBER' => '42', 'DOOR_HOME' => $home]);

        self::assertSame(0, $r['code'], $r['stderr']);
        self::assertTrue($this->interpreterWasLaunched(), 'interpreter was not launched');

        $argv = $this->interpreterArgv();
        // dfrotz -R <DOOR_HOME>/saves -m <story>
        self::assertSame('-R', $argv[0]);
        self::assertSame($home . '/saves', $argv[1]);
        self::assertSame('-m', $argv[2]);
        self::assertSame($this->story, $argv[3]);

        self::assertDirectoryExists($home . '/saves', 'per-user save dir was not created');
    }

    public function testSaveDirectoryIsKeyedOffDoorHomeNotUsername(): void
    {
        $home = $this->scratch . '/users/42/tristam';
        $r = $this->runWrapper([
            'DOOR_USER_NUMBER' => '42',
            'DOOR_USER_NAME' => 'Skrawl',
            'DOOR_USER_REAL_NAME' => 'Matthew Nobody',
            'DOOR_HOME' => $home,
        ]);

        self::assertSame(0, $r['code'], $r['stderr']);
        $argv = $this->interpreterArgv();
        $restrictPath = $argv[1] ?? '';

        self::assertSame($home . '/saves', $restrictPath);
        self::assertStringNotContainsStringIgnoringCase('skrawl', $restrictPath);
        self::assertStringNotContainsStringIgnoringCase('nobody', $restrictPath);
    }

    public function testLoadsTheBundledCc0StoryFile(): void
    {
        $this->runWrapper(['DOOR_USER_NUMBER' => '42']);
        $argv = $this->interpreterArgv();
        $storyArg = end($argv);

        self::assertFileExists($storyArg);
        self::assertSame(
            'd178078af04528be0dbc3bb41743ca44d5436b78f10e8ca99e95fddc0a4c2b0f',
            hash_file('sha256', $storyArg)
        );
    }

    // ---- source-level guarantees ---------------------------------------

    public function testWrapperExecsTheInterpreterInRestrictedMode(): void
    {
        $src = (string)file_get_contents($this->wrapper);

        self::assertMatchesRegularExpression('~^\s*set -eu~m', $src);
        // Hands the process straight to the interpreter — no second lifecycle.
        self::assertMatchesRegularExpression('~^\s*exec "\$\{dfrotz_bin\}" -R ~m', $src);
        // Restricted I/O is rooted in $DOOR_HOME, and a username is never a path key.
        self::assertStringContainsString('save_dir="${DOOR_HOME}/saves"', $src);
        self::assertStringNotContainsString('DOOR_USER_NAME', $src);
        self::assertStringNotContainsString('DOOR_USER_REAL_NAME', $src);
        // PATH-independent interpreter discovery (the bridge PATH has no /usr/games).
        self::assertStringContainsString('/usr/games/dfrotz', $src);
    }

    // ---- util ---------------------------------------------------------

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
