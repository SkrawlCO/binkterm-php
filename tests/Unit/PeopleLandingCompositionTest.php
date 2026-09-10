<?php

declare(strict_types=1);

use BinktermPHP\Terminal\Navigation\AccessContext;
use BinktermPHP\Terminal\Navigation\AnsiScreenBuffer;
use BinktermPHP\Terminal\Navigation\NavigationDefinition;
use BinktermPHP\Terminal\Navigation\NavigationPath;
use BinktermPHP\Terminal\Navigation\NavigationRuntime;
use BinktermPHP\Terminal\Navigation\NavigationScreenBuilder;
use BinktermPHP\Terminal\Navigation\NavigationScreenRenderer;
use BinktermPHP\Terminal\Navigation\NavigationTheme;
use BinktermPHP\Terminal\Navigation\NavigationThemeLoader;
use BinktermPHP\Terminal\Navigation\TerminalActionCatalog;
use BinktermPHP\Terminal\Navigation\ThemedNavigationRenderer;
use BinktermPHP\Terminal\People\PeopleLanding;
use BinktermPHP\Terminal\People\PeopleRosterSnapshot;
use BinktermPHP\Tests\Support\TerminalRenderHarness;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/TerminalRenderHarness.php';

/**
 * People M2 Slice 1 — the authored "who's here / who was" landing.
 *
 * Off-session: a fabricated presence roster + Recent Callers line are fed
 * through the real projection ({@see PeopleLanding}) and the real builder /
 * renderer / theme, exactly as {@see \BinktermPHP\TelnetServer\DeclarativeMenuBridge}
 * wires them live. No database, no BbsSession, no writes.
 */
