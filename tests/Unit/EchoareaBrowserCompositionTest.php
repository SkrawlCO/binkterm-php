<?php

declare(strict_types=1);

use BinktermPHP\Newscan\NewscanArea;
use BinktermPHP\Newscan\NewscanPlan;
use BinktermPHP\Terminal\Navigation\AnsiScreenBuffer;
use BinktermPHP\Terminal\Navigation\NavigationThemeLoader;
use BinktermPHP\Terminal\Presentation\DenseList;
use BinktermPHP\Terminal\Presentation\DenseListColumn;
use BinktermPHP\Terminal\Presentation\DenseListRow;
use BinktermPHP\Terminal\Presentation\DenseListView;
use BinktermPHP\Terminal\Presentation\ThemedDenseListView;
use BinktermPHP\Tests\Support\TerminalRenderHarness;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/TerminalRenderHarness.php';

/**
 * Echomail area browser (M2). The canonical newscan plan is fabricated and fed
 * through the real projection ({@see NewscanPlan::areaNewCounts()}), the real
 * dense-list primitive and the shipped surface theme — no database, no session,
 * no writes.
 */
final class EchoareaBrowserCompositionTest extends TestCase
{
    private function theme(): \BinktermPHP\Terminal\Navigation\NavigationTheme
    {
        $r = (new NavigationThemeLoader())->fromFile(
            dirname(__DIR__, 2) . '/config/terminal_theme_echoareas.json.example'
        );
        self::assertTrue($r->isOk(), $r->errorSummary());

        return $r->theme();
    }

    /** @return list<DenseListColumn> */
    private function columns(): array
    {
        return [
            new DenseListColumn('tag', 20),
            new DenseListColumn('net', 10),
            new DenseListColumn('desc', 0),
        ];
    }

