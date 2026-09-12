<?php

namespace BinktermPHP\TelnetServer;

use BinktermPHP\Mrc\MrcChatService;

/**
 * Terminal MRC (Multi Relay Chat) client.
 *
 * MRC Terminal Convergence:
 *
 *  - M1B (show()): a READ-ONLY proof that a Telnet/SSH caller can see the
 *    same live external MRC network reality the Web WebDoor shows -- daemon/
 *    network status, current rooms, recent room messages, current users --
 *    through {@see MrcChatService} directly (no terminal self-HTTP, no
 *    second MRC network client, same local Postgres state/queue tables, same
 *    supervised mrc_daemon). Selecting a room in show()/pickRoom() only
 *    chooses which locally-cached room state to VIEW -- it never queues an
 *    MRC JOIN, so it does not represent joining the room on the external
 *    network.
 *
 *  - M1C-1 (participate()): the participation/lifecycle foundation -- real
 *    identity, real JOIN, a bounded heartbeat, and a clean LEAVE. Still no
 *    message-send UI (transcript stays read-only while participating) --
 *    that is M1C-2.
 *
 * Deliberately distinct from {@see ChatHandler} (L33TEST's own local chat):
 * different network, different service, different data model. Only the
 * visual/input shell conventions are shared (TerminalShellInterface widgets),
 * not any room/presence state.
 */
class MrcChatHandler
{
    /**
     * Idle message/status/user refresh cadence while participating, in
     * milliseconds -- matches Web MRC's own 3s poll fallback interval
     * (public_html/webdoors/mrc/mrc.js pollTimer).
     */
    private const IDLE_TICK_MS = 3000;

    /**
     * Minimum seconds between heartbeat writes while participating --
     * matches Web MRC's own pingPresence() cadence (mrc.js: "at most once
     * per 30000ms"), so terminal presence ages at the same rate Web's does.
     */
    private const HEARTBEAT_INTERVAL_S = 30;

    public function __construct(private BbsSession $server, private MrcChatService $mrc)
    {
    }

    /**
     * Enter active MRC participation: verify daemon/network connectivity,
     * establish the caller's identity + presence, JOIN a room, then run a
     * dedicated (not showScrollablePanel-based) transcript loop with a real
     * idle tick -- R Rooms / U Users / L Leave / Q Back -- until the caller
     * leaves or backs out.
     *
     * Deliberately does NOT use {@see TerminalShellInterface::showScrollablePanel()}
     * for the live loop: that widget blocks inside BbsSession::readKeyWithIdleCheck(),
     * which loops past every timeout internally and never returns control on
     * one, so a caller sitting idle would never refresh presence or see new
     * messages. Instead this uses the existing PUBLIC
     * {@see BbsSession::readKeyWithTimeout()} directly -- the same token
     * vocabulary and stream_select-based timeout readKeyWithIdleCheck()
     * itself wraps, just without that method's own timeout-swallowing loop
     * -- so a timeout is visible here and drives the idle tick. No shared
     * widget is modified; M1B's show() (passive history browsing, with full
     * scrollback) is unaffected and still uses showScrollablePanel().
     *
     * The live view itself is a simpler "recent messages that fit the
     * screen" render (via TelnetUtils::renderFullScreen()) rather than
     * manual scrollback -- a deliberate scope reduction for this slice,
     * not an oversight; scrollback while participating can follow later if
     * wanted.
     *
     * No message-send path exists here -- M1C-2.
     */
    public function participate($conn, array &$state): void
    {
        $status = $this->mrc->getStatus();
        if (empty($status['connected']) || empty($status['daemon_running'])) {
            TerminalShellFactory::create($this->server, $state)->showAlert(
                $conn,
                $state,
                'MRC',
                'The MRC network is not currently connected. Please try again later.',
                'error'
            );
            return;
        }

        $room = $this->pickRoom($conn, $state);
        if ($room === null) {
            return;
        }

        [$userId, $username, $bbsName] = $this->identity($state);
        $clientIp = $this->clientIp($state);

        $this->mrc->connect($userId, $username, $bbsName, null, $clientIp);
        $this->mrc->joinRoom($userId, $username, $bbsName, $room, '', $clientIp);
        $lastHeartbeat = time();

        try {
            $this->renderParticipatingScreen($conn, $state, $room);

            while (true) {
                [$key, $timedOut, $shouldDisconnect] = $this->server->readKeyWithTimeout($conn, $state, self::IDLE_TICK_MS);

                if ($shouldDisconnect) {
                    return; // finally still runs -> leaveRoom
                }

                if ($this->dueForHeartbeat($lastHeartbeat, time())) {
                    $this->mrc->recordHeartbeat($userId, $username, $bbsName, $room);
                    $lastHeartbeat = time();
                }

                if ($timedOut) {
                    // Idle tick: no key was pressed. Refresh the screen
                    // (messages/status/users may have changed) and loop --
                    // never queues a network command on its own.
                    $this->renderParticipatingScreen($conn, $state, $room);
                    continue;
                }

                $action = $this->mapParticipatingKey($key);

                if ($action === 'rooms') {
                    $next = $this->pickRoom($conn, $state);
                    if ($next !== null && $next !== $room) {
                        // Real JOIN semantics for the new room (NEWROOM:from:to),
                        // matching Web MRC's own room-switch behavior exactly.
                        $this->mrc->joinRoom($userId, $username, $bbsName, $next, $room, $clientIp);
                        $room = $next;
                        $lastHeartbeat = time();
                    }
                    $this->renderParticipatingScreen($conn, $state, $room);
                    continue;
                }

                if ($action === 'users') {
                    $this->showUsers($conn, $state, $room, $this->mrc->getUsers($room));
                    $this->renderParticipatingScreen($conn, $state, $room);
                    continue;
                }

                if ($action === 'quit') {
                    // Covers both explicit Leave (L) and the generic
                    // Q/B/Esc/Enter back keys -- both mean "I'm done here".
                    return;
                }

                // Unrecognised key: ignore, redraw.
                $this->renderParticipatingScreen($conn, $state, $room);
            }
        } finally {
            // Room-scoped leave only (see MrcChatService::leaveRoom() docblock):
            // never the whole-account disconnect(), which would tear down an
            // independently-active Web MRC session for the same account in a
            // different room. Covers the normal exit path and any disconnect
            // readKeyWithTimeout() detects within this call stack; a lower-
            // level abrupt kill that never reaches this code is NOT covered --
            // see the class-level M1C-1 lifecycle-gap note.
            $this->mrc->leaveRoom($userId, $username, $bbsName, $room);
        }
    }

