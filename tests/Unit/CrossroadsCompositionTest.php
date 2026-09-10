<?php

declare(strict_types=1);

use BinktermPHP\TelnetServer\DoorHandler;
use BinktermPHP\Terminal\Navigation\AnsiScreenBuffer;
use BinktermPHP\Terminal\Navigation\NavigationThemeConfig;
use BinktermPHP\Terminal\Navigation\NavigationThemeLoader;
use BinktermPHP\Terminal\Presentation\Directory;
use BinktermPHP\Terminal\Presentation\DirectoryRow;
use BinktermPHP\Terminal\Presentation\DirectorySection;
use BinktermPHP\Terminal\Presentation\ThemedDirectoryView;
use BinktermPHP\Tests\Support\TerminalRenderHarness;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/TerminalRenderHarness.php';
require_once __DIR__ . '/../../telnet/src/TelnetUtils.php';
require_once __DIR__ . '/../../telnet/src/DoorHandler.php';

final class CrossroadsCompositionTest extends TestCase
{
    private function config(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__, 2) . '/config/terminal_theme_crossroads.json.example'), true);
    }

    private function directory(): Directory
    {
        $hall = [];
        for ($i = 0; $i < 12; $i++) {
            $hall[] = new DirectoryRow('Hall destination ' . ($i + 1), 'Explore this existing game.', null, 'hall-' . $i);
        }
        return new Directory('Crossroads', 'Where people, games, and worlds meet.', [
            new DirectorySection('', [
                new DirectoryRow('Live Now', 'The Crossroads are quiet right now.', null, 'live_now'),
                new DirectoryRow('Your Places', 'You have no active places right now.', null, 'your_places'),
            ], true),
            new DirectorySection('Curated Experiences', [
                new DirectoryRow('MultiZork', 'Explore a persistent shared world with other callers.', 'Multiplayer', 'multizork'),
                new DirectoryRow('ascii-royale', 'Last player standing.', 'Multiplayer', 'ascii-royale'),
            ]),
            new DirectorySection('Game Hall', $hall),
        ], ['Recently in the Crossroads', 'Renée played MultiZork - 2h ago']);
    }

    private function view(?Directory $directory = null, ?array $config = null, array $status = []): ThemedDirectoryView
    {
        $result = (new NavigationThemeLoader())->fromArray($config ?? $this->config());
        self::assertTrue($result->isOk(), $result->errorSummary());
        return new ThemedDirectoryView($directory ?? $this->directory(), $result->theme(), $status);
    }

    private function hints(): array
    {
        return [['text' => 'U/D Move  Enter Select  Q Back']];
    }

    private function grid(string $bytes, string $charset = 'utf8'): array
    {
        if ($charset === 'cp437') { $bytes = iconv('CP437', 'UTF-8', $bytes); }
        return array_map(fn ($line) => preg_replace('/\x1b\[[0-9;]*m/', '', $line),
            (new AnsiScreenBuffer(80, 24))->write($bytes)->toLines());
    }

    public function testAuthoredHierarchyAndSelectedContextInBothCharsets(): void
    {
        foreach (['utf8', 'cp437'] as $charset) {
            $status = ['The Crossroads are quiet right now.', 'Renée played MultiZork - 2h ago', ''];
            $directory = $this->directory();
            $before = serialize($directory);
            $view = $this->view($directory, status: $status);
            foreach ([2 => 'MultiZork', 3 => 'ascii-royale'] as $cursor => $name) {
                $h = TerminalRenderHarness::at(80, 24)->charset($charset);
                self::assertTrue($view->tryRender($h->context(), $cursor, $this->hints()), json_encode($view->lastReport()));
                $grid = $this->grid($h->bytes(), $charset);
                self::assertStringContainsString('CROSSROADS', $grid[1]);
                self::assertSame($status[0], trim(mb_substr($grid[3], 3, 72)));
                self::assertSame($status[1], trim(mb_substr($grid[4], 3, 72)));
                self::assertSame('', trim(mb_substr($grid[5], 3, 72)));
                self::assertSame($name, trim(mb_substr($grid[8], 45, 30)));
                self::assertSame('Multiplayer', trim(mb_substr($grid[9], 45, 30)));
                self::assertStringContainsString('CURATED EXPERIENCES', implode("\n", $grid));
                self::assertStringContainsString('Q Back', $grid[22]);
                foreach (array_slice($grid, 8, 12) as $line) {
                    self::assertSame('|', mb_substr($line, 42, 1), 'dynamic panes never overwrite their divider');
                }
            }
            self::assertSame($before, serialize($directory));
        }
    }

    public function testEveryDestinationRemainsReachableInTheOriginalNumbering(): void
    {
        $directory = $this->directory();
        $view = $this->view($directory);
        foreach ($directory->rows() as $i => $row) {
            $h = TerminalRenderHarness::at(80, 24);
            self::assertTrue($view->tryRender($h->context(), $i, $this->hints()));
            $grid = $this->grid($h->bytes());
            $menu = implode("\n", array_map(fn ($line) => mb_substr($line, 3, 37), array_slice($grid, 8, 12)));
            self::assertStringContainsString(sprintf('%2d) %s', $i + 1, $row->label), $menu);
            self::assertSame($row->label, trim(mb_substr($grid[8], 45, 30)));
            self::assertMatchesRegularExpression('/\[\d+-\d+\/16\]/', $grid[22]);
        }
    }

    public function testStatusUsesExistingQuietLiveAndHistoricalSemantics(): void
    {
        $t = static function ($key, $params = [], $fallback = '') {
            foreach ($params as $name => $value) { $fallback = str_replace('{' . $name . '}', (string)$value, $fallback); }
            return $fallback;
        };
        $quiet = DoorHandler::composeLiveNow([], 42, $t);
        $noPlaces = DoorHandler::composeYourPlaces([], 42, $t);
        $history = ['lines' => ['Recently in the Crossroads', 'Renée played MultiZork - 2h ago'], 'count' => 1];
        $status = DoorHandler::composeArrivalThemeStatus($quiet, $noPlaces, $history, $t);
        self::assertSame(['The Crossroads are quiet right now.', 'Renée played MultiZork - 2h ago', ''], $status);
        self::assertSame(['The Crossroads are quiet right now.', '', ''],
            DoorHandler::composeArrivalThemeStatus($quiet, $noPlaces, ['lines' => [], 'count' => 0], $t));
        $status = DoorHandler::composeArrivalThemeStatus(
            ['summary' => '2 callers in 1 Experience'],
            ['summary' => '1 active place', 'experience_count' => 1], $history, $t);
        self::assertSame('Your Places: 1 active place', $status[2]);
        $h = TerminalRenderHarness::at(80, 24);
        self::assertTrue($this->view(status: $status)->tryRender($h->context(), 0, $this->hints()));
        $grid = $this->grid($h->bytes());
        self::assertSame($status[0], trim(mb_substr($grid[3], 3, 72)));
        self::assertSame($status[2], trim(mb_substr($grid[5], 3, 72)));
        // The next quiet snapshot clears the same previously populated cells.
        self::assertTrue($this->view()->tryRender($h->context(), 0, $this->hints()));
        $grid = $this->grid($h->bytes());
        self::assertSame('', trim(mb_substr($grid[5], 3, 72)));
    }

    public function testRequiredContentOverflowYieldsToFallback(): void
    {
        foreach ([['MENU', 'height', 1], ['MENU', 'width', 8], ['FOOTER', 'width', 8]] as [$region, $field, $value]) {
            $config = $this->config();
            $config['geometries']['80x24']['regions'][$region][$field] = $value;
            $view = $this->view(config: $config);
            $h = TerminalRenderHarness::at(80, 24);
            self::assertFalse($view->tryRender($h->context(), 2, $this->hints()));
            self::assertSame('fallback', $view->lastReport()['mode']);
        }
        $directory = new Directory('Crossroads', null, [new DirectorySection('', [new DirectoryRow(str_repeat('x', 38))])]);
        self::assertFalse($this->view($directory)->tryRender(TerminalRenderHarness::at(80, 24)->context(), 0, $this->hints()));
    }

    public function testMissingUnsafeArtAndUnsupportedGeometryYieldToFallback(): void
    {
        $config = $this->config();
        $config['geometries']['80x24']['template'] = 'missing-crossroads-proof';
        $view = $this->view(config: $config);
        self::assertFalse($view->tryRender(TerminalRenderHarness::at(80, 24)->context(), 0, $this->hints()));
        $token = 'crossroads-test-' . bin2hex(random_bytes(5));
        $path = NavigationThemeConfig::screensDir() . '/' . $token . '.ans';
        try {
            file_put_contents($path, "\x1b[2J\x1b]52;c;secret\x07");
            $config['geometries']['80x24']['template'] = $token;
            $view = $this->view(config: $config);
            $h = TerminalRenderHarness::at(80, 24);
            self::assertFalse($view->tryRender($h->context(), 0, $this->hints()));
            self::assertStringNotContainsString('secret', $h->bytes());
        } finally { unlink($path); }
        $view = $this->view();
        foreach ([[132,36], [132,51], [79,23]] as [$cols,$rows]) {
            self::assertFalse($view->tryRender(TerminalRenderHarness::at($cols, $rows)->context(), 0, $this->hints()));
        }
        self::assertFalse($view->tryRender(TerminalRenderHarness::at(80, 24)->mono()->context(), 0, $this->hints()));
        self::assertFalse($view->tryRender(TerminalRenderHarness::at(80, 24)->charset('ascii')->context(), 0, $this->hints()));
        self::assertTrue($view->tryRender(TerminalRenderHarness::at(80, 24)->context(), 0, $this->hints()));
    }
}
