<?php

declare(strict_types=1);

use BinktermPHP\TelnetServer\DoorHandler;
use BinktermPHP\TelnetServer\TerminalShellInterface;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../telnet/src/TelnetUtils.php';
require_once __DIR__ . '/../../telnet/src/TerminalShellInterface.php';
require_once __DIR__ . '/../../telnet/src/DoorHandler.php';

final class DoorHandlerLiveNowTest extends TestCase
{
    private static function translator(): callable
    {
        return static function (string $key, array $params = [], string $fallback = ''): string {
            $text = $fallback !== '' ? $fallback : $key;
            foreach ($params as $name => $value) {
                $text = str_replace('{' . $name . '}', (string)$value, $text);
            }
            return $text;
        };
    }

    /** @return array<string,mixed> */
    private static function experience(string $id, array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => $id,
            'name' => ucwords(str_replace('-', ' ', $id)),
            'backend' => ['type' => 'native', 'id' => $id],
            'actions' => ['launch' => true],
            'capacity' => ['max_sessions' => 10],
            'surfaces' => ['web' => 'full', 'telnet' => 'full'],
        ], $overrides);
    }

    /** @return array<string,mixed> */
    private static function player(int $userId, string $username, string $sessionId): array
    {
        return [
            'user_id' => $userId,
            'username' => $username,
            'session_id' => $sessionId,
            'node' => null,
        ];
    }

    /** @return array<string,mixed> */
    private static function snapshot(array $experience, array $players): array
    {
        $distinct = [];
        foreach ($players as $player) {
            $distinct[(int)$player['user_id']] = true;
        }

        return [
            'experience' => $experience,
            'active' => $players !== [],
            'session_count' => count($players),
            'player_count' => count($distinct),
            'players' => $players,
        ];
    }

    public function testOccupiedAuthorizedTerminalExperienceIsIncluded(): void
    {
        $live = DoorHandler::composeLiveNow([
            'green-dragon' => self::snapshot(
                self::experience('green-dragon'),
                [self::player(11, 'bob', 'session-bob')]
            ),
        ], 10, self::translator());

        self::assertSame(1, $live['experience_count']);
        self::assertSame(1, $live['player_count']);
        self::assertSame('green-dragon', $live['entries'][0]['id']);
        self::assertStringContainsString('bob', $live['entries'][0]['item']['detail']);
        self::assertStringContainsString('1/10 sessions', $live['entries'][0]['item']['detail']);
    }

    public function testUnoccupiedAndNonTerminalLaunchableExperiencesAreExcluded(): void
    {
        $live = DoorHandler::composeLiveNow([
            'quiet' => self::snapshot(self::experience('quiet'), []),
            'planned' => self::snapshot(
                self::experience('planned', ['actions' => ['launch' => false]]),
                [self::player(11, 'bob', 'session-bob')]
            ),
        ], 10, self::translator());

        self::assertSame([], $live['entries']);
        self::assertSame(0, $live['experience_count']);
        self::assertSame('The Crossroads are quiet right now.', $live['summary']);
    }

    public function testViewerOnlyExperienceIsExcludedButViewerWithOthersRemainsLive(): void
    {
        $live = DoorHandler::composeLiveNow([
            'only-me' => self::snapshot(
                self::experience('only-me'),
                [self::player(10, 'alice', 'session-alice')]
            ),
            'together' => self::snapshot(
                self::experience('together'),
                [
                    self::player(10, 'alice', 'session-alice-2'),
                    self::player(11, 'bob', 'session-bob'),
                ]
            ),
        ], 10, self::translator());

        self::assertSame(['together'], array_column($live['entries'], 'id'));
        self::assertSame(2, $live['entries'][0]['player_count']);
        self::assertStringContainsString('alice, bob', $live['entries'][0]['item']['detail']);
    }

    public function testMultipleSessionsDoNotInflateDistinctPlayerCount(): void
    {
        $live = DoorHandler::composeLiveNow([
            'multi-node' => self::snapshot(
                self::experience('multi-node'),
                [
                    self::player(11, 'bob', 'session-one'),
                    self::player(11, 'bob', 'session-two'),
                ]
            ),
        ], 10, self::translator());

        self::assertSame(1, $live['player_count']);
        self::assertSame(1, $live['entries'][0]['player_count']);
        self::assertSame(2, $live['entries'][0]['session_count']);
        self::assertStringContainsString('1 caller | 2/10 sessions | bob', $live['entries'][0]['item']['detail']);
    }

    public function testRosterPreviewIsBounded(): void
    {
        $players = [
            self::player(11, 'bob', 's1'),
            self::player(12, 'carol', 's2'),
            self::player(13, 'dave', 's3'),
            self::player(14, 'erin', 's4'),
        ];
        $live = DoorHandler::composeLiveNow([
            'busy' => self::snapshot(self::experience('busy'), $players),
        ], 10, self::translator());

        $detail = $live['entries'][0]['item']['detail'];
        self::assertStringContainsString('bob, carol, dave +1 more', $detail);
        self::assertStringNotContainsString('erin', $detail);
    }

    public function testEmptyLiveNowUsesTerminalNativeEmptyState(): void
    {
        $capturedOptions = [];
        $shell = $this->createMock(TerminalShellInterface::class);
        $shell->expects($this->once())
            ->method('chooseFromList')
            ->willReturnCallback(function (...$args) use (&$capturedOptions): ?int {
                self::assertSame([], $args[3]);
                $capturedOptions = $args[4];
                return null;
            });

        $this->runLiveNowLoop(
            $shell,
            static fn (): array => [
                'summary' => 'quiet',
                'entries' => [],
                'player_count' => 0,
                'experience_count' => 0,
            ],
            static function (): void {}
        );

        self::assertSame(
            'Nobody else is active in an Experience right now.',
            $capturedOptions['empty_message']
        );
    }

    public function testSelectionOpensExistingDetailAndReturnRefreshesLiveNow(): void
    {
        $first = DoorHandler::composeLiveNow([
            'green-dragon' => self::snapshot(
                self::experience('green-dragon'),
                [self::player(11, 'bob', 'session-bob')]
            ),
        ], 10, self::translator());
        $second = DoorHandler::composeLiveNow([
            'red-dragon' => self::snapshot(
                self::experience('red-dragon'),
                [self::player(12, 'carol', 'session-carol')]
            ),
        ], 10, self::translator());
        $snapshots = [$first, $second];
        $reloads = 0;
        $opened = [];
        $shownItems = [];

        $shell = $this->createMock(TerminalShellInterface::class);
        $calls = 0;
        $shell->method('chooseFromList')->willReturnCallback(
            function (...$args) use (&$calls, &$shownItems): ?int {
                $shownItems[] = $args[3];
                return $calls++ === 0 ? 0 : null;
            }
        );

        $reload = static function () use (&$reloads, $snapshots): array {
            return $snapshots[min($reloads++, 1)];
        };
        $openDetail = static function (string $experienceId) use (&$opened): void {
            $opened[] = $experienceId;
        };

        $this->runLiveNowLoop($shell, $reload, $openDetail);

        self::assertSame(['green-dragon'], $opened);
        self::assertSame(2, $reloads);
        self::assertSame('Green Dragon', $shownItems[0][0]['label']);
        self::assertSame('Red Dragon', $shownItems[1][0]['label']);
    }

    public function testActivityDisappearingAfterSelectionDoesNotBreakNavigation(): void
    {
        $first = DoorHandler::composeLiveNow([
            'green-dragon' => self::snapshot(
                self::experience('green-dragon'),
                [self::player(11, 'bob', 'session-bob')]
            ),
        ], 10, self::translator());
        $empty = DoorHandler::composeLiveNow([], 10, self::translator());
        $reloads = 0;
        $opened = 0;

        $shell = $this->createMock(TerminalShellInterface::class);
        $shell->method('chooseFromList')->willReturnOnConsecutiveCalls(0, null);

        $this->runLiveNowLoop(
            $shell,
            static function () use (&$reloads, $first, $empty): array {
                return $reloads++ === 0 ? $first : $empty;
            },
            static function () use (&$opened): void {
                $opened++;
            }
        );

        self::assertSame(1, $opened);
        self::assertSame(2, $reloads);
    }

    public function testArrivalPrecedesCatalogAndUsesOneCollectionStateBoundary(): void
    {
        $source = (string)file_get_contents(__DIR__ . '/../../telnet/src/DoorHandler.php');
        $show = $this->between($source, 'public function show(', 'public static function composeLiveNow(');

        self::assertStringContainsString("getExperienceStates(\n                \$modelUser,\n                'terminal'", $show);
        self::assertStringContainsString('self::buildLiveNowArrivalItem($liveNow, $t)', $show);
        self::assertStringContainsString('if ($selected === 0)', $show);
        self::assertStringContainsString('$this->showExperienceDetail(', $show);
        self::assertStringNotContainsString('foreach ($experienceStates as', $this->between(
            $source,
            '$reloadLiveNow = function',
            '$openDetail = function'
        ));
    }

    private function runLiveNowLoop(
        TerminalShellInterface $shell,
        callable $reload,
        callable $openDetail
    ): void {
        $method = new ReflectionMethod(DoorHandler::class, 'runLiveNowLoop');
        $method->setAccessible(true);
        $handler = (new ReflectionClass(DoorHandler::class))->newInstanceWithoutConstructor();
        $state = ['locale' => 'en'];
        $conn = null;

        $method->invokeArgs($handler, [
            $conn,
            &$state,
            $shell,
            $reload,
            $openDetail,
            self::translator(),
        ]);
    }

    private function between(string $source, string $start, string $end): string
    {
        $startPosition = strpos($source, $start);
        self::assertNotFalse($startPosition);
        $endPosition = strpos($source, $end, $startPosition + strlen($start));
        self::assertNotFalse($endPosition);

        return substr($source, $startPosition, $endPosition - $startPosition);
    }
}
