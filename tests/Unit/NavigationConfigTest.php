<?php

declare(strict_types=1);

use BinktermPHP\Terminal\Navigation\NavigationConfig;
use BinktermPHP\Terminal\Navigation\NavigationDefinition;
use BinktermPHP\Terminal\Navigation\NavigationDefinitionLoader;
use BinktermPHP\Terminal\Navigation\TerminalActionCatalog;
use PHPUnit\Framework\TestCase;

/**
 * R5B + R5J — config loading, the double gate, and zero-config compatibility.
 */
final class NavigationConfigTest extends TestCase
{
    private array $envBackup = [];

    protected function setUp(): void
    {
        foreach (['TERMINAL_NAV_RUNTIME', 'TERMINAL_NAV_CONFIG'] as $k) {
            $this->envBackup[$k] = $_ENV[$k] ?? null;
            unset($_ENV[$k]);
        }
        NavigationConfig::reset();
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $k => $v) {
            if ($v === null) {
                unset($_ENV[$k]);
            } else {
                $_ENV[$k] = $v;
            }
        }
        NavigationConfig::reset();
    }

    public function testRuntimeIsDisabledByDefault(): void
    {
        self::assertFalse(NavigationConfig::isRuntimeEnabled(), 'zero-config: legacy menu path');
        self::assertNull(NavigationConfig::resolveDefinition());
    }

    public function testRuntimeStaysDisabledWithTheFlagButNoFile(): void
    {
        $_ENV['TERMINAL_NAV_RUNTIME'] = 'on';
        $_ENV['TERMINAL_NAV_CONFIG']  = '/nonexistent/path/terminal_navigation.json';
        NavigationConfig::reset();

        self::assertFalse(NavigationConfig::isRuntimeEnabled());
        self::assertNull(NavigationConfig::resolveDefinition());
    }

    public function testRuntimeStaysDisabledWithAFileButNoFlag(): void
    {
        $_ENV['TERMINAL_NAV_CONFIG'] = $this->writeTempDefinition($this->validRaw());
        NavigationConfig::reset();

        self::assertFalse(NavigationConfig::isRuntimeEnabled(), 'a file alone does not activate the runtime');
        self::assertNull(NavigationConfig::resolveDefinition());
    }

    public function testBothGatesPresentAndValidActivatesTheRuntime(): void
    {
        $_ENV['TERMINAL_NAV_RUNTIME'] = 'true';
        $_ENV['TERMINAL_NAV_CONFIG']  = $this->writeTempDefinition($this->validRaw());
        NavigationConfig::reset();

        self::assertTrue(NavigationConfig::isRuntimeEnabled());
        $def = NavigationConfig::resolveDefinition();
        self::assertNotNull($def);
        self::assertSame('unit.board', $def->id);
    }

    public function testInvalidFileKeepsTheRuntimeOffAndReportsErrors(): void
    {
        $bad = $this->validRaw();
        $bad['nodes'][0]['items'][0]['action'] = 'not_a_real_action';

        $_ENV['TERMINAL_NAV_RUNTIME'] = 'on';
        $_ENV['TERMINAL_NAV_CONFIG']  = $this->writeTempDefinition($bad);
        NavigationConfig::reset();

        self::assertTrue(NavigationConfig::isRuntimeEnabled(), 'the gate is on...');
        self::assertNull(NavigationConfig::resolveDefinition(), '...but an invalid definition is never used');
        self::assertFalse(NavigationConfig::load()->isOk());
        self::assertStringContainsString('unknown_action', NavigationConfig::load()->errorSummary());
    }

    public function testShippedExampleDefinitionIsValid(): void
    {
        $example = dirname(__DIR__, 2) . '/config/terminal_navigation.json.example';
        self::assertFileExists($example);

        $result = (new NavigationDefinitionLoader(TerminalActionCatalog::defaultRegistry()))
            ->fromFile($example);

        self::assertTrue($result->isOk(), $result->errorSummary());
    }

    // ===== failure-path hardening (B) =====

    /** @dataProvider flagValueProvider */
    public function testFlagValueParsing(string $value, bool $expected): void
    {
        $_ENV['TERMINAL_NAV_RUNTIME'] = $value;
        NavigationConfig::reset();
        self::assertSame($expected, NavigationConfig::isFlagEnabled());
    }

    public static function flagValueProvider(): array
    {
        return [
            ['on', true], ['ON', true], ['1', true], ['true', true], ['yes', true], [' YeS ', true],
            ['off', false], ['0', false], ['false', false], ['no', false], ['', false], ['garbage', false],
        ];
    }

    /** @dataProvider badFileProvider */
    public function testEachFileFailureModeYieldsADistinctActionableError(callable $make, string $code, string $needle): void
    {
        $path = $make($this);
        $_ENV['TERMINAL_NAV_RUNTIME'] = 'on';
        $_ENV['TERMINAL_NAV_CONFIG']  = $path;
        NavigationConfig::reset();

        $result = NavigationConfig::load();
        self::assertFalse($result->isOk());
        self::assertSame([$code], array_values(array_unique(array_map(fn ($e) => $e->code, $result->errors()))));
        self::assertStringContainsString($needle, $result->errorSummary());
        self::assertNull(NavigationConfig::resolveDefinition(), 'runtime not activated on a bad file');
    }

    public static function badFileProvider(): array
    {
        return [
            'missing' => [
                fn () => sys_get_temp_dir() . '/nav_missing_' . bin2hex(random_bytes(5)) . '.json',
                'missing', 'no navigation definition at',
            ],
            'directory' => [
                function () {
                    $d = sys_get_temp_dir() . '/nav_dir_' . bin2hex(random_bytes(5));
                    mkdir($d);
                    register_shutdown_function(static fn () => @rmdir($d));
                    return $d;
                },
                'missing', 'path is a directory',
            ],
            'empty file' => [
                fn ($t) => $t->writeRaw(''),
                'json', 'file is empty',
            ],
            'invalid json' => [
                fn ($t) => $t->writeRaw('{ "schema": 1, '),
                'json', 'invalid JSON',
            ],
            'json array not object' => [
                fn ($t) => $t->writeRaw('[1,2,3]'),
                'json', 'top level must be a JSON object',
            ],
            'unsupported schema' => [
                fn ($t) => $t->writeRaw(json_encode(['schema' => 99, 'id' => 'x', 'root' => 'm', 'nodes' => [['id' => 'm', 'label_fallback' => 'M', 'items' => [['id' => 'a', 'label_fallback' => 'A', 'action' => 'netmail']]]]])),
                'schema', 'unsupported schema version 99',
            ],
        ];
    }

    public function testInvalidJsonErrorIdentifiesTheFile(): void
    {
        $path = $this->writeRaw('not json at all');
        $_ENV['TERMINAL_NAV_RUNTIME'] = 'on';
        $_ENV['TERMINAL_NAV_CONFIG']  = $path;
        NavigationConfig::reset();

        self::assertStringContainsString($path, (string) NavigationConfig::load()->errors()[0]);
    }

    public function testUnreadableFileIsDistinguishedFromMissing(): void
    {
        if (posix_geteuid() === 0) {
            self::markTestSkipped('running as root — chmod 000 is still readable');
        }
        $path = $this->writeRaw('{}');
        chmod($path, 0000);

        $_ENV['TERMINAL_NAV_CONFIG'] = $path;
        NavigationConfig::reset();

        $errors = NavigationConfig::load()->errors();
        self::assertSame('unreadable', $errors[0]->code);
        self::assertStringContainsString('permission', strtolower((string) $errors[0]));
        chmod($path, 0644);
    }

    public function testTooManyNodesIsRejected(): void
    {
        $nodes = [['id' => 'main', 'label_fallback' => 'M', 'items' => [['id' => 'a', 'label_fallback' => 'A', 'action' => 'netmail']]]];
        for ($i = 0; $i < NavigationDefinition::MAX_NODES + 5; $i++) {
            $nodes[] = ['id' => "n{$i}", 'label_fallback' => "N{$i}", 'items' => [['id' => 'x', 'label_fallback' => 'X', 'action' => 'netmail']]];
        }
        $result = $this->loader()->fromArray(['schema' => 1, 'id' => 'big', 'root' => 'main', 'nodes' => $nodes]);
        self::assertFalse($result->isOk());
        self::assertStringContainsString('too many nodes', $result->errorSummary());
    }

    public function testDeepSubmenuChainUnderTheLimitDoesNotOverflow(): void
    {
        // A long linear submenu chain — the iterative cycle check must handle it.
        $depth = 400;
        $nodes = [];
        for ($i = 0; $i < $depth; $i++) {
            $items = $i + 1 < $depth
                ? [['id' => 'next', 'label_fallback' => 'Next', 'submenu' => "n" . ($i + 1)]]
                : [['id' => 'leaf', 'label_fallback' => 'Leaf', 'action' => 'netmail']];
            $nodes[] = ['id' => "n{$i}", 'label_fallback' => "N{$i}", 'items' => $items];
        }
        $result = $this->loader()->fromArray(['schema' => 1, 'id' => 'chain', 'root' => 'n0', 'nodes' => $nodes]);
        self::assertTrue($result->isOk(), $result->errorSummary());
    }

    public function testCacheIsInvalidatedWhenTheFileIsReplaced(): void
    {
        $path = $this->writeRaw(json_encode($this->validRawWithId('first')));
        $_ENV['TERMINAL_NAV_RUNTIME'] = 'on';
        $_ENV['TERMINAL_NAV_CONFIG']  = $path;
        NavigationConfig::reset();

        self::assertSame('first', NavigationConfig::load()->definition()->id);

        // Replace the file with different content + a newer mtime.
        file_put_contents($path, json_encode($this->validRawWithId('second')));
        touch($path, time() + 5);

        self::assertSame('second', NavigationConfig::load()->definition()->id, 'stale cache must not survive a file replacement');
    }

    public function testPathOverrideIsHonouredForReads(): void
    {
        // The override is a sysop-controlled .env value used verbatim for reads;
        // it grants no privilege the sysop lacks, so it is not sandboxed here.
        $path = $this->writeRaw(json_encode($this->validRaw()));
        $_ENV['TERMINAL_NAV_CONFIG'] = $path;
        NavigationConfig::reset();

        self::assertSame($path, NavigationConfig::path());
        self::assertTrue(NavigationConfig::load()->isOk());
    }

    public function testNothingThrowsFromTheGateHelpers(): void
    {
        // Even with a hostile path value, the flag/enabled checks stay quiet.
        $_ENV['TERMINAL_NAV_CONFIG']  = "\0/bad";
        $_ENV['TERMINAL_NAV_RUNTIME'] = 'on';
        NavigationConfig::reset();

        self::assertTrue(NavigationConfig::isFlagEnabled());
        self::assertFalse(NavigationConfig::isRuntimeEnabled());
        self::assertNull(NavigationConfig::resolveDefinition());
    }

    private function loader(): NavigationDefinitionLoader
    {
        return new NavigationDefinitionLoader(TerminalActionCatalog::defaultRegistry());
    }

    public function writeRaw(string $contents): string
    {
        $path = sys_get_temp_dir() . '/nav_raw_' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($path, $contents);
        register_shutdown_function(static function () use ($path) {
            @chmod($path, 0644);
            @unlink($path);
        });

        return $path;
    }

    private function validRawWithId(string $id): array
    {
        $raw = $this->validRaw();
        $raw['id'] = $id;

        return $raw;
    }

    private function validRaw(): array
    {
        return [
            'schema' => 1,
            'id'     => 'unit.board',
            'root'   => 'main',
            'nodes'  => [
                ['id' => 'main', 'label_fallback' => 'Main', 'items' => [
                    ['id' => 'n', 'label_fallback' => 'Netmail', 'hotkey' => 'n', 'action' => 'netmail'],
                    ['id' => 'q', 'label_fallback' => 'Quit', 'hotkey' => 'q', 'action' => 'quit'],
                ]],
            ],
        ];
    }

    private function writeTempDefinition(array $raw): string
    {
        $path = sys_get_temp_dir() . '/nav_' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($path, json_encode($raw));
        register_shutdown_function(static fn () => @unlink($path));

        return $path;
    }
}
