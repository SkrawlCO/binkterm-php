<?php

declare(strict_types=1);

use BinktermPHP\Terminal\Navigation\AccessContext;
use BinktermPHP\Terminal\Navigation\DefaultNavigationDefinition;
use BinktermPHP\Terminal\Navigation\NavigationDefinition;
use BinktermPHP\Terminal\Navigation\NavigationLineRenderer;
use BinktermPHP\Terminal\Navigation\NavigationPath;
use BinktermPHP\Terminal\Navigation\NavigationPreviewProfile;
use BinktermPHP\Terminal\Navigation\NavigationPreviewService;
use BinktermPHP\Terminal\Navigation\NavigationScreenBuilder;
use BinktermPHP\Terminal\Navigation\NavigationScreenItem;
use BinktermPHP\Terminal\Navigation\TerminalActionCatalog;
use PHPUnit\Framework\TestCase;

/**
 * R5E + R5F + R5G + R5I — screen model construction, deterministic rendering at
 * multiple geometry/charset/colour profiles, orientation, and the line-shell
 * projection.
 */
final class NavigationRenderTest extends TestCase
{
    private function builder(): NavigationScreenBuilder
    {
        return new NavigationScreenBuilder(
            TerminalActionCatalog::defaultRegistry(),
            fn (?string $key, string $fallback, string $locale) => $fallback,
        );
    }

    private function fullAccess(array $features = []): AccessContext
    {
        return new AccessContext(
            true,
            false,
            false,
            fn (string $f) => $features === [] ? true : in_array($f, $features, true),
            fn () => true,
            ['color' => true, 'utf8' => true],
        );
    }

    // ===== screen model =====

    public function testScreenModelResolvesLabelsAndDropsHiddenItems(): void
    {
        $def = NavigationDefinition::fromArray([
            'schema' => 1, 'id' => 'x', 'root' => 'main',
            'nodes' => [['id' => 'main', 'label_fallback' => 'Home', 'items' => [
                ['id' => 'nm', 'label_fallback' => 'Netmail', 'hotkey' => 'n', 'action' => 'netmail'],
                ['id' => 'admin', 'label_fallback' => 'Admin Only', 'hotkey' => 'a', 'action' => 'settings', 'access' => 'admin'],
            ]]],
        ]);

        $screen = $this->builder()->build($def, $this->fullAccess(), NavigationPath::root('main', 'Home'));

        self::assertSame('Home', $screen->title);
        self::assertCount(1, $screen->items, 'the admin-only item is hidden for a non-admin');
        self::assertSame('Netmail', $screen->items[0]->label);
        self::assertNull($screen->itemForHotkey('a'), 'a hidden item leaves no dangling hotkey');
        self::assertNotNull($screen->itemForHotkey('n'));
    }

    public function testDisabledItemIsShownButNotSelectable(): void
    {
        $def = NavigationDefinition::fromArray([
            'schema' => 1, 'id' => 'x', 'root' => 'main',
            'nodes' => [['id' => 'main', 'label_fallback' => 'Home', 'items' => [
                ['id' => 'nm', 'label_fallback' => 'Netmail', 'hotkey' => 'n', 'action' => 'netmail', 'enabled' => false],
            ]]],
        ]);

        $screen = $this->builder()->build($def, $this->fullAccess(), NavigationPath::root('main', 'Home'));

        self::assertCount(1, $screen->items);
        self::assertFalse($screen->items[0]->isSelectable());
        self::assertSame([], $screen->selectableItems());
        self::assertNull($screen->itemForHotkey('n'), 'a disabled item does not answer its hotkey');
    }

