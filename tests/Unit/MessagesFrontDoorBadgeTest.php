<?php

declare(strict_types=1);

use BinktermPHP\Newscan\NewscanArea;
use BinktermPHP\Newscan\NewscanPlan;
use BinktermPHP\Newscan\TerminalNewscanLanding;
use BinktermPHP\Terminal\Navigation\AccessContext;
use BinktermPHP\Terminal\Navigation\AnsiScreenBuffer;
use BinktermPHP\Terminal\Navigation\NavigationDefinition;
use BinktermPHP\Terminal\Navigation\NavigationPath;
use BinktermPHP\Terminal\Navigation\NavigationScreenBuilder;
use BinktermPHP\Terminal\Navigation\NavigationScreenRenderer;
use BinktermPHP\Terminal\Navigation\NavigationThemeLoader;
use BinktermPHP\Terminal\Navigation\TerminalActionCatalog;
use BinktermPHP\Terminal\Navigation\ThemedNavigationRenderer;
use BinktermPHP\Tests\Support\TerminalRenderHarness;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/TerminalRenderHarness.php';

/**
 * Front Door `[M] Messages` inline message-activity signal
 * (`messages.summary_badge` + `presentation.badge_inline`).
 *
 * Three honest positive states, never merging public echomail volume into a
 * personal "waiting" figure; the zero state carries NO annotation at all (a
 * permanent "All caught up" was tried and rejected as clutter in human
 * acceptance testing). Unlike Crossroads/People, this one root item is flagged
 * `badge_inline: true` so its badge renders on its own menu row instead of
 * being swept into the ambient "AROUND THE BOARD" activity line — proven end
 * to end here through the real {@see NavigationScreenRenderer}, not just the
 * projection. No new database access is added — everything is fed from an
 * in-memory {@see NewscanPlan}.
 */
final class MessagesFrontDoorBadgeTest extends TestCase
{
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

    private function areas(): array
    {
        return [
            new NewscanArea(1, 'FIDO.GENERAL', '', 'General chatter', [1, 2, 3]),
            new NewscanArea(2, 'LINUX', '', 'Linux talk', [10, 11]),
        ];
    }

    private function badges(NewscanPlan $plan): array
    {
        return TerminalNewscanLanding::project($plan, $this->tr())['badges'];
    }

    /** A real front-door screen: crossroads/messages/people, badge resolver wired
     *  exactly as DeclarativeMenuBridge wires it. */
    private function frontDoorScreen(NewscanPlan $plan): \BinktermPHP\Terminal\Navigation\NavigationScreenModel
    {
        $t = $this->tr();
        $badgeResolver = static function (string $signal) use ($plan, $t): ?string {
            if (str_starts_with($signal, 'messages.')) {
                return TerminalNewscanLanding::project($plan, $t)['badges'][$signal] ?? null;
            }

            return match ($signal) {
                'experiences_active' => '2 playing',
                'callers_online'     => '3 online',
                default              => null,
            };
        };

        $builder = new NavigationScreenBuilder(
            TerminalActionCatalog::defaultRegistry(),
            fn (?string $key, string $fallback, string $locale) => $fallback,
            $badgeResolver,
        );
        $def = NavigationDefinition::fromArray([
            'schema' => 1, 'id' => 't', 'root' => 'root', 'nodes' => [
                ['id' => 'root', 'label_fallback' => 'L33TEST', 'items' => [
                    ['id' => 'crossroads', 'label_fallback' => 'Crossroads', 'hotkey' => 'c', 'action' => 'doors',
                     'presentation' => ['badge' => 'experiences_active']],
                    ['id' => 'messages', 'label_fallback' => 'Messages', 'hotkey' => 'm', 'submenu' => 'messages',
                     'description_fallback' => 'Echomail, netmail & bulletins',
                     'presentation' => [
                         'group' => 'Connect',
                         'badge' => TerminalNewscanLanding::BADGE_SUMMARY,
                         'badge_inline' => true,
                     ]],
                    ['id' => 'people', 'label_fallback' => 'People', 'hotkey' => 'p', 'action' => 'whosonline',
                     'presentation' => ['badge' => 'callers_online']],
                ]],
                ['id' => 'messages', 'label_fallback' => 'Messages', 'items' => [
                    ['id' => 'netmail', 'label_fallback' => 'Netmail', 'hotkey' => 'n', 'action' => 'netmail'],
                ]],
            ],
        ]);
        $access = new AccessContext(true, false, false, fn () => true, fn () => true, ['color' => true]);

        return $builder->build($def, $access, NavigationPath::root('root', 'L33TEST'));
    }

