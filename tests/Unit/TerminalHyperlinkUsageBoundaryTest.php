<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Architectural guard-rail, not a behavior test: {@see
 * \BinktermPHP\Terminal\Presentation\TerminalHyperlink} must only ever be
 * referenced from the one approved, admin-approved-URL surface (BBS
 * Directory). It must never be wired into echomail, netmail, or any other
 * message-rendering path, where the "text" being displayed is
 * user/network-generated rather than application-validated. This scans real
 * source files so a future change that adds an unapproved reference fails
 * the suite instead of silently widening the trust boundary.
 */
final class TerminalHyperlinkUsageBoundaryTest extends TestCase
{
    /** Files allowed to reference TerminalHyperlink outside its own definition/tests. */
    private const ALLOWED_REFERERS = [
        // The one production integration: BBS Directory's admin-approved website field.
        'telnet/src/BbsListHandler.php',
        // Doc-only reference: explains why its ANSI-width helpers treat an
        // OSC 8 wrapper as a zero-width atomic token. Never constructs a link.
        'telnet/src/TerminalBoxRenderer.php',
    ];

    private function appRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return array<int,string> repo-relative PHP source paths, tests/vendor excluded */
    private function sourceFiles(): array
    {
        $root  = $this->appRoot();
        $dirs  = ['src', 'telnet/src', 'ssh/src', 'routes'];
        $files = [];
        foreach ($dirs as $dir) {
            $full = $root . '/' . $dir;
            if (!is_dir($full)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($full, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $fileInfo) {
                if ($fileInfo->getExtension() === 'php') {
                    $files[] = ltrim(str_replace($root, '', $fileInfo->getPathname()), '/');
                }
            }
        }
        return $files;
    }

    public function testOnlyTheApprovedBbsDirectorySurfaceReferencesTheHelper(): void
    {
        $offenders = [];
        foreach ($this->sourceFiles() as $relativePath) {
            if ($relativePath === 'src/Terminal/Presentation/TerminalHyperlink.php') {
                continue; // its own definition
            }
            $contents = file_get_contents($this->appRoot() . '/' . $relativePath);
            if ($contents === false || !str_contains($contents, 'TerminalHyperlink')) {
                continue;
            }
            if (!in_array($relativePath, self::ALLOWED_REFERERS, true)) {
                $offenders[] = $relativePath;
            }
        }

        self::assertSame(
            [],
            $offenders,
            'TerminalHyperlink referenced outside the approved surface(s): ' . implode(', ', $offenders)
        );
    }

    /** @dataProvider messageRenderingFileProvider */
    public function testKnownMessageRenderingFilesDoNotReferenceTheHelper(string $relativePath): void
    {
        $full = $this->appRoot() . '/' . $relativePath;
        if (!is_file($full)) {
            self::markTestSkipped("{$relativePath} not present");
        }
        $contents = file_get_contents($full);
        self::assertIsString($contents);
        self::assertStringNotContainsString('TerminalHyperlink', $contents);
    }

    public static function messageRenderingFileProvider(): array
    {
        return [
            ['telnet/src/EchomailHandler.php'],
            ['telnet/src/NetmailHandler.php'],
            ['telnet/src/BulletinsHandler.php'],
            ['src/Terminal/Navigation/TemplateArtSanitizer.php'],
            ['src/Terminal/Navigation/NavigationScreenRenderer.php'],
        ];
    }
}
