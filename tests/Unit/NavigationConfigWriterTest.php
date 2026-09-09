<?php

declare(strict_types=1);

use BinktermPHP\Terminal\Navigation\NavigationConfigWriter;
use BinktermPHP\Terminal\Navigation\NavigationDefinitionLoader;
use BinktermPHP\Terminal\Navigation\TerminalActionCatalog;
use PHPUnit\Framework\TestCase;

/**
 * R5 pre-F6 item C — the privileged, validated, atomic navigation-config write
 * boundary. No IPC / daemon needed: the writer is a plain service.
 */
final class NavigationConfigWriterTest extends TestCase
{
    private string $dir;
    private string $target;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/navwrite_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0755);
        $this->target = $this->dir . '/terminal_navigation.json';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @chmod($f, 0644);
            @unlink($f);
        }
        foreach (glob($this->dir . '/.*') ?: [] as $f) {
            if (!is_dir($f)) {
                @unlink($f);
            }
        }
        @rmdir($this->dir);
    }

    private function writer(): NavigationConfigWriter
    {
        return new NavigationConfigWriter(TerminalActionCatalog::defaultRegistry());
    }

    private function validJson(string $id = 'writer.test'): string
    {
        return json_encode([
            'schema' => 1, 'id' => $id, 'root' => 'main',
            'nodes' => [['id' => 'main', 'label_fallback' => 'Main', 'items' => [
                ['id' => 'n', 'label_fallback' => 'Netmail', 'hotkey' => 'n', 'action' => 'netmail'],
                ['id' => 'q', 'label_fallback' => 'Quit', 'hotkey' => 'q', 'action' => 'quit'],
            ]]],
        ]);
    }

    // ===== happy path =====

    public function testWritesAValidDefinitionAtomicallyAndCanonically(): void
    {
        $result = $this->writer()->write($this->validJson(), $this->target, $this->dir);

        self::assertTrue($result->written, $result->errorSummary());
        self::assertTrue($result->valid);
        self::assertFileExists($this->target);

        $onDisk = file_get_contents($this->target);
        self::assertSame($result->canonicalJson, $onDisk);
        self::assertStringEndsWith("\n", $onDisk);
        self::assertStringContainsString("\n    ", $onDisk, 'pretty-printed');

        // Re-loads cleanly through the normal loader.
        $reload = (new NavigationDefinitionLoader(TerminalActionCatalog::defaultRegistry()))->fromFile($this->target);
        self::assertTrue($reload->isOk());

        // No temp files left behind.
        self::assertSame([], glob($this->dir . '/.*.tmp') ?: []);
        self::assertCount(1, glob($this->dir . '/*') ?: []);
    }

    public function testCanonicalisationNormalisesWhitespaceButPreservesKeyOrder(): void
    {
        $ugly = '{"schema":1,   "id":"x",    "root":"main","nodes":[{"id":"main","label_fallback":"Main","items":[{"id":"n","label_fallback":"Netmail","action":"netmail"}]}]}';
        $result = $this->writer()->write($ugly, $this->target, $this->dir);

        self::assertTrue($result->written);
        $lines = explode("\n", trim($result->canonicalJson));
        self::assertSame('{', $lines[0]);
        self::assertStringContainsString('"schema": 1', $lines[1], 'first key stays first');
    }

    public function testExistingFileModeIsPreserved(): void
    {
        file_put_contents($this->target, '{}');
        chmod($this->target, 0640);

        $this->writer()->write($this->validJson(), $this->target, $this->dir);

        self::assertSame('0640', substr(sprintf('%o', fileperms($this->target)), -4));
    }

    // ===== validation: nothing written, old file intact =====

    public function testInvalidDefinitionIsNotWrittenAndLeavesTheOldFileIntact(): void
    {
        $this->writer()->write($this->validJson('original'), $this->target, $this->dir);
        $before = file_get_contents($this->target);

        $bad = json_encode([
            'schema' => 1, 'id' => 'x', 'root' => 'main',
            'nodes' => [['id' => 'main', 'label_fallback' => 'M', 'items' => [
                ['id' => 'a', 'label_fallback' => 'A', 'action' => 'no_such_action'],
            ]]],
        ]);
        $result = $this->writer()->write($bad, $this->target, $this->dir);

        self::assertFalse($result->written);
        self::assertFalse($result->valid);
        self::assertNotEmpty($result->errors);
        self::assertContains('unknown_action', array_map(fn ($e) => $e->code, $result->errors));
        self::assertSame($before, file_get_contents($this->target), 'previous config untouched');
        self::assertSame([], glob($this->dir . '/.*.tmp') ?: [], 'no temp file left behind');
    }

    public function testMalformedJsonIsRejected(): void
    {
        $result = $this->writer()->write('{ not json', $this->target, $this->dir);
        self::assertFalse($result->written);
        self::assertSame('json', $result->errors[0]->code);
        self::assertFileDoesNotExist($this->target);
    }

    public function testUnsupportedSchemaIsRejected(): void
    {
        $result = $this->writer()->write(
            json_encode(['schema' => 7, 'id' => 'x', 'root' => 'm', 'nodes' => [['id' => 'm', 'label_fallback' => 'M', 'items' => [['id' => 'a', 'label_fallback' => 'A', 'action' => 'netmail']]]]]),
            $this->target,
            $this->dir
        );
        self::assertFalse($result->written);
        self::assertSame('schema', $result->errors[0]->code);
    }

    // ===== destination constraints =====

    public function testRefusesATargetOutsideTheBaseDirectory(): void
    {
        $outside = sys_get_temp_dir() . '/navwrite_escape_' . bin2hex(random_bytes(4)) . '.json';
        $result = $this->writer()->write($this->validJson(), $outside, $this->dir);

        self::assertFalse($result->written);
        self::assertSame('path_constraint', $result->ioError);
        self::assertFileDoesNotExist($outside);
    }

    public function testRefusesATraversalStyleTarget(): void
    {
        $result = $this->writer()->write($this->validJson(), $this->dir . '/../evil.json', $this->dir);
        self::assertFalse($result->written);
        self::assertSame('path_constraint', $result->ioError);
    }

    public function testRefusesANonJsonBasename(): void
    {
        $result = $this->writer()->write($this->validJson(), $this->dir . '/terminal_navigation.txt', $this->dir);
        self::assertFalse($result->written);
        self::assertSame('path_constraint', $result->ioError);
    }

    public function testRefusesWhenTargetIsADirectory(): void
    {
        mkdir($this->dir . '/adir.json');
        $result = $this->writer()->write($this->validJson(), $this->dir . '/adir.json', $this->dir);
        @rmdir($this->dir . '/adir.json');

        self::assertFalse($result->written);
        self::assertSame('path_constraint', $result->ioError);
    }

    public function testRefusesWhenTargetIsASymlinkPointingOutsideTheBase(): void
    {
        $elsewhere = sys_get_temp_dir() . '/navwrite_sym_' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($elsewhere, '{}');
        symlink($elsewhere, $this->target);

        try {
            $result = $this->writer()->write($this->validJson(), $this->target, $this->dir);
            self::assertFalse($result->written);
            self::assertSame('path_constraint', $result->ioError);
            self::assertSame('{}', file_get_contents($elsewhere), 'the symlink target was not written through');
        } finally {
            @unlink($this->target);
            @unlink($elsewhere);
        }
    }

    public function testRefusesWhenTheBaseDirectoryDoesNotExist(): void
    {
        $result = $this->writer()->write(
            $this->validJson(),
            '/no/such/dir/terminal_navigation.json',
            '/no/such/dir'
        );
        self::assertFalse($result->written);
        self::assertSame('path_constraint', $result->ioError);
    }

    public function testResultToArrayIsStructuredForTheDaemonResponse(): void
    {
        $ok = $this->writer()->write($this->validJson(), $this->target, $this->dir)->toArray();
        self::assertTrue($ok['written']);
        self::assertSame($this->target, $ok['path']);
        self::assertGreaterThan(0, $ok['bytes']);
        self::assertNull($ok['io_error']);
        self::assertIsArray($ok['errors']);
    }

    /**
     * The admin daemon must always write to the fixed config/ path — never a
     * TERMINAL_NAV_CONFIG override.
     */
    public function testAdminDaemonUsesAFixedTargetPathIgnoringTheReadOverride(): void
    {
        $server = new \BinktermPHP\Admin\AdminDaemonServer(secret: 'test');
        $m = new \ReflectionMethod($server, 'getTerminalNavigationConfigPath');
        $m->setAccessible(true);

        $prev = $_ENV['TERMINAL_NAV_CONFIG'] ?? null;
        $_ENV['TERMINAL_NAV_CONFIG'] = '/tmp/somewhere/else.json';
        try {
            $path = $m->invoke($server);
        } finally {
            if ($prev === null) {
                unset($_ENV['TERMINAL_NAV_CONFIG']);
            } else {
                $_ENV['TERMINAL_NAV_CONFIG'] = $prev;
            }
        }

        self::assertStringEndsWith('/config/terminal_navigation.json', $path);
        self::assertStringNotContainsString('somewhere/else', $path);
    }

    public function testAdminDaemonReadHelperReturnsTheStructuredShape(): void
    {
        $server = new \BinktermPHP\Admin\AdminDaemonServer(secret: 'test');
        $m = new \ReflectionMethod($server, 'getTerminalNavigationConfig');
        $m->setAccessible(true);

        $out = $m->invoke($server);

        self::assertArrayHasKey('exists', $out);
        self::assertArrayHasKey('valid', $out);
        self::assertArrayHasKey('errors', $out);
        self::assertArrayHasKey('json', $out);
        self::assertIsBool($out['exists']);
    }
}
