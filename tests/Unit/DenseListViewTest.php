<?php

declare(strict_types=1);

use BinktermPHP\Terminal\Presentation\DenseList;
use BinktermPHP\Terminal\Presentation\DenseListColumn;
use BinktermPHP\Terminal\Presentation\DenseListRow;
use BinktermPHP\Terminal\Presentation\DenseListView;
use BinktermPHP\Tests\Support\TerminalRenderHarness;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/TerminalRenderHarness.php';

/**
 * Terminal Experience Unification M2, Part 2 — the shared dense-list
 * presentation primitive. Composition is pure and geometry-aware; these run it
 * through {@see TerminalRenderHarness} with no socket, session, or database.
 *
 * The dense-list half of the primitive spends exactly two chrome lines (a
 * location identity line with a right-aligned page indicator, and one optional
 * compact context line) so the column grid keeps its density.
 */
final class DenseListViewTest extends TestCase
{
    /** @return list<DenseListColumn> */
    private function columns(): array
    {
        return [
            new DenseListColumn('tag', 20),
            new DenseListColumn('net', 10),
            new DenseListColumn('desc', 0),
        ];
    }

    private function sampleList(int $rowCount = 17, int $page = 1, int $totalPages = 2): DenseList
    {
        $rows = [];
        for ($i = 1; $i <= $rowCount; $i++) {
            $rows[] = new DenseListRow(
                ['tag' => "AREA_{$i}", 'net' => 'agoranet', 'desc' => "Description number {$i}"],
                "area-{$i}",
            );
        }

        return new DenseList(
            'Echomail Areas',
            ['Messages'],
            'Areas you follow - 34',
            $this->columns(),
            $rows,
            $page,
            $totalPages,
        );
    }

    private static function plain(string $s): string
    {
        return preg_replace('/\033\[[0-9;]*m/', '', $s) ?? $s;
    }

    public function testTitleCarriesTheCrumbLocationAndRightAlignedPageIndicator(): void
    {
        $ctx = TerminalRenderHarness::at(80, 24)->charset('utf8')->context();
        $composed = DenseListView::compose($this->sampleList(), $ctx);

        $title = self::plain($composed['title']);
        self::assertStringContainsString('Messages', $title);
        self::assertStringContainsString('Echomail Areas', $title);
        self::assertStringContainsString("\u{203A}", $title, 'a chevron separates the crumb from the location');
        self::assertStringContainsString('Page 1/2', $title);
        self::assertStringEndsWith('Page 1/2', rtrim($title), 'the page indicator is pushed to the right');
        self::assertLessThanOrEqual(80, mb_strlen($title, 'UTF-8'));
    }

    public function testContextBecomesASingleDimHeaderLine(): void
    {
        $ctx = TerminalRenderHarness::at(80, 24)->charset('utf8')->context();
        $composed = DenseListView::compose($this->sampleList(), $ctx);

        self::assertCount(1, $composed['headerLines']);
        self::assertStringContainsString('Areas you follow - 34', self::plain($composed['headerLines'][0]));
        self::assertStringContainsString("\033[2m", $composed['headerLines'][0], 'dim SGR');
    }

    public function testNoContextMeansZeroHeaderLinesSoTheGridKeepsEveryRow(): void
    {
        $list = new DenseList('Echomail Areas', ['Messages'], null, $this->columns(), [
            new DenseListRow(['tag' => 'A', 'net' => 'n', 'desc' => 'd'], 'a'),
        ], 1, 1);

        $ctx = TerminalRenderHarness::at(80, 24)->charset('utf8')->context();
        $composed = DenseListView::compose($list, $ctx);

        self::assertSame([], $composed['headerLines']);
    }

    public function testRowsAreColumnAlignedAndNeverExceedTheWidthAt80x24(): void
    {
        $ctx = TerminalRenderHarness::at(80, 24)->charset('utf8')->context();
        $composed = DenseListView::compose($this->sampleList(), $ctx);

        self::assertCount(17, $composed['rows']);
        foreach ($composed['rows'] as $row) {
            self::assertLessThanOrEqual(79, TerminalRenderHarness::visibleWidth($row), "row within guard width: {$row}");
        }

        // Column grid: " 1) " then tag padded to 20, a gap, net padded to 10, a
        // gap, then the flexible description (never padded).
        $first = self::plain($composed['rows'][0]);
        self::assertMatchesRegularExpression('/^\s+1\) AREA_1\s{15}agoranet\s{3}Description number 1$/', $first);
    }

    public function testDensityTargetAtLeastFifteenRowsFitAt80x24(): void
    {
        $ctx = TerminalRenderHarness::at(80, 24)->charset('utf8')->context();
        // The list is handed one page; the primitive spends 2 chrome lines
        // (title + context) + the shell's 1 status line = 3, leaving 20 body
        // rows on a 24-row SyncTerm-safe screen. 17 rows must survive.
        $composed = DenseListView::compose($this->sampleList(17), $ctx);
        self::assertGreaterThanOrEqual(15, count($composed['rows']));
    }

