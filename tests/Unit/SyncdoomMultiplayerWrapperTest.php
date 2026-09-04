<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Slice 1: the SyncDOOM multiplayer native-door wrapper (syncdoom-mp.sh).
 *
 * The wrapper is the SyncDOOM-owned integration layer between the generic
 * NativeDoor bridge and the engine. This slice covers only:
 *
 *   [S] the existing, already-accepted single-player launch (unchanged);
 *   [C] Create a 2-player co-op match: spawn a detached dedicated server,
 *       confirm its registry entry, then exec the caller into the client
 *       as the netgame controller.
 *
 * These tests drive the real script against a FAKE `syncdoom` placed at the
 * exact path the wrapper resolves ($(dirname "$0")/syncdoom) -- no real
 * engine, no real network. They assert:
 *
 *   * Create invokes -spawnserver with the exact proven flag set;
 *   * the wrapper safely parses the "<pid> <port>" spawn output;
 *   * the final client exec carries the real dropfile, -connect to the
 *     spawned port, -players 2, -skill 3, -home, -iwad, -name as one argv
 *     entry, and -sixel 1 -- and never -deathmatch/-altdeath;
 *   * a caller name containing shell metacharacters can never execute;
 *   * a failed spawn never execs a client;
 *   * Quit spawns nothing;
 *   * Single Player reproduces today's exact accepted launch semantics.
 */
final class SyncdoomMultiplayerWrapperTest extends TestCase
{
    private string $realDoorDir;
    private string $wrapperSource;
    private string $scratch;
    private string $doorDir;
    private string $wrapper;
    private string $fakeEngine;
    private string $gamesDir;

