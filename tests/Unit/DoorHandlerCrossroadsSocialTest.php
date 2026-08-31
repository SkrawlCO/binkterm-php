<?php

declare(strict_types=1);

use BinktermPHP\ExperiencePresentation;
use BinktermPHP\TelnetServer\DoorHandler;
use BinktermPHP\TelnetServer\TerminalShellInterface;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../telnet/src/TelnetUtils.php';
require_once __DIR__ . '/../../telnet/src/TerminalShellInterface.php';
require_once __DIR__ . '/../../telnet/src/ChatHandler.php';
require_once __DIR__ . '/../../telnet/src/DoorHandler.php';

/**
 * DoorHandler test double: overrides only the thin protected seams that reach
 * live infrastructure (public-profile fetch, ChatHandler DM, ChatHandler room)
 * so the People / Conversation navigation can be driven without a daemon.
 */
final class SpyDoorHandler extends DoorHandler
{
    public int $roomConversationCalls = 0;
    public ?int $lastRoomId = null;
    public bool $roomConversationResult = true;

    public int $directMessageCalls = 0;
    public bool $directMessageResult = true;

    public int $profileFetchCalls = 0;
    /** @var array<string,mixed>|null */
    public ?array $profileResult = ['user_id' => 7, 'username' => 'ada', 'about_me' => 'hi'];

    public function __construct()
    {
        // Intentionally skip the parent constructor: these tests never touch
        // $this->server or $this->apiBase.
    }

    protected function fetchPersonProfile(string $session, int $userId): ?array
    {
        $this->profileFetchCalls++;
        return $this->profileResult;
    }

    protected function invokeDirectMessage($conn, array &$state, string $session, int $userId, string $username): bool
    {
        $this->directMessageCalls++;
        return $this->directMessageResult;
    }

    protected function invokeRoomConversation($conn, array &$state, string $session, int $roomId): bool
    {
        $this->roomConversationCalls++;
        $this->lastRoomId = $roomId;
        return $this->roomConversationResult;
    }
}

/**
 * Telnet Crossroads Slice 2 — social layer (People, Profile, Message,
 * Conversation) hung off the Slice 1 experience detail screen.
 *
 * These tests pin:
 *   - social actions are offered only when the shared read models support them
 *     (roster non-empty -> People; canonical conversation room + chat enabled
 *     -> Conversation), with no experience-name special-casing;
 *   - the People view is built from the detail view model's roster snapshot and
 *     distinguishes the current caller;
 *   - selecting a caller drives the existing profile / DM infrastructure via
 *     thin seams, exactly once, and always returns to the People / detail
 *     context;
 *   - Conversation drives the existing ChatHandler room path exactly once and
 *     never launches the door;
 *   - races (caller gone, room inaccessible, experience gone) degrade to an
 *     alert and the nearest valid context;
 *   - the Slice 1 Play / Return / door-exit / Back behaviour is unchanged.
 */
