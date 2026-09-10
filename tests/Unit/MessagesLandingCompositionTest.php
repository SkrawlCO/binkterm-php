<?php

declare(strict_types=1);

use BinktermPHP\Newscan\NewscanArea;
use BinktermPHP\Newscan\NewscanPlan;
use BinktermPHP\Newscan\NewscanSnapshot;
use BinktermPHP\Newscan\TerminalNewscanLanding;
use BinktermPHP\Terminal\Navigation\AccessContext;
use BinktermPHP\Terminal\Navigation\AnsiScreenBuffer;
use BinktermPHP\Terminal\Navigation\NavigationDefinition;
use BinktermPHP\Terminal\Navigation\NavigationPath;
use BinktermPHP\Terminal\Navigation\NavigationRuntime;
use BinktermPHP\Terminal\Navigation\NavigationScreenBuilder;
use BinktermPHP\Terminal\Navigation\NavigationScreenRenderer;
use BinktermPHP\Terminal\Navigation\NavigationThemeLoader;
use BinktermPHP\Terminal\Navigation\TerminalActionCatalog;
use BinktermPHP\Terminal\Navigation\ThemedNavigationRenderer;
use BinktermPHP\Tests\Support\TerminalRenderHarness;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/TerminalRenderHarness.php';

/**
 * Messages M2 Slice 1 — the state-forward authored landing.
 *
 * Off-session: the canonical newscan plan is fabricated and fed through the
 * real projection ({@see TerminalNewscanLanding}) and the real builder /
 * renderer / theme, exactly as the bridge wires them live. No database, no
 * BbsSession, no writes.
 */
final class MessagesLandingCompositionTest extends TestCase
{
    /** The shipped example: front door + an authored `messages` node. */
    private function theme(): \BinktermPHP\Terminal\Navigation\NavigationTheme
    {
        $r = (new NavigationThemeLoader())->fromFile(
            dirname(__DIR__, 2) . '/config/terminal_theme_m2_messages.json.example'
        );
        self::assertTrue($r->isOk(), $r->errorSummary());

        return $r->theme();
    }

    private function definition(): NavigationDefinition
    {
        return NavigationDefinition::fromArray([
            'schema' => 1, 'id' => 'messages.landing.test', 'root' => 'root', 'nodes' => [
                ['id' => 'root', 'label_fallback' => 'L33TEST', 'items' => [
                    ['id' => 'messages', 'label_fallback' => 'Messages', 'hotkey' => 'm', 'submenu' => 'messages',
                     'description_fallback' => 'Echomail, netmail and bulletins'],
                    ['id' => 'people', 'label_fallback' => 'People', 'hotkey' => 'p', 'action' => 'whosonline'],
                ]],
                ['id' => 'messages', 'label_fallback' => 'Messages',
                 'description_fallback' => 'Your mail, the echoes, and the boards.', 'items' => [
                    ['id' => 'whatsnew', 'label_fallback' => "What's New", 'hotkey' => 'a', 'action' => 'newscan',
                     'description_fallback' => 'Everything new since your last visit.'],
                    ['id' => 'netmail', 'label_fallback' => 'Netmail', 'hotkey' => 'n', 'action' => 'netmail',
                     'description_fallback' => 'Private point-to-point mail.',
                     'presentation' => ['badge' => TerminalNewscanLanding::BADGE_NETMAIL]],
                    ['id' => 'echomail', 'label_fallback' => 'Echomail', 'hotkey' => 'e', 'action' => 'echomail',
                     'description_fallback' => 'Public message areas across the network.',
                     'presentation' => ['badge' => TerminalNewscanLanding::BADGE_ECHOMAIL]],
                    ['id' => 'bulletins', 'label_fallback' => 'Bulletins', 'hotkey' => 'b', 'action' => 'bulletins',
                     'description_fallback' => 'Announcements from the sysop.',
                     'presentation' => ['badge' => TerminalNewscanLanding::BADGE_BULLETINS]],
                 ]],
            ],
        ]);
    }

    /** English-fallback translator (params substituted), as the live path degrades. */
    private function tr(): callable
    {
        return static function (string $k, string $f, array $p = []): string {
            foreach ($p as $key => $v) {
                $f = str_replace('{' . $key . '}', (string) $v, $f);
            }

            return $f;
        };
    }

