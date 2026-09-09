<?php

declare(strict_types=1);

require_once __DIR__ . '/Support/TerminalRenderHarness.php';
require_once __DIR__ . '/../../telnet/src/TelnetUtils.php';
require_once __DIR__ . '/../../telnet/src/TerminalBoxRenderer.php';
require_once __DIR__ . '/../../telnet/src/BbsSession.php';
require_once __DIR__ . '/../../telnet/src/MailUtils.php';
require_once __DIR__ . '/../../telnet/src/NetmailHandler.php';
require_once __DIR__ . '/../../telnet/src/EchomailHandler.php';
require_once __DIR__ . '/../../telnet/src/TerminalMessageQueueViewer.php';

use BinktermPHP\TelnetServer\TerminalMessageQueueViewer;
use PHPUnit\Framework\TestCase;

/**
 * Queue-control logic for {@see TerminalMessageQueueViewer}. The per-message
 * `view()` seam is stubbed with a scripted sequence of viewer actions so the
 * cursor / quit-propagation / skip behaviour can be asserted without a socket
 * or the real message readers.
 */
final class TerminalMessageQueueViewerTest extends TestCase
{
    /**
     * @param string[] $script viewer actions returned in order, one per open
     */
    private function scripted(array $script): TerminalMessageQueueViewer
    {
        return new class ($script) extends TerminalMessageQueueViewer {
            /** @var string[] */
            private array $script;
            private int $step = 0;
            /** @var array<int,int> */
            public array $opened = [];

            public function __construct(array $script)
            {
                // No parent ctor: view() is stubbed, so the handlers are never touched.
                $this->script = $script;
            }

            protected function view($conn, array &$state, string $session, array $item): array
            {
                $this->opened[] = (int) ($item['msg']['id'] ?? -1);
                $action = $this->script[$this->step] ?? 'next';
                $this->step++;

                return ['action' => $action];
            }
        };
    }

    /** @param int[] $ids */
    private function queue(array $ids): array
    {
        return array_map(static fn (int $id): array => ['type' => 'echomail', 'msg' => ['id' => $id]], $ids);
    }

    public function testWalksTheWholeQueueThenReportsExhausted(): void
    {
        $viewer = $this->scripted(['next', 'next', 'next']);
        $state = [];

        $result = $viewer->run(null, $state, 'sess', $this->queue([1, 2, 3]));

        self::assertSame('exhausted', $result);
        self::assertSame([1, 2, 3], $viewer->opened);
    }

    public function testEmptyQueueIsImmediatelyExhausted(): void
    {
        $viewer = $this->scripted([]);
        $state = [];

        self::assertSame('exhausted', $viewer->run(null, $state, 'sess', []));
        self::assertSame([], $viewer->opened);
    }

    public function testQuitFromTheViewerPropagates(): void
    {
        $viewer = $this->scripted(['next', 'quit']);
        $state = [];

        $result = $viewer->run(null, $state, 'sess', $this->queue([10, 11, 12, 13]));

        self::assertSame('quit', $result);
        self::assertSame([10, 11], $viewer->opened, 'stops at the message the caller quit from');
    }

    public function testPrevStepsBackAndFloorsAtZero(): void
    {
        // open 0 -> next(1) -> prev(back to 0) -> next(1) -> next(2) -> exhausted
        $viewer = $this->scripted(['next', 'prev', 'next', 'next']);
        $state = [];

        $result = $viewer->run(null, $state, 'sess', $this->queue([100, 200, 300]));

        self::assertSame('exhausted', $result);
        self::assertSame([100, 200, 100, 200, 300], $viewer->opened);
    }

    public function testPrevOnTheFirstMessageStaysPut(): void
    {
        $viewer = $this->scripted(['prev', 'next']);
        $state = [];

        $viewer->run(null, $state, 'sess', $this->queue([5, 6]));

        self::assertSame([5, 5, 6], $viewer->opened);
    }

    public function testReplyForwardAndDeletedAllAdvance(): void
    {
        $viewer = $this->scripted(['reply', 'forward', 'deleted']);
        $state = [];

        $result = $viewer->run(null, $state, 'sess', $this->queue([1, 2, 3]));

        self::assertSame('exhausted', $result);
        self::assertSame([1, 2, 3], $viewer->opened);
    }

    public function testStartIndexIsHonoured(): void
    {
        $viewer = $this->scripted(['next', 'next']);
        $state = [];

        $viewer->run(null, $state, 'sess', $this->queue([1, 2, 3, 4]), 2);

        self::assertSame([3, 4], $viewer->opened);
    }
}
