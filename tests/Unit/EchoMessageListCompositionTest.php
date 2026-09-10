<?php

declare(strict_types=1);

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
 * Echomail message list (M2 authored dense browser). The list model is built
 * exactly as EchomailHandler::echomailListFrameRenderer() builds it and pushed
 * through the shipped surface theme — no database, no session, no reader, no
 * writes.
 */
final class EchoMessageListCompositionTest extends TestCase
{
    private function theme(): \BinktermPHP\Terminal\Navigation\NavigationTheme
    {
        $r = (new NavigationThemeLoader())->fromFile(
            dirname(__DIR__, 2) . '/config/terminal_theme_echomsgs.json.example'
        );
        self::assertTrue($r->isOk(), $r->errorSummary());

        return $r->theme();
    }

    /** @return list<DenseListColumn> */
    private function columns(): array
    {
        return [
            new DenseListColumn('from', 18),
            new DenseListColumn('subj', 0),
            new DenseListColumn('date', 16, DenseListColumn::ALIGN_RIGHT),
        ];
    }

    /**
     * @param array<int,array{from?:string,subj?:string,read?:bool,reply?:bool,marked?:bool}> $spec
     */
    private function list(array $spec, string $status = 'PHIL @ fidonet   ·   3 unread of 40   ·   Newest   ·   Page 1/3'): DenseList
    {
        $rows = [];
        foreach ($spec as $i => $s) {
            $read   = $s['read'] ?? true;
            $marked = $s['marked'] ?? false;
            $rows[] = new DenseListRow(
                [
                    'from' => $s['from'] ?? ('Author ' . ($i + 1)),
                    'subj' => $s['subj'] ?? ('Subject line number ' . ($i + 1)),
                    'date' => '09/10/2026 14:32',
                ],
                ['id' => $i + 1, 'from_name' => $s['from'] ?? ('Author ' . ($i + 1)), 'from_address' => '21:1/' . ($i + 1),
                 'subject' => $s['subj'] ?? ('Subject line number ' . ($i + 1)), 'message_id' => sprintf('%08x@fidonet', $i + 1)],
                $marked ? '*' : ' ',
                $marked ? "\033[32m\033[1m" : null,
                !empty($s['reply']) ? "\u{203A}" : null,
                $read ? null : "\033[1m",
            );
        }

        return new DenseList('PHIL @ fidonet', ['Messages', 'Echomail'], $status, $this->columns(), $rows, 1, 3);
    }