    protected function setUp(): void
    {
        $this->realDoorDir = dirname(__DIR__, 2) . '/native-doors/doors/syncdoom';
        $this->wrapperSource = $this->realDoorDir . '/syncdoom-mp.sh';

        self::assertFileExists($this->wrapperSource);

        // The wrapper resolves the app root as three levels above its own
        // directory (native-doors/doors/<door_id>/) to find data/run/syncdoom/
        // games/, so the test tree must reproduce that exact layout.
        $this->scratch = sys_get_temp_dir() . '/syncdoom-wrap-' . bin2hex(random_bytes(6));
        $this->doorDir = $this->scratch . '/native-doors/doors/syncdoom';
        mkdir($this->doorDir, 0700, true);
        mkdir($this->scratch . '/home', 0700, true);
        mkdir($this->scratch . '/drops', 0700, true);

        copy($this->wrapperSource, $this->doorDir . '/syncdoom-mp.sh');
        chmod($this->doorDir . '/syncdoom-mp.sh', 0755);
        $this->wrapper = $this->doorDir . '/syncdoom-mp.sh';

        $this->fakeEngine = $this->doorDir . '/syncdoom';
        $this->gamesDir = $this->scratch . '/data/run/syncdoom/games';

        file_put_contents($this->scratch . '/drops/DOOR32.SYS', "dummy dropfile\n");

        // Default fake engine: a successful -spawnserver (prints "PID PORT" and
        // drops a registry entry under the -gamesdir it was given), and any
        // other invocation (single-player or -connect) just records its argv.
        $this->writeFakeEngine(<<<'SH'
#!/bin/bash
args=("$@")
is_spawn=0
gamesdir=""
for ((i=0;i<${#args[@]};i++)); do
    [ "${args[$i]}" = "-spawnserver" ] && is_spawn=1
    [ "${args[$i]}" = "-gamesdir" ] && gamesdir="${args[$((i+1))]}"
done
if [ "$is_spawn" = "1" ]; then
    printf '%s\n' "$@" > "__SCRATCH__/spawn.argv"
    mkdir -p "$gamesdir"
    echo "ok" > "$gamesdir/FAKEHOST-21777.ini"
    echo "54321 21777"
    exit 0
fi
printf '%s\n' "$@" > "__SCRATCH__/exec.argv"
env > "__SCRATCH__/exec.env"
exit 0
SH
        );
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->scratch);
    }

    private function writeFakeEngine(string $script): void
    {
        $script = str_replace('__SCRATCH__', $this->scratch, $script);
        file_put_contents($this->fakeEngine, $script);
        chmod($this->fakeEngine, 0755);
    }

    /**
     * @param array<string,string|false> $env value false removes the variable
     * @param string $input bytes to feed the wrapper's stdin (menu keypress)
     * @return array{code:int,stdout:string,stderr:string}
     */
    private function runWrapper(array $env = [], string $input = 'C'): array
    {
        $base = [
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'DOOR_DROPFILE' => $this->scratch . '/drops/DOOR32.SYS',
            'DOOR_HOME' => $this->scratch . '/home',
            'DOOR_USER_NAME' => 'Tester One',
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
        $proc = proc_open(['/bin/bash', $this->wrapper], $descriptors, $pipes, $this->doorDir, $final);
        self::assertIsResource($proc);
        fwrite($pipes[0], $input);
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

    /** @return list<string> */
    private function readArgvFile(string $name): array
    {
        $path = $this->scratch . '/' . $name;
        self::assertFileExists($path, "$name was not written -- engine not invoked as expected");
        $raw = (string)file_get_contents($path);
        $parts = explode("\n", $raw);
        if (end($parts) === '') {
            array_pop($parts);
        }
        return array_values($parts);
    }

    // ---- Create: server spawn -----------------------------------------

    public function testCreateSpawnsServerWithExactProvenFlagSet(): void
    {
        $r = $this->runWrapper();
        self::assertSame(0, $r['code'], $r['stdout'] . $r['stderr']);

        $argv = $this->readArgvFile('spawn.argv');

        self::assertContains('-spawnserver', $argv);
        self::assertContains('-maxplayers', $argv);
        self::assertSame('2', $argv[array_search('-maxplayers', $argv, true) + 1]);
        self::assertContains('-gamesdir', $argv);
        // Must carry a trailing slash: mp_write_registry() (mp_server.c) builds
        // its filename as a raw "<gamesdir><hostid>-<port>.ini" concatenation
        // with no separator -- a bare directory path would glue the registry
        // entry onto the directory's own name instead of inside it.
        self::assertSame($this->gamesDir . '/', $argv[array_search('-gamesdir', $argv, true) + 1]);
        self::assertContains('-wadset', $argv);
        self::assertSame('freedoom2', $argv[array_search('-wadset', $argv, true) + 1]);
        self::assertContains('-gamemode', $argv);
        self::assertSame('coop', $argv[array_search('-gamemode', $argv, true) + 1]);
        self::assertContains('-bindaddr', $argv);
        self::assertSame('127.0.0.1', $argv[array_search('-bindaddr', $argv, true) + 1]);
        self::assertContains('-advertise', $argv);
        self::assertSame('127.0.0.1', $argv[array_search('-advertise', $argv, true) + 1]);

        // Never preselects a port -- the engine's own allocation is used.
        self::assertNotContains('-port', $argv);
        // The detached server never gets a dropfile or a rendering flag.
        self::assertNotContains('-sixel', $argv);
        self::assertStringNotContainsString('DOOR32.SYS', implode(' ', $argv));
    }

    // ---- Create: successful hand-off -----------------------------------

    public function testCreateExecsClientWithConnectPlayersSkillHomeIwadNameSixel(): void
    {
        $r = $this->runWrapper();
        self::assertSame(0, $r['code'], $r['stdout'] . $r['stderr']);

        $argv = $this->readArgvFile('exec.argv');

        self::assertSame($this->scratch . '/drops/DOOR32.SYS', $argv[0], 'real drop file must be the first argument');
        self::assertContains('-connect', $argv);
        self::assertSame('127.0.0.1:21777', $argv[array_search('-connect', $argv, true) + 1]);
        self::assertContains('-players', $argv);
        self::assertSame('2', $argv[array_search('-players', $argv, true) + 1]);
        self::assertContains('-skill', $argv);
        self::assertSame('3', $argv[array_search('-skill', $argv, true) + 1]);
        self::assertContains('-home', $argv);
        self::assertSame($this->scratch . '/home', $argv[array_search('-home', $argv, true) + 1]);
        self::assertContains('-iwad', $argv);
        self::assertSame('freedoom2.wad', $argv[array_search('-iwad', $argv, true) + 1]);
        self::assertContains('-name', $argv);
        self::assertSame('Tester One', $argv[array_search('-name', $argv, true) + 1]);
        self::assertContains('-sixel', $argv);
        self::assertSame('1', $argv[array_search('-sixel', $argv, true) + 1]);
    }

    public function testCoopNeverPassesDeathmatchOrAltdeath(): void
    {
        $this->runWrapper();
        $argv = $this->readArgvFile('exec.argv');

        self::assertNotContains('-deathmatch', $argv);
        self::assertNotContains('-altdeath', $argv);
    }

    // ---- argv safety against a hostile caller name ----------------------

    public function testHostileCallerNameRemainsOneLiteralArgvEntryAndNeverExecutes(): void
    {
        $canary = $this->scratch . '/PWNED';
        $hostile = 'Bob"; touch ' . $canary . '; echo $(whoami) `id` $USER \' && rm -rf /';

        $r = $this->runWrapper(['DOOR_USER_NAME' => $hostile]);
        self::assertSame(0, $r['code'], $r['stdout'] . $r['stderr']);

        self::assertFileDoesNotExist($canary, 'caller-controlled username executed as shell syntax');

        $argv = $this->readArgvFile('exec.argv');
        $nameIdx = array_search('-name', $argv, true);
        self::assertNotFalse($nameIdx);
        self::assertSame($hostile, $argv[$nameIdx + 1], 'the hostile string must survive as one untouched argv entry');
        // Exactly one -name entry -- nothing was split into extra arguments.
        self::assertSame(1, count(array_keys($argv, '-name', true)));
    }

    public function testHostileCallerNameIsSanitizedInTheRegistryHostField(): void
    {
        // -host feeds mp_write_registry()'s unescaped fprintf into a shared
        // plain-text .ini (mp_server.c) -- unlike -name (sent over the wire),
        // it must never carry raw caller text that could corrupt the file.
        $hostile = "evil\nplayers = 99\nstatus = lobby";
        $this->runWrapper(['DOOR_USER_NAME' => $hostile]);

        $argv = $this->readArgvFile('spawn.argv');
        $hostIdx = array_search('-host', $argv, true);
        self::assertNotFalse($hostIdx);
        $hostValue = $argv[$hostIdx + 1];

        self::assertStringNotContainsString("\n", $hostValue);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $hostValue);
    }

    // ---- spawn failure never execs a client -----------------------------

    public function testSpawnFailureDoesNotExecAClient(): void
    {
        $this->writeFakeEngine(<<<'SH'
#!/bin/bash
if [[ " $* " == *" -spawnserver "* ]]; then
    echo "syncdoom: no free server port" >&2
    exit 1
fi
printf '%s\n' "$@" > "__SCRATCH__/exec.argv"
exit 0
SH
        );

        $r = $this->runWrapper();

        self::assertNotSame(0, $r['code']);
        self::assertFileDoesNotExist($this->scratch . '/exec.argv', 'a client must never be exec\'d after a failed spawn');
    }

    public function testMalformedSpawnOutputDoesNotExecAClient(): void
    {
        $this->writeFakeEngine(<<<'SH'
#!/bin/bash
if [[ " $* " == *" -spawnserver "* ]]; then
    echo "not-a-pid not-a-port"
    exit 0
fi
printf '%s\n' "$@" > "__SCRATCH__/exec.argv"
exit 0
SH
        );

        $r = $this->runWrapper();

        self::assertNotSame(0, $r['code']);
        self::assertFileDoesNotExist($this->scratch . '/exec.argv', 'a client must never be exec\'d after malformed spawn output');
    }

    public function testMissingRegistryEntryDoesNotExecAClient(): void
    {
        // -spawnserver reports success but never actually writes the
        // registry entry the wrapper waits for.
        $this->writeFakeEngine(<<<'SH'
#!/bin/bash
if [[ " $* " == *" -spawnserver "* ]]; then
    echo "54321 21777"
    exit 0
fi
printf '%s\n' "$@" > "__SCRATCH__/exec.argv"
exit 0
SH
        );

        $r = $this->runWrapper();

        self::assertNotSame(0, $r['code']);
        self::assertFileDoesNotExist($this->scratch . '/exec.argv');
    }

    // ---- Quit -------------------------------------------------------

    public function testQuitSpawnsNoServerOrClient(): void
    {
        $r = $this->runWrapper([], 'Q');

        self::assertSame(0, $r['code'], $r['stdout'] . $r['stderr']);
        self::assertFileDoesNotExist($this->scratch . '/spawn.argv');
        self::assertFileDoesNotExist($this->scratch . '/exec.argv');
    }

    public function testEofAtMenuReturnsCleanlyWithoutSpawning(): void
    {
        $r = $this->runWrapper([], '');

        self::assertSame(0, $r['code'], $r['stdout'] . $r['stderr']);
        self::assertFileDoesNotExist($this->scratch . '/spawn.argv');
        self::assertFileDoesNotExist($this->scratch . '/exec.argv');
    }

    // ---- Single Player: must reproduce today's exact accepted launch ---

    public function testSinglePlayerReproducesExactAcceptedLaunchSemantics(): void
    {
        $r = $this->runWrapper([], 'S');
        self::assertSame(0, $r['code'], $r['stdout'] . $r['stderr']);

        $argv = $this->readArgvFile('exec.argv');

        // Exact accepted production shape:
        //   syncdoom {dropfile} -home {homedir} -iwad freedoom2.wad -sixel 1
        self::assertSame([
            $this->scratch . '/drops/DOOR32.SYS',
            '-home', $this->scratch . '/home',
            '-iwad', 'freedoom2.wad',
            '-sixel', '1',
        ], $argv);

        self::assertNotContains('-connect', $argv);
        self::assertNotContains('-name', $argv);
        self::assertFileDoesNotExist($this->scratch . '/spawn.argv', 'single player must never spawn a dedicated server');
    }

    // ---- required-variable validation ------------------------------

    public function testMissingCallerNameFailsClosedBeforeCreate(): void
    {
        $r = $this->runWrapper(['DOOR_USER_NAME' => false]);

        self::assertNotSame(0, $r['code']);
        self::assertFileDoesNotExist($this->scratch . '/spawn.argv');
        self::assertStringContainsStringIgnoringCase('caller name', $r['stdout']);
    }

    public function testMissingHomeDirFailsClosedBeforeSinglePlayer(): void
    {
        $r = $this->runWrapper(['DOOR_HOME' => false], 'S');

        self::assertNotSame(0, $r['code']);
        self::assertFileDoesNotExist($this->scratch . '/exec.argv');
    }

    // ---- source-level guarantees -------------------------------------

    public function testSourceUsesNoEvalAndNoInterpolatedCommandString(): void
    {
        $src = (string)file_get_contents($this->wrapperSource);

        // No eval anywhere in the executable source (comments mentioning the
        // word are fine; an actual invocation is not).
        self::assertDoesNotMatchRegularExpression('/(^|[^A-Za-z0-9_#])eval\s/m', $src);
        self::assertMatchesRegularExpression('~^\s*set -eu\b~m', $src);

        // Every caller identity value is expanded as its own quoted argv
        // token, never concatenated into a single string handed to sh -c.
        self::assertStringNotContainsString('sh -c', $src);
        self::assertStringNotContainsString('bash -c', $src);
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