    /**
     * A builder wired the way the bridge wires it: badge + summary resolvers
     * both derived from ONE projection of the given plan.
     */
    private function builder(NewscanPlan $plan, ?callable &$planCalls = null): NavigationScreenBuilder
    {
        $calls = 0;
        $planCalls = static fn (): int => $calls;
        $t = $this->tr();
        $projection = static function () use (&$calls, $plan, $t): array {
            $calls++;

            return TerminalNewscanLanding::project($plan, $t);
        };

        return new NavigationScreenBuilder(
            TerminalActionCatalog::defaultRegistry(),
            fn (?string $key, string $fallback, string $locale) => $fallback,
            static fn (string $signal): ?string => $projection()['badges'][$signal] ?? null,
            null,
            static fn (string $nodeId, string $locale): ?array => $nodeId === 'messages'
                ? $projection()['summary'] : null,
        );
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

        return array_map(
            fn ($line) => preg_replace('/\x1b\[[0-9;]*m/', '', $line),
            (new AnsiScreenBuffer(80, 24))->write($bytes)->toLines()
        );
    }

    private function messagesScreen(NewscanPlan $plan, int $cursor = 0, string $charset = 'utf8'): array
    {
        $builder  = $this->builder($plan);
        $renderer = new ThemedNavigationRenderer(new NavigationScreenRenderer(), $this->theme());
        $path     = NavigationPath::root('root', 'L33TEST')->push('messages', 'Messages');
        $screen   = $builder->build($this->definition(), $this->access(), $path);
        $h        = TerminalRenderHarness::at(80, 24)->charset($charset);
        $renderer->render($h->context(), $screen, ['cursor' => $cursor]);
        self::assertSame('themed', $renderer->lastReport()['mode'], $renderer->lastReport()['reason'] ?? '');

        return $this->grid($h->bytes(), $charset);
    }

    private function areas(): array
    {
        return [
            new NewscanArea(1, 'FIDO.GENERAL', '', 'General chatter', [1, 2, 3]),
            new NewscanArea(2, 'LINUX', '', 'Linux talk', [10, 11]),
        ];
    }

    // ---- 1. the shipped theme validates and themes exactly the messages node -

    public function testShippedExampleThemesTheMessagesNodeOnlyAt80x24(): void
    {
        $t = $this->theme();
        self::assertTrue($t->themesNode('messages'));
        self::assertFalse($t->themesNode('people'));
        self::assertSame('nav-messages-m2', $t->geometryForScreen('messages', false, 80, 24)?->templateToken);
        self::assertNull($t->geometryForScreen('messages', false, 132, 24));
        self::assertNull($t->geometryForScreen('people', false, 80, 24), 'root_only theme still yields for other submenus');
        self::assertSame('nav-frontdoor-m2', $t->geometryForScreen('root', true, 80, 24)?->templateToken);
    }

    public function testNodesRequiresSchemaTwoAndValidatesRegionsLikeTheTheme(): void
    {
        $bad = (new NavigationThemeLoader())->fromJson(json_encode([
            'schema' => 1, 'id' => 'x',
            'geometries' => ['80x24' => ['template' => 't', 'regions' => [
                'MENU' => ['row' => 1, 'col' => 1, 'width' => 5, 'height' => 1],
                'FOOTER' => ['row' => 3, 'col' => 1, 'width' => 5, 'height' => 1],
            ]]],
            'nodes' => ['messages' => ['geometries' => ['80x24' => ['template' => 't', 'regions' => []]]]],
        ]));
        self::assertTrue($bad->isInvalid(), 'nodes on schema 1 is rejected');

        $overflow = (new NavigationThemeLoader())->fromJson(json_encode([
            'schema' => 2, 'id' => 'x', 'root_only' => true,
            'geometries' => ['80x24' => ['template' => 'nav-frontdoor-m2', 'regions' => [
                'MENU' => ['row' => 5, 'col' => 5, 'width' => 72, 'height' => 12],
                'DESCRIPTION' => ['row' => 18, 'col' => 5, 'width' => 72, 'height' => 2],
                'STATUS' => ['row' => 21, 'col' => 5, 'width' => 72, 'height' => 2],
                'FOOTER' => ['row' => 24, 'col' => 5, 'width' => 72, 'height' => 1],
            ]]],
            'nodes' => ['messages' => ['geometries' => ['80x24' => ['template' => 'nav-messages-m2', 'regions' => [
                'MENU' => ['row' => 12, 'col' => 5, 'width' => 72, 'height' => 7],
                'DESCRIPTION' => ['row' => 20, 'col' => 5, 'width' => 72, 'height' => 2],
                'STATUS' => ['row' => 8, 'col' => 5, 'width' => 72, 'height' => 2],
                'FOOTER' => ['row' => 23, 'col' => 5, 'width' => 999, 'height' => 1],
            ]]]]],
        ]));
        self::assertTrue($overflow->isInvalid(), 'an out-of-bounds node region is rejected');
    }