    public function testGeometrySafeColumnClippingWhenTheTerminalIsNarrow(): void
    {
        $ctx = TerminalRenderHarness::at(44, 24)->charset('utf8')->context();
        $list = new DenseList('Echomail Areas', ['Messages'], 'ctx', $this->columns(), [
            new DenseListRow(
                ['tag' => 'VERYLONGAREANAME_THAT_OVERFLOWS', 'net' => 'agoranetlong', 'desc' => 'A description that is quite long indeed'],
                'x',
            ),
        ], 1, 1);

        $composed = DenseListView::compose($list, $ctx);

        self::assertLessThanOrEqual(43, TerminalRenderHarness::visibleWidth($composed['rows'][0]));
        self::assertLessThanOrEqual(43, mb_strlen(self::plain($composed['title']), 'UTF-8'));
        self::assertLessThanOrEqual(43, mb_strlen(self::plain($composed['headerLines'][0]), 'UTF-8'));
        // The over-wide tag is clipped with an ellipsis, not left to wrap.
        self::assertStringContainsString("\u{2026}", self::plain($composed['rows'][0]));
    }

    public function testExpandedGeometryDrawsWiderColumnsForTheFlexibleField(): void
    {
        $wide = new DenseListRow(
            ['tag' => 'AREA', 'net' => 'agoranet', 'desc' => str_repeat('x', 200)],
            'a',
        );
        $list = new DenseList('Echomail Areas', ['Messages'], null, $this->columns(), [$wide], 1, 1);

        $at80  = self::plain(DenseListView::compose($list, TerminalRenderHarness::at(80, 24)->context())['rows'][0]);
        $at132 = self::plain(DenseListView::compose($list, TerminalRenderHarness::at(132, 51)->context())['rows'][0]);

        self::assertGreaterThan(mb_strlen($at80, 'UTF-8'), mb_strlen($at132, 'UTF-8'), 'the flexible column grows with the terminal');
        self::assertLessThanOrEqual(131, TerminalRenderHarness::visibleWidth($at132));
    }

    public function testPrefixBadgeIsRenderedBeforeTheColumnsAndColourised(): void
    {
        $list = new DenseList('Echomail Areas', ['Messages'], null, [
            new DenseListColumn('tag', 16),
            new DenseListColumn('net', 10),
            new DenseListColumn('desc', 0),
        ], [
            new DenseListRow(['tag' => 'SUBBED', 'net' => 'n', 'desc' => 'd'], 'a', '[+]', "\033[32m"),
            new DenseListRow(['tag' => 'NOTSUB', 'net' => 'n', 'desc' => 'd'], 'b', '[ ]', "\033[2m"),
        ], 1, 1);

        $composed = DenseListView::compose($list, TerminalRenderHarness::at(80, 24)->context());

        self::assertStringContainsString("\033[32m[+]\033[0m", $composed['rows'][0]);
        self::assertMatchesRegularExpression('/^\s+1\) \[\+\] SUBBED/', self::plain($composed['rows'][0]));
        self::assertMatchesRegularExpression('/^\s+2\) \[ \] NOTSUB/', self::plain($composed['rows'][1]));
    }

    public function testValuesAreParallelToTheRows(): void
    {
        $composed = DenseListView::compose($this->sampleList(3), TerminalRenderHarness::at(80, 24)->context());
        self::assertSame(['area-1', 'area-2', 'area-3'], $composed['values']);
    }

    public function testMonochromeAsciiTerminalGetsNoSgrAndAnAsciiSeparator(): void
    {
        $ctx = TerminalRenderHarness::at(80, 24)->charset('ascii')->mono()->context();
        $composed = DenseListView::compose($this->sampleList(2), $ctx);

        $blob = $composed['title'] . "\n" . implode("\n", $composed['headerLines']) . "\n" . implode("\n", $composed['rows']);
        self::assertSame(0, preg_match('/\033\[/', $blob), 'no escape sequences on a mono terminal');
        self::assertStringContainsString('Messages > Echomail Areas', $composed['title']);
        self::assertStringNotContainsString("\u{203A}", $composed['title']);
    }

    public function testCp437TerminalEncodesTheChevron(): void
    {
        $ctx = TerminalRenderHarness::at(80, 24)->charset('cp437')->context();
        $composed = DenseListView::compose($this->sampleList(2), $ctx);

        self::assertStringNotContainsString("\u{203A}", $composed['title']);
    }

    public function testTitleDropsThePageIndicatorRatherThanOverflowWhenCramped(): void
    {
        $ctx = TerminalRenderHarness::at(24, 24)->charset('utf8')->context();
        $list = new DenseList(
            'A Rather Long Location Name Here',
            ['Messages', 'Deeply', 'Nested'],
            null,
            $this->columns(),
            [new DenseListRow(['tag' => 'A', 'net' => 'n', 'desc' => 'd'], 'a')],
            1,
            9,
        );

        $composed = DenseListView::compose($list, $ctx);
        self::assertLessThanOrEqual(23, mb_strlen(self::plain($composed['title']), 'UTF-8'));
    }
}