    private function grid(string $bytes): array
    {
        return array_map(
            fn ($line) => preg_replace('/\x1b\[[0-9;]*m/', '', $line),
            (new AnsiScreenBuffer(80, 24))->write($bytes)->toLines()
        );
    }

    private function theme(): \BinktermPHP\Terminal\Navigation\NavigationTheme
    {
        $r = (new NavigationThemeLoader())->fromFile(
            dirname(__DIR__, 2) . '/config/terminal_theme_m2_messages.json.example'
        );
        self::assertTrue($r->isOk(), $r->errorSummary());

        return $r->theme();
    }

    /**
     * Renders the REAL Front Door screen through composeSemanticRegions()
     * (via ThemedNavigationRenderer) — the exact schema-2 code path live at
     * 80x24 today, where MENU annotations are gated by $withAnnotations OR
     * $it->annotationInline and Crossroads/People sweep into STATUS
     * (`ambientActivityLine()`, the "AROUND THE BOARD" region).
     *
     * @return array{menu:string,status:string}
     */
    private function renderRoot(\BinktermPHP\Terminal\Navigation\NavigationScreenModel $screen): array
    {
        $renderer = new ThemedNavigationRenderer(new NavigationScreenRenderer(), $this->theme());
        $h = TerminalRenderHarness::at(80, 24);
        $renderer->render($h->context(), $screen, ['cursor' => 0]);
        self::assertSame('themed', $renderer->lastReport()['mode'], $renderer->lastReport()['reason'] ?? '');
        $grid = $this->grid($h->bytes());

        // Geometry (config/terminal_theme_m2_messages.json.example, 80x24):
        // STATUS rows 5-6 (0-based 4-5), MENU rows 8-19 (0-based 7-18).
        return [
            'menu'   => implode("\n", array_slice($grid, 7, 12)),
            'status' => implode("\n", array_slice($grid, 4, 2)),
        ];
    }

    // ---- 1. zero state: no annotation anywhere -------------------------------

    public function testZeroStateHasNoMessagesAnnotationOnTheRowOrInAmbient(): void
    {
        $badges = $this->badges(NewscanPlan::empty());
        self::assertArrayNotHasKey(TerminalNewscanLanding::BADGE_SUMMARY, $badges);

        $rendered = $this->renderRoot($this->frontDoorScreen(NewscanPlan::empty()));
        self::assertStringContainsString('MESSAGES', $rendered['menu']);
        self::assertStringNotContainsString('waiting', $rendered['menu']);
        self::assertStringNotContainsString('new echo', $rendered['menu']);
        self::assertStringNotContainsString('caught up', $rendered['status']);
        self::assertStringNotContainsString('Messages', $rendered['status'], 'no Messages line in the ambient footer');
    }

    // ---- 2/3/4. the three positive states render INLINE on the menu row -----

    public function testWaitingAndEchoRenderInlineOnTheMessagesRow(): void
    {
        // 1 netmail + 2 bulletins = 3 waiting; 5 echomail across 2 areas.
        $plan = new NewscanPlan([1], $this->areas(), 2, false);
        self::assertSame("3 waiting \u{00B7} 5 new echo", $this->badges($plan)[TerminalNewscanLanding::BADGE_SUMMARY]);

        $rendered = $this->renderRoot($this->frontDoorScreen($plan));
        self::assertMatchesRegularExpression('/MESSAGES.*3 waiting.*5 new echo/', $rendered['menu']);
        self::assertStringNotContainsString('waiting', $rendered['status'], 'not duplicated into the ambient line');
        $this->assertAnnotationIsAdjacentNotRightAligned($rendered['menu'], '3 waiting');
    }

