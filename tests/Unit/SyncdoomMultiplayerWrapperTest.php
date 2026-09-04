<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The SyncDOOM multiplayer native-door wrapper (syncdoom-mp.sh):
 *
 *   [S] the existing, already-accepted single-player launch (unchanged);
 *   [C] Create a game: pick mode (Co-op/Deathmatch/Altdeath), player count
 *       (2-4) and skill (1-5), confirm, then spawn a detached dedicated
 *       server, confirm its registry entry, and exec the caller into the
 *       client as the netgame controller;
 *   [J] Join a game: enumerate other callers' currently-joinable lobbies
 *       directly from data/run/syncdoom/games/*.ini (no database, no other
 *       service) and, on a validated selection, exec into that match as a
 *       client.
 *
 * These tests drive the real script against a FAKE `syncdoom` placed at the
 * exact path the wrapper resolves ($(dirname "$0")/syncdoom) -- no real
 * engine, no real network -- and hand-written registry files for Join. They
 * assert:
 *
 *   * Create invokes -spawnserver with the exact proven flag set, and its
 *     registry metadata (-gamemode, -maxplayers) matches the caller's
 *     selection;
 *   * the wrapper safely parses the "<pid> <port>" spawn output;
 *   * the controller exec carries the real dropfile, -connect to the
 *     spawned port, the selected -players/-skill, -home, -iwad, -name as
 *     one argv entry, -sixel 1, and -deathmatch/-altdeath exactly when that
 *     mode was chosen (never for Co-op);
 *   * a caller name containing shell metacharacters can never execute;
 *   * a failed spawn never execs a client; cancelling at the [Y/N] prompt
 *     spawns nothing;
 *   * Quit spawns nothing;
 *   * Single Player reproduces today's exact accepted launch semantics;
 *   * Join lists Co-op/Deathmatch/Altdeath lobbies with 2-4 players and
 *     produces the exact expected join argv (never -deathmatch/-altdeath/
 *     -skill/-warp -- those are negotiated from the controller);
 *   * multiple lobbies list deterministically and numeric selection maps to
 *     the correct entry;
 *   * a full, in-progress, stale, malformed-port, non-loopback,
 *     unsupported-mode, or out-of-range-player-count entry is hidden
 *     without being touched or deleted;
 *   * a malformed registry file is ignored without crashing the wrapper;
 *   * a hostile host field (shell metacharacters, an ANSI escape sequence)
 *     is displayed only as inert sanitized text, never executed or emitted
 *     as a raw terminal control sequence;
 *   * a registry line that looks like a command substitution is treated as
 *     pure data and never executed;
 *   * a lobby that disappears between listing and selection (TOCTOU) is
 *     refused, not joined with stale cached values;
 *   * Q from the Join list launches nothing.
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
     * Default Create input: [C] then a single keystroke per prompt (mode,
     * players, skill, confirm). '9' never matches any prompt's own choices,
     * so it always falls through to that prompt's default -- Co-op, 2
     * players, skill 3 (Hurt Me Plenty) -- then 'Y' confirms. This reproduces
     * exactly the fixed co-op/2/3 shape the wrapper always used before
     * mode/player-count/skill selection existed.
     */
    private const DEFAULT_CREATE_INPUT = 'C999Y';

    /**
     * @param array<string,string|false> $env value false removes the variable
     * @param string $input bytes to feed the wrapper's stdin (menu keypresses)
     * @return array{code:int,stdout:string,stderr:string}
     */
    private function runWrapper(array $env = [], string $input = self::DEFAULT_CREATE_INPUT): array
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

    /**
     * Write a registry .ini exactly the way mp_write_registry() (mp_server.c)
     * does -- "key = value" lines -- under the same games dir the wrapper
     * resolves. Returns the file path.
     *
     * @param array<string,string> $fields
     */
    private function writeRegistry(string $name, array $fields): string
    {
        if (!is_dir($this->gamesDir)) {
            mkdir($this->gamesDir, 0700, true);
        }
        $lines = [];
        foreach ($fields as $k => $v) {
            $lines[] = "$k = $v";
        }
        $path = $this->gamesDir . '/' . $name . '.ini';
        file_put_contents($path, implode("\n", $lines) . "\n");
        return $path;
    }

    /** @param array<string,string> $overrides @return array<string,string> */
    private function defaultLobbyFields(array $overrides = []): array
    {
        return array_merge([
            'host' => 'GoodHost',
            'wadset' => 'freedoom2',
            'mode' => 'coop',
            'addr' => '127.0.0.1',
            'port' => '20500',
            'hostid' => 'HOSTX',
            'players' => '1',
            'maxplayers' => '2',
            'status' => 'lobby',
            'pid' => '99999',
            'heartbeat' => (string)time(),
        ], $overrides);
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

    // ---- Create: mode / player count / skill selection ------------------

    public function testCreateDeathmatchPassesDeathmatchToControllerAndRegistry(): void
    {
        // [C] mode=2 (Deathmatch), players=default, skill=default, confirm Y.
        $r = $this->runWrapper([], 'C299Y');
        self::assertSame(0, $r['code'], $r['stdout'] . $r['stderr']);

        $spawnArgv = $this->readArgvFile('spawn.argv');
        self::assertSame('deathmatch', $spawnArgv[array_search('-gamemode', $spawnArgv, true) + 1]);

        $execArgv = $this->readArgvFile('exec.argv');
        self::assertContains('-deathmatch', $execArgv);
        self::assertNotContains('-altdeath', $execArgv);
        self::assertStringContainsString('Deathmatch', $r['stdout']);
    }

    public function testCreateAltdeathPassesAltdeathToControllerAndRegistry(): void
    {
        // [C] mode=3 (Altdeath), players=default, skill=default, confirm Y.
        $r = $this->runWrapper([], 'C399Y');
        self::assertSame(0, $r['code'], $r['stdout'] . $r['stderr']);

        $spawnArgv = $this->readArgvFile('spawn.argv');
        self::assertSame('altdeath', $spawnArgv[array_search('-gamemode', $spawnArgv, true) + 1]);

        $execArgv = $this->readArgvFile('exec.argv');
        self::assertContains('-altdeath', $execArgv);
        self::assertNotContains('-deathmatch', $execArgv);
    }

    public function testCreatePlayerCountAppliesToServerAndController(): void
    {
        // [C] mode=default, players=4, skill=default, confirm Y.
        $r = $this->runWrapper([], 'C949Y');
        self::assertSame(0, $r['code'], $r['stdout'] . $r['stderr']);

        $spawnArgv = $this->readArgvFile('spawn.argv');
        self::assertSame('4', $spawnArgv[array_search('-maxplayers', $spawnArgv, true) + 1]);

        $execArgv = $this->readArgvFile('exec.argv');
        self::assertSame('4', $execArgv[array_search('-players', $execArgv, true) + 1]);
        self::assertStringContainsString('Players: 4', $r['stdout']);
    }

    public function testCreateSkillSelectionAppliesToController(): void
    {
        // [C] mode=default, players=default, skill=5 (Nightmare!), confirm Y.
        $r = $this->runWrapper([], 'C995Y');
        self::assertSame(0, $r['code'], $r['stdout'] . $r['stderr']);

        $execArgv = $this->readArgvFile('exec.argv');
        self::assertSame('5', $execArgv[array_search('-skill', $execArgv, true) + 1]);
        self::assertStringContainsString('Nightmare!', $r['stdout']);
    }

    public function testCreateCancelledAtConfirmationSpawnsNothing(): void
    {
        $r = $this->runWrapper([], 'C999N');
        self::assertSame(0, $r['code'], $r['stdout'] . $r['stderr']);

        self::assertFileDoesNotExist($this->scratch . '/spawn.argv');
        self::assertFileDoesNotExist($this->scratch . '/exec.argv');
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

    // ---- Join: discovery, selection, hand-off ---------------------------

    public function testJoinWithNoLobbiesShowsMessageAndExecsNothing(): void
    {
        $r = $this->runWrapper([], 'J');

        self::assertSame(0, $r['code'], $r['stdout'] . $r['stderr']);
        self::assertStringContainsStringIgnoringCase('no games are waiting', $r['stdout']);
        self::assertFileDoesNotExist($this->scratch . '/exec.argv');
        self::assertFileDoesNotExist($this->scratch . '/spawn.argv');
    }

    public function testJoinListsAndJoinsSingleValidLobbyWithExactArgvAndNoControllerFlags(): void
    {
        $this->writeRegistry('HOSTX-20500', $this->defaultLobbyFields());

        $r = $this->runWrapper([], "J1\n");
        self::assertSame(0, $r['code'], $r['stdout'] . $r['stderr']);
        self::assertStringContainsString('GoodHost', $r['stdout']);
        self::assertStringContainsString('1/2', $r['stdout']);

        $argv = $this->readArgvFile('exec.argv');
        self::assertSame($this->scratch . '/drops/DOOR32.SYS', $argv[0]);
        self::assertContains('-connect', $argv);
        self::assertSame('127.0.0.1:20500', $argv[array_search('-connect', $argv, true) + 1]);
        self::assertContains('-players', $argv);
        self::assertSame('2', $argv[array_search('-players', $argv, true) + 1]);
        self::assertContains('-home', $argv);
        self::assertSame($this->scratch . '/home', $argv[array_search('-home', $argv, true) + 1]);
        self::assertContains('-iwad', $argv);
        self::assertSame('freedoom2.wad', $argv[array_search('-iwad', $argv, true) + 1]);
        self::assertContains('-name', $argv);
        self::assertSame('Tester One', $argv[array_search('-name', $argv, true) + 1]);
        self::assertSame(1, count(array_keys($argv, '-name', true)));
        self::assertContains('-sixel', $argv);
        self::assertSame('1', $argv[array_search('-sixel', $argv, true) + 1]);

        // The joiner never reconstructs controller gameplay flags -- those
        // are negotiated from the controller over the network.
        self::assertNotContains('-deathmatch', $argv);
        self::assertNotContains('-altdeath', $argv);
        self::assertNotContains('-skill', $argv);
        self::assertNotContains('-warp', $argv);
    }

    public function testJoinListsMultipleLobbiesDeterministicallyAndSelectionMapsCorrectly(): void
    {
        $this->writeRegistry('AAA-20501', $this->defaultLobbyFields(['host' => 'FirstHost', 'port' => '20501']));
        $this->writeRegistry('BBB-20502', $this->defaultLobbyFields(['host' => 'SecondHost', 'port' => '20502']));

        // Selecting "2" must join whichever entry is listed second, not
        // whichever file happens to sort first on disk.
        $r = $this->runWrapper([], 'J');
        self::assertMatchesRegularExpression('/1\.\s+\S+.*\r?\n.*2\.\s+\S+/s', $r['stdout']);

        preg_match('/1\.\s+(\S+)/', $r['stdout'], $m1);
        preg_match('/2\.\s+(\S+)/', $r['stdout'], $m2);
        $secondListedHost = $m2[1];

        $r2 = $this->runWrapper([], "J2\n");
        $argv = $this->readArgvFile('exec.argv');
        $connect = $argv[array_search('-connect', $argv, true) + 1];
        $expectedPort = $secondListedHost === 'FirstHost' ? '20501' : '20502';
        self::assertSame('127.0.0.1:' . $expectedPort, $connect);
    }

    public function testJoinHidesFullInProgressStaleMalformedNonLoopbackOutOfRangeAndUnsupportedModeLobbies(): void
    {
        $now = time();
        $this->writeRegistry('FULL', $this->defaultLobbyFields(['host' => 'FullHost', 'port' => '20510', 'players' => '2', 'maxplayers' => '2']));
        $this->writeRegistry('PLAYING', $this->defaultLobbyFields(['host' => 'PlayingHost', 'port' => '20511', 'status' => 'playing']));
        $this->writeRegistry('STALE', $this->defaultLobbyFields(['host' => 'StaleHost', 'port' => '20512', 'heartbeat' => (string)($now - 100)]));
        $this->writeRegistry('BADPORT', $this->defaultLobbyFields(['host' => 'BadPortHost', 'port' => 'not-a-port']));
        $this->writeRegistry('LAN', $this->defaultLobbyFields(['host' => 'LanHost', 'port' => '20513', 'addr' => '10.0.0.5']));
        // "duel" is not one of coop/deathmatch/altdeath -- a genuinely
        // unsupported mode, unlike deathmatch/altdeath which Create now ships.
        $this->writeRegistry('UNSUPPORTED', $this->defaultLobbyFields(['host' => 'DuelHost', 'port' => '20514', 'mode' => 'duel']));
        $this->writeRegistry('TOOFEW', $this->defaultLobbyFields(['host' => 'TooFewHost', 'port' => '20516', 'players' => '0', 'maxplayers' => '1']));
        $this->writeRegistry('TOOMANY', $this->defaultLobbyFields(['host' => 'TooManyHost', 'port' => '20517', 'players' => '0', 'maxplayers' => '5']));
        $this->writeRegistry('GOOD', $this->defaultLobbyFields(['host' => 'OnlyGoodHost', 'port' => '20515']));

        $r = $this->runWrapper([], 'J');

        foreach (['FullHost', 'PlayingHost', 'StaleHost', 'BadPortHost', 'LanHost', 'DuelHost', 'TooFewHost', 'TooManyHost'] as $hidden) {
            self::assertStringNotContainsString($hidden, $r['stdout'], "$hidden must be hidden from Join");
        }
        self::assertStringContainsString('OnlyGoodHost', $r['stdout']);
        // Exactly one numbered entry.
        self::assertSame(1, preg_match_all('/^\s*\d+\.\s/m', $r['stdout']));
    }

    public function testJoinDisplaysDeathmatchAltdeathAndVariedPlayerCounts(): void
    {
        $this->writeRegistry('COOP4', $this->defaultLobbyFields([
            'host' => 'Skrawl', 'port' => '20570', 'mode' => 'coop', 'players' => '1', 'maxplayers' => '4',
        ]));
        $this->writeRegistry('DM3', $this->defaultLobbyFields([
            'host' => 'BraidedDuck', 'port' => '20571', 'mode' => 'deathmatch', 'players' => '2', 'maxplayers' => '3',
        ]));
        $this->writeRegistry('ALTD2', $this->defaultLobbyFields([
            'host' => 'AltHost', 'port' => '20572', 'mode' => 'altdeath', 'players' => '1', 'maxplayers' => '2',
        ]));

        $r = $this->runWrapper([], 'J');

        self::assertMatchesRegularExpression('/Skrawl\s+--\s+Co-op\s+--\s+1\/4 players/', $r['stdout']);
        self::assertMatchesRegularExpression('/BraidedDuck\s+--\s+Deathmatch\s+--\s+2\/3 players/', $r['stdout']);
        self::assertMatchesRegularExpression('/AltHost\s+--\s+Altdeath\s+--\s+1\/2 players/', $r['stdout']);
    }

    public function testJoinAcceptsAndConnectsToADeathmatchThreePlayerLobby(): void
    {
        $this->writeRegistry('DM3-20573', $this->defaultLobbyFields([
            'host' => 'BraidedDuck', 'port' => '20573', 'mode' => 'deathmatch', 'players' => '1', 'maxplayers' => '3',
        ]));

        $r = $this->runWrapper([], "J1\n");
        self::assertSame(0, $r['code'], $r['stdout'] . $r['stderr']);

        $argv = $this->readArgvFile('exec.argv');
        self::assertSame('127.0.0.1:20573', $argv[array_search('-connect', $argv, true) + 1]);
        self::assertSame('3', $argv[array_search('-players', $argv, true) + 1]);
        // The joiner still never reconstructs controller gameplay flags,
        // even for a deathmatch lobby -- the controller already negotiates it.
        self::assertNotContains('-deathmatch', $argv);
        self::assertNotContains('-altdeath', $argv);
        self::assertNotContains('-skill', $argv);
        self::assertNotContains('-warp', $argv);
    }

    public function testJoinIgnoresMalformedRegistryFileWithoutCrashing(): void
    {
        if (!is_dir($this->gamesDir)) {
            mkdir($this->gamesDir, 0700, true);
        }
        // No '=' anywhere, binary-ish content, no recognizable fields at all.
        file_put_contents($this->gamesDir . '/GARBAGE.ini', "\x00\x01\xffnot an ini file at all\nrandom text\n");
        $this->writeRegistry('GOOD', $this->defaultLobbyFields(['host' => 'SurvivorHost', 'port' => '20520']));

        $r = $this->runWrapper([], 'J');

        self::assertSame(0, $r['code'], $r['stdout'] . $r['stderr']);
        self::assertStringContainsString('SurvivorHost', $r['stdout']);
        self::assertSame(1, preg_match_all('/^\s*\d+\.\s/m', $r['stdout']));
    }

    public function testJoinSanitizesHostileHostDisplayValueAndNeverEmitsRawEscapeOrExecutesIt(): void
    {
        $canary = $this->scratch . '/JOIN_HOST_PWNED';
        $hostileHost = "Evil\x1b[31m; touch {$canary}; \$(id)";
        $this->writeRegistry('HOSTILE', $this->defaultLobbyFields(['host' => $hostileHost, 'port' => '20530']));

        $r = $this->runWrapper([], 'J');

        self::assertFileDoesNotExist($canary, 'a hostile host field must never execute');
        // The raw ESC byte must never reach the terminal stream.
        self::assertStringNotContainsString("\x1b", $r['stdout'], 'a raw ANSI escape byte leaked into Join display output');
    }

    public function testJoinRegistryLineLookingLikeCommandSubstitutionIsInertData(): void
    {
        $canary = $this->scratch . '/JOIN_SUBSHELL_PWNED';
        $path = $this->writeRegistry('SUBSHELL', $this->defaultLobbyFields(['port' => '20540']));
        file_put_contents($path, "evil=\$(touch {$canary})\n", FILE_APPEND);

        $r = $this->runWrapper([], "J1\n");

        self::assertFileDoesNotExist($canary, 'a registry line resembling a command substitution must never execute');
        // The lobby itself is still otherwise valid and joinable -- the
        // unrecognized "evil" key is simply ignored, not fatal.
        self::assertFileExists($this->scratch . '/exec.argv');
    }

    public function testToctouRefusesJoinWhenLobbyDisappearsBeforeSelection(): void
    {
        $ini = $this->writeRegistry('TOCTOU', $this->defaultLobbyFields(['port' => '20550']));

        $env = [
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'DOOR_DROPFILE' => $this->scratch . '/drops/DOOR32.SYS',
            'DOOR_HOME' => $this->scratch . '/home',
            'DOOR_USER_NAME' => 'Tester One',
        ];
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open(['/bin/bash', $this->wrapper], $descriptors, $pipes, $this->doorDir, $env);
        self::assertIsResource($proc);

        fwrite($pipes[0], 'J');
        usleep(300000);
        // The lobby vanishes between the list being shown and the caller's
        // selection reaching the wrapper -- the real-world race this guards.
        unlink($ini);
        usleep(200000);
        fwrite($pipes[0], "1\n");
        fclose($pipes[0]);

        $stdout = '';
        $deadline = microtime(true) + 15.0;
        do {
            $stdout .= (string)stream_get_contents($pipes[1]);
            $st = proc_get_status($proc);
            if (!$st['running']) {
                break;
            }
            usleep(15000);
        } while (microtime(true) < $deadline);
        $stdout .= (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        self::assertStringContainsStringIgnoringCase('no longer available', $stdout);
        self::assertFileDoesNotExist($this->scratch . '/exec.argv');
    }

    public function testQuitFromJoinLaunchesNothing(): void
    {
        $this->writeRegistry('GOOD', $this->defaultLobbyFields(['port' => '20560']));

        $r = $this->runWrapper([], "JQ\n");

        self::assertSame(0, $r['code'], $r['stdout'] . $r['stderr']);
        self::assertFileDoesNotExist($this->scratch . '/exec.argv');
        self::assertFileDoesNotExist($this->scratch . '/spawn.argv');
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
