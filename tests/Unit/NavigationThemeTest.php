<?php

declare(strict_types=1);

use BinktermPHP\Terminal\Navigation\AccessContext;
use BinktermPHP\Terminal\Navigation\AnsiScreenBuffer;
use BinktermPHP\Terminal\Navigation\NavigationDefinition;
use BinktermPHP\Terminal\Navigation\NavigationPath;
use BinktermPHP\Terminal\Navigation\NavigationRenderer;
use BinktermPHP\Terminal\Navigation\NavigationRendererFactory;
use BinktermPHP\Terminal\Navigation\NavigationScreenBuilder;
use BinktermPHP\Terminal\Navigation\NavigationScreenModel;
use BinktermPHP\Terminal\Navigation\NavigationScreenRenderer;
use BinktermPHP\Terminal\Navigation\NavigationTheme;
use BinktermPHP\Terminal\Navigation\NavigationThemeConfig;
use BinktermPHP\Terminal\Navigation\NavigationThemeLoader;
use BinktermPHP\Terminal\Navigation\TemplateArtSanitizer;
use BinktermPHP\Terminal\Navigation\TerminalActionCatalog;
use BinktermPHP\Terminal\Navigation\ThemedNavigationRenderer;
use BinktermPHP\Tests\Support\TerminalRenderHarness;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/TerminalRenderHarness.php';

/**
 * ANSI presentation / theme M1 — the config contract, the trusted-template
 * sanitiser, the region bounds, the themed renderer's placement and its
 * first-class fallback, and the F6 preview's themed vs fallback report.
 *
 * ANSI is presentation only: these tests also pin that a theme cannot change
 * what the navigation does (structure, hotkeys, actions are untouched) and that
 * a theme never makes navigation unusable.
 */
final class NavigationThemeTest extends TestCase
{
    private string $repo;

    protected function setUp(): void
    {
        $this->repo = dirname(__DIR__, 2);
        NavigationThemeConfig::reset();
    }

    protected function tearDown(): void
    {
        putenv('TERMINAL_NAV_THEME_CONFIG');
        unset($_ENV['TERMINAL_NAV_THEME_CONFIG'], $_SERVER['TERMINAL_NAV_THEME_CONFIG']);
        NavigationThemeConfig::reset();
    }

    // ---- helpers ------------------------------------------------------------

    private function loader(): NavigationThemeLoader
    {
        return new NavigationThemeLoader();
    }

    private function validThemeJson(string $template = 'nav-crossroads'): string
    {
        return json_encode([
            'schema' => 1,
            'id'     => 'test.theme',
            'enabled' => true,
            'geometries' => [
                '80x24' => [
                    'template' => $template,
                    'regions'  => [
                        'MENU'   => ['row' => 3, 'col' => 5, 'width' => 70, 'height' => 18],
                        'FOOTER' => ['row' => 22, 'col' => 5, 'width' => 70, 'height' => 1],
                    ],
                ],
            ],
        ]);
    }

    private function screen(?string $nodeId = null): NavigationScreenModel
    {
        $def = NavigationDefinition::fromArray([
            'schema' => 1, 'id' => 'demo', 'root' => 'main',
            'nodes' => [
                [
                    'id' => 'main', 'label_fallback' => 'Crossroads',
                    'description_fallback' => 'Where the networks meet.',
                    'items' => [
                        ['id' => 'c', 'label_fallback' => 'Crossroads', 'hotkey' => 'c', 'action' => 'doors',
                         'presentation' => ['group' => 'Places']],
                        ['id' => 'm', 'label_fallback' => 'Messages', 'hotkey' => 'm', 'submenu' => 'msgs',
                         'presentation' => ['group' => 'Places']],
                        ['id' => 'q', 'label_fallback' => 'Log Off', 'hotkey' => 'q', 'action' => 'quit',
                         'presentation' => ['group' => 'Session']],
                    ],
                ],
                ['id' => 'msgs', 'label_fallback' => 'Messages', 'items' => [
                    ['id' => 'n', 'label_fallback' => 'Netmail', 'hotkey' => 'n', 'action' => 'netmail'],
                ]],
            ],
        ]);

        $builder = new NavigationScreenBuilder(
            TerminalActionCatalog::defaultRegistry(),
            fn (?string $k, string $f, string $l) => $f,
        );
        $access = new AccessContext(true, false, false, fn () => true, fn () => true, ['color' => true, 'utf8' => true]);
        $path   = $nodeId === null || $nodeId === 'main'
            ? NavigationPath::root('main', 'Crossroads')
            : NavigationPath::root('main', 'Crossroads')->push($nodeId, ucfirst($nodeId));

        return $builder->build($def, $access, $path, 'en');
    }

