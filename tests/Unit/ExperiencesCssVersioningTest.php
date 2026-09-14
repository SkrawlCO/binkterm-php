<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Slice 5 ("caller-path corrections", Correction A): /css/experiences.css
 * was loaded with no cache-busting token by any of its three consumers,
 * which could let a browser Back navigation restore Puzlmastr's Patch (or
 * any other curated-place/webdoor page) using a stale cached copy. Proves
 * every actual consumer now uses the same versioned URL, and that no bare
 * (unversioned) inclusion remains anywhere in templates/.
 */
final class ExperiencesCssVersioningTest extends TestCase
{
    private function templatesDir(): string
    {
        return dirname(__DIR__, 2) . '/templates';
    }

    public function testAllThreeKnownConsumersUseTheSameVersionedUrl(): void
    {
        $consumers = ['crossroads.twig', 'curated_place.twig', 'webdoors.twig'];
        foreach ($consumers as $file) {
            $path = $this->templatesDir() . '/' . $file;
            self::assertFileExists($path);
            $contents = (string)file_get_contents($path);
            self::assertMatchesRegularExpression(
                '#<link href="/css/experiences\.css\?v=1" rel="stylesheet">#',
                $contents,
                $file . ' must load the versioned experiences.css URL'
            );
        }
    }

    public function testNoBareUnversionedInclusionRemainsAnywhereInTemplates(): void
    {
        $bareOccurrences = 0;
        $offendingFiles = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->templatesDir(), FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!str_ends_with((string)$file, '.twig')) {
                continue;
            }
            $contents = (string)file_get_contents((string)$file);
            // A bare inclusion is href="/css/experiences.css" NOT followed by "?" --
            // i.e. the exact literal tag with no query string at all.
            if (preg_match('#href="/css/experiences\.css"#', $contents) === 1) {
                $bareOccurrences++;
                $offendingFiles[] = (string)$file;
            }
        }
        self::assertSame(0, $bareOccurrences, 'no bare/unversioned experiences.css inclusion should remain: ' . implode(', ', $offendingFiles));
    }
}