    public function testUnavailableActionIsHiddenWhenItsFeatureIsOff(): void
    {
        $def = NavigationDefinition::fromArray([
            'schema' => 1, 'id' => 'x', 'root' => 'main',
            'nodes' => [['id' => 'main', 'label_fallback' => 'Home', 'items' => [
                ['id' => 'd', 'label_fallback' => 'Doors', 'hotkey' => 'd', 'action' => 'doors'],
                ['id' => 'nm', 'label_fallback' => 'Netmail', 'hotkey' => 'n', 'action' => 'netmail'],
            ]]],
        ]);

        $noDoors = new AccessContext(true, false, false, fn () => false, fn () => false, []);
        $screen  = $this->builder()->build($def, $noDoors, NavigationPath::root('main', 'Home'));

        self::assertCount(1, $screen->items);
        self::assertSame('Netmail', $screen->items[0]->label);
    }

    public function testOrientationReflectsDepth(): void
    {
        $def = NavigationDefinition::fromArray([
            'schema' => 1, 'id' => 'x', 'root' => 'main',
            'nodes' => [
                ['id' => 'main', 'label_fallback' => 'Main', 'items' => [
                    ['id' => 'go', 'label_fallback' => 'Deeper', 'submenu' => 'sub'],
                ]],
                ['id' => 'sub', 'label_fallback' => 'Sub', 'items' => [
                    ['id' => 'go2', 'label_fallback' => 'Deeper still', 'submenu' => 'sub2'],
                ]],
                ['id' => 'sub2', 'label_fallback' => 'Sub Two', 'items' => [
                    ['id' => 'n', 'label_fallback' => 'Netmail', 'action' => 'netmail'],
                ]],
            ],
        ]);
        $b = $this->builder();

        $root = $b->build($def, $this->fullAccess(), NavigationPath::root('main', 'Main'));
        self::assertFalse($root->backAvailable);
        self::assertFalse($root->homeAvailable);

        $deep = $b->build($def, $this->fullAccess(), NavigationPath::root('main', 'Main')->push('sub', 'Sub')->push('sub2', 'Sub Two'));
        self::assertTrue($deep->backAvailable);
        self::assertTrue($deep->homeAvailable);
        self::assertSame('Main > Sub > Sub Two', $deep->path->crumb(' > '));
    }

    public function testFooterAdvertisesQuitOnlyWhenTheScreenBindsAQItem(): void
    {
        $def = NavigationDefinition::fromArray([
            'schema' => 1, 'id' => 'x', 'root' => 'main',
            'nodes' => [
                ['id' => 'main', 'label_fallback' => 'Main', 'items' => [
                    ['id' => 'sub', 'label_fallback' => 'Section', 'hotkey' => 's', 'submenu' => 'sub'],
                    ['id' => 'off', 'label_fallback' => 'Log Off', 'hotkey' => 'q', 'action' => 'quit'],
                ]],
                ['id' => 'sub', 'label_fallback' => 'Section', 'items' => [
                    ['id' => 'n', 'label_fallback' => 'Netmail', 'hotkey' => 'n', 'action' => 'netmail'],
                ]],
            ],
        ]);
        $svc = new NavigationPreviewService();
        $strip = static fn (string $b): string => preg_replace('/\033\[[0-9;?]*[A-Za-z]/', '', $b);

        $rootFooter = $strip($svc->render($def, new NavigationPreviewProfile(), 'main'));
        self::assertStringContainsString('Q Log Off', $rootFooter, 'root binds [Q] Log Off');

        $subFooter = $strip($svc->render($def, new NavigationPreviewProfile(), 'sub'));
        self::assertStringNotContainsString(' Q ', $subFooter, 'submenu does not bind Q, so it is not advertised');
        self::assertStringContainsString('Back', $subFooter, 'submenu still shows Back');
        self::assertStringContainsString('B/Left Back', $subFooter, 'submenu without a B item advertises B as Back');
    }