    /** Pure cadence check: has HEARTBEAT_INTERVAL_S elapsed since the last heartbeat? */
    private function dueForHeartbeat(int $lastHeartbeat, int $now): bool
    {
        return ($now - $lastHeartbeat) >= self::HEARTBEAT_INTERVAL_S;
    }

    /**
     * Map a raw BbsSession key token to a participating-loop action. Same
     * exit-key vocabulary as showScrollablePanel() (ENTER/ESC/q/Q/b/B ->
     * quit) plus this loop's own r/u/l shortcuts.
     */
    private function mapParticipatingKey(?string $key): string
    {
        if ($key === null || $key === 'ENTER' || $key === 'ESC') {
            return 'quit';
        }
        if (str_starts_with($key, 'CHAR:')) {
            $char = strtolower(substr($key, 5));
            if ($char === 'q' || $char === 'b' || $char === 'l') {
                return 'quit';
            }
            if ($char === 'r') {
                return 'rooms';
            }
            if ($char === 'u') {
                return 'users';
            }
        }
        return 'noop';
    }

    /** Redraw the live participating screen from current service state. No writes. */
    private function renderParticipatingScreen($conn, array &$state, string $room): void
    {
        $liveStatus = $this->mrc->getStatus();
        $users = $this->mrc->getUsers($room);
        $rows = max(10, (int)($state['rows'] ?? 24));
        $lines = $this->formatTranscript($this->mrc->getRecentRoomMessages($room, $rows));

        TelnetUtils::renderFullScreen(
            $conn,
            [$this->participatingTitle($room, $liveStatus)],
            $lines,
            $this->participatingStatusLine(count($users)),
            $rows
        );
    }

    /**
     * Resolve the terminal caller's MRC identity from their existing
     * authenticated BinkTerm session -- the exact same fallback path Web MRC
     * uses when its username field is left blank (no separate terminal
     * naming scheme, no password prompt in this slice).
     *
     * @return array{0:?int,1:string,2:string} [user_id, username, bbs_name]
     */
    private function identity(array $state): array
    {
        $userId = (int)($state['user_id'] ?? 0);
        $username = $this->mrc->normalizeHandle('', (string)($state['username'] ?? ''));
        $bbsName = \BinktermPHP\Mrc\MrcClient::sanitizeName(
            \BinktermPHP\Mrc\MrcConfig::getInstance()->getBbsName()
        );
        return [$userId > 0 ? $userId : null, $username, $bbsName];
    }

    /**
     * The caller's peer IP is a private BbsSession property with no public
     * accessor and no $state entry -- adding one is a BbsSession change,
     * out of scope for this bounded slice. MrcChatService::connect()/
     * joinRoom() already treat a null/empty IP as "don't queue USERIP",
     * exactly as Web MRC does when its own IP resolution comes up empty, so
     * this is a safe, honest simplification, not a protocol deviation.
     */
    private function clientIp(array $state): ?string
    {
        return null;
    }

    private function participatingTitle(string $room, array $status): string
    {
        $network = !empty($status['connected']) ? 'Connected' : 'Disconnected';
        return 'MRC — #' . $room . ' — Participating — ' . $network;
    }

    private function participatingStatusLine(int $userCount): string
    {
        return $userCount . ' user(s) — R Rooms  U Users  L Leave  Q Back';
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