    /**
     * The annotation must read as metadata belonging to the Messages label —
     * a small fixed gap immediately after it — never a right-aligned second
     * column filled out to the row's edge (the human-observed regression this
     * corrects: "2263 new echo" landing far from "MESSAGES").
     */
    private function assertAnnotationIsAdjacentNotRightAligned(string $menu, string $needle): void
    {
        $line = null;
        foreach (explode("\n", $menu) as $l) {
            if (str_contains($l, 'MESSAGES')) {
                $line = $l;
            }
        }
        self::assertNotNull($line, 'the MESSAGES row must be present');
        $pos = strpos($line, $needle);
        self::assertNotFalse($pos, 'the annotation must be on the MESSAGES row');
        $between = substr($line, strpos($line, 'MESSAGES') + strlen('MESSAGES'), $pos - (strpos($line, 'MESSAGES') + strlen('MESSAGES')));
        // A handful of characters (glyph + a couple of spaces + the middot
        // marker), never dozens of blank columns pushing it to the row edge.
        self::assertLessThanOrEqual(8, mb_strlen($between, 'UTF-8'), 'gap between label and annotation: "' . $between . '"');
    }

    public function testWaitingOnlyRendersInlineOnTheMessagesRow(): void
    {
        $plan = new NewscanPlan([1, 2], [], 1, false); // 3 waiting, no echo
        self::assertSame('3 waiting', $this->badges($plan)[TerminalNewscanLanding::BADGE_SUMMARY]);

        $rendered = $this->renderRoot($this->frontDoorScreen($plan));
        self::assertMatchesRegularExpression('/MESSAGES.*3 waiting/', $rendered['menu']);
        self::assertStringNotContainsString('waiting', $rendered['status']);
    }

    public function testEchoOnlyRendersInlineOnTheMessagesRow(): void
    {
        $plan = new NewscanPlan([], $this->areas(), 0, false); // 5 new echo, no waiting
        self::assertSame('5 new echo', $this->badges($plan)[TerminalNewscanLanding::BADGE_SUMMARY]);

        $rendered = $this->renderRoot($this->frontDoorScreen($plan));
        self::assertMatchesRegularExpression('/MESSAGES.*5 new echo/', $rendered['menu']);
        self::assertStringNotContainsString('new echo', $rendered['status']);
    }

    // ---- 5. Messages status never appears in the ambient footer -------------

    public function testMessagesStatusNeverAppearsInTheAmbientFooterAtAnyState(): void
    {
        foreach ([
            NewscanPlan::empty(),
            new NewscanPlan([1], [], 0, false),
            new NewscanPlan([], $this->areas(), 0, false),
            new NewscanPlan([1], $this->areas(), 2, false),
        ] as $plan) {
            $rendered = $this->renderRoot($this->frontDoorScreen($plan));
            self::assertStringNotContainsString('Messages', $rendered['status']);
        }
    }

    // ---- 6/7. Crossroads / People ambient behaviour is unaffected ------------

    public function testCrossroadsAmbientBehaviourIsUnaffected(): void
    {
        $rendered = $this->renderRoot($this->frontDoorScreen(new NewscanPlan([1], $this->areas(), 2, false)));
        self::assertStringContainsString('Crossroads 2 playing', $rendered['status']);
        self::assertStringNotContainsString('2 playing', $rendered['menu'], 'Crossroads stays ambient-only, not inline');
    }

    public function testPeopleAmbientBehaviourIsUnaffected(): void
    {
        $rendered = $this->renderRoot($this->frontDoorScreen(new NewscanPlan([1], $this->areas(), 2, false)));
        self::assertStringContainsString('People 3 online', $rendered['status']);
        self::assertStringNotContainsString('3 online', $rendered['menu'], 'People stays ambient-only, not inline');
    }

    // ---- composition: what contributes to which half ------------------------

    public function testNetmailContributesToWaitingNotEcho(): void
    {
        self::assertSame('3 waiting', $this->badges(new NewscanPlan([1, 2, 3], [], 0, false))[TerminalNewscanLanding::BADGE_SUMMARY]);
    }