    public function testFooterDoesNotAdvertiseBAsBackWhenBIsAnItemHotkey(): void
    {
        // Mirrors the live "Explore" node: [B] BBS Directory + [L] Node List.
        $def = NavigationDefinition::fromArray([
            'schema' => 1, 'id' => 'x', 'root' => 'main',
            'nodes' => [
                ['id' => 'main', 'label_fallback' => 'Main', 'items' => [
                    ['id' => 'x', 'label_fallback' => 'Explore', 'hotkey' => 'x', 'submenu' => 'explore'],
                ]],
                ['id' => 'explore', 'label_fallback' => 'Explore', 'items' => [
                    ['id' => 'b', 'label_fallback' => 'BBS Directory', 'hotkey' => 'b', 'action' => 'bbslist'],
                    ['id' => 'l', 'label_fallback' => 'Node List', 'hotkey' => 'l', 'action' => 'nodelist'],
                ]],
            ],
        ]);
        $svc = new NavigationPreviewService();
        $strip = static fn (string $b): string => preg_replace('/\033\[[0-9;?]*[A-Za-z]/', '', $b);

        $footer = $strip($svc->render($def, (new NavigationPreviewProfile())->withAllFeaturesEnabled(
            TerminalActionCatalog::defaultRegistry()
        ), 'explore'));

        self::assertStringNotContainsString('B/Left Back', $footer, 'B is an item hotkey here — not advertised as Back');
        self::assertStringNotContainsString('B Back', $footer);
        self::assertStringContainsString('Back', $footer, 'Back is still offered');
        self::assertStringContainsString('Left/Esc Back', $footer, 'the non-conflicting Back keys are advertised');
    }

    public function testFooterDoesNotAdvertiseHHomeWhenHIsAnItemHotkey(): void
    {
        $def = NavigationDefinition::fromArray([
            'schema' => 1, 'id' => 'x', 'root' => 'main',
            'nodes' => [
                ['id' => 'main', 'label_fallback' => 'Main', 'items' => [
                    ['id' => 'a', 'label_fallback' => 'A', 'hotkey' => 'a', 'submenu' => 'a'],
                ]],
                ['id' => 'a', 'label_fallback' => 'A', 'items' => [
                    ['id' => 'b', 'label_fallback' => 'B', 'hotkey' => 'b', 'submenu' => 'deep'],
                ]],
                ['id' => 'deep', 'label_fallback' => 'Deep', 'items' => [
                    ['id' => 'h', 'label_fallback' => 'Homestead', 'hotkey' => 'h', 'action' => 'settings'],
                ]],
            ],
        ]);
        $svc = new NavigationPreviewService();
        $strip = static fn (string $b): string => preg_replace('/\033\[[0-9;?]*[A-Za-z]/', '', $b);

        $footer = $strip($svc->render($def, new NavigationPreviewProfile(), 'deep'));
        self::assertStringNotContainsString('H Home', $footer, 'H is an item hotkey at this depth — no conflicting Home shortcut');
    }

    // ===== deterministic rendering (R5I) =====

    /** @dataProvider profileProvider */
    public function testDeterministicRenderAtEveryStandardProfile(string $name, NavigationPreviewProfile $profile): void
    {
        $def     = DefaultNavigationDefinition::build();
        $service = new NavigationPreviewService();

        $profile = $profile->withAllFeaturesEnabled(TerminalActionCatalog::defaultRegistry());
        $bytes   = $service->render($def, $profile);

        self::assertNotSame('', $bytes);

        // No rendered content line exceeds the terminal width.
        $lines = preg_split('/\r\n|\n/', preg_replace('/\033\[[0-9;?]*[A-Za-z]/', '', $bytes) ?? '');
        foreach ($lines as $line) {
            self::assertLessThanOrEqual(
                $profile->cols,
                mb_strlen(rtrim($line, "\r"), 'UTF-8'),
                "{$name}: line within {$profile->cols} cols: " . json_encode($line)
            );
        }

        if (!$profile->color) {
            $withoutResets = preg_replace('/\033\[0?m/', '', $bytes);
            self::assertSame(0, preg_match('/\033\[[0-9;]*[0-9][0-9;]*m/', $withoutResets), "{$name}: mono emits no colour SGR");
        }
        if ($profile->charset === 'ascii') {
            self::assertSame(0, preg_match('/[\x80-\xff]/', $bytes), "{$name}: ascii is 7-bit clean");
        }
        if ($profile->charset === 'cp437') {
            self::assertSame(0, preg_match('/\xE2\x94|\xE2\x95/', $bytes), "{$name}: no UTF-8 box drawing in cp437");
        }

        // Determinism: same inputs -> identical bytes.
        self::assertSame(bin2hex($bytes), bin2hex($service->render($def, $profile)));
    }