final class DoorHandlerCrossroadsSocialTest extends TestCase
{
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
            'id' => 'green-dragon',
            'name' => 'The Green Dragon Inn',
            'description' => 'A tavern where callers gather.',
            'category' => 'game',
            'backend' => ['type' => 'native', 'id' => 'green-dragon'],
            'capabilities' => ['multiplayer' => true],
            'capacity' => ['max_sessions' => 8],
            'policy' => ['enabled' => true, 'credit_cost' => 0],
            'surfaces' => ['web' => 'full', 'telnet' => 'full'],
        ], $overrides);
    }

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

    /**
     * @param array<int,array<string,mixed>> $players
     * @return array<string,mixed>
     */
    private static function experienceState(array $experience, array $players): array
    {
        $userIds = [];
        foreach ($players as $p) {
            $userIds[(int) $p['user_id']] = true;
        }
        return [
            'experience' => $experience,
            'active' => $players !== [],
            'session_count' => count($players),
            'player_count' => count($userIds),
            'players' => $players,
        ];
    }

    // ---- 1-4, 19: action availability comes only from shared data ---------

    public function testRosterPresenceEnablesPeopleAction(): void
    {
        $experience = self::experience();
        $state = self::experienceState($experience, [self::player(10, 'alice')]);
        $presentation = ExperiencePresentation::build($experience, 'telnet', $state, null);

        $actions = DoorHandler::resolveDetailActions($presentation, $state, false);

        self::assertTrue($actions['can_people']);
        self::assertContains('w', $actions['keys']);
    }

    public function testEmptyRosterHidesPeopleAction(): void
    {
        $experience = self::experience();
        $state = self::experienceState($experience, []);
        $presentation = ExperiencePresentation::build($experience, 'telnet', $state, null);

        $actions = DoorHandler::resolveDetailActions($presentation, $state, false);

        self::assertFalse($actions['can_people']);
        self::assertNotContains('w', $actions['keys']);
    }

    public function testCanonicalConversationRoomEnablesConversationAction(): void
    {
        $experience = self::experience([
            'capabilities' => [
                'multiplayer' => true,
                'conversation' => ['type' => 'chat_room', 'room_id' => 42],
            ],
        ]);
        $state = self::experienceState($experience, []);
        $presentation = ExperiencePresentation::build($experience, 'telnet', $state, null);

        $actions = DoorHandler::resolveDetailActions($presentation, $state, true);

        self::assertTrue($actions['can_conversation']);
        self::assertSame(42, $actions['conversation_room_id']);
        self::assertContains('c', $actions['keys']);
    }

    public function testConversationActionRequiresBothRoomAndChatFeature(): void
    {
        $withRoom = self::experience([
            'capabilities' => ['conversation' => ['type' => 'chat_room', 'room_id' => 42]],
        ]);
        $noRoom = self::experience();

        $withRoomState = self::experienceState($withRoom, []);
        $noRoomState = self::experienceState($noRoom, []);

        // room present but chat feature disabled
        self::assertFalse(
            DoorHandler::resolveDetailActions(
                ExperiencePresentation::build($withRoom, 'telnet', $withRoomState, null),
                $withRoomState,
                false
            )['can_conversation']
        );

        // chat feature enabled but no canonical room
        self::assertFalse(
            DoorHandler::resolveDetailActions(
                ExperiencePresentation::build($noRoom, 'telnet', $noRoomState, null),
                $noRoomState,
                true
            )['can_conversation']
        );
    }

    public function testConversationRoomIdIsDataDrivenNotNameMapped(): void
    {
        // Same room id regardless of experience id/name; different id => follows the data.
        foreach ([['a-game', 11], ['totally-different', 11], ['x', 99]] as [$id, $roomId]) {
            $experience = self::experience([
                'id' => $id,
                'name' => strtoupper($id),
                'backend' => ['type' => 'native', 'id' => $id],
                'capabilities' => ['conversation' => ['type' => 'chat_room', 'room_id' => $roomId]],
            ]);
            $state = self::experienceState($experience, []);
            $actions = DoorHandler::resolveDetailActions(
                ExperiencePresentation::build($experience, 'telnet', $state, null),
                $state,
                true
            );
            self::assertSame($roomId, $actions['conversation_room_id']);
        }
    }

    // ---- compose view: roster snapshot + social status segments ----------

    public function testDetailViewCarriesRosterSnapshotAndSocialSegments(): void
    {
        $experience = self::experience([
            'capabilities' => [
                'multiplayer' => true,
                'conversation' => ['type' => 'chat_room', 'room_id' => 42],
            ],
        ]);
        $players = [self::player(10, 'alice', 1), self::player(11, 'bob', 2)];
        $state = self::experienceState($experience, $players);
        $presentation = ExperiencePresentation::build($experience, 'telnet', $state, null);

        $view = DoorHandler::composeExperienceDetailView(
            $experience,
            $presentation,
            $state,
            [],
            null,
            self::translator(),
            true
        );

        self::assertCount(2, $view['roster']);
        self::assertSame([10, 11], array_column($view['roster'], 'user_id'));

        $segmentText = implode('', array_column($view['status_segments'], 'text'));
        self::assertStringContainsString('People', $segmentText);
        self::assertStringContainsString('Conversation', $segmentText);
    }

    public function testDetailViewOmitsSocialSegmentsWhenUnavailable(): void
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
            self::translator(),
            false
        );

        self::assertSame([], $view['roster']);
        $segmentText = implode('', array_column($view['status_segments'], 'text'));
        self::assertStringNotContainsString('People', $segmentText);
        self::assertStringNotContainsString('Conversation', $segmentText);
    }

    // ---- detail loop: social keys route to onSocial, never launch --------

    /**
     * @param string[] $scriptedPanelActions
     * @param array<string,mixed> $view
     */
    private function runDetailLoop(array $scriptedPanelActions, array $view, array &$log): void
    {
        $shell = $this->createMock(TerminalShellInterface::class);
        $shell->method('showScrollablePanel')->willReturnOnConsecutiveCalls(...$scriptedPanelActions);

        $reload = static function () use ($view, &$log): array {
            $log[] = 'reload';
            return $view;
        };
        $onLaunch = static function () use (&$log): void {
            $log[] = 'launch';
        };
        $onSocial = static function (string $kind) use (&$log): void {
            $log[] = 'social:' . $kind;
        };

        $m = new ReflectionMethod(DoorHandler::class, 'runExperienceDetailLoop');
        $m->setAccessible(true);
        $handler = (new ReflectionClass(DoorHandler::class))->newInstanceWithoutConstructor();
        $state = ['locale' => 'en'];
        $conn = null;
        $m->invokeArgs($handler, [$conn, &$state, $shell, $reload, $onLaunch, self::translator(), $onSocial]);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private static function view(array $overrides = []): array
    {
        return array_replace([
            'experience' => self::experience(),
            'name' => 'The Green Dragon Inn',
            'roster' => [self::player(10, 'alice', 1)],
            'lines' => ['A tavern.'],
            'actions' => [
                'can_play' => true,
                'can_return' => false,
                'can_people' => true,
                'can_conversation' => true,
                'conversation_room_id' => 42,
                'keys' => ['g', 'w', 'c', 'q'],
                'primary' => 'g',
            ],
            'status_segments' => [['text' => 'G']],
        ], $overrides);
    }

    public function testPeopleKeyRoutesToSocialHandlerThenRedrawsDetail(): void
    {
        $log = [];
        $this->runDetailLoop(['people', 'quit'], self::view(), $log);

        self::assertSame(['reload', 'social:people', 'reload'], $log);
        self::assertNotContains('launch', $log);
    }

    public function testConversationKeyRoutesToSocialHandlerExactlyOnceAndDoesNotLaunch(): void
    {
        $log = [];
        $this->runDetailLoop(['conversation', 'quit'], self::view(), $log);

        self::assertSame(['reload', 'social:conversation', 'reload'], $log);
        self::assertSame(1, count(array_filter($log, static fn ($e) => $e === 'social:conversation')));
        self::assertNotContains('launch', $log);
    }

    public function testLaunchStillWorksAlongsideSocialActions(): void
    {
        $log = [];
        $this->runDetailLoop(['launch', 'quit'], self::view(), $log);

        self::assertSame(['reload', 'launch', 'reload'], $log);
    }

    public function testBackFromDetailExitsWithoutSocialOrLaunch(): void
    {
        $log = [];
        $this->runDetailLoop(['quit'], self::view(), $log);

        self::assertSame(['reload'], $log);
    }

    public function testSocialKeysAreInertWithoutAnOnSocialHandler(): void
    {
        // Slice 1 call shape (no onSocial): 'w'/'c' must not be offered/handled.
        $shell = $this->createMock(TerminalShellInterface::class);
        $captured = [];
        $shell->method('showScrollablePanel')->willReturnCallback(function () use (&$captured) {
            $captured = func_get_args()[4] ?? [];
            return 'quit';
        });

        $m = new ReflectionMethod(DoorHandler::class, 'runExperienceDetailLoop');
        $m->setAccessible(true);
        $handler = (new ReflectionClass(DoorHandler::class))->newInstanceWithoutConstructor();
        $state = ['locale' => 'en'];
        $conn = null;
        $reload = fn (): array => self::view();
        $m->invokeArgs($handler, [$conn, &$state, $shell, $reload, static function (): void {}, self::translator()]);

        self::assertArrayNotHasKey('w', $captured['extra_keys'] ?? []);
        self::assertArrayNotHasKey('c', $captured['extra_keys'] ?? []);
    }

    // ---- People loop: built from roster, distinguishes caller -----------

    /**
     * @param array<int,array<string,mixed>> $roster
     * @param array<int,?array<string,mixed>> $scriptedDialog
     * @param string[] $log
     */
    private function runPeopleLoop(array $roster, int $viewerId, array $scriptedDialog, array &$log): void
    {
        $shell = $this->createMock(TerminalShellInterface::class);
        $shell->method('showSelectableDialog')->willReturnOnConsecutiveCalls(...$scriptedDialog);
        $shell->method('showAlert')->willReturnCallback(function (...$a) use (&$log): void {
            $log[] = 'alert:' . $a[4];
        });

        $onPerson = static function (array $person) use (&$log): void {
            $log[] = 'person:' . $person['username'];
        };

        $m = new ReflectionMethod(DoorHandler::class, 'runExperiencePeopleLoop');
        $m->setAccessible(true);
        $handler = (new ReflectionClass(DoorHandler::class))->newInstanceWithoutConstructor();
        $state = ['locale' => 'en', 'user_id' => $viewerId];
        $conn = null;
        $m->invokeArgs($handler, [
            $conn, &$state, $shell, $roster, $viewerId, 'The Green Dragon Inn - Who is here',
            $onPerson, self::translator(),
        ]);
    }

    public function testPeopleLoopSelectsFromTheProvidedRoster(): void
    {
        $log = [];
        $this->runPeopleLoop(
            [self::player(10, 'alice'), self::player(11, 'bob')],
            99,
            [['action' => 'select', 'index' => 1], ['action' => 'quit', 'index' => 1]],
            $log
        );

        self::assertSame(['person:bob'], $log);
    }

    public function testPeopleLoopBuildsSelectableItemsThatDistinguishTheCaller(): void
    {
        $captured = [];
        $shell = $this->createMock(TerminalShellInterface::class);
        $shell->method('showSelectableDialog')->willReturnCallback(function () use (&$captured) {
            $captured = func_get_args()[3] ?? [];
            return ['action' => 'quit', 'index' => 0];
        });

        $m = new ReflectionMethod(DoorHandler::class, 'runExperiencePeopleLoop');
        $m->setAccessible(true);
        $handler = (new ReflectionClass(DoorHandler::class))->newInstanceWithoutConstructor();
        $state = ['locale' => 'en', 'user_id' => 10];
        $conn = null;
        $m->invokeArgs($handler, [
            $conn, &$state, $shell,
            [self::player(10, 'alice', 3), self::player(11, 'bob', 4)],
            10, 'title', static function (): void {}, self::translator(),
        ]);

        self::assertStringContainsString('alice', $captured[0]);
        self::assertStringContainsString('(you)', $captured[0]);
        self::assertStringContainsString('node 3', $captured[0]);
        self::assertStringContainsString('bob', $captured[1]);
        self::assertStringNotContainsString('(you)', $captured[1]);
    }

    public function testPeopleLoopSelectingYourselfShowsANoticeAndStaysInTheList(): void
    {
        $log = [];
        $this->runPeopleLoop(
            [self::player(10, 'alice'), self::player(11, 'bob')],
            10,
            [['action' => 'select', 'index' => 0], ['action' => 'quit', 'index' => 0]],
            $log
        );

        self::assertContains('alert:info', $log);
        self::assertNotContains('person:alice', $log);
    }

    public function testPeopleLoopWithEmptyRosterShowsAnEmptyStateAndExits(): void
    {
        $log = [];
        $this->runPeopleLoop([], 10, [], $log);

        self::assertSame(['alert:info'], $log);
    }

    // ---- Person action loop: profile / message via seams ---------------

    /**
     * @param array<int,?array<string,mixed>> $scriptedDialog
     * @param string[] $log
     */
    private function runPersonActionLoop(array $scriptedDialog, bool $messageOk, array &$log): void
    {
        $shell = $this->createMock(TerminalShellInterface::class);
        $shell->method('showSelectableDialog')->willReturnOnConsecutiveCalls(...$scriptedDialog);
        $shell->method('showAlert')->willReturnCallback(function (...$a) use (&$log): void {
            $log[] = 'alert:' . $a[4];
        });

        $onProfile = static function () use (&$log): void {
            $log[] = 'profile';
        };
        $onMessage = static function () use (&$log, $messageOk): bool {
            $log[] = 'message';
            return $messageOk;
        };

        $m = new ReflectionMethod(DoorHandler::class, 'runPersonActionLoop');
        $m->setAccessible(true);
        $handler = (new ReflectionClass(DoorHandler::class))->newInstanceWithoutConstructor();
        $state = ['locale' => 'en'];
        $conn = null;
        $m->invokeArgs($handler, [$conn, &$state, $shell, 'ada', $onProfile, $onMessage, self::translator()]);
    }

    public function testPersonActionLoopViewProfileThenReturnsToTheMenu(): void
    {
        $log = [];
        $this->runPersonActionLoop(
            [['action' => 'select', 'index' => 0], ['action' => 'quit', 'index' => 0]],
            true,
            $log
        );

        self::assertSame(['profile'], $log);
    }

    public function testPersonActionLoopSendMessageThenReturnsToTheMenu(): void
    {
        $log = [];
        $this->runPersonActionLoop(
            [['action' => 'select', 'index' => 1], ['action' => 'quit', 'index' => 1]],
            true,
            $log
        );

        self::assertSame(['message'], $log);
    }

    public function testPersonActionLoopAlertsWhenMessagingIsUnavailable(): void
    {
        $log = [];
        $this->runPersonActionLoop(
            [['action' => 'select', 'index' => 1], ['action' => 'quit', 'index' => 1]],
            false,
            $log
        );

        self::assertSame(['message', 'alert:error'], $log);
    }

    // ---- showPersonActions: profile fetch + seam wiring ----------------

    /**
     * @return array{0:SpyDoorHandler,1:TerminalShellInterface&\PHPUnit\Framework\MockObject\MockObject}
     */
    private function invokeShowPersonActions(array $scriptedDialog, ?array $profile): array
    {
        $spy = new SpyDoorHandler();
        $spy->profileResult = $profile;

        $shell = $this->createMock(TerminalShellInterface::class);
        $shell->method('showSelectableDialog')->willReturnOnConsecutiveCalls(...$scriptedDialog);

        $m = new ReflectionMethod(DoorHandler::class, 'showPersonActions');
        $m->setAccessible(true);
        $state = ['locale' => 'en'];
        $conn = null;
        $m->invokeArgs($spy, [
            $conn, &$state, 'sess', ['user_id' => 7, 'username' => 'ada'], $shell, self::translator(),
        ]);

        return [$spy, $shell];
    }

    public function testShowPersonActionsFetchesProfileAndDrivesTheProfileViewer(): void
    {
        $shell = $this->createMock(TerminalShellInterface::class);
        $shell->method('showSelectableDialog')->willReturnOnConsecutiveCalls(
            ['action' => 'select', 'index' => 0],
            ['action' => 'quit', 'index' => 0]
        );
        $shell->expects($this->once())->method('showPublicProfileViewer');

        $spy = new SpyDoorHandler();
        $spy->profileResult = ['user_id' => 7, 'username' => 'ada', 'about_me' => 'x'];

        $m = new ReflectionMethod(DoorHandler::class, 'showPersonActions');
        $m->setAccessible(true);
        $state = ['locale' => 'en'];
        $conn = null;
        $m->invokeArgs($spy, [$conn, &$state, 'sess', ['user_id' => 7, 'username' => 'ada'], $shell, self::translator()]);

        self::assertSame(1, $spy->profileFetchCalls);
        self::assertSame(0, $spy->directMessageCalls);
    }

    public function testShowPersonActionsDrivesTheDirectMessageSeam(): void
    {
        $shell = $this->createMock(TerminalShellInterface::class);
        $shell->method('showSelectableDialog')->willReturnOnConsecutiveCalls(
            ['action' => 'select', 'index' => 1],
            ['action' => 'quit', 'index' => 1]
        );

        $spy = new SpyDoorHandler();

        $m = new ReflectionMethod(DoorHandler::class, 'showPersonActions');
        $m->setAccessible(true);
        $state = ['locale' => 'en'];
        $conn = null;
        $m->invokeArgs($spy, [$conn, &$state, 'sess', ['user_id' => 7, 'username' => 'ada'], $shell, self::translator()]);

        self::assertSame(1, $spy->directMessageCalls);
    }

    public function testShowPersonActionsAlertsAndReturnsWhenTheCallerIsGone(): void
    {
        $shell = $this->createMock(TerminalShellInterface::class);
        $shell->expects($this->never())->method('showSelectableDialog');
        $shell->expects($this->once())->method('showAlert');

        $spy = new SpyDoorHandler();
        $spy->profileResult = null; // 404 -> caller signed off

        $m = new ReflectionMethod(DoorHandler::class, 'showPersonActions');
        $m->setAccessible(true);
        $state = ['locale' => 'en'];
        $conn = null;
        $m->invokeArgs($spy, [$conn, &$state, 'sess', ['user_id' => 7, 'username' => 'ada'], $shell, self::translator()]);

        self::assertSame(1, $spy->profileFetchCalls);
        self::assertSame(0, $spy->directMessageCalls);
    }

    // ---- openExperienceConversation: room seam + graceful failure -----

    private function invokeOpenConversation(SpyDoorHandler $spy, array $view, TerminalShellInterface $shell): void
    {
        $m = new ReflectionMethod(DoorHandler::class, 'openExperienceConversation');
        $m->setAccessible(true);
        $state = ['locale' => 'en'];
        $conn = null;
        $m->invokeArgs($spy, [$conn, &$state, 'sess', $view, $shell, self::translator()]);
    }

    public function testOpenConversationInvokesTheChatRoomSeamExactlyOnce(): void
    {
        $spy = new SpyDoorHandler();
        $spy->roomConversationResult = true;

        $shell = $this->createMock(TerminalShellInterface::class);
        $shell->expects($this->never())->method('showAlert');

        $this->invokeOpenConversation($spy, ['name' => 'X', 'actions' => ['conversation_room_id' => 42]], $shell);

        self::assertSame(1, $spy->roomConversationCalls);
        self::assertSame(42, $spy->lastRoomId);
    }

    public function testOpenConversationAlertsWhenTheRoomIsInaccessible(): void
    {
        $spy = new SpyDoorHandler();
        $spy->roomConversationResult = false; // room deactivated / not accessible

        $shell = $this->createMock(TerminalShellInterface::class);
        $shell->expects($this->once())->method('showAlert');

        $this->invokeOpenConversation($spy, ['name' => 'X', 'actions' => ['conversation_room_id' => 42]], $shell);

        self::assertSame(1, $spy->roomConversationCalls);
    }

    public function testOpenConversationAlertsAndSkipsChatWhenNoRoomIsConfigured(): void
    {
        $spy = new SpyDoorHandler();

        $shell = $this->createMock(TerminalShellInterface::class);
        $shell->expects($this->once())->method('showAlert');

        $this->invokeOpenConversation($spy, ['name' => 'X', 'actions' => ['conversation_room_id' => 0]], $shell);

        self::assertSame(0, $spy->roomConversationCalls);
    }

    // ---- 20: no duplicate chat / profile / DM implementation ----------

    public function testDoorHandlerDelegatesSocialWorkRatherThanReimplementingIt(): void
    {
        $src = file_get_contents(__DIR__ . '/../../telnet/src/DoorHandler.php');
        self::assertIsString($src);

        // Delegates to the existing infrastructure...
        self::assertStringContainsString('new ChatHandler(', $src);
        self::assertStringContainsString('->showRoom(', $src);
        self::assertStringContainsString('->showDirectMessage(', $src);
        self::assertStringContainsString('/api/user/public-profile/', $src);
        self::assertStringContainsString('showPublicProfileViewer(', $src);

        // ...and does not reimplement chat delivery or profile rendering.
        self::assertStringNotContainsString('/api/chat/send', $src);
        self::assertStringNotContainsString('chat_messages', $src);
    }
}