    /** 31 followed areas; `$newByIndex` maps a 0-based row to its canonical NEW count. */
    private function areaList(int $count = 31, array $newByIndex = [], string $label = 'new', ?string $desc = null): DenseList
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $new = (int) ($newByIndex[$i] ?? 0);
            $rows[] = new DenseListRow(
                [
                    'tag'  => sprintf('AREA.%02d', $i + 1),
                    'net'  => $i % 2 ? 'fidonet' : 'agoranet',
                    'desc' => $desc ?? ('Discussion area number ' . ($i + 1)),
                ],
                ['id' => $i + 1, 'tag' => sprintf('AREA.%02d', $i + 1)],
                null,
                null,
                $new > 0 ? "{$new} {$label}" : null,
            );
        }

        return new DenseList('Echomail Areas', ['Messages'], 'Areas you follow - ' . $count, $this->columns(), $rows, 1, 2);
    }

    /** The same NEW figures a UnifiedNewscanService::plan() would yield for those areas. */
    private function plan(array $areaIdToCount): NewscanPlan
    {
        $areas = [];
        foreach ($areaIdToCount as $id => $n) {
            $areas[] = new NewscanArea((int) $id, "AREA.$id", 'fidonet', 'Discussion', range(1, $n));
        }

        return new NewscanPlan([], $areas, 0, false);
    }

    private function grid(string $bytes, string $charset = 'utf8'): array
    {
        if ($charset === 'cp437') {
            $bytes = iconv('CP437', 'UTF-8', $bytes);
        }

        return array_map(
            fn ($l) => preg_replace('/\x1b\[[0-9;]*m/', '', $l),
            (new AnsiScreenBuffer(80, 24))->write($bytes)->toLines()
        );
    }

    private function render(DenseList $list, int $selected = 0, array $statusLines = [], string $charset = 'utf8'): array
    {
        $view = new ThemedDenseListView($list, $this->theme(), $statusLines);
        $h = TerminalRenderHarness::at(80, 24)->charset($charset);
        $ok = $view->tryRender($h->context(), $selected, [['text' => 'U/D Move  L/R Page  Enter Select  Q Back  Ctrl-K Help']]);
        self::assertTrue($ok, $view->lastReport()['reason'] ?? 'not themed');
        self::assertSame('themed', $view->lastReport()['mode']);

        return $this->grid($h->bytes(), $charset);
    }

    // ---- 1. per-area NEW comes from the canonical plan projection ----------

    public function testAreaNewCountsProjectFromNewscanPlan(): void
    {
        $plan = $this->plan([3 => 42, 7 => 31, 12 => 18]); // 91 across 3 areas
        self::assertSame(91, $plan->echomailCount());
        self::assertSame(3, $plan->areaCount());
        self::assertSame([3 => 42, 7 => 31, 12 => 18], $plan->areaNewCounts());

        $counts = $plan->areaNewCounts();
        $list = $this->areaList(31, [2 => $counts[3], 6 => $counts[7], 11 => $counts[12]]);
        $joined = implode("\n", $this->render($list, 2));

        self::assertMatchesRegularExpression('/AREA\.03.*42 new/', $joined);
        self::assertMatchesRegularExpression('/AREA\.07.*31 new/', $joined);
        self::assertMatchesRegularExpression('/AREA\.12.*18 new/', $joined);
    }

    public function testZeroCountsSuppressCleanly(): void
    {
        $list = $this->areaList(31, [4 => 9]); // exactly one area has new
        $joined = implode("\n", $this->render($list, 4));
        self::assertSame(1, substr_count($joined, ' new'), 'only the one non-zero count is shown');
        self::assertStringContainsString('AREA.05', $joined);
        self::assertStringNotContainsString('0 new', $joined);
    }

    public function testAggregateStatusIsHonest(): void
    {
        $list = $this->areaList(31, [1 => 42, 2 => 31, 3 => 18]);
        $status = 'Areas you follow - 31   ' . "\u{00B7}" . '   91 new across 3 area(s)   ' . "\u{00B7}" . '   Page 1/2';
        $joined = implode("\n", $this->render($list, 0, [$status]));
        self::assertStringContainsString('91 new across 3 area(s)', $joined);
        self::assertStringContainsString('Page 1/2', $joined);
    }

    // ---- 2. presentation only — no read-state, no watermark --------------

    public function testRenderingNeverMutatesTheListOrTouchesReadState(): void
    {
        $list = $this->areaList(31, [1 => 5, 9 => 12]);
        $before = serialize($list);
        // Two renders at different cursor positions — a real Newscan recompute
        // would need a service; this view is handed a resolved DenseList only.
        $this->render($list, 0);
        $this->render($list, 20);
        self::assertSame($before, serialize($list), 'the resolved list model is immutable to the view');
    }

    // ---- 3. density + geometry -----------------------------------------

    public function testDensityAndFitAt80x24(): void
    {
        foreach (['utf8', 'cp437'] as $charset) {
            $grid = $this->render($this->areaList(31), 0, ['Areas you follow - 31'], $charset);
            self::assertCount(24, $grid);
            foreach ($grid as $line) {
                self::assertLessThanOrEqual(80, mb_strlen($line), $charset);
            }
            // The MENU region is 17 rows: at least 14 visible area rows.
            $areaRows = 0;
            foreach ($grid as $line) {
                if (preg_match('/\bAREA\.\d\d\b/', $line)) {
                    $areaRows++;
                }
            }
            self::assertGreaterThanOrEqual(14, $areaRows, "$charset density");
            self::assertStringContainsString('L33TEST', implode("\n", $grid));
            self::assertStringContainsString('ECHOMAIL AREAS', implode("\n", $grid));
        }
    }

    /**
     * Regression: on CP437 a description long enough to be ellipsized used to
     * push every MENU row from 72 to 74 cells — TextBlock::ellipsize appends
     * U+2026, which encodeForTerminal() transliterates to "..." (3 cells) — so
     * fitSemanticBlock(mustFit) threw and the whole authored frame dropped to
     * the plain dense list in real SyncTerm sessions.
     */
    public function testLongDescriptionStaysThemedInCp437(): void
    {
        $long = 'A fairly long real-world description of the area that will certainly be truncated to fit';

        foreach (['utf8', 'cp437'] as $charset) {
            $list = $this->areaList(31, [2 => 42, 6 => 31, 11 => 18], 'new', $long);
            $view = new ThemedDenseListView($list, $this->theme(), ['Areas you follow - 31']);
            $h = TerminalRenderHarness::at(80, 24)->charset($charset);
            $ok = $view->tryRender($h->context(), 3, [['text' => 'U/D Move  L/R Page  Enter Select  Q Quit  Ctrl-K Help']]);

            self::assertTrue($ok, $charset . ': ' . ($view->lastReport()['reason'] ?? ''));
            self::assertSame('themed', $view->lastReport()['mode'], $charset);

            // Every rendered line — including the MENU grid rows — fits 80.
            foreach ($this->grid($h->bytes(), $charset) as $line) {
                self::assertLessThanOrEqual(80, mb_strlen($line), $charset . ' line width');
            }
        }
    }

    public function testEllipsisTerminatorIsCharsetSafe(): void
    {
        $long = str_repeat('long ', 30);

        $utf8 = implode("\n", $this->render($this->areaList(20, [], 'new', $long), 0, ['ctx'], 'utf8'));
        $cp437 = implode("\n", $this->render($this->areaList(20, [], 'new', $long), 0, ['ctx'], 'cp437'));

        // UTF-8 keeps the single-glyph ellipsis; CP437 spells it out as "..."
        // and is charged its real width (no U+2026 anywhere).
        self::assertStringContainsString("\u{2026}", $utf8);
        self::assertStringNotContainsString("\u{2026}", $cp437);
        self::assertStringContainsString('...', $cp437);
    }

    public function testSelectionFollowsAcrossThePageWindow(): void
    {
        $list = $this->areaList(31);
        $top = implode("\n", $this->render($list, 0));
        $bottom = implode("\n", $this->render($list, 30));
        self::assertStringContainsString('AREA.01', $top);
        self::assertStringContainsString('AREA.31', $bottom);
        // The window moved: the last area is not visible from the top.
        self::assertStringNotContainsString('AREA.31', $top);
        self::assertStringNotContainsString('AREA.01', $bottom);
    }

    public function testWindowStartIsStableAndBounded(): void
    {
        // 31 rows, 17-row window.
        self::assertSame(0, DenseListView::windowStart(31, 17, 0));
        self::assertSame(0, DenseListView::windowStart(31, 17, 5));
        self::assertSame(14, DenseListView::windowStart(31, 17, 30));
        self::assertSame(0, DenseListView::windowStart(10, 17, 9), 'short list never scrolls');
    }

    // ---- 4. fallback is first-class -----------------------------------

    public function testNonThemedGeometryYieldsToTheDenseRenderer(): void
    {
        $view = new ThemedDenseListView($this->areaList(31), $this->theme(), []);
        $h = TerminalRenderHarness::at(100, 30);
        self::assertFalse($view->tryRender($h->context(), 0, []));
        self::assertSame('fallback', $view->lastReport()['mode']);
    }

    public function testMonoTerminalYieldsToTheDenseRenderer(): void
    {
        $view = new ThemedDenseListView($this->areaList(31), $this->theme(), []);
        $h = TerminalRenderHarness::at(80, 24)->mono();
        self::assertFalse($view->tryRender($h->context(), 0, []));
        self::assertSame('fallback', $view->lastReport()['mode']);
    }

    // ---- 5. the flat fallback list keeps the NEW column consistently -----

    public function testFlatDenseListAlsoShowsTheTrailingNewCount(): void
    {
        $list = $this->areaList(17, [0 => 42, 3 => 7]);
        $h = TerminalRenderHarness::at(80, 24);
        $composed = DenseListView::compose($list, $h->context());
        $plain = array_map(fn ($r) => preg_replace('/\x1b\[[0-9;]*m/', '', $r), $composed['rows']);
        self::assertMatchesRegularExpression('/AREA\.01.*42 new\s*$/', $plain[0]);
        self::assertMatchesRegularExpression('/AREA\.04.*7 new\s*$/', $plain[3]);
        self::assertDoesNotMatchRegularExpression('/ new/', $plain[1], 'zero areas carry no trailing');
    }
}
