<?php

declare(strict_types=1);

use BinktermPHP\ExperiencePresentation;
use BinktermPHP\TelnetServer\DoorHandler;
use BinktermPHP\TelnetServer\TerminalShellInterface;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../telnet/src/TelnetUtils.php';
require_once __DIR__ . '/../../telnet/src/TerminalShellInterface.php';
require_once __DIR__ . '/../../telnet/src/DoorHandler.php';

/**
 * Telnet Crossroads Slice 1 — Experience Detail Foundation.
 *
 * The catalog journey is now:
 *   catalog -> detail -> Play/Return -> door -> detail -> Back -> catalog
 *
 * These tests pin:
 *   - the detail view is composed from the shared Crossroads read models
 *     (ExperiencePresentation / ExperienceState / ExperienceActivity), not from
 *     independently derived business state;
 *   - occupancy, roster, capacity, cost, and recent activity are represented;
 *   - Play/Return is offered exactly per the normalized viewer contract;
 *   - the detail loop routes selection through the detail screen, launches via
 *     the injected launch path, returns to the same detail screen afterwards,
 *     and Back exits to the catalog without launching.
 */
final class DoorHandlerExperienceDetailTest extends TestCase
{
    /** A fake translator: substitute {params} into the English fallback. */
    private static function translator(): callable
    {
        return static function (string $key, array $params = [], string $fallback = ''): string {
            $text = $fallback !== '' ? $fallback : $key;
            foreach ($params as $name => $value) {
                $text = str_replace('{' . $name . '}', (string) $value, $text);
            }
            return $text;
        };
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private static function experience(array $overrides = []): array
    {
        return array_replace([
            'id' => 'lord',
            'name' => 'Legend of the Red Dragon',
            'description' => 'A fantasy RPG door where warriors duel for glory.',
            'category' => 'game',
            'backend' => ['type' => 'native', 'id' => 'lord'],
            'capabilities' => ['multiplayer' => true],
            'capacity' => ['max_sessions' => 4],
            'policy' => ['enabled' => true, 'credit_cost' => 0],
            'surfaces' => ['web' => 'full', 'telnet' => 'full'],
        ], $overrides);
    }

    /**
     * @param array<int,array<string,mixed>> $players
     * @return array<string,mixed>
     */
    private static function experienceState(array $experience, array $players): array
    {
        $userIds = [];
        foreach ($players as $player) {
            $userIds[(int) $player['user_id']] = true;
        }

        return [
            'experience' => $experience,
            'active' => $players !== [],
            'session_count' => count($players),
            'player_count' => count($userIds),
            'players' => $players,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $players
     */
    private static function player(int $userId, string $username, ?int $node = null): array
    {
        return [
            'user_id' => $userId,
            'username' => $username,
            'session_id' => 'sess-' . $userId,
            'presence' => null,
            'presence_state' => 'playing',
            'node' => $node,
            'started_at' => 1_700_000_000,
        ];
    }

    // ---- detail body is composed from the shared read models -------------

    public function testDetailLinesReflectSharedPresentationStateAndActivity(): void
    {
        $experience = self::experience();
        $players = [self::player(10, 'alice', 1), self::player(11, 'bob', 2)];
        $state = self::experienceState($experience, $players);

        // The presentation is the canonical shared read model — the detail body
        // must consume it rather than re-deriving availability/capacity.
        $presentation = ExperiencePresentation::build($experience, 'telnet', $state, null);

        $activity = [
            ['id' => 2, 'type' => 'play', 'user_id' => 11, 'username' => 'bob', 'occurred_at' => '2026-08-30 10:00:00'],
            ['id' => 1, 'type' => 'first_play', 'user_id' => 10, 'username' => 'alice', 'occurred_at' => '2026-08-29 09:00:00'],
        ];

        $lines = DoorHandler::buildExperienceDetailLines(
            $presentation,
            $state,
            $activity,
            null,
            self::translator()
        );
        $blob = implode("\n", $lines);

        self::assertStringContainsString('A fantasy RPG door where warriors duel', $blob);
        self::assertStringContainsString('Type: Game / Multiplayer', $blob);
        self::assertStringContainsString('Status: Available', $blob);
        self::assertStringContainsString('Players online: 2 / 4', $blob);
        self::assertStringContainsString('alice', $blob);
        self::assertStringContainsString('bob', $blob);
        self::assertStringContainsString('alice played for the first time', $blob);
        self::assertStringContainsString('bob played', $blob);
    }

    public function testDetailStatusAndActionFollowViewerParticipation(): void
    {
        $experience = self::experience();
        $viewer = self::player(10, 'alice', 3);
        $state = self::experienceState($experience, [$viewer, self::player(11, 'bob', 4)]);

        $viewerPlayer = \BinktermPHP\ExperienceParticipation::findViewerPlayer($state, 10);
        self::assertNotNull($viewerPlayer);

        $presentation = ExperiencePresentation::build($experience, 'telnet', $state, $viewerPlayer);

        // Shared contract: participating viewer gets Return, not Play.
        $actions = DoorHandler::resolveDetailActions($presentation);
        self::assertTrue($actions['can_return']);
        self::assertFalse($actions['can_play']);

        $lines = DoorHandler::buildExperienceDetailLines(
            $presentation,
            $state,
            [],
            $viewerPlayer,
            self::translator()
        );
        $blob = implode("\n", $lines);

        self::assertStringContainsString('Status: You are playing this now', $blob);
        self::assertStringContainsString('alice (node 3) (you)', $blob);
    }

    public function testFreeAndUnlimitedExperienceOmitsCostAndCapacityBounds(): void
    {
        $experience = self::experience([
            'capacity' => ['max_sessions' => null],
            'policy' => ['enabled' => true, 'credit_cost' => 0],
        ]);
        $state = self::experienceState($experience, [self::player(10, 'alice')]);
        $presentation = ExperiencePresentation::build($experience, 'telnet', $state, null);

        $lines = DoorHandler::buildExperienceDetailLines($presentation, $state, [], null, self::translator());
        $blob = implode("\n", $lines);

        self::assertContains('Players online: 1', $lines);
        self::assertStringNotContainsString('Players online: 1 /', $blob);
        self::assertStringNotContainsString('Cost:', $blob);
    }

    public function testPaidExperienceShowsCreditCost(): void
    {
        $experience = self::experience([
            'policy' => ['enabled' => true, 'credit_cost' => 15],
        ]);
        $state = self::experienceState($experience, []);
        $presentation = ExperiencePresentation::build($experience, 'telnet', $state, null);

        $lines = DoorHandler::buildExperienceDetailLines($presentation, $state, [], null, self::translator());

        self::assertStringContainsString('Cost: 15 credits', implode("\n", $lines));
    }

    public function testEmptyRecentActivityFallsBackToInviteLine(): void
    {
        $experience = self::experience();
        $state = self::experienceState($experience, []);
        $presentation = ExperiencePresentation::build($experience, 'telnet', $state, null);

        $lines = DoorHandler::buildExperienceDetailLines($presentation, $state, [], null, self::translator());
        $blob = implode("\n", $lines);

        self::assertStringContainsString('Recent activity:', $blob);
        self::assertStringContainsString('Nothing yet', $blob);
    }

    public function testRosterIsCappedWithOverflowSummary(): void
    {
        $experience = self::experience(['capacity' => ['max_sessions' => 20]]);
        $players = [];
        for ($i = 1; $i <= 11; $i++) {
            $players[] = self::player(100 + $i, 'player' . $i, $i);
        }
        $state = self::experienceState($experience, $players);
        $presentation = ExperiencePresentation::build($experience, 'telnet', $state, null);

        $lines = DoorHandler::buildExperienceDetailLines($presentation, $state, [], null, self::translator());
        $blob = implode("\n", $lines);

        self::assertStringContainsString('player1 (node 1)', $blob);
        self::assertStringContainsString('player8 (node 8)', $blob);
        self::assertStringNotContainsString('player9 (node 9)', $blob);
        self::assertStringContainsString('and 3 more', $blob);
    }

    // ---- action resolution mirrors the normalized contract --------------

    public function testResolveDetailActionsOffersPlayWhenLaunchableAndIdle(): void
    {
        $presentation = ExperiencePresentation::build(self::experience(), 'telnet', self::experienceState(self::experience(), []), null);

        $actions = DoorHandler::resolveDetailActions($presentation);

        self::assertTrue($actions['can_play']);
        self::assertFalse($actions['can_return']);
        self::assertSame(['g', 'q'], $actions['keys']);
    }

    public function testResolveDetailActionsOffersOnlyBackWhenNotLaunchable(): void
    {
        // 'planned' telnet surface -> not static-launchable -> no Play/Return.
        $experience = self::experience(['surfaces' => ['web' => 'full', 'telnet' => 'planned']]);
        $presentation = ExperiencePresentation::build($experience, 'telnet', self::experienceState($experience, []), null);

        $actions = DoorHandler::resolveDetailActions($presentation);

        self::assertFalse($actions['can_play']);
        self::assertFalse($actions['can_return']);
        self::assertSame(['q'], $actions['keys']);
    }

    public function testComposeDetailViewCarriesExperienceNameLinesAndLaunchSegment(): void
    {
        $experience = self::experience();
        $state = self::experienceState($experience, []);
        $presentation = ExperiencePresentation::build($experience, 'telnet', $state, null);

        $view = DoorHandler::composeExperienceDetailView(
            $experience,
            $presentation,
            $state,
            [],
            null,
            self::translator()
        );

        self::assertSame($experience, $view['experience']);
        self::assertSame('Legend of the Red Dragon', $view['name']);
        self::assertNotEmpty($view['lines']);
        self::assertTrue($view['actions']['can_play']);

        $segmentText = implode('', array_column($view['status_segments'], 'text'));
        self::assertStringContainsString('G', $segmentText);
        self::assertStringContainsString('Play', $segmentText);
        self::assertStringContainsString('Back', $segmentText);
    }

    // ---- catalog -> detail -> door -> detail -> catalog flow ------------

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private static function playView(array $overrides = []): array
    {
        return array_replace([
            'experience' => self::experience(),
            'name' => 'Legend of the Red Dragon',
            'lines' => ['Fantasy RPG.', '', 'Status: Available'],
            'actions' => ['can_play' => true, 'can_return' => false, 'keys' => ['g', 'q'], 'primary' => 'g'],
            'status_segments' => [['text' => 'G'], ['text' => ' Play  '], ['text' => 'Q'], ['text' => ' Back']],
        ], $overrides);
    }

    private function invokeDetailLoop(
        TerminalShellInterface $shell,
        callable $reload,
        callable $onLaunch
    ): void {
        $ref = new ReflectionMethod(DoorHandler::class, 'runExperienceDetailLoop');
        $ref->setAccessible(true);
        $handler = (new ReflectionClass(DoorHandler::class))->newInstanceWithoutConstructor();
        $state = ['locale' => 'en'];
        $conn = null;
        $ref->invokeArgs($handler, [$conn, &$state, $shell, $reload, $onLaunch, self::translator()]);
    }

    public function testBackFromDetailReturnsToCatalogWithoutLaunching(): void
    {
        $launched = 0;

        $shell = $this->createMock(TerminalShellInterface::class);
        $shell->expects($this->once())->method('showScrollablePanel')->willReturn('quit');
        $shell->expects($this->never())->method('showAlert');

        $this->invokeDetailLoop(
            $shell,
            fn (): array => self::playView(),
            function () use (&$launched): void { $launched++; }
        );

        self::assertSame(0, $launched, 'Back must not launch the experience');
    }

    public function testPlayLaunchesViaInjectedPathThenRedrawsSameDetailScreen(): void
    {
        $launched = [];

        $shell = $this->createMock(TerminalShellInterface::class);
        // First render -> caller presses the launch key; second render (the
        // return destination after the door exits) -> caller backs out.
        $shell->expects($this->exactly(2))
            ->method('showScrollablePanel')
            ->willReturnOnConsecutiveCalls('launch', 'quit');

        $this->invokeDetailLoop(
            $shell,
            fn (): array => self::playView(),
            function (array $view) use (&$launched): void { $launched[] = $view['name']; }
        );

        self::assertSame(['Legend of the Red Dragon'], $launched);
    }

    public function testDetailLoopAlertsAndExitsWhenExperienceIsGone(): void
    {
        $launched = 0;

        $shell = $this->createMock(TerminalShellInterface::class);
        $shell->expects($this->never())->method('showScrollablePanel');
        $shell->expects($this->once())->method('showAlert');

        $this->invokeDetailLoop(
            $shell,
            fn (): ?array => null,
            function () use (&$launched): void { $launched++; }
        );

        self::assertSame(0, $launched);
    }

    public function testUnlaunchableDetailExposesNoLaunchKey(): void
    {
        $capturedOptions = [];

        $shell = $this->createMock(TerminalShellInterface::class);
        $shell->method('showScrollablePanel')->willReturnCallback(
            function () use (&$capturedOptions) {
                $args = func_get_args();
                $capturedOptions = $args[4] ?? [];
                return 'quit';
            }
        );

        $this->invokeDetailLoop(
            $shell,
            fn (): array => self::playView([
                'actions' => ['can_play' => false, 'can_return' => false, 'keys' => ['q'], 'primary' => 'q'],
            ]),
            function (): void { self::fail('unlaunchable detail must not launch'); }
        );

        self::assertSame([], $capturedOptions['extra_keys'] ?? null);
    }

    public function testReturnActionAlsoDrivesTheLaunchPath(): void
    {
        $launched = 0;

        $shell = $this->createMock(TerminalShellInterface::class);
        $shell->expects($this->exactly(2))
            ->method('showScrollablePanel')
            ->willReturnOnConsecutiveCalls('launch', 'quit');

        $this->invokeDetailLoop(
            $shell,
            fn (): array => self::playView([
                'actions' => ['can_play' => false, 'can_return' => true, 'keys' => ['g', 'q'], 'primary' => 'g'],
            ]),
            function () use (&$launched): void { $launched++; }
        );

        self::assertSame(1, $launched);
    }
}
