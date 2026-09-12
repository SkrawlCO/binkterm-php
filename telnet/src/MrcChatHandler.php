<?php

namespace BinktermPHP\TelnetServer;

use BinktermPHP\Mrc\MrcChatService;

/**
 * Terminal MRC (Multi Relay Chat) client.
 *
 * MRC Terminal Convergence M1B: a READ-ONLY proof that a Telnet/SSH caller
 * can see the same live external MRC network reality the Web WebDoor shows
 * -- daemon/network status, current rooms, recent room messages, current
 * users -- through {@see MrcChatService} directly (no terminal self-HTTP, no
 * second MRC network client, same local Postgres state/queue tables, same
 * supervised mrc_daemon).
 *
 * M1B intentionally does NOT identify, join, send, or heartbeat. Selecting a
 * room here only chooses which locally-cached room state to VIEW -- it never
 * queues an MRC JOIN command, so it does not represent joining the room on
 * the external network. That participation lifecycle (identify/connect,
 * join/leave, send, heartbeat, clean disconnect) is M1C.
 *
 * Deliberately distinct from {@see ChatHandler} (L33TEST's own local chat):
 * different network, different service, different data model. Only the
 * visual/input shell conventions are shared (TerminalShellInterface widgets),
 * not any room/presence state.
 */
class MrcChatHandler
{
    public function __construct(private BbsSession $server, private MrcChatService $mrc)
    {
    }

    /**
     * Entry point: pick a room to view, then loop the read-only transcript
     * view until the caller backs out to the calling menu.
     */
    public function show($conn, array &$state): void
    {
        $room = $this->pickRoom($conn, $state);
        if ($room === null) {
            return;
        }

        while (true) {
            $shell = TerminalShellFactory::create($this->server, $state);
            $status = $this->mrc->getStatus();
            $users = $this->mrc->getUsers($room);
            $lines = $this->formatTranscript($this->mrc->getRecentRoomMessages($room, 100));

            $key = $shell->showScrollablePanel(
                $conn,
                $state,
                $this->panelTitle($room, $status),
                $lines,
                [
                    'status_line' => $this->statusLine(count($users)),
                    'extra_keys' => ['r' => 'rooms', 'u' => 'users'],
                ]
            );

            if ($key === 'rooms') {
                $next = $this->pickRoom($conn, $state);
                if ($next !== null) {
                    $room = $next;
                }
                continue;
            }

            if ($key === 'users') {
                $this->showUsers($conn, $state, $room, $users);
                continue;
            }

            return; // 'quit' (Q/B/Esc/Enter) — back to the calling menu
        }
    }

    /**
     * Present the current room list and let the caller pick one to VIEW.
     * This never queues a JOIN — it is a passive local read, not
     * participation on the external network.
     *
     * @return string|null The chosen room name, or null if the caller backed out.
     */
    private function pickRoom($conn, array &$state): ?string
    {
        $rooms = $this->mrc->getRoomList();
        $shell = TerminalShellFactory::create($this->server, $state);

        $items = array_map(static function (array $room): string {
            $label = '#' . $room['room_name'] . ' (' . (int)($room['user_count'] ?? 0) . ')';
            $topic = trim((string)($room['topic'] ?? ''));
            return $topic !== '' ? $label . ' — ' . $topic : $label;
        }, $rooms);

        $index = $shell->chooseFromList($conn, $state, 'MRC Rooms — View', $items, [
            'empty_message' => 'No MRC rooms available right now.',
        ]);

        return $index === null ? null : (string)$rooms[$index]['room_name'];
    }

    /**
     * A lightweight user-list overlay. Read-only; returns to the transcript
     * view on any key.
     *
     * @param array<int, array<string, mixed>> $users
     */
    private function showUsers($conn, array &$state, string $room, array $users): void
    {
        $lines = [];
        foreach ($users as $user) {
            $bbs = trim((string)($user['bbs_name'] ?? ''));
            $afk = !empty($user['is_afk']) ? ' (AFK)' : '';
            $lines[] = ' ' . $user['username'] . ($bbs !== '' ? ' @ ' . $bbs : '') . $afk;
        }
        if ($lines === []) {
            $lines[] = ' No one is currently in this room.';
        }

        $shell = TerminalShellFactory::create($this->server, $state);
        $shell->showPagedBox(
            $conn,
            $state,
            'Users in #' . $room,
            $lines,
            'Press any key to return...'
        );
    }

    /** @param array<int, array<string, mixed>> $messages */
    private function formatTranscript(array $messages): array
    {
        if ($messages === []) {
            return ['(no recent messages in this room)'];
        }

        $lines = [];
        foreach ($messages as $message) {
            $when = '';
            $receivedAt = (string)($message['received_at'] ?? '');
            if ($receivedAt !== '') {
                $ts = strtotime($receivedAt);
                if ($ts !== false) {
                    $when = date('H:i', $ts) . ' ';
                }
            }
            $from = (string)($message['from_user'] ?? '?');
            $body = $this->stripMrcPipeCodes((string)($message['message_body'] ?? ''));
            $lines[] = $when . '<' . $from . '> ' . $body;
        }
        return $lines;
    }

    /** MRC's own inline "|NN" colour codes (distinct from ANSI) -- stripped, not themed, for M1B. */
    private function stripMrcPipeCodes(string $text): string
    {
        return (string)preg_replace('/\|[0-9]{2}/', '', $text);
    }

    private function panelTitle(string $room, array $status): string
    {
        $network = !empty($status['connected']) ? 'Connected' : 'Disconnected';
        return 'MRC — #' . $room . ' — ' . $network;
    }

    private function statusLine(int $userCount): string
    {
        return $userCount . ' user(s) — R Rooms  U Users  Q Back';
    }
}
