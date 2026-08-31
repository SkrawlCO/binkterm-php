<?php

declare(strict_types=1);

use BinktermPHP\TelnetServer\DoorHandler;
use BinktermPHP\TelnetServer\TerminalShellInterface;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../telnet/src/TelnetUtils.php';
require_once __DIR__ . '/../../telnet/src/TerminalShellInterface.php';
require_once __DIR__ . '/../../telnet/src/DoorHandler.php';

final class DoorHandlerYourPlacesTest extends TestCase
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
            'description' => '',
            'category' => 'game',
            'backend' => ['type' => 'native', 'id' => $id],
            'actions' => ['launch' => true],
            'capacity' => ['max_sessions' => 10],
            'policy' => ['credit_cost' => 0],
            'capabilities' => ['multiplayer' => true],
            'surfaces' => ['web' => 'full', 'telnet' => 'full'],
        ], $overrides);
    }

    /** @return array<string,mixed> */
    private static function player(int $userId, string $username, string $sessionId): array
    {
        return ['user_id' => $userId, 'username' => $username, 'session_id' => $sessionId];
    }

    /** @return array<string,mixed> */
    private static function snapshot(string $id, array $players): array
    {
        $distinct = [];
        foreach ($players as $player) {
            $distinct[(int)$player['user_id']] = true;
        }

        return [
            'experience' => self::experience($id),
            'active' => $players !== [],
            'session_count' => count($players),
            'player_count' => count($distinct),
            'players' => $players,
        ];
    }

    public function testViewerOnlyExperienceAppearsWithSharedReturnAction(): void
    {
        $places = DoorHandler::composeYourPlaces([
            'green-dragon' => self::snapshot('green-dragon', [
                self::player(10, 'alice', 'alice-1'),
            ]),
        ], 10, self::translator());

        self::assertSame(1, $places['experience_count']);
        self::assertSame('green-dragon', $places['entries'][0]['id']);
        self::assertTrue($places['entries'][0]['presentation']['actions']['return']);
        self::assertSame('Participating | Return available', $places['entries'][0]['item']['detail']);
    }

    public function testViewerWithAnotherCallerAppears(): void
    {
        $places = DoorHandler::composeYourPlaces([
            'together' => self::snapshot('together', [
                self::player(10, 'alice', 'alice-1'),
                self::player(11, 'bob', 'bob-1'),
            ]),
        ], 10, self::translator());

        self::assertSame(['together'], array_column($places['entries'], 'id'));
    }

    public function testParticipatingAuthorizedExperienceDoesNotRequireReturnAvailability(): void
    {
        $snapshot = self::snapshot('paused', [self::player(10, 'alice', 'alice-1')]);
        $snapshot['experience'] = self::experience('paused', [
            'actions' => ['launch' => false],
            'surfaces' => ['telnet' => 'browse'],
        ]);

        $places = DoorHandler::composeYourPlaces(['paused' => $snapshot], 10, self::translator());

        self::assertSame(['paused'], array_column($places['entries'], 'id'));
        self::assertFalse($places['entries'][0]['presentation']['actions']['return']);
        self::assertSame('Participating', $places['entries'][0]['item']['detail']);
    }

    public function testOtherCallerOnlyAndUnoccupiedExperiencesAreExcluded(): void
    {
        $places = DoorHandler::composeYourPlaces([
            'others' => self::snapshot('others', [self::player(11, 'bob', 'bob-1')]),
            'empty' => self::snapshot('empty', []),
        ], 10, self::translator());

        self::assertSame([], $places['entries']);
        self::assertSame('You have no active places right now.', $places['summary']);
    }

    public function testOnlyAuthorizedCollectionRowsCanAppear(): void
    {
        $authorizedSnapshot = [
            'visible' => self::snapshot('visible', [self::player(10, 'alice', 'alice-1')]),
        ];

        $places = DoorHandler::composeYourPlaces($authorizedSnapshot, 10, self::translator());

        self::assertSame(['visible'], array_column($places['entries'], 'id'));
        self::assertArrayNotHasKey('hidden', $authorizedSnapshot);
    }

    public function testMultipleViewerSessionsProduceOneExperienceEntry(): void
    {
        $places = DoorHandler::composeYourPlaces([
            'multi-node' => self::snapshot('multi-node', [
                self::player(10, 'alice', 'alice-1'),
                self::player(10, 'alice', 'alice-2'),
            ]),
        ], 10, self::translator());

        self::assertSame(1, $places['experience_count']);
        self::assertCount(1, $places['entries']);
    }

    public function testMultipleActiveExperiencesAreSortedAndIncluded(): void
    {
        $places = DoorHandler::composeYourPlaces([
            'zeta' => self::snapshot('zeta', [self::player(10, 'alice', 'alice-z')]),
            'alpha' => self::snapshot('alpha', [self::player(10, 'alice', 'alice-a')]),
        ], 10, self::translator());

        self::assertSame(['alpha', 'zeta'], array_column($places['entries'], 'id'));
        self::assertSame('2 active places', $places['summary']);
    }

    public function testEmptyYourPlacesUsesQuietSelectorState(): void
    {
        $options = [];
        $shell = $this->createMock(TerminalShellInterface::class);
        $shell->expects($this->once())->method('chooseFromList')
            ->willReturnCallback(function (...$args) use (&$options): ?int {
                self::assertSame([], $args[3]);
                $options = $args[4];
                return null;
            });

        $this->runLoop(
            $shell,
            static fn(): array => DoorHandler::composeYourPlaces([], 10, self::translator()),
            static function (): void {}
        );

        self::assertSame('You have no active places right now.', $options['empty_message']);
    }

    public function testSelectionUsesExistingDetailSeamAndRefreshRemovesEndedPlace(): void
    {
        $active = DoorHandler::composeYourPlaces([
            'green-dragon' => self::snapshot('green-dragon', [
                self::player(10, 'alice', 'alice-1'),
            ]),
        ], 10, self::translator());
        $ended = DoorHandler::composeYourPlaces([], 10, self::translator());
        $reloads = 0;
        $opened = [];
        $shown = [];

        $shell = $this->createMock(TerminalShellInterface::class);
        $calls = 0;
        $shell->method('chooseFromList')->willReturnCallback(
            function (...$args) use (&$calls, &$shown): ?int {
                $shown[] = $args[3];
                return $calls++ === 0 ? 0 : null;
            }
        );

        $this->runLoop(
            $shell,
            static function () use (&$reloads, $active, $ended): array {
                return $reloads++ === 0 ? $active : $ended;
            },
            static function (string $id) use (&$opened): void {
                $opened[] = $id;
            }
        );

        self::assertSame(['green-dragon'], $opened);
        self::assertSame(2, $reloads);
        self::assertCount(1, $shown[0]);
        self::assertSame([], $shown[1]);
    }

    public function testLiveNowSemanticsRemainComplementary(): void
    {
        $states = [
            'viewer-only' => self::snapshot('viewer-only', [self::player(10, 'alice', 'a1')]),
            'other-only' => self::snapshot('other-only', [self::player(11, 'bob', 'b1')]),
            'together' => self::snapshot('together', [
                self::player(10, 'alice', 'a2'),
                self::player(11, 'bob', 'b2'),
            ]),
        ];

        $live = DoorHandler::composeLiveNow($states, 10, self::translator());
        $places = DoorHandler::composeYourPlaces($states, 10, self::translator());

        self::assertSame(['other-only', 'together'], array_column($live['entries'], 'id'));
        self::assertSame(['together', 'viewer-only'], array_column($places['entries'], 'id'));
    }

    public function testArrivalCompositionReusesOneSnapshotAndKeepsCatalog(): void
    {
        $source = (string)file_get_contents(__DIR__ . '/../../telnet/src/DoorHandler.php');
        $show = $this->between($source, 'public function show(', 'public static function composeLiveNow(');
        $beforeChooser = $this->between($show, 'while (true) {', '$selected = $shell->chooseFromList(');

        self::assertSame(1, substr_count($beforeChooser, 'getExperienceStates('));
        self::assertStringContainsString("composeLiveNow(\n                \$experienceStates", $beforeChooser);
        self::assertStringContainsString("composeYourPlaces(\n                \$experienceStates", $beforeChooser);
        self::assertStringContainsString('$doorList[]', $beforeChooser);
        self::assertStringContainsString('if ($experienceStates === [])', $beforeChooser);
        self::assertStringContainsString('if ($selected === 1)', $show);
        self::assertStringContainsString('$this->showExperienceDetail(', $show);
    }

    private function runLoop(
        TerminalShellInterface $shell,
        callable $reload,
        callable $openDetail
    ): void {
        $method = new ReflectionMethod(DoorHandler::class, 'runYourPlacesLoop');
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