    public function testBulletinsContributeToWaitingNotEcho(): void
    {
        self::assertSame('4 waiting', $this->badges(new NewscanPlan([], [], 4, false))[TerminalNewscanLanding::BADGE_SUMMARY]);
    }

    public function testEchomailContributesOnlyToNewEchoNeverToWaiting(): void
    {
        $signal = $this->badges(new NewscanPlan([], $this->areas(), 0, false))[TerminalNewscanLanding::BADGE_SUMMARY];
        self::assertStringContainsString('new echo', $signal);
        self::assertStringNotContainsString('waiting', $signal);
    }

    public function testEchomailAreaCountIsNeverSummedIntoNewEcho(): void
    {
        $areas = [
            new NewscanArea(1, 'A', '', '', [1]),
            new NewscanArea(2, 'B', '', '', [2, 3]),
            new NewscanArea(3, 'C', '', '', [4]),
        ];
        // Would be "7 new echo" (4 messages + 3 areas) if the area count leaked in.
        self::assertSame('4 new echo', $this->badges(new NewscanPlan([], $areas, 0, false))[TerminalNewscanLanding::BADGE_SUMMARY]);
    }

    // ---- 8. existing detailed Messages-landing badges are unchanged ---------

    public function testExistingPerDestinationBadgesAreUnchangedByTheNewSignal(): void
    {
        $badges = $this->badges(new NewscanPlan([1], $this->areas(), 2, false));
        self::assertSame('1 unread', $badges[TerminalNewscanLanding::BADGE_NETMAIL]);
        self::assertSame('5 new, 2 area(s)', $badges[TerminalNewscanLanding::BADGE_ECHOMAIL]);
        self::assertSame('2 new', $badges[TerminalNewscanLanding::BADGE_BULLETINS]);
    }

    // ---- 9. rendering the signal performs no extra DB/service call ----------

    public function testRenderingTheFrontDoorSignalIsAPureFormattingStepWithNoQuery(): void
    {
        $ref = new ReflectionMethod(TerminalNewscanLanding::class, 'project');
        self::assertTrue($ref->isStatic());
        self::assertCount(2, $ref->getParameters());
        self::assertSame('plan', $ref->getParameters()[0]->getName());
        self::assertSame(NewscanPlan::class, (string) $ref->getParameters()[0]->getType());
    }

    // ---- 10. root menu order/hotkeys unchanged -------------------------------

    public function testRootOrderAndHotkeysAreUnchangedByTheInlineFlag(): void
    {
        $screen = $this->frontDoorScreen(new NewscanPlan([1], $this->areas(), 2, false));
        $ids = array_map(static fn ($it) => [$it->id, $it->hotkey], $screen->selectableItems());
        self::assertSame([
            ['crossroads', 'c'],
            ['messages', 'm'],
            ['people', 'p'],
        ], $ids);
    }

    // ---- 11. no duplicate Messages status anywhere on the Front Door --------

    public function testNoDuplicateMessagesStatusAnywhereOnTheFrontDoor(): void
    {
        $rendered = $this->renderRoot($this->frontDoorScreen(new NewscanPlan([1], $this->areas(), 2, false)));
        $wholeScreen = $rendered['menu'] . "\n" . $rendered['status'];
        self::assertSame(1, substr_count($wholeScreen, '3 waiting'), 'the figure appears exactly once');
        self::assertSame(1, substr_count($wholeScreen, '5 new echo'), 'the figure appears exactly once');
    }

    // ---- 12. an oversized annotation is clipped, never overflows/wraps -----

    public function testAnOversizedAnnotationAtEightyColumnsIsClippedNotOverflowed(): void
    {
        // A huge personal-waiting figure (the human-observed "2263" case) must
        // never push the row past 80 visible columns, wrap, or scroll.
        $plan = new NewscanPlan(array_fill(0, 5000, 1), $this->areas(), 99999, false);
        $rendered = $this->renderRoot($this->frontDoorScreen($plan));
        foreach (explode("\n", $rendered['menu']) as $line) {
            self::assertLessThanOrEqual(80, mb_strlen($line, 'UTF-8'), 'row: "' . $line . '"');
        }
        self::assertStringContainsString('MESSAGES', $rendered['menu']);
    }
}