    // ---- 2. STATUS is the newscan projection, on a NON-ROOT node -------------

    public function testWaitingStateStatusIsTheCanonicalPlanProjection(): void
    {
        $grid = $this->messagesScreen(new NewscanPlan([7], $this->areas(), 1, false));
        $status = $grid[6] . ' ' . $grid[7]; // STATUS region rows 8-9 -> 0-based 7-8; label row is 7 -> grid[6]
        $joined = implode("\n", $grid);
        self::assertStringContainsString('WAITING FOR YOU', $joined);
        self::assertStringContainsString('1 unread netmail', $joined);
        self::assertStringContainsString('5 new echomail in 2 area(s)', $joined);
        self::assertStringContainsString('1 unread bulletin(s)', $joined);
    }

    public function testCaughtUpStateRendersHonestly(): void
    {
        $joined = implode("\n", $this->messagesScreen(NewscanPlan::empty()));
        self::assertStringContainsString("You're all caught up.", $joined);
        self::assertStringNotContainsString('unread netmail', $joined);
        self::assertStringNotContainsString('new echomail', $joined);
    }

    public function testTruncationIsShownAndNeverPresentedAsComplete(): void
    {
        $joined = implode("\n", $this->messagesScreen(new NewscanPlan([1, 2], $this->areas(), 0, true)));
        self::assertStringContainsString('beyond the scan limit', $joined);
    }

    // ---- 3. MENU annotations from the same plan, zeros suppressed -----------

    public function testMenuAnnotationsComeFromThePlanAndZerosAreSuppressed(): void
    {
        // netmail + bulletins waiting; NO new echomail.
        $joined = implode("\n", $this->messagesScreen(new NewscanPlan([1, 2, 3], [], 4, false)));
        self::assertMatchesRegularExpression('/NETMAIL\s+3 unread/', $joined);
        self::assertMatchesRegularExpression('/BULLETINS\s+4 new/', $joined);
        // Echomail row present, but with no annotation (0 new -> suppressed).
        self::assertMatchesRegularExpression('/\[E\] ECHOMAIL\s*$/m', $joined);
        self::assertStringContainsString("[A] WHAT'S NEW", $joined);
    }

    public function testFrontDoorMenuRowsStayCleanOfLiveBadges(): void
    {
        // The people item has a live badge on the root screen; the front-door
        // menu must not surface it into a row (only Messages does that).
        $builder = new NavigationScreenBuilder(
            TerminalActionCatalog::defaultRegistry(),
            fn (?string $key, string $fallback, string $locale) => $fallback,
            static fn (string $signal): ?string => $signal === 'callers_online' ? '2 online' : null,
            static fn (string $locale): ?string => 'Recent callers: nobody',
        );
        $def = NavigationDefinition::fromArray([
            'schema' => 1, 'id' => 't', 'root' => 'root', 'nodes' => [
                ['id' => 'root', 'label_fallback' => 'L33TEST', 'items' => [
                    ['id' => 'people', 'label_fallback' => 'People', 'hotkey' => 'p', 'action' => 'whosonline',
                     'description_fallback' => 'Who is here', 'presentation' => ['badge' => 'callers_online']],
                ]],
            ],
        ]);
        $renderer = new ThemedNavigationRenderer(new NavigationScreenRenderer(), $this->theme());
        $h = TerminalRenderHarness::at(80, 24);
        $screen = $builder->build($def, $this->access(), NavigationPath::root('root', 'L33TEST'));
        $renderer->render($h->context(), $screen, ['cursor' => 0]);
        self::assertSame('themed', $renderer->lastReport()['mode']);
        $grid = $this->grid($h->bytes());
        $menu = implode("\n", array_slice($grid, 4, 12));
        self::assertStringContainsString('PEOPLE', $menu);
        self::assertStringNotContainsString('2 online', $menu, 'live badge stays out of the front-door menu rows');
    }