    /** 17 messages, some unread, some replies, one marked. */
    private function page(?string $from = null, ?string $subj = null): DenseList
    {
        $spec = [];
        for ($i = 0; $i < 17; $i++) {
            $spec[$i] = [
                'from'   => $from ?? ('Author ' . ($i + 1)),
                'subj'   => $subj ?? ('Subject line number ' . ($i + 1)),
                'read'   => $i % 3 !== 0,
                'reply'  => $i % 4 === 0,
                'marked' => $i === 2,
            ];
        }

        return $this->list($spec);
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

    private function render(DenseList $list, int $selected = 0, ?array $statusLines = null, string $charset = 'utf8'): array
    {
        $describe = static function (DenseListRow $r): string {
            $m = is_array($r->value) ? $r->value : [];

            return trim((string) ($m['subject'] ?? '')) . '  ' . "\u{00B7}" . '  '
                . trim((string) ($m['from_name'] ?? '')) . ' <' . trim((string) ($m['from_address'] ?? '')) . '>  '
                . "\u{00B7}" . '  ' . trim((string) ($m['message_id'] ?? ''));
        };
        $view = new ThemedDenseListView($list, $this->theme(), $statusLines ?? [$list->context ?? ''], $describe);
        $h = TerminalRenderHarness::at(80, 24)->charset($charset);
        $ok = $view->tryRender($h->context(), $selected, [['text' => 'U/D Move  L/R Page  Enter Read  C Compose  Space Select  Ctrl-K Help  Q Back']]);
        self::assertTrue($ok, $charset . ': ' . ($view->lastReport()['reason'] ?? ''));
        self::assertSame('themed', $view->lastReport()['mode'], $charset);

        return $this->grid($h->bytes(), $charset);
    }

    // ---- themed in both charsets, density, fit ---------------------------

    public function testThemedAndDenseInBothCharsets(): void
    {
        foreach (['utf8', 'cp437'] as $charset) {
            $grid = $this->render($this->page(), 0, null, $charset);
            self::assertCount(24, $grid);
            foreach ($grid as $line) {
                self::assertLessThanOrEqual(80, mb_strlen($line), $charset . ' line width');
            }
            $joined = implode("\n", $grid);
            self::assertStringContainsString('L33TEST', $joined);
            self::assertStringContainsString('ECHOMAIL', $joined);

            $bodyRows = 0;
            foreach ($grid as $line) {
                if (preg_match('/^\s*\d+\)\s/', $line)) {
                    $bodyRows++;
                }
            }
            self::assertGreaterThanOrEqual(14, $bodyRows, $charset . ' density');
        }
    }

    public function testLongAuthorAndSubjectClipSafelyInCp437(): void
    {
        $long = 'A tremendously long real-world subject that will certainly be truncated to fit the column';
        $grid = $this->render($this->page('Jean-Baptiste Poquelin de Molière', $long), 5, null, 'cp437');
        self::assertSame(24, count($grid));
        foreach ($grid as $line) {
            self::assertLessThanOrEqual(80, mb_strlen($line));
        }
        // CP437 must not carry the single-glyph ellipsis.
        self::assertStringNotContainsString("\u{2026}", implode("\n", $grid));
    }

    // ---- UNREAD status wording -----------------------------------------

    public function testStatusUsesUnreadNeverNewscanWording(): void
    {
        $joined = implode("\n", $this->render(
            $this->page(),
            0,
            ['PHIL @ fidonet   ·   3 unread of 40   ·   Newest   ·   Page 1/3']
        ));
        self::assertStringContainsString('3 unread of 40', $joined);
        self::assertStringNotContainsString('new echomail', $joined);
        self::assertStringNotContainsString('new across', $joined);
        self::assertDoesNotMatchRegularExpression('/\bnew\b/i', $joined);
    }

    public function testAllReadStatusIsHonest(): void
    {
        $spec = [];
        for ($i = 0; $i < 17; $i++) {
            $spec[$i] = ['read' => true];
        }
        $joined = implode("\n", $this->render($this->list($spec, 'PHIL @ fidonet   ·   all 40 read   ·   Newest   ·   Page 1/3')));
        self::assertStringContainsString('all 40 read', $joined);
    }

    // ---- row model: unread emphasis, thread mark, multi-select ---------

    public function testUnreadEmphasisReplyMarkAndMultiSelectMarkerAreVisible(): void
    {
        // row 1 (index 0): unread + reply; row 3 (index 2): marked
        foreach (['utf8', 'cp437'] as $charset) {
            $h = TerminalRenderHarness::at(80, 24)->charset($charset);
            $view = new ThemedDenseListView($this->page(), $this->theme(), ['ctx']);
            $view->tryRender($h->context(), 8, [['text' => 'Q Back']]);
            $raw = $charset === 'cp437' ? iconv('CP437', 'UTF-8', $h->bytes()) : $h->bytes();

            // unread rows carry a bold SGR run around their content
            self::assertMatchesRegularExpression('/\x1b\[1m/', $raw, $charset . ' unread bold present');
            // marked row carries the green marker
            self::assertMatchesRegularExpression('/\x1b\[32m\x1b\[1m\*/', $raw, $charset . ' multi-select marker');
        }

        // thread indicator visible in the plain grid
        $grid = implode("\n", $this->render($this->page(), 0));
        self::assertStringContainsString("\u{203A}", $grid);
    }

    // ---- presentation only — no read state --------------------------

    public function testRenderingNeverMutatesTheListModel(): void
    {
        $list = $this->page();
        $before = serialize($list);
        $this->render($list, 0);
        $this->render($list, 16);
        self::assertSame($before, serialize($list));
    }

    // ---- selection window follows -------------------------------------

    public function testSelectionWindowFollowsAcrossThePage(): void
    {
        $spec = [];
        for ($i = 0; $i < 40; $i++) {
            $spec[$i] = ['subj' => 'MSG_' . sprintf('%02d', $i + 1)];
        }
        $list = $this->list($spec);
        $top = implode("\n", $this->render($list, 0));
        $bottom = implode("\n", $this->render($list, 39));
        self::assertStringContainsString('MSG_01', $top);
        self::assertStringNotContainsString('MSG_40', $top);
        self::assertStringContainsString('MSG_40', $bottom);
    }

    // ---- fallback contract -----------------------------------------

    public function testNonThemedGeometryFallsBack(): void
    {
        $view = new ThemedDenseListView($this->page(), $this->theme(), ['ctx']);
        self::assertFalse($view->tryRender(TerminalRenderHarness::at(80, 25)->context(), 0, []));
        self::assertSame('fallback', $view->lastReport()['mode']);
    }

    public function testMonoTerminalFallsBack(): void
    {
        $view = new ThemedDenseListView($this->page(), $this->theme(), ['ctx']);
        self::assertFalse($view->tryRender(TerminalRenderHarness::at(80, 24)->mono()->context(), 0, []));
        self::assertSame('fallback', $view->lastReport()['mode']);
    }

    public function testInvalidSurfaceIsIgnoredByTheLoader(): void
    {
        $r = (new NavigationThemeLoader())->fromJson('{ "schema": 2, "id": "x", "geometries": {} }');
        self::assertTrue($r->isInvalid());
    }

    // ---- the flat fallback list still renders the trailing / emphasis fields

    public function testFlatDenseListRendersEmphasisAndTrailing(): void
    {
        $spec = [['read' => false, 'reply' => true], ['read' => true]];
        $h = TerminalRenderHarness::at(80, 24);
        $composed = DenseListView::compose($this->list($spec), $h->context());
        $r0 = $composed['rows'][0];
        self::assertStringContainsString("\033[1m", $r0, 'unread row carries bold');
        self::assertStringContainsString("\u{203A}", preg_replace('/\x1b\[[0-9;]*m/', '', $r0), 'reply mark');
    }
}
