<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AsciiRoyaleM3LaunchWrapperTest extends TestCase
{
    private const SHA = 'ac7d9771dfd788b278427db619e43989d4317029';
    private string $root;
    private string $wrapper;
    private string $secret = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ascii-royale-m3-test-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/runtime/' . self::SHA, 0700, true);
        mkdir($this->root . '/channel', 0750, true);
        mkdir($this->root . '/home', 0700, true);
        $this->wrapper = dirname(__DIR__, 2) . '/native-doors/doors/ascii-royale-m3/launch-ascii-royale.sh';
        file_put_contents($this->root . '/runtime/alsa-null.conf', "pcm.!default { type null }\nctl.!default { type null }\n");
        $fake = <<<'SH'
#!/bin/bash
printf 'pid=%s ppid=%s name=%s argc=%s home=%s xdg=%s alsa=%s\n' \
  "$$" "$PPID" "$4" "$#" "$HOME" "$XDG_CONFIG_HOME" "$ALSA_CONFIG_PATH"
SH;
        file_put_contents($this->root . '/runtime/' . self::SHA . '/ascii-royale', $fake);
        chmod($this->root . '/runtime/' . self::SHA . '/ascii-royale', 0700);
        $this->writeChannel($this->secret);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($this->root);
        }
    }

    /** @dataProvider identityProvider */
    public function testDeterministicIdentity(string $name, string $id, string $expected): void
    {
        $a = $this->runWrapper($name, $id);
        $b = $this->runWrapper($name, $id);
        self::assertSame(0, $a['code'], $a['stderr']);
        self::assertSame(
            preg_replace('/pid=\d+/', 'pid=<dynamic>', $a['stdout']),
            preg_replace('/pid=\d+/', 'pid=<dynamic>', $b['stdout'])
        );
        self::assertStringContainsString("name={$expected}", $a['stdout']);
        self::assertMatchesRegularExpression('/name=[a-z0-9_-]{1,12}/', $a['stdout']);
        self::assertStringNotContainsString($this->secret, $a['stdout'] . $a['stderr']);
    }

    public static function identityProvider(): array
    {
        return [
            ['alice', '42', 'alice-16'],
            ['Jane Doe', '1001', 'janedoe-rt'],
            ['R@t!', '1002', 'rt-ru'],
            ['VeryLongUsername', '7', 'verylongus-7'],
            ['A B', '1001', 'ab-rt'],
            ['A!B', '1002', 'ab-ru'],
            ['!!!', '42', 'user-16'],
            ['', '42', 'user-16'],
            ['maximum', '2147483647', 'maxim-zik0zj'],
        ];
    }

    /** @dataProvider invalidIdentityProvider */
    public function testInvalidIdentityFailsBeforeChannelAccess(string $id): void
    {
        unlink($this->root . '/channel/endpoint-id');
        $r = $this->runWrapper('alice', $id);
        self::assertSame(1, $r['code']);
        self::assertSame("The ascii-royale arena is temporarily unavailable.\r\n", $r['stderr']);
    }

    public static function invalidIdentityProvider(): array
    {
        return [[''], ['0'], ['-1'], ['abc'], ['2147483648']];
    }

    public function testMissingStaleMalformedAndWrongShaChannelsFailWithoutDisclosure(): void
    {
        $cases = [
            fn() => unlink($this->root . '/channel/endpoint-id'),
            function () { touch($this->root . '/channel/endpoint-id', time() - 30); },
            fn() => $this->writeChannel('not-an-endpoint'),
            fn() => $this->writeChannel($this->secret, str_repeat('f', 40)),
        ];
        foreach ($cases as $index => $prepare) {
            if ($index > 0 && !is_file($this->root . '/channel/endpoint-id')) {
                $this->writeChannel($this->secret);
            }
            $prepare();
            $r = $this->runWrapper('alice', '42');
            self::assertSame(1, $r['code']);
            self::assertStringNotContainsString($this->secret, $r['stdout'] . $r['stderr']);
            $this->writeChannel($this->secret);
        }
    }

    public function testExecReplacesWrapperAndSuppliesPrivateEnvironment(): void
    {
        $r = $this->runWrapper('alice', '42');
        self::assertSame(0, $r['code'], $r['stderr']);
        self::assertMatchesRegularExpression('/pid=\d+ ppid=\d+ name=alice-16 argc=4/', $r['stdout']);
        self::assertStringStartsWith('pid=' . $r['pid'] . ' ', $r['stdout'], 'exec must preserve the wrapper PID');
        self::assertStringContainsString('home=' . $this->root . '/home/ascii-royale-m3', $r['stdout']);
        self::assertStringContainsString('alsa=' . $this->root . '/runtime/alsa-null.conf', $r['stdout']);
        self::assertStringNotContainsString($this->secret, $r['stdout'] . $r['stderr']);
        $source = (string)file_get_contents($this->wrapper);
        self::assertStringContainsString('exec "$binary" join "$endpoint_id" --name "$call_sign"', $source);
        self::assertStringNotContainsString('eval ', $source);
    }

    private function writeChannel(string $endpoint, string $sha = self::SHA): void
    {
        $content = "version=1\npinned_sha={$sha}\nupdated_unix=" . time()
            . "\nhost_generation=test-1\nendpoint_id={$endpoint}\n";
        file_put_contents($this->root . '/channel/endpoint-id', $content);
        chmod($this->root . '/channel/endpoint-id', 0640);
    }

    private function runWrapper(string $name, string $id): array
    {
        $env = getenv();
        $env['ASCII_ROYALE_M3_RUNTIME_ROOT'] = $this->root . '/runtime';
        $env['ASCII_ROYALE_M3_CHANNEL'] = $this->root . '/channel/endpoint-id';
        $env['DOOR_HOME'] = $this->root . '/home';
        $proc = proc_open(
            ['/bin/bash', $this->wrapper, $name, $id],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env
        );
        self::assertIsResource($proc);
        $status = proc_get_status($proc);
        $pid = (int)$status['pid'];
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['code' => proc_close($proc), 'stdout' => $stdout, 'stderr' => $stderr, 'pid' => $pid];
    }
}
