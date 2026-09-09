<?php

declare(strict_types=1);

use BinktermPHP\Terminal\Presentation\Directory;
use BinktermPHP\Terminal\Presentation\DirectoryRow;
use BinktermPHP\Terminal\Presentation\DirectorySection;
use BinktermPHP\Terminal\Presentation\DirectoryView;
use BinktermPHP\Tests\Support\TerminalRenderHarness;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/TerminalRenderHarness.php';

/**
 * Terminal Experience Unification, Part 1 — the shared directory presentation
 * primitive. Composition is pure and geometry-aware; these run it through the
 * {@see TerminalRenderHarness} with no socket, session, or database.
 */
final class DirectoryViewTest extends TestCase
{
    private function sampleDirectory(): Directory
    {
        return new Directory(
            'Crossroads',
            'Where people, games, and worlds meet.',
            [
                new DirectorySection('', [
                    new DirectoryRow('Live Now', '2 callers in 1 Experience', null, 'live_now'),
                    new DirectoryRow('Your Places', 'You have no active places right now.', null, 'your_places'),
                ]),
                new DirectorySection('Curated Experiences', [
                    new DirectoryRow('ascii-royale', 'Last player standing.', 'Multiplayer', 'ascii-royale'),
                ]),
                new DirectorySection('Game Hall', [
                    new DirectoryRow('Legend of the Red Dragon', 'Fantasy RPG.', 'Multiplayer', 'lord'),
                    new DirectoryRow('NetHack', 'Dungeon crawl.', null, 'nethack'),
                ]),
                new DirectorySection('Gateways', [
                    new DirectoryRow('DoorParty', 'A remote door gateway.', 'Gateway', 'doorparty'),
                ]),
            ],
            ['Recently in the Crossroads', 'Bard played LORD - 47m ago'],
        );
    }

    private static function plain(string $s): string
    {
        return preg_replace('/\033\[[0-9;]*m/', '', $s) ?? $s;
    }

    public function testTitleIsAMastheadBandNamingTheLocation(): void
    {
        $ctx = TerminalRenderHarness::at(80, 24)->charset('utf8')->context();
        $composed = DirectoryView::compose($this->sampleDirectory(), $ctx);

        self::assertStringContainsString('Crossroads', $composed['title']);
        self::assertStringContainsString("\u{2500}\u{2500}", $composed['title'], 'a horizontal rule frames the name');
        self::assertLessThanOrEqual(80, mb_strlen(self::plain($composed['title']), 'UTF-8'));
    }

    public function testTaglineRidesRowZeroAndSectionsBecomeUppercaseHeadings(): void
    {
        $ctx = TerminalRenderHarness::at(80, 24)->charset('utf8')->context();
        $composed = DirectoryView::compose($this->sampleDirectory(), $ctx);
        $items = $composed['items'];

        // Row 0 (Live Now) carries the tagline; it is NOT a section heading.
        $row0Preamble = implode("\n", array_map([self::class, 'plain'], $items[0]['section_before_lines'] ?? []));
        self::assertStringContainsString('Where people, games, and worlds meet.', $row0Preamble);
        self::assertArrayNotHasKey('section_before', $items[0]);
        self::assertArrayNotHasKey('section_before', $items[1]);

        // First titled section heading is uppercased; it also carries the
        // ambient context block above it.
        self::assertSame('CURATED EXPERIENCES', self::plain($items[2]['section_before']));
        $ctxLines = implode("\n", array_map([self::class, 'plain'], $items[2]['section_before_lines'] ?? []));
        self::assertStringContainsString('Recently in the Crossroads', $ctxLines);
        self::assertStringContainsString('Bard played LORD - 47m ago', $ctxLines);

        // Later sections: heading only, no repeated context block.
        self::assertSame('GAME HALL', self::plain($items[3]['section_before']));
        self::assertArrayNotHasKey('section_before_lines', $items[3]);
        self::assertSame('GATEWAYS', self::plain($items[5]['section_before']));
    }

    public function testRowsCarryDescriptionAndBadgeAndParallelValues(): void
    {
        $ctx = TerminalRenderHarness::at(80, 24)->charset('utf8')->context();
        $composed = DirectoryView::compose($this->sampleDirectory(), $ctx);

        self::assertSame(
            ['live_now', 'your_places', 'ascii-royale', 'lord', 'nethack', 'doorparty'],
            $composed['values']
        );

        // Badge is appended to the label with a middot; description is the
        // detail column (never folded into the label).
        self::assertStringContainsString("ascii-royale  \u{00B7} Multiplayer", $composed['items'][2]['label']);
        self::assertSame('Last player standing.', $composed['items'][2]['detail']);
        self::assertSame('NetHack', $composed['items'][4]['label'], 'no badge, no separator');
        self::assertSame('Dungeon crawl.', $composed['items'][4]['detail']);
    }

    public function testMonochromeTerminalGetsPlainHeadingsWithNoSgr(): void
    {
        $ctx = TerminalRenderHarness::at(80, 24)->charset('ascii')->mono()->context();
        $composed = DirectoryView::compose($this->sampleDirectory(), $ctx);

        $blob = $composed['title'] . "\n" . json_encode($composed['items']);
        self::assertSame(0, preg_match('/\033\[/', $blob), 'no escape sequences on a mono terminal');
        self::assertStringContainsString('-- Crossroads --', $composed['title']);
        self::assertStringContainsString('CURATED EXPERIENCES', $composed['items'][2]['section_before']);
        self::assertStringContainsString('ascii-royale  - Multiplayer', $composed['items'][2]['label']);
    }

    public function testCp437TerminalEncodesTheRuleGlyph(): void
    {
        $ctx = TerminalRenderHarness::at(80, 24)->charset('cp437')->context();
        $composed = DirectoryView::compose($this->sampleDirectory(), $ctx);

        self::assertStringContainsString("\xC4\xC4", $composed['title'], 'CP437 horizontal bar');
        self::assertStringNotContainsString("\u{2500}", $composed['title']);
    }

    public function testNarrowTerminalKeepsTheBandAndTaglineWithinWidth(): void
    {
        $ctx = TerminalRenderHarness::at(40, 24)->charset('utf8')->context();
        $composed = DirectoryView::compose($this->sampleDirectory(), $ctx);

        self::assertLessThanOrEqual(40, mb_strlen(self::plain($composed['title']), 'UTF-8'));
        foreach ($composed['items'][0]['section_before_lines'] as $line) {
            self::assertLessThanOrEqual(40, mb_strlen(self::plain($line), 'UTF-8'));
        }
    }

    public function testContextBlockFallsBackToRowZeroWhenNoSectionIsTitled(): void
    {
        $directory = new Directory(
            'Crossroads',
            null,
            [new DirectorySection('', [new DirectoryRow('Live Now', null, null, 'live_now')])],
            ['Recently in the Crossroads', 'Bard played LORD - 47m ago'],
        );

        $ctx = TerminalRenderHarness::at(80, 24)->charset('utf8')->context();
        $composed = DirectoryView::compose($directory, $ctx);

        $row0 = implode("\n", array_map([self::class, 'plain'], $composed['items'][0]['section_before_lines'] ?? []));
        self::assertStringContainsString('Bard played LORD - 47m ago', $row0);
    }

    public function testEmptyDirectoryYieldsNoItems(): void
    {
        $directory = new Directory('Crossroads', 'tagline', [], []);
        $ctx = TerminalRenderHarness::at(80, 24)->context();
        $composed = DirectoryView::compose($directory, $ctx);

        self::assertSame([], $composed['items']);
        self::assertSame([], $composed['values']);
    }
}
