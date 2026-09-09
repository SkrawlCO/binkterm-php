<?php

namespace BinktermPHP\TelnetServer;

/**
 * Steps a caller through a flat, cross-source queue of already-identified
 * messages, opening each one in the full message viewer and interpreting its
 * exit action as queue navigation.
 *
 * It reuses the single-message seam extracted from the existing readers
 * ({@see EchomailHandler::viewSingleMessage()}, {@see NetmailHandler::viewSingleMessage()}),
 * so a scanned message is rendered, replied to, saved, forwarded and marked
 * read exactly as it is from the normal echomail / netmail lists. This class
 * adds no rendering and no read state of its own — it is pure queue control.
 *
 * Queue item shape:
 *   ['type' => 'netmail',  'msg' => ['id' => 123]]
 *   ['type' => 'echomail', 'msg' => ['id' => 456, 'echoarea' => 'GENERAL', 'echoarea_domain' => 'fidonet']]
 */
class TerminalMessageQueueViewer
{
    public function __construct(
        private readonly NetmailHandler $netmail,
        private readonly EchomailHandler $echomail,
    ) {
    }

    /**
     * @param array<int,array{type:string,msg:array<string,mixed>}> $queue
     * @param int $start index to open first
     * @return string 'exhausted' when the caller walked or skipped off the end,
     *                'quit' when the caller quit the scan from the viewer
     */
    public function run($conn, array &$state, string $session, array $queue, int $start = 0): string
    {
        $count = count($queue);
        $i     = max(0, min($start, $count));

        while ($i < $count) {
            $item = $queue[$i];
            $result = $this->view($conn, $state, $session, $item);

            switch ($result['action'] ?? 'next') {
                case 'quit':
                    return 'quit';
                case 'prev':
                    $i = max(0, $i - 1);
                    break;
                default:
                    // next / reply / forward / deleted / anything else -> advance
                    $i++;
                    break;
            }
        }

        return 'exhausted';
    }

    /**
     * Open one queue item. Isolated so tests can drive the queue logic with a
     * scripted stand-in.
     *
     * @param array{type:string,msg:array<string,mixed>} $item
     * @return array{action:string}
     */
    protected function view($conn, array &$state, string $session, array $item): array
    {
        $msg = is_array($item['msg'] ?? null) ? $item['msg'] : [];

        if (($item['type'] ?? '') === 'netmail') {
            return $this->netmail->viewSingleMessage($conn, $state, $session, $msg, 'inbox', ['context' => 'newscan']);
        }

        return $this->echomail->viewSingleMessage($conn, $state, $session, $msg, ['context' => 'newscan']);
    }
}
