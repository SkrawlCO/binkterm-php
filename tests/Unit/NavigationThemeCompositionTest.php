<?php

declare(strict_types=1);

use BinktermPHP\Terminal\Navigation\AccessContext;
use BinktermPHP\Terminal\Navigation\AnsiScreenBuffer;
use BinktermPHP\Terminal\Navigation\NavigationDefinition;
use BinktermPHP\Terminal\Navigation\NavigationPath;
use BinktermPHP\Terminal\Navigation\NavigationPreviewProfile;
use BinktermPHP\Terminal\Navigation\NavigationPreviewService;
use BinktermPHP\Terminal\Navigation\NavigationRendererFactory;
use BinktermPHP\Terminal\Navigation\NavigationRuntime;
use BinktermPHP\Terminal\Navigation\NavigationScreenBuilder;
use BinktermPHP\Terminal\Navigation\NavigationScreenRenderer;
use BinktermPHP\Terminal\Navigation\NavigationTheme;
use BinktermPHP\Terminal\Navigation\NavigationThemeConfig;
use BinktermPHP\Terminal\Navigation\NavigationThemeLoader;
use BinktermPHP\Terminal\Navigation\TerminalActionCatalog;
use BinktermPHP\Terminal\Navigation\ThemedNavigationRenderer;
use BinktermPHP\Tests\Support\TerminalRenderHarness;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/TerminalRenderHarness.php';

/** Semantic composition uses the real builder/runtime, with off-session data. */
final class NavigationThemeCompositionTest extends TestCase
{
    private array $temporaryFiles = [];
    private mixed $oldEnv;
    private mixed $oldProcessEnv;