    // ---- 4. DESCRIPTION is the existing static text (slice-1 restraint) -----

    public function testDescriptionUsesTheExistingDestinationTextNotPlanData(): void
    {
        $plan = new NewscanPlan([9], $this->areas(), 0, false);
        $onNetmail  = implode("\n", $this->messagesScreen($plan, 1)); // cursor 1 = Netmail
        $onEchomail = implode("\n", $this->messagesScreen($plan, 2)); // cursor 2 = Echomail
        self::assertStringContainsString('Private point-to-point mail.', $onNetmail);
        self::assertStringContainsString('Public message areas across the network.', $onEchomail);
        // The DESCRIPTION region must not repeat a live count.
        self::assertStringNotContainsString('unread', $this->descriptionRegion($onNetmail));
    }

    private function descriptionRegion(string $joined): string
    {
        $lines = explode("\n", $joined);
        return ($lines[18] ?? '') . "\n" . ($lines[19] ?? ''); // DESCRIPTION rows 20-21 -> 0-based 19-20; label sits above
    }

    // ---- 5. both charsets, and the 80x24 fit -------------------------------

    public function testRendersThemedInBothCharsetsAt80x24(): void
    {
        foreach (['utf8', 'cp437'] as $charset) {
            $grid = $this->messagesScreen(new NewscanPlan([1], $this->areas(), 1, false), 0, $charset);
            self::assertCount(24, $grid);
            foreach ($grid as $line) {
                self::assertLessThanOrEqual(80, mb_strlen($line), $charset);
            }
            self::assertStringContainsString('L33TEST', implode("\n", $grid));
            self::assertStringContainsString('MESSAGES', implode("\n", $grid));
        }
    }

    public function testWrongGeometryFallsBackToTheFlowingRenderer(): void
    {
        $builder  = $this->builder(NewscanPlan::empty());
        $renderer = new ThemedNavigationRenderer(new NavigationScreenRenderer(), $this->theme());
        $path     = NavigationPath::root('root', 'L33TEST')->push('messages', 'Messages');
        $screen   = $builder->build($this->definition(), $this->access(), $path);
        $h        = TerminalRenderHarness::at(100, 30);
        $renderer->render($h->context(), $screen, ['cursor' => 0]);
        self::assertSame('fallback', $renderer->lastReport()['mode']);
        self::assertStringContainsString('[A]', $h->bytes(), 'navigation is never stranded');
    }

    public function testMonoOrCharsetlessTerminalFallsBack(): void
    {
        $builder  = $this->builder(NewscanPlan::empty());
        $renderer = new ThemedNavigationRenderer(new NavigationScreenRenderer(), $this->theme());
        $path     = NavigationPath::root('root', 'L33TEST')->push('messages', 'Messages');
        $screen   = $builder->build($this->definition(), $this->access(), $path);
        $h        = TerminalRenderHarness::at(80, 24)->mono();
        $renderer->render($h->context(), $screen, ['cursor' => 0]);
        self::assertSame('fallback', $renderer->lastReport()['mode']);
    }

    // ---- 6. write-free + no mutation --------------------------------------

    public function testRenderingIsWriteFreeAndDoesNotMutateTheScreen(): void
    {
        $plan = new NewscanPlan([1, 2], $this->areas(), 1, false);
        $planSerialized = serialize($plan);
        $builder  = $this->builder($plan);
        $renderer = new ThemedNavigationRenderer(new NavigationScreenRenderer(), $this->theme());
        $path     = NavigationPath::root('root', 'L33TEST')->push('messages', 'Messages');
        $screen   = $builder->build($this->definition(), $this->access(), $path);
        $before   = serialize($screen);
        $h        = TerminalRenderHarness::at(80, 24);
        $renderer->render($h->context(), $screen, ['cursor' => 0]);
        self::assertSame($before, serialize($screen), 'render must not mutate the model');
        self::assertSame($planSerialized, serialize($plan), 'projection must not mutate the plan');
    }

