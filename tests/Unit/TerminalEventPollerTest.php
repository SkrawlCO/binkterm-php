<?php

declare(strict_types=1);

require_once __DIR__ . '/../../telnet/src/TerminalEventHandlerInterface.php';
require_once __DIR__ . '/../../telnet/src/TerminalEventPoller.php';

use BinktermPHP\Realtime\StreamService;
use BinktermPHP\TelnetServer\TerminalEventHandlerInterface;
use BinktermPHP\TelnetServer\TerminalEventPoller;
use PHPUnit\Framework\TestCase;

/**
 * F3H — generic terminal event-consumption substrate.
 *
 * The poller is exercised against a fake {@see StreamService} so the contract
 * (history-skipping, throttling, forward-only cursor, non-blocking, no side
 * effects) is pinned without a database.
 */
final class TerminalEventPollerTest extends TestCase
{
    public function testStartAnchorsCursorAtNewestEventSoHistoryIsSkipped(): void
    {
        $stream = $this->fakeStream(maxId: 100, events: []);
        $poller = new TerminalEventPoller($stream, ['user_id' => 7]);

        $poller->start();

        self::assertSame(100, $poller->cursor());
    }

    public function testPollDispatchesOnlyEventsNewerThanTheAnchor(): void
    {
        $stream = $this->fakeStream(
            maxId: 5,
            events: [
                ['id' => 6, 'event' => 'mail_new', 'data' => '{"count":1}'],
                ['id' => 7, 'event' => 'sysop_broadcast', 'data' => '{"text":"hi"}'],
            ]
        );
        $poller = new TerminalEventPoller($stream, ['user_id' => 7]);

        $seen = [];
        $n = $poller->poll(function (string $type, array $payload, int $id) use (&$seen): void {
            $seen[] = [$type, $payload, $id];
        });

        self::assertSame(2, $n);
        self::assertSame('mail_new', $seen[0][0]);
        self::assertSame(['count' => 1], $seen[0][1]);
        self::assertSame(7, $poller->cursor(), 'cursor advanced to the newest dispatched id');
    }

    public function testPollIsThrottledByTheMinimumInterval(): void
    {
        $stream = $this->fakeStream(
            maxId: 0,
            events: [['id' => 1, 'event' => 'x', 'data' => '{}']]
        );
        $poller = new TerminalEventPoller($stream, ['user_id' => 1], minIntervalSeconds: 30);

        $first = $poller->poll(fn () => null);
        $second = $poller->poll(fn () => null);

        self::assertSame(1, $first);
        self::assertSame(0, $second, 'a second immediate poll is throttled');
        self::assertSame(1, $stream->fetchCalls, 'the throttled poll issued no query');
    }

    public function testHandlerInterfaceIsSupported(): void
    {
        $stream = $this->fakeStream(maxId: 0, events: [['id' => 1, 'event' => 'ping', 'data' => '{}']]);
        $poller = new TerminalEventPoller($stream, ['user_id' => 1]);

        $handler = new class implements TerminalEventHandlerInterface {
            public array $calls = [];
            public function handleTerminalEvent(string $eventType, array $payload, int $eventId): void
            {
                $this->calls[] = [$eventType, $eventId];
            }
        };

        $poller->poll($handler);

        self::assertSame([['ping', 1]], $handler->calls);
    }

    public function testEmptyOrPrunedBusIsToleratedAndCursorOnlyMovesForward(): void
    {
        $stream = $this->fakeStream(maxId: 0, events: []);
        $poller = new TerminalEventPoller($stream, ['user_id' => 1]);

        self::assertSame(0, $poller->poll(fn () => null));
        self::assertSame(0, $poller->cursor());
    }

    public function testPollNeverWritesOrPrunes(): void
    {
        $stream = $this->fakeStream(maxId: 3, events: [['id' => 4, 'event' => 'e', 'data' => '{}']]);
        $poller = new TerminalEventPoller($stream, ['user_id' => 1]);

        $poller->poll(fn () => null);

        self::assertSame(0, $stream->writeCalls, 'poller is read-only');
    }

    private function fakeStream(int $maxId, array $events): StreamService
    {
        return new class($maxId, $events) extends StreamService {
            public int $fetchCalls = 0;
            public int $writeCalls = 0;

            public function __construct(private int $maxId, private array $events)
            {
                // Intentionally skip parent ctor: no PDO needed for the fake.
            }

            public function getMaxSseId(): int
            {
                return $this->maxId;
            }

            public function fetchEventsSince(array $user, int $fromId, int $limit = 1000): array
            {
                $this->fetchCalls++;

                return array_values(array_filter(
                    $this->events,
                    static fn (array $e): bool => (int)$e['id'] > $fromId
                ));
            }
        };
    }
}