    protected function setUp(): void
    {
        $this->oldEnv = $_ENV['TERMINAL_NAV_THEME_CONFIG'] ?? null;
        $this->oldProcessEnv = getenv('TERMINAL_NAV_THEME_CONFIG');
        NavigationThemeConfig::reset();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            unlink($file);
        }
        if ($this->oldEnv === null) {
            unset($_ENV['TERMINAL_NAV_THEME_CONFIG']);
        } else {
            $_ENV['TERMINAL_NAV_THEME_CONFIG'] = $this->oldEnv;
        }
        putenv($this->oldProcessEnv === false ? 'TERMINAL_NAV_THEME_CONFIG'
            : 'TERMINAL_NAV_THEME_CONFIG=' . $this->oldProcessEnv);
        NavigationThemeConfig::reset();
    }

    private function config(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__, 2) . '/config/terminal_theme_m2.json.example'), true, 32, JSON_THROW_ON_ERROR);
    }

    private function theme(?array $config = null): NavigationTheme
    {
        $result = (new NavigationThemeLoader())->fromArray($config ?? $this->config());
        self::assertTrue($result->isOk(), $result->errorSummary());
        return $result->theme();
    }

    private function definition(): NavigationDefinition
    {
        // Same seven destinations / three groups as the L33TEST proof surface.
        $items = [];
        foreach ([
            ['crossroads', 'Crossroads', 'c', 'Places', 'doors'],
            ['library', 'Library', 'l', 'Places', 'files'],
            ['explore', 'Explore', 'x', 'Places', 'bbslist'],
            ['messages', 'Messages', 'm', 'Connect', null],
            ['people', 'People', 'p', 'Connect', 'whosonline'],
            ['settings', 'Settings', 's', 'Session', 'settings'],
            ['quit', 'Log Off', 'q', 'Session', 'quit'],
        ] as [$id, $label, $key, $group, $action]) {
            $item = ['id' => $id, 'label_fallback' => $label, 'hotkey' => $key,
                'description_fallback' => 'Purpose of ' . $label,
                'presentation' => ['group' => $group]];
            if ($id === 'people') {
                $item['presentation']['badge'] = 'callers_online';
            }
            $item[$action === null ? 'submenu' : 'action'] = $action ?? 'messages';
            $items[] = $item;
        }
        return NavigationDefinition::fromArray([
            'schema' => 1, 'id' => 'composition.proof', 'root' => 'main', 'nodes' => [
                ['id' => 'main', 'label_fallback' => 'L33TEST', 'items' => $items],
                ['id' => 'messages', 'label_fallback' => 'Messages', 'items' => [
                    ['id' => 'netmail', 'label_fallback' => 'Netmail', 'hotkey' => 'n', 'action' => 'netmail'],
                ]],
            ],
        ]);
    }

    private function builder(?callable $badge = null, ?callable $ambient = null): NavigationScreenBuilder
    {
        return new NavigationScreenBuilder(TerminalActionCatalog::defaultRegistry(),
            fn (?string $key, string $fallback, string $locale) => $fallback, $badge, $ambient);
    }

    private function access(): AccessContext
    {
        return new AccessContext(true, false, false, fn () => true, fn () => true, ['color' => true]);
    }

    private function grid(string $bytes, string $charset = 'utf8'): array
    {
        if ($charset === 'cp437') {
            $bytes = iconv('CP437', 'UTF-8', $bytes);
        }
        return array_map(fn ($line) => preg_replace('/\x1b\[[0-9;]*m/', '', $line),
            (new AnsiScreenBuffer(80, 24))->write($bytes)->toLines());
    }

    private function useConfig(string $json): string
    {
        $file = tempnam(sys_get_temp_dir(), 'composition-theme-');
        $this->temporaryFiles[] = $file;
        file_put_contents($file, $json);
        $_ENV['TERMINAL_NAV_THEME_CONFIG'] = $file;
        putenv('TERMINAL_NAV_THEME_CONFIG=' . $file);
        NavigationThemeConfig::reset();
        return $file;
    }

    public function testAllFourRegionsAndPairwiseValidation(): void
    {
        $theme = $this->theme();
        self::assertSame(2, $theme->schema);
        self::assertTrue($theme->rootOnly);
        self::assertCount(4, $theme->forGeometry(80, 24)->regions);
        foreach (['MENU', 'STATUS', 'DESCRIPTION', 'FOOTER'] as $name) {
            $missing = $this->config();
            unset($missing['geometries']['80x24']['regions'][$name]);
            $this->useConfig(json_encode($missing));
            self::assertInstanceOf(NavigationScreenRenderer::class, NavigationRendererFactory::create());
            $overlap = $this->config();
            $other = $name === 'STATUS' ? 'DESCRIPTION' : 'STATUS';
            $overlap['geometries']['80x24']['regions'][$name] = $overlap['geometries']['80x24']['regions'][$other];
            self::assertStringContainsString('overlap', (new NavigationThemeLoader())->fromArray($overlap)->errorSummary());
        }
        foreach (['STATUS', 'DESCRIPTION'] as $name) {
            $invalid = $this->config();
            $invalid['geometries']['80x24']['regions'][$name]['width'] = 100;
            self::assertTrue((new NavigationThemeLoader())->fromArray($invalid)->isInvalid());
        }
    }

    public function testDynamicContentPlacementAndCursorInBothCharsets(): void
    {
        foreach (['utf8', 'cp437'] as $charset) {
            $count = 2;
            $builder = $this->builder(function () use (&$count) { return "$count online"; }, fn () => 'Recent callers: Renée');
            $renderer = new ThemedNavigationRenderer(new NavigationScreenRenderer(), $this->theme());
            foreach ([0, 4] as $cursor) {
                $h = TerminalRenderHarness::at(80, 24)->charset($charset);
                $screen = $builder->build($this->definition(), $this->access(), NavigationPath::root('main', 'L33TEST'));
                $before = serialize($screen);
                $renderer->render($h->context(), $screen, ['cursor' => $cursor]);
                self::assertSame('themed', $renderer->lastReport()['mode']);
                self::assertSame($before, serialize($screen), 'rendering must not mutate application state');
                $grid = $this->grid($h->bytes(), $charset);
                self::assertStringContainsString($cursor === 0 ? 'Crossroads' : 'People', $grid[17]);
                self::assertStringContainsString('Purpose of ' . ($cursor === 0 ? 'Crossroads' : 'People'), $grid[18]);
                self::assertStringContainsString('Recent callers: Renée', $grid[20]);
                self::assertStringContainsString("People $count online", $grid[21]);
                self::assertStringContainsString('Select an option', $grid[23]);
                $menu = implode("\n", array_slice($grid, 4, 12));
                self::assertStringContainsString('[Q] LOG OFF', $menu);
                self::assertStringNotContainsString('Purpose of', $menu);
                self::assertStringNotContainsString('online', $menu);
                $count = 3;
            }
        }
    }

    public function testQuietStatusClearsPreviousContentAndControlsStayData(): void
    {
        $h = TerminalRenderHarness::at(80, 24);
        $renderer = new ThemedNavigationRenderer(new NavigationScreenRenderer(), $this->theme());
        foreach (['Caller A', null] as $ambient) {
            $builder = $this->builder(null, fn () => $ambient);
            $screen = $builder->build($this->definition(), $this->access(), NavigationPath::root('main', 'L33TEST'));
            $renderer->render($h->context(), $screen);
        }
        $grid = $this->grid($h->bytes());
        self::assertSame('', trim(mb_substr($grid[20], 4, 72)));
        self::assertSame('', trim(mb_substr($grid[21], 4, 72)));
        $builder = $this->builder(null, fn () => "Caller\x1b[2J\x07");
        $screen = $builder->build($this->definition(), $this->access(), NavigationPath::root('main', 'L33TEST'));
        $blocks = (new NavigationScreenRenderer())->composeSemanticRegions($h->context(), $screen, $this->theme()->forGeometry(80, 24));
        self::assertStringNotContainsString("\x1b[2J", implode('', $blocks['STATUS']));
        self::assertStringNotContainsString("\x07", implode('', $blocks['STATUS']));
    }

    public function testInsufficientMenuAndFooterFallBackInsteadOfHidingNavigation(): void
    {
        foreach ([['MENU', 'height', 1], ['MENU', 'width', 8], ['FOOTER', 'width', 8]] as [$name, $field, $value]) {
            $config = $this->config();
            $config['geometries']['80x24']['regions'][$name][$field] = $value;
            $renderer = new ThemedNavigationRenderer(new NavigationScreenRenderer(), $this->theme($config));
            $h = TerminalRenderHarness::at(80, 24);
            $screen = $this->builder()->build($this->definition(), $this->access(), NavigationPath::root('main', 'L33TEST'));
            $renderer->render($h->context(), $screen);
            self::assertSame('fallback', $renderer->lastReport()['mode']);
            self::assertStringContainsString('cannot fit', $renderer->lastReport()['reason']);
            self::assertStringContainsString('[Q]', $h->bytes());
        }
    }

    public function testResizeAndSubmenuUseFallbackAndRecover(): void
    {
        $renderer = new ThemedNavigationRenderer(new NavigationScreenRenderer(), $this->theme());
        foreach ([[80,24,'main'], [132,36,'main'], [132,51,'main'], [79,23,'main'], [80,24,'messages'], [80,24,'main']] as [$cols,$rows,$node]) {
            $path = NavigationPath::root('main', 'L33TEST');
            if ($node !== 'main') { $path = $path->push($node, 'Messages'); }
            $screen = $this->builder()->build($this->definition(), $this->access(), $path);
            $h = TerminalRenderHarness::at($cols, $rows);
            $renderer->render($h->context(), $screen);
            self::assertSame($cols === 80 && $rows === 24 && $node === 'main' ? 'themed' : 'fallback', $renderer->lastReport()['mode']);
            self::assertStringContainsString($node === 'main' ? '[Q]' : '[N]', $h->bytes());
        }
    }

    public function testInvalidAndNonFittingArtFallsBack(): void
    {
        foreach (["\x1b[2J\x1b]52;c;secret\x07", str_repeat('x', 81), str_repeat("x\n", 25), "art\ttext"] as $art) {
            $token = 'nav-m2-test-' . bin2hex(random_bytes(5));
            $file = NavigationThemeConfig::screensDir() . '/' . $token . '.ans';
            $this->temporaryFiles[] = $file;
            file_put_contents($file, $art);
            $config = $this->config();
            $config['geometries']['80x24']['template'] = $token;
            $renderer = new ThemedNavigationRenderer(new NavigationScreenRenderer(), $this->theme($config));
            $h = TerminalRenderHarness::at(80, 24);
            $screen = $this->builder()->build($this->definition(), $this->access(), NavigationPath::root('main', 'L33TEST'));
            $renderer->render($h->context(), $screen);
            self::assertSame('fallback', $renderer->lastReport()['mode']);
            self::assertStringContainsString('[Q]', $h->bytes());
            self::assertStringNotContainsString('secret', $h->bytes());
        }
    }

    public function testPreviewIsDeterministicAndDoesNotWriteOrInvokeActions(): void
    {
        $file = $this->useConfig(json_encode($this->config()));
        $before = hash_file('sha256', $file);
        $definition = $this->definition();
        $definitionBefore = serialize($definition);
        $registry = TerminalActionCatalog::defaultRegistry();
        foreach ($registry->ids() as $id) {
            $registry->bind($id, function () { self::fail('preview invoked an action'); });
        }
        $preview = new NavigationPreviewService($registry);
        $profile = NavigationPreviewProfile::geometry('80x24')->withAllFeaturesEnabled($registry);
        $a = $preview->renderReport($definition, $profile);
        self::assertSame('themed', $a['mode']);
        self::assertSame($a, $preview->renderReport($definition, $profile));
        self::assertStringContainsString('Purpose of Crossroads', implode('', $a['lines']));
        self::assertSame($before, hash_file('sha256', $file));
        self::assertSame($definitionBefore, serialize($definition));
        self::assertSame('fallback', $preview->renderReport($definition, $profile, 'messages')['mode']);
        $this->useConfig('{ malformed');
        self::assertSame('fallback', $preview->renderReport($definition, $profile)['mode']);
    }

    public function testRuntimeStillOwnsHotkeysArrowsBackAndActions(): void
    {
        $registry = TerminalActionCatalog::defaultRegistry();
        $invoked = [];
        foreach ($registry->ids() as $id) {
            $registry->bind($id, function () use (&$invoked, $id) { $invoked[] = $id; });
        }
        $builder = new NavigationScreenBuilder($registry, fn ($key, $fallback, $locale) => $fallback);
        $renderer = new ThemedNavigationRenderer(new NavigationScreenRenderer(), $this->theme());
        $runtime = new NavigationRuntime($this->definition(), $registry, $builder, $renderer);
        $keys = ['DOWN', 'ENTER', 'CHAR:m', 'CHAR:n', 'LEFT', 'CHAR:c', 'CHAR:q'];
        $h = TerminalRenderHarness::at(80, 24);
        $modes = [];
        $exit = $runtime->run(function () use (&$keys) { return [array_shift($keys), false, false]; },
            $h->context(), $this->access(), null, 'en',
            function () use (&$modes, $renderer) { $modes[] = $renderer->lastReport()['mode']; },
            maxIterations: 10);
        self::assertSame(NavigationRuntime::EXIT_QUIT, $exit);
        self::assertSame(['files', 'netmail', 'doors'], $invoked);
        self::assertContains('themed', $modes);
        self::assertContains('fallback', $modes);
    }
}
