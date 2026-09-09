<?php

declare(strict_types=1);

use BinktermPHP\Terminal\Navigation\NavigationConfig;
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
