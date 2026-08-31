<?php

declare(strict_types=1);

use BinktermPHP\TelnetServer\ChatHandler;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../telnet/src/TelnetUtils.php';
require_once __DIR__ . '/../../telnet/src/ChatHandler.php';

/**
 * ChatHandler contextual-target entrypoints for Crossroads Slice 2.
 *
 * showRoom() / showDirectMessage() let another handler open the existing chat
 * client focused on a specific room or DM. The only new gating logic lives in
 * openPreferredTarget(): a room must be present in the authorized room list
 * (GET /api/chat/rooms) before it is opened; a DM only needs a positive id and
 * a label. These tests pin those guards without touching the network.
 */
final class ChatHandlerContextualTargetTest extends TestCase
{
    private function invokeOpenPreferredTarget(array $chat, array $target): array
    {
        $handler = (new ReflectionClass(ChatHandler::class))->newInstanceWithoutConstructor();
        $m = new ReflectionMethod(ChatHandler::class, 'openPreferredTarget');
        $m->setAccessible(true);

        $session = 'sess';
        $state = ['locale' => 'en'];
        $result = $m->invokeArgs($handler, [&$chat, $session, &$state, $target]);

        return [$result, $chat];
    }

    public function testRoomTargetIsRejectedWhenNotInTheAuthorizedRoomList(): void
    {
        [$result, $chat] = $this->invokeOpenPreferredTarget(
            ['room_map' => [], 'online_map' => []],
            ['type' => 'room', 'id' => 99]
        );

        self::assertFalse($result);
        self::assertArrayNotHasKey('active_target', $chat);
    }

    public function testTargetWithNonPositiveIdIsRejected(): void
    {
        [$result] = $this->invokeOpenPreferredTarget(
            ['room_map' => [7 => ['name' => 'Lobby']], 'online_map' => []],
            ['type' => 'room', 'id' => 0]
        );

        self::assertFalse($result);
    }

    public function testDirectMessageTargetIsRejectedWithoutALabel(): void
    {
        [$result] = $this->invokeOpenPreferredTarget(
            ['room_map' => [], 'online_map' => []],
            ['type' => 'dm', 'id' => 5, 'label' => '   ']
        );

        self::assertFalse($result);
    }

    public function testUnknownTargetTypeIsRejected(): void
    {
        [$result] = $this->invokeOpenPreferredTarget(
            ['room_map' => [], 'online_map' => []],
            ['type' => 'nonsense', 'id' => 5]
        );

        self::assertFalse($result);
    }

    public function testContextualEntrypointsExistWithBoolReturns(): void
    {
        $room = new ReflectionMethod(ChatHandler::class, 'showRoom');
        $dm = new ReflectionMethod(ChatHandler::class, 'showDirectMessage');

        self::assertTrue($room->isPublic());
        self::assertTrue($dm->isPublic());
        self::assertSame('bool', (string) $room->getReturnType());
        self::assertSame('bool', (string) $dm->getReturnType());

        // show() stays a void wrapper.
        $show = new ReflectionMethod(ChatHandler::class, 'show');
        self::assertSame('void', (string) $show->getReturnType());
    }
}