final class PeopleLandingCompositionTest extends TestCase
{
    /** The shipped example: front door + authored `messages` and `people` nodes. */
    private function theme(): NavigationTheme
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
            'schema' => 1, 'id' => 'people.landing.test', 'root' => 'root', 'nodes' => [
                ['id' => 'root', 'label_fallback' => 'L33TEST', 'items' => [
                    ['id' => 'people', 'label_fallback' => 'People', 'hotkey' => 'p', 'submenu' => 'people',
                     'description_fallback' => "Who's here and the conversation",
                     'presentation' => ['badge' => 'callers_online']],
                    ['id' => 'messages', 'label_fallback' => 'Messages', 'hotkey' => 'm', 'action' => 'newscan'],
                ]],
                ['id' => 'people', 'label_fallback' => 'People',
                 'description_fallback' => "Who's around, and how to reach them.", 'items' => [
                    ['id' => 'whosonline', 'label_fallback' => "Who's Online", 'hotkey' => 'w', 'action' => 'whosonline',
                     'description_fallback' => 'Callers connected right now.'],
                    ['id' => 'localchat', 'label_fallback' => 'Local Chat', 'hotkey' => 'c', 'action' => 'localchat',
                     'description_fallback' => 'Real-time rooms and direct messages.'],
                    ['id' => 'shoutbox', 'label_fallback' => 'Shoutbox', 'hotkey' => 's', 'action' => 'shoutbox',
                     'description_fallback' => 'A short public wall for quick notes.'],
                    ['id' => 'polls', 'label_fallback' => 'Polls', 'hotkey' => 'o', 'action' => 'polls',
                     'description_fallback' => 'Vote and see what the board thinks.'],
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

    private function access(): AccessContext
    {
        return new AccessContext(true, false, false, fn () => true, fn () => true, ['color' => true]);
    }

    /**
     * A builder wired the way the bridge wires it: the `people` STATUS is the
     * real projection of the given roster + recent line.
     *
     * @param array<int,array{name:string,activity?:string}> $roster
     */
    private function builder(array $roster, ?string $recentLine): NavigationScreenBuilder
    {
        $t = $this->tr();

        return new NavigationScreenBuilder(
            TerminalActionCatalog::defaultRegistry(),
            fn (?string $key, string $fallback, string $locale) => $fallback,
            static fn (string $signal): ?string => $signal === 'callers_online' ? (count($roster) . ' online') : null,
            static fn (string $locale): ?string => 'Recent callers: ambient',
            static fn (string $nodeId, string $locale): ?array => $nodeId === 'people'
                ? PeopleLanding::project($roster, $recentLine, $t)
                : null,
        );
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

    /**
     * @param array<int,array{name:string,activity?:string}> $roster
     */
    private function peopleScreen(array $roster, ?string $recentLine, int $cursor = 0, string $charset = 'utf8'): array
    {
        $builder  = $this->builder($roster, $recentLine);
        $renderer = new ThemedNavigationRenderer(new NavigationScreenRenderer(), $this->theme());
        $path     = NavigationPath::root('root', 'L33TEST')->push('people', 'People');
        $screen   = $builder->build($this->definition(), $this->access(), $path);
        $h        = TerminalRenderHarness::at(80, 24)->charset($charset);
        $renderer->render($h->context(), $screen, ['cursor' => $cursor]);
        self::assertSame('themed', $renderer->lastReport()['mode'], $renderer->lastReport()['reason'] ?? '');

        return $this->grid($h->bytes(), $charset);
    }

    // ---- 1. the shipped theme authors the people node at 80x24 --------------

    public function testShippedExampleAuthorsThePeopleNodeAt80x24(): void
    {
        $t = $this->theme();
        self::assertTrue($t->themesNode('people'));
        self::assertSame('nav-people-m2', $t->geometryForScreen('people', false, 80, 24)?->templateToken);
        self::assertNull($t->geometryForScreen('people', false, 132, 24));
        self::assertSame('nav-frontdoor-m2', $t->geometryForScreen('root', true, 80, 24)?->templateToken);
        // Messages stays authored alongside it; an un-named node still flows.
        self::assertTrue($t->themesNode('messages'));
        self::assertFalse($t->themesNode('settings'));
    }

    // ---- 2. live state renders honestly ------------------------------------

    public function testLiveCallersRenderWithNameAndPublicActivity(): void
    {
        $grid = $this->peopleScreen(
            [
                ['name' => 'Skrawl', 'activity' => 'in Crossroads'],
                ['name' => 'vixen', 'activity' => ''],
            ],
            'Recent Callers: bandit (2h ago)'
        );
        $joined = implode("\n", $grid);
        self::assertStringContainsString('AROUND THE BOARD', $joined);
        self::assertStringContainsString('Online now: Skrawl (in Crossroads), vixen', $joined);
    }

    public function testManyCallersAreBoundedWithAnOverflowCount(): void
    {
        $roster = [];
        foreach (['ann', 'bob', 'cy', 'dee', 'evan'] as $n) {
            $roster[] = ['name' => $n, 'activity' => ''];
        }
        $line = PeopleLanding::project($roster, null, $this->tr())[0];
        self::assertStringContainsString('Online now: ann, bob, cy', $line);
        self::assertStringContainsString('(+2 more)', $line);
    }

    // ---- 3. internal / admin-only session fields never leak ----------------

    public function testProjectionOnlyReadsNameAndActivity(): void
    {
        // fromSessions() is the sole reducer of raw presence rows; it must keep
        // only the two caller-visible fields.
        $rows = [
            ['user_id' => 7, 'username' => 'Skrawl', 'public_activity' => 'in Crossroads',
             'activity' => 'reading netmail 42', 'service' => 'ssh', 'ip_address' => '198.51.100.9',
             'real_name' => 'S. Krawl', 'fidonet_address' => '999:1/1', 'last_activity' => '2026-09-10 10:00:00'],
        ];
        $roster = PeopleLanding::fromSessions($rows, 0);
        self::assertSame([['name' => 'Skrawl', 'activity' => 'in Crossroads']], $roster);

        $line = implode("\n", PeopleLanding::project($roster, null, $this->tr()));
        foreach (['reading netmail 42', 'ssh', '198.51.100.9', 'S. Krawl', '999:1/1', '10:00:00'] as $secret) {
            self::assertStringNotContainsString($secret, $line);
        }
    }

    public function testViewerOwnSessionsAndDuplicatesAreExcluded(): void
    {
        $rows = [
            ['user_id' => 1, 'username' => 'me',     'public_activity' => ''],
            ['user_id' => 2, 'username' => 'other',  'public_activity' => 'idle'],
            ['user_id' => 2, 'username' => 'other',  'public_activity' => ''], // second session, same user
            ['user_id' => 0, 'username' => 'ghost',  'public_activity' => ''], // no id
        ];
        self::assertSame([['name' => 'other', 'activity' => 'idle']], PeopleLanding::fromSessions($rows, 1));
    }

    // ---- 4. quiet state is honest ----------------------------------------

    public function testQuietBoardSaysSoPlainly(): void
    {
        $joined = implode("\n", $this->peopleScreen([], 'Recent Callers: bandit (2h ago)'));
        self::assertStringContainsString('The board is quiet right now.', $joined);
        self::assertStringNotContainsString('Online now', $joined);
    }

    public function testQuietBoardWithNoHistoryIsStillHonest(): void
    {
        [$live, $recent] = PeopleLanding::project([], null, $this->tr());
        self::assertSame('The board is quiet right now.', $live);
        self::assertSame('', $recent, 'no fabricated liveliness when there is no history');
    }

    // ---- 5. live and recent are never blurred ----------------------------

    public function testRecentCallersLineIsHistoricalAndDistinctFromLive(): void
    {
        [$live, $recent] = PeopleLanding::project(
            [['name' => 'Skrawl', 'activity' => '']],
            'Recent Callers: bandit (2h ago)',
            $this->tr()
        );
        self::assertSame('Online now: Skrawl', $live);
        self::assertSame('Recent Callers: bandit (2h ago)', $recent);
        // The recent line is passed straight through — it is not re-derived from
        // the live roster and never implies bandit is still on.
        self::assertStringNotContainsString('Skrawl', $recent);
    }

    public function testProjectionIsAlwaysExactlyTwoLines(): void
    {
        foreach ([[], [['name' => 'a', 'activity' => '']]] as $roster) {
            foreach ([null, 'Recent Callers: x (1h ago)'] as $recent) {
                $out = PeopleLanding::project($roster, $recent, $this->tr());
                self::assertCount(2, $out);
                self::assertContainsOnly('string', $out);
            }
        }
    }

    // ---- 6. width / charset / fit --------------------------------------

    public function testRendersThemedInBothCharsetsAt80x24(): void
    {
        foreach (['utf8', 'cp437'] as $charset) {
            $grid = $this->peopleScreen(
                [['name' => 'Skrawl', 'activity' => 'in Crossroads']],
                'Recent Callers: bandit (2h ago)',
                0,
                $charset
            );
            self::assertCount(24, $grid);
            foreach ($grid as $line) {
                self::assertLessThanOrEqual(80, mb_strlen($line), $charset);
            }
            $joined = implode("\n", $grid);
            self::assertStringContainsString('L33TEST', $joined);
            self::assertStringContainsString('PEOPLE', $joined);
        }
    }

    public function testLongRosterLineIsClippedToTheStatusWidth(): void
    {
        $roster = [['name' => str_repeat('Xael', 12), 'activity' => str_repeat('deep in the arena ', 4)]];
        $line = PeopleLanding::project($roster, null, $this->tr(), 72)[0];
        self::assertLessThanOrEqual(72, mb_strlen($line, 'UTF-8'));
    }

    // ---- 7. fallback is first-class ------------------------------------

    public function testWrongGeometryFallsBackToTheFlowingRenderer(): void
    {
        $builder  = $this->builder([['name' => 'a', 'activity' => '']], null);
        $renderer = new ThemedNavigationRenderer(new NavigationScreenRenderer(), $this->theme());
        $path     = NavigationPath::root('root', 'L33TEST')->push('people', 'People');
        $screen   = $builder->build($this->definition(), $this->access(), $path);
        $h        = TerminalRenderHarness::at(100, 30);
        $renderer->render($h->context(), $screen, ['cursor' => 0]);
        self::assertSame('fallback', $renderer->lastReport()['mode']);
        self::assertStringContainsString('[W]', $h->bytes(), 'navigation is never stranded');
    }

    public function testMonoTerminalFallsBackButKeepsNavigation(): void
    {
        $builder  = $this->builder([], null);
        $renderer = new ThemedNavigationRenderer(new NavigationScreenRenderer(), $this->theme());
        $path     = NavigationPath::root('root', 'L33TEST')->push('people', 'People');
        $screen   = $builder->build($this->definition(), $this->access(), $path);
        $h        = TerminalRenderHarness::at(80, 24)->mono();
        $renderer->render($h->context(), $screen, ['cursor' => 0]);
        self::assertSame('fallback', $renderer->lastReport()['mode']);
        self::assertStringContainsString("WHO'S ONLINE", strtoupper($h->bytes()));
    }

    // ---- 8. existing child actions untouched ---------------------------

    public function testRuntimeKeepsHotkeysActionsAndAccessOnTheThemedPeopleNode(): void
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
            static fn (string $n, string $l): ?array => $n === 'people' ? ['The board is quiet right now.', ''] : null,
        );
        $renderer = new ThemedNavigationRenderer(new NavigationScreenRenderer(), $this->theme());
        $runtime  = new NavigationRuntime($this->definition(), $registry, $builder, $renderer);
        $modes = [];
        $keys  = ['CHAR:p', 'CHAR:w', 'LEFT', 'CHAR:p', 'CHAR:s', 'LEFT', 'LEFT'];
        $exit  = $runtime->run(
            static function () use (&$keys) { return [array_shift($keys) ?? 'ESC', false, false]; },
            TerminalRenderHarness::at(80, 24)->context(),
            $this->access(),
            null,
            'en',
            static function () use (&$modes, $renderer) { $modes[] = $renderer->lastReport()['mode']; },
            maxIterations: 14,
        );
        self::assertContains('themed', $modes, 'the people node rendered themed at least once');
        self::assertSame(
            ['whosonline', 'shoutbox'],
            array_values(array_intersect(['whosonline', 'shoutbox'], $invoked))
        );
        self::assertContains($exit, [NavigationRuntime::EXIT_BACK_ROOT, NavigationRuntime::EXIT_QUIT]);
    }

    // ---- 9. render is write-free / no mutation -------------------------

    public function testRenderingIsWriteFreeAndDoesNotMutateInputs(): void
    {
        $roster = [['name' => 'Skrawl', 'activity' => 'in Crossroads']];
        $rosterSerialized = serialize($roster);
        $builder  = $this->builder($roster, 'Recent Callers: bandit (2h ago)');
        $renderer = new ThemedNavigationRenderer(new NavigationScreenRenderer(), $this->theme());
        $path     = NavigationPath::root('root', 'L33TEST')->push('people', 'People');
        $screen   = $builder->build($this->definition(), $this->access(), $path);
        $before   = serialize($screen);
        $h        = TerminalRenderHarness::at(80, 24);
        $renderer->render($h->context(), $screen, ['cursor' => 0]);
        self::assertSame($before, serialize($screen), 'render must not mutate the model');
        self::assertSame($rosterSerialized, serialize($roster), 'projection must not mutate the roster');
    }

    // ---- 10. snapshot: cursor movement vs invalidation ----------------

    public function testSnapshotRecomputesOnlyOnInvalidationOrTtlNotOnRepeatedReads(): void
    {
        $calls = 0;
        $now = 1000;
        $snap = new PeopleRosterSnapshot(
            static function () use (&$calls) {
                $calls++;

                return ['roster' => [], 'recentLine' => null];
            },
            10,
            static function () use (&$now) { return $now; },
        );

        $snap->value();
        $snap->value();
        $snap->value(); // repeated reads (cursor movement) — one query
        self::assertSame(1, $calls);
        self::assertSame(1, $snap->generation());

        $snap->invalidate();           // action boundary (returned from Who's Online)
        $snap->value();
        self::assertSame(2, $calls);
        self::assertSame(2, $snap->generation());

        $now += 9;
        $snap->value();
        self::assertSame(2, $calls, 'still within the TTL backstop');

        $now += 1;
        $snap->value();
        self::assertSame(3, $calls, 'TTL backstop expired');
    }

}