    public static function profileProvider(): array
    {
        $out = [];
        foreach (NavigationPreviewService::standardProfiles() as $name => $profile) {
            $out[$name] = [$name, $profile];
        }

        return $out;
    }

    public function testRenderHonoursAccessSoAdminSeesMoreThanAUser(): void
    {
        $def = NavigationDefinition::fromArray([
            'schema' => 1, 'id' => 'x', 'root' => 'main',
            'nodes' => [['id' => 'main', 'label_fallback' => 'Home', 'items' => [
                ['id' => 'n', 'label_fallback' => 'Netmail', 'hotkey' => 'n', 'action' => 'netmail'],
                ['id' => 's', 'label_fallback' => 'Sysop Tools', 'hotkey' => 's', 'action' => 'settings', 'access' => 'admin'],
            ]]],
        ]);
        $svc = new NavigationPreviewService();

        $user  = $svc->render($def, new NavigationPreviewProfile(admin: false));
        $admin = $svc->render($def, new NavigationPreviewProfile(admin: true));

        self::assertStringNotContainsString('Sysop Tools', preg_replace('/\033\[[0-9;]*m/', '', $user));
        self::assertStringContainsString('Sysop Tools', preg_replace('/\033\[[0-9;]*m/', '', $admin));
    }

    public function testShortTerminalClipsBodyFromBottomKeepingHeader(): void
    {
        $def   = DefaultNavigationDefinition::build();
        $svc   = new NavigationPreviewService();
        $bytes = $svc->render($def, (new NavigationPreviewProfile(cols: 80, rows: 10))->withAllFeaturesEnabled(TerminalActionCatalog::defaultRegistry()));

        $plain = preg_replace('/\033\[[0-9;?]*[A-Za-z]/', '', $bytes) ?? '';
        self::assertStringContainsString('Main Menu', $plain, 'title survives on a 10-row terminal');
        self::assertStringContainsString('more', $plain, 'a clip indicator is shown');
        $lines = array_filter(explode("\n", str_replace("\r", '', $plain)), fn ($l) => $l !== '');
        self::assertLessThanOrEqual(10, count($lines) + 1);
    }

    // ===== line shell projection (R5F fallback) =====

    public function testLineRendererProducesNumberedListAndHotkeyMap(): void
    {
        $def = DefaultNavigationDefinition::build();
        $screen = $this->builder()->build($def, $this->fullAccess(), NavigationPath::root('main', 'Main Menu'));

        $projection = (new NavigationLineRenderer())->project($screen);

        self::assertNotEmpty($projection['items']);
        self::assertArrayHasKey('n', $projection['key_to_index']);
        self::assertSame(
            'Netmail',
            $projection['selectable'][$projection['key_to_index']['n']]->label
        );
        self::assertStringStartsWith('[N] ', $projection['items'][$projection['key_to_index']['n']]);
    }

    public function testLineRendererOmitsUnavailableOptions(): void
    {
        $def = NavigationDefinition::fromArray([
            'schema' => 1, 'id' => 'x', 'root' => 'main',
            'nodes' => [['id' => 'main', 'label_fallback' => 'Home', 'items' => [
                ['id' => 'n', 'label_fallback' => 'Netmail', 'hotkey' => 'n', 'action' => 'netmail'],
                ['id' => 'x', 'label_fallback' => 'Disabled', 'hotkey' => 'x', 'action' => 'echomail', 'enabled' => false],
            ]]],
        ]);
        $screen = $this->builder()->build($def, $this->fullAccess(), NavigationPath::root('main', 'Home'));

        $projection = (new NavigationLineRenderer())->project($screen);
        self::assertCount(1, $projection['items']);
        self::assertArrayNotHasKey('x', $projection['key_to_index']);
    }
}