    // ---- 7. runtime still owns hotkeys / actions / access ------------------

    public function testRuntimeKeepsHotkeysActionsAndAccessOnTheThemedMessagesNode(): void
    {
        $registry = TerminalActionCatalog::defaultRegistry();
        $invoked = [];
        foreach ($registry->ids() as $id) {
            $registry->bind($id, static function () use (&$invoked, $id) { $invoked[] = $id; });
        }
        $builder = new NavigationScreenBuilder(
            $registry,
            fn (?string $key, string $fallback, string $locale) => $fallback,
            static fn (string $s): ?string => null,
            null,
            static fn (string $n, string $l): ?array => $n === 'messages' ? ['WAITING FOR YOU', ''] : null,
        );
        $renderer = new ThemedNavigationRenderer(new NavigationScreenRenderer(), $this->theme());
        $runtime  = new NavigationRuntime($this->definition(), $registry, $builder, $renderer);
        $modes = [];
        $keys  = ['CHAR:m', 'CHAR:e', 'CHAR:n', 'LEFT', 'CHAR:p', 'LEFT'];
        $exit  = $runtime->run(
            static function () use (&$keys) { return [array_shift($keys) ?? 'ESC', false, false]; },
            TerminalRenderHarness::at(80, 24)->context(),
            $this->access(),
            null,
            'en',
            static function () use (&$modes, $renderer) { $modes[] = $renderer->lastReport()['mode']; },
            maxIterations: 12,
        );
        self::assertContains('themed', $modes, 'the messages node rendered themed at least once');
        self::assertSame(['echomail', 'netmail'], array_values(array_intersect(['echomail', 'netmail'], $invoked)));
        self::assertContains($exit, [NavigationRuntime::EXIT_BACK_ROOT, NavigationRuntime::EXIT_QUIT]);
    }

    // ---- 8. NewscanSnapshot: cursor movement vs invalidation --------------

    public function testSnapshotRecomputesOnlyOnInvalidationOrTtlNotOnRepeatedReads(): void
    {
        $calls = 0;
        $now = 1000;
        $snap = new NewscanSnapshot(
            static function () use (&$calls) { $calls++; return NewscanPlan::empty(); },
            90,
            static function () use (&$now) { return $now; },
        );

        $snap->plan();
        $snap->plan();
        $snap->plan(); // repeated reads (cursor movement) — one computation
        self::assertSame(1, $calls);
        self::assertSame(1, $snap->generation());

        $snap->invalidate();           // action boundary
        $snap->plan();
        self::assertSame(2, $calls);
        self::assertSame(2, $snap->generation());

        $now += 89;
        $snap->plan();
        self::assertSame(2, $calls, 'still within the TTL backstop');

        $now += 1;
        $snap->plan();
        self::assertSame(3, $calls, 'TTL backstop expired');
    }

    // ---- 9. projection unit coverage ------------------------------------

    public function testProjectionShape(): void
    {
        $t = $this->tr();

        $empty = TerminalNewscanLanding::project(NewscanPlan::empty(), $t);
        self::assertSame(["You're all caught up.", ''], $empty['summary']);
        self::assertSame([], $empty['badges']);

        $mixed = TerminalNewscanLanding::project(new NewscanPlan([1], $this->areas(), 2, false), $t);
        self::assertSame(
            "1 unread netmail \u{00B7} 5 new echomail in 2 area(s) \u{00B7} 2 unread bulletin(s)",
            $mixed['summary'][0]
        );
        self::assertSame('', $mixed['summary'][1]);
        self::assertSame([
            TerminalNewscanLanding::BADGE_NETMAIL   => '1 unread',
            TerminalNewscanLanding::BADGE_ECHOMAIL  => '5 new, 2 area(s)',
            TerminalNewscanLanding::BADGE_BULLETINS => '2 new',
        ], $mixed['badges']);

        $trunc = TerminalNewscanLanding::project(new NewscanPlan([1], [], 0, true), $t);
        self::assertStringContainsString('beyond the scan limit', $trunc['summary'][1]);
    }
}