    private function useThemeFile(string $json): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'navtheme_') . '.json';
        file_put_contents($tmp, $json);
        putenv('TERMINAL_NAV_THEME_CONFIG=' . $tmp);
        $_ENV['TERMINAL_NAV_THEME_CONFIG'] = $tmp;
        NavigationThemeConfig::reset();
        $this->cleanup[] = $tmp;
    }

    /** @var array<int,string> */
    private array $cleanup = [];

    protected function assertPostConditions(): void
    {
        foreach ($this->cleanup as $f) {
            @unlink($f);
        }
        $this->cleanup = [];
    }

    // ---- config: valid load ----------------------------------------------

    public function testValidThemeLoads(): void
    {
        $res = $this->loader()->fromJson($this->validThemeJson());
        self::assertTrue($res->isOk(), $res->errorSummary());
        $theme = $res->theme();
        self::assertSame('test.theme', $theme->id);
        self::assertTrue($theme->isEnabled());
        self::assertSame(['80x24'], $theme->geometryKeys());

        $geo = $theme->forGeometry(80, 24);
        self::assertNotNull($geo);
        self::assertSame('nav-crossroads', $geo->templateToken);
        self::assertSame(3, $geo->menu()->row);
        self::assertSame(70, $geo->menu()->width);
        self::assertNull($theme->forGeometry(132, 36), '132x36 is not themed');
    }

    public function testShippedExampleThemeIsValid(): void
    {
        $json = file_get_contents($this->repo . '/config/terminal_theme.json.example');
        self::assertIsString($json);
        $res = $this->loader()->fromJson($json);
        self::assertTrue($res->isOk(), 'example theme must validate: ' . $res->errorSummary());
        self::assertSame('nav-crossroads', $res->theme()->forGeometry(80, 24)->templateToken);
        self::assertFileExists(
            $this->repo . '/telnet/screens/nav-crossroads.ans',
            'the example theme references a template that must exist'
        );
    }

    // ---- config: disabled ----------------------------------------------

    public function testDisabledThemeLoadsButIsNotEnabled(): void
    {
        $json = json_decode($this->validThemeJson(), true);
        $json['enabled'] = false;
        $res = $this->loader()->fromJson(json_encode($json));
        self::assertTrue($res->isOk());
        self::assertFalse($res->theme()->isEnabled());
    }

    // ---- config: malformed / invalid ---------------------------------

    /**
     * @dataProvider invalidConfigs
     */
    public function testInvalidConfigsAreRejectedWithAPathTaggedError(string $json, string $expectCode): void
    {
        $res = $this->loader()->fromJson($json);
        self::assertTrue($res->isInvalid(), "should be invalid: {$json}");
        $codes = array_map(static fn ($e) => $e->code, $res->errors());
        self::assertContains($expectCode, $codes, $res->errorSummary());
        $wholeDocCodes = ['json', 'shape'];
        foreach ($res->errors() as $e) {
            if ($e->code === $expectCode && !in_array($expectCode, $wholeDocCodes, true)) {
                self::assertNotSame('', $e->path, 'a structural error carries a node/item path');
            }
        }
    }

    public static function invalidConfigs(): array
    {
        $rect = ['row' => 1, 'col' => 1, 'width' => 1, 'height' => 1];
        $geo  = fn (array $regions) => json_encode([
            'schema' => 1, 'id' => 'x',
            'geometries' => ['80x24' => ['template' => 't', 'regions' => $regions]],
        ]);

        return [
            'bad json'         => ['{ not json', 'json'],
            'not an object'    => ['[1,2,3]', 'shape'],
            'wrong schema'     => ['{"schema":9,"id":"x","geometries":{"80x24":{"template":"t","regions":{"MENU":{"row":1,"col":1,"width":1,"height":1},"FOOTER":{"row":2,"col":1,"width":1,"height":1}}}}}', 'schema'],
            'empty id'         => ['{"schema":1,"id":"  ","geometries":{"80x24":{"template":"t","regions":{"MENU":{"row":1,"col":1,"width":1,"height":1},"FOOTER":{"row":2,"col":1,"width":1,"height":1}}}}}', 'id'],
            'non-bool enabled' => ['{"schema":1,"id":"x","enabled":"yes","geometries":{"80x24":{"template":"t","regions":{"MENU":{"row":1,"col":1,"width":1,"height":1},"FOOTER":{"row":2,"col":1,"width":1,"height":1}}}}}', 'enabled'],
            'no geometries'    => ['{"schema":1,"id":"x"}', 'geometries'],
            'other geometry'   => ['{"schema":1,"id":"x","geometries":{"132x36":{"template":"t","regions":{"MENU":{"row":1,"col":1,"width":1,"height":1},"FOOTER":{"row":2,"col":1,"width":1,"height":1}}}}}', 'geometry_unsupported'],
            'bad template tok' => [$geo(['MENU' => $rect, 'FOOTER' => ['row' => 3, 'col' => 1, 'width' => 1, 'height' => 1]]) === '' ? '{}' : '{"schema":1,"id":"x","geometries":{"80x24":{"template":"../evil","regions":{"MENU":{"row":1,"col":1,"width":1,"height":1},"FOOTER":{"row":3,"col":1,"width":1,"height":1}}}}}', 'template'],
            'unknown region'   => [$geo(['MENU' => $rect, 'FOOTER' => ['row' => 3, 'col' => 1, 'width' => 1, 'height' => 1], 'HEADER' => ['row' => 5, 'col' => 1, 'width' => 1, 'height' => 1]]), 'region_unknown'],
            'missing footer'   => [$geo(['MENU' => $rect]), 'region_missing'],
            'negative coord'   => [$geo(['MENU' => ['row' => 0, 'col' => 1, 'width' => 1, 'height' => 1], 'FOOTER' => ['row' => 3, 'col' => 1, 'width' => 1, 'height' => 1]]), 'region_bounds'],
            'zero dimension'   => [$geo(['MENU' => ['row' => 1, 'col' => 1, 'width' => 0, 'height' => 1], 'FOOTER' => ['row' => 3, 'col' => 1, 'width' => 1, 'height' => 1]]), 'region_bounds'],
            'out of bounds'    => [$geo(['MENU' => ['row' => 1, 'col' => 1, 'width' => 200, 'height' => 1], 'FOOTER' => ['row' => 3, 'col' => 1, 'width' => 1, 'height' => 1]]), 'region_bounds'],
            'row overflow'     => [$geo(['MENU' => ['row' => 20, 'col' => 1, 'width' => 1, 'height' => 10], 'FOOTER' => ['row' => 3, 'col' => 1, 'width' => 1, 'height' => 1]]), 'region_bounds'],
            'overlap'          => [$geo(['MENU' => ['row' => 5, 'col' => 5, 'width' => 10, 'height' => 10], 'FOOTER' => ['row' => 6, 'col' => 6, 'width' => 4, 'height' => 2]]), 'region_overlap'],
        ];
    }

    // ---- template sanitisation ----------------------------------------

    public function testSanitiserKeepsSgrAndStripsEverythingElse(): void
    {
        $raw = "\x1b[2J\x1b[H"                    // clear + home
            . "\x1b[1;36mL33TEST\x1b[0m"          // SGR — kept
            . "\x1b[10;40Hmoved"                  // CUP — stripped
            . "\x1b]0;title\x07"                  // OSC — stripped
            . "\x1bP1;2pDCS\x1b\\"                // DCS — stripped
            . "\x1b[?25l"                         // private mode — stripped
            . "\x1b(0"                            // charset designation — stripped
            . "line\x07\x00\x08done"              // BEL/NUL/BS — stripped
            . "\x1a" . "SAUCE-junk-after-eof";    // DOS EOF — truncated

        $out = TemplateArtSanitizer::sanitize($raw, 'utf8');

        self::assertStringContainsString("\x1b[1;36m", $out, 'SGR kept');
        self::assertStringContainsString("\x1b[0m", $out, 'SGR reset kept');
        self::assertStringContainsString('L33TEST', $out);
        self::assertStringContainsString('linedone', $out, 'control bytes removed, text joined');
        self::assertStringContainsString('moved', $out, 'text after a stripped CUP survives; only the positioning is gone');
        self::assertDoesNotMatchRegularExpression('/\x1b\[[0-9;]*[Hf]/', $out, 'no cursor positioning');
        self::assertDoesNotMatchRegularExpression('/\x1b\[2J/', $out, 'no erase-display');
        self::assertDoesNotMatchRegularExpression('/\x1b\][^\x07]*\x07/', $out, 'no OSC');
        self::assertStringNotContainsString('DCS', $out, 'DCS payload removed');
        self::assertStringNotContainsString('SAUCE-junk', $out, 'SAUCE / EOF truncated');
    }

    public function testSanitiserCharsetHandling(): void
    {
        // A CP437 byte string (0xC9 = box corner) that is not valid UTF-8.
        $cp437 = "\xC9\xCD\xCD\xBB";
        self::assertFalse(mb_check_encoding($cp437, 'UTF-8'));

        $utf = TemplateArtSanitizer::sanitize($cp437, 'utf8');
        self::assertTrue(mb_check_encoding($utf, 'UTF-8'), 'converted to UTF-8 for a utf8 terminal');

        $cp = TemplateArtSanitizer::sanitize($cp437, 'cp437');
        self::assertSame($cp437, $cp, 'left as CP437 bytes for a cp437 terminal');

        self::assertSame('', TemplateArtSanitizer::sanitize($cp437, 'ascii'), 'ascii => empty => fallback');
    }

    public function testShippedTemplateSanitisesToPositioningFreeArt(): void
    {
        $raw = file_get_contents($this->repo . '/telnet/screens/nav-crossroads.ans');
        self::assertIsString($raw);

        foreach (['utf8', 'cp437'] as $charset) {
            $art = TemplateArtSanitizer::sanitize($raw, $charset);
            self::assertNotSame('', $art);
            self::assertDoesNotMatchRegularExpression('/\x1b\[[0-9;]*[A-DHJKf]/', $art, "{$charset}: no cursor/erase control");
            self::assertLessThanOrEqual(24, count(explode("\n", rtrim($art, "\n"))), "{$charset}: fits 24 rows");
        }
    }

    // ---- AnsiScreenBuffer -------------------------------------------------

    public function testAnsiScreenBufferResolvesAbsolutePositioning(): void
    {
        $b = new AnsiScreenBuffer(20, 4);
        $b->write("\x1b[2J\x1b[H\x1b[1;1Htop\x1b[3;5H\x1b[31mRED\x1b[0m\x1b[2;10Hmid");
        $lines = array_map(
            static fn ($l) => rtrim(preg_replace('/\x1b\[[0-9;]*m/', '', $l)),
            $b->toLines()
        );
        self::assertCount(4, $lines);
        self::assertSame('top', $lines[0]);
        self::assertSame('         mid', $lines[1]);
        self::assertSame('    RED', $lines[2]);
        self::assertSame('', $lines[3]);

        // SGR is preserved through the grid.
        self::assertStringContainsString("\x1b[31m", $b->toLines()[2]);
    }

    // ---- themed renderer: placement -------------------------------------

    public function testThemedRendererPaintsTemplateAndPlacesRegions(): void
    {
        $harness = TerminalRenderHarness::at(80, 24)->charset('utf8')->color(true);
        $ctx     = $harness->context();

        $theme  = $this->loader()->fromJson($this->validThemeJson())->theme();
        $themed = new ThemedNavigationRenderer(new NavigationScreenRenderer(), $theme);
        $themed->render($ctx, $this->screen(), ['cursor' => 0]);

        self::assertSame('themed', $themed->lastReport()['mode']);

        $grid = (new AnsiScreenBuffer(80, 24))->write($harness->bytes())->toLines();
        $plain = array_map(static fn ($l) => preg_replace('/\x1b\[[0-9;]*m/', '', $l), $grid);

        // Template identity is on screen.
        self::assertStringContainsString('L33TEST', $plain[0]);
        // MENU content landed inside the MENU rectangle (rows 3-20).
        $menuText = implode("\n", array_slice($plain, 2, 18));
        self::assertStringContainsString('Crossroads', $menuText);
        self::assertStringContainsString('[C]', $menuText);
        self::assertStringContainsString('[Q]', $menuText);
        // FOOTER hints landed on the FOOTER row (22).
        self::assertStringContainsString('Select an option', $plain[21]);
        // Nothing painted past the geometry.
        self::assertCount(24, $grid);
    }

    public function testThemedMenuAndFooterContentIsClippedToTheRegion(): void
    {
        $harness = TerminalRenderHarness::at(80, 24)->charset('utf8')->color(true);
        $ctx     = $harness->context();

        // A deliberately tiny MENU so the item list must clip.
        $json = json_decode($this->validThemeJson(), true);
        $json['geometries']['80x24']['regions']['MENU'] = ['row' => 5, 'col' => 10, 'width' => 24, 'height' => 4];
        $theme = $this->loader()->fromJson(json_encode($json))->theme();

        $themed = new ThemedNavigationRenderer(new NavigationScreenRenderer(), $theme);
        $themed->render($ctx, $this->screen(), ['cursor' => 0]);
        self::assertSame('themed', $themed->lastReport()['mode']);

        $grid  = (new AnsiScreenBuffer(80, 24))->write($harness->bytes())->toLines();
        $plain = array_map(static fn ($l) => preg_replace('/\x1b\[[0-9;]*m/', '', rtrim($l)), $grid);

        // Rows above (4) and below (9+) the 4-row MENU must not carry menu text.
        for ($r = 8; $r <= 12; $r++) {          // rows 9..13 (0-based 8..12)
            self::assertStringNotContainsString('Crossroads', $plain[$r] ?? '', "row " . ($r + 1) . " outside MENU");
        }
        // The clipped MENU shows a "more" marker.
        $menuText = implode("\n", array_slice($plain, 4, 4));
        self::assertMatchesRegularExpression('/more/i', $menuText);
    }

    // ---- fallback contract ---------------------------------------------

    public function testFallsBackWhenGeometryNotThemed(): void
    {
        foreach (['132x36', '132x51'] as $geo) {
            [$c, $r] = explode('x', $geo);
            $harness = TerminalRenderHarness::at((int) $c, (int) $r)->charset('utf8')->color(true);
            $ctx     = $harness->context();

            $theme  = $this->loader()->fromJson($this->validThemeJson())->theme();
            $themed = new ThemedNavigationRenderer(new NavigationScreenRenderer(), $theme);
            $themed->render($ctx, $this->screen());

            self::assertSame('fallback', $themed->lastReport()['mode'], $geo);
            self::assertStringContainsString('not a themed geometry', (string) $themed->lastReport()['reason']);
            // The flowing renderer still produced a usable screen.
            self::assertStringContainsString('Crossroads', $harness->bytes());
        }
    }

    public function testFallsBackOnAsciiOrMonoTerminal(): void
    {
        $theme = $this->loader()->fromJson($this->validThemeJson())->theme();

        $ascii = TerminalRenderHarness::at(80, 24)->charset('ascii')->color(true);
        $t1    = new ThemedNavigationRenderer(new NavigationScreenRenderer(), $theme);
        $t1->render($ascii->context(), $this->screen());
        self::assertSame('fallback', $t1->lastReport()['mode']);

        $mono = TerminalRenderHarness::at(80, 24)->charset('utf8')->mono();
        $t2   = new ThemedNavigationRenderer(new NavigationScreenRenderer(), $theme);
        $t2->render($mono->context(), $this->screen());
        self::assertSame('fallback', $t2->lastReport()['mode']);
        self::assertStringContainsString('colour', (string) $t2->lastReport()['reason']);
    }

    public function testFallsBackWhenTemplateAssetIsMissing(): void
    {
        $theme = $this->loader()->fromJson($this->validThemeJson('no-such-template-xyz'))->theme();
        $harness = TerminalRenderHarness::at(80, 24)->charset('utf8')->color(true);

        $themed = new ThemedNavigationRenderer(new NavigationScreenRenderer(), $theme);
        $themed->render($harness->context(), $this->screen());

        self::assertSame('fallback', $themed->lastReport()['mode']);
        self::assertStringContainsString('missing', (string) $themed->lastReport()['reason']);
        self::assertStringContainsString('Crossroads', $harness->bytes(), 'navigation still rendered');
    }

    public function testFactoryReturnsPlainRendererWhenNoThemeConfigured(): void
    {
        putenv('TERMINAL_NAV_THEME_CONFIG=/definitely/not/a/file.json');
        NavigationThemeConfig::reset();

        $renderer = NavigationRendererFactory::create();
        self::assertInstanceOf(NavigationScreenRenderer::class, $renderer);
        self::assertNotInstanceOf(ThemedNavigationRenderer::class, $renderer);
    }

    public function testFactoryReturnsPlainRendererWhenThemeInvalid(): void
    {
        $this->useThemeFile('{ not valid json');
        $logged = [];
        $renderer = NavigationRendererFactory::create(function (string $m) use (&$logged) {
            $logged[] = $m;
        });
        self::assertNotInstanceOf(ThemedNavigationRenderer::class, $renderer);
        self::assertNotEmpty($logged, 'an invalid theme file is logged for the sysop');
    }

    public function testFactoryReturnsThemedRendererWhenValidAndEnabled(): void
    {
        $this->useThemeFile($this->validThemeJson());
        $renderer = NavigationRendererFactory::create();
        self::assertInstanceOf(ThemedNavigationRenderer::class, $renderer);
    }

    public function testFactoryReturnsPlainRendererWhenThemeDisabled(): void
    {
        $json = json_decode($this->validThemeJson(), true);
        $json['enabled'] = false;
        $this->useThemeFile(json_encode($json));
        $renderer = NavigationRendererFactory::create();
        self::assertNotInstanceOf(ThemedNavigationRenderer::class, $renderer);
    }

    // ---- ANSI is presentation only ------------------------------------

    public function testThemeDoesNotChangeNavigationSemantics(): void
    {
        $screen = $this->screen();

        $harnessPlain  = TerminalRenderHarness::at(80, 24)->charset('utf8')->color(true);
        (new NavigationScreenRenderer())->render($harnessPlain->context(), $screen, ['cursor' => 0]);
        $plainBytes = $harnessPlain->bytes();

        $harnessThemed = TerminalRenderHarness::at(80, 24)->charset('utf8')->color(true);
        $theme = $this->loader()->fromJson($this->validThemeJson())->theme();
        (new ThemedNavigationRenderer(new NavigationScreenRenderer(), $theme))
            ->render($harnessThemed->context(), $screen, ['cursor' => 0]);
        $themedGrid = implode("\n", (new AnsiScreenBuffer(80, 24))->write($harnessThemed->bytes())->toLines());
        $themedGrid = preg_replace('/\x1b\[[0-9;]*m/', '', $themedGrid);

        // Same items, same hotkeys, same order — the theme only reframes them.
        foreach (['Crossroads', 'Messages', 'Log Off', '[C]', '[M]', '[Q]'] as $needle) {
            self::assertStringContainsString($needle, preg_replace('/\x1b\[[0-9;]*m/', '', $plainBytes), "plain: {$needle}");
            self::assertStringContainsString($needle, $themedGrid, "themed: {$needle}");
        }

        // The screen model the renderer received is identical — the themed
        // renderer never rebuilds or mutates it.
        self::assertSame(
            array_map(static fn ($i) => [$i->id, $i->hotkey, $i->isSelectable()], $screen->items),
            array_map(static fn ($i) => [$i->id, $i->hotkey, $i->isSelectable()], $this->screen()->items),
        );
    }

    public function testRendererInterfaceIsImplementedByBoth(): void
    {
        self::assertInstanceOf(NavigationRenderer::class, new NavigationScreenRenderer());
        $theme = $this->loader()->fromJson($this->validThemeJson())->theme();
        self::assertInstanceOf(
            NavigationRenderer::class,
            new ThemedNavigationRenderer(new NavigationScreenRenderer(), $theme)
        );
    }
}
