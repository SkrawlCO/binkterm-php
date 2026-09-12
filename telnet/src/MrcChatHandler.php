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
 *    identity, real JOIN, a bounded heartbeat, and a clean LEAVE.
 *
 *  - M1C-2 (composeAndSend(), the 'M' key): room-message composition/send
 *    via {@see MrcChatService::sendMessage()} -- the same operation, room,
 *    identity, and length limit Web MRC's own `send` action uses. No local
 *    echo: the relayed/inbound copy shows up through the existing ~3s idle
 *    refresh, exactly like Web MRC's own poll. Still no private messages,
 *    no registration/moderation commands -- those remain out of scope.
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
     * M1C-2: pressing M composes and sends one room message via
     * MrcChatService::sendMessage() (see composeAndSend()) -- the only path
     * in this handler that ever queues a chat message. Passive view (show()),
     * the idle tick, and heartbeat never send.
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

        $room = $this->pickRoom($conn, $state, 'participate');
        if ($room === null) {
            return;
        }

        [$userId, $username, $bbsName] = $this->identity($state);
        $clientIp = $this->clientIp($state);

        $this->mrc->connect($userId, $username, $bbsName, null, $clientIp);
        $this->mrc->joinRoom($userId, $username, $bbsName, $room, '', $clientIp);
        $lastHeartbeat = time();

        try {
            $this->renderParticipatingScreen($conn, $state, $room, $username);

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
                    $this->renderParticipatingScreen($conn, $state, $room, $username);
                    continue;
                }

                $action = $this->mapParticipatingKey($key);

                if ($action === 'rooms') {
                    $next = $this->pickRoom($conn, $state, 'participate');
                    if ($next !== null && $next !== $room) {
                        // Real JOIN semantics for the new room (NEWROOM:from:to),
                        // matching Web MRC's own room-switch behavior exactly.
                        $this->mrc->joinRoom($userId, $username, $bbsName, $next, $room, $clientIp);
                        $room = $next;
                        $lastHeartbeat = time();
                    }
                    $this->renderParticipatingScreen($conn, $state, $room, $username);
                    continue;
                }

                if ($action === 'users') {
                    $this->showUsers($conn, $state, $room, $this->mrc->getUsers($room));
                    $this->renderParticipatingScreen($conn, $state, $room, $username);
                    continue;
                }

                if ($action === 'message') {
                    $this->composeAndSend($conn, $state, $room, $username, $bbsName);
                    // Post-send: return straight to the room screen. No local
                    // echo -- the normal ~3s idle-tick refresh (unchanged)
                    // shows the relayed/inbound copy exactly as Web MRC's own
                    // poll does, not an optimistic client-side insert.
                    $this->renderParticipatingScreen($conn, $state, $room, $username);
                    continue;
                }

                if ($action === 'quit') {
                    // Covers both explicit Leave (L) and the generic
                    // Q/B/Esc/Enter back keys -- both mean "I'm done here".
                    return;
                }

                // Unrecognised key: ignore, redraw.
                $this->renderParticipatingScreen($conn, $state, $room, $username);
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
            if ($char === 'm') {
                return 'message';
            }
        }
        return 'noop';
    }

    /**
     * Prompt for one room message and send it via MrcChatService::sendMessage()
     * -- the exact same operation/validation/truncation Web MRC's `send`
     * action uses, unchanged. Empty input (or Esc) cancels without sending.
     * A validation failure (InvalidArgumentException) shows a brief alert and
     * returns to the room; it is never destructive to participation.
     */
    private function composeAndSend($conn, array &$state, string $room, string $username, string $bbsName): void
    {
        $shell = TerminalShellFactory::create($this->server, $state);
        $input = $shell->promptText($conn, $state, 'MRC — #' . $room, 'Message: ', [
            'max_length' => 140,
        ]);

        if ($this->shouldCancelSend($input)) {
            return; // cancelled or empty -- nothing sent
        }

        try {
            $this->sendRoomMessage($room, $username, $bbsName, $input);
        } catch (\InvalidArgumentException $e) {
            $shell->showAlert($conn, $state, 'MRC', $e->getMessage(), 'error');
        }
    }

    /** Pure guard: cancelled (Esc/null) or blank input sends nothing. */
    private function shouldCancelSend(?string $input): bool
    {
        return $input === null || trim($input) === '';
    }

    /**
     * The actual send, split out from composeAndSend() so it is directly
     * testable without driving the interactive promptText() widget: given
     * input, send it via MrcChatService::sendMessage() -- the same
     * operation/validation/length-limit Web MRC's own `send` action uses.
     */
    private function sendRoomMessage(string $room, string $username, string $bbsName, string $message): void
    {
        $this->mrc->sendMessage(
            $username,
            $bbsName,
            $room,
            $message,
            '',
            \BinktermPHP\Mrc\MrcConfig::getInstance()->getMaxMessageLength()
        );
    }

    /**
     * Redraw the live participating screen from current service state. No writes.
     *
     * M1C-2D layout (box removed, header-garble mitigated): back to the
     * lean, already-production-proven {@see TelnetUtils::renderFullScreen()}
     * shape used by e.g. EchomailHandler's message viewer and
     * runKludgeViewer() -- one compact header row (MRC/#room/connection
     * state/occupancy folded into it), one separator, a DENSE full-width
     * transcript (no per-row border glyphs), one more separator, and a
     * controls row. No left/right rails, no bottom frame corners.
     *
     * M1C-2C's full-frame design (removed here) added per-row corner/border
     * width computation -- multiple colorize()+encodeForTerminal() segments
     * concatenated per row -- to build the top/bottom borders and every
     * transcript/controls row's left/right rails. That is meaningfully more
     * width-computation surface than this simpler shape has, and unlike this
     * shape it has no track record elsewhere in the codebase. Human
     * SyncTerm-over-SSH testing showed the top of the frame still missing
     * after M1C-2C even with {@see effectiveRows()} reserving a row
     * unconditionally, which rules out a pure row-COUNT overflow as the
     * (or at least the only) cause -- the disposable synthetic capture used
     * to "prove" M1C-2C only checks byte/row COUNTS on a memory stream,
     * which has no column width or auto-wrap behavior at all, so it cannot
     * detect a real terminal auto-wrapping one visually-overlong row and
     * pushing everything down. Rather than chase that byte-for-byte on a
     * client this environment cannot drive live, this revision removes the
     * entire risk surface by returning to the simple shape with the clean
     * production history. {@see effectiveRows()}'s unconditional reservation
     * is kept (cheap, and it is the confirmed fix for the earlier
     * controls-vs-status-bar collision) but is no longer claimed to be a
     * complete explanation for the top-garble symptom by itself.
     */
    private function renderParticipatingScreen($conn, array &$state, string $room, string $currentUsername): void
    {
        $liveStatus = $this->mrc->getStatus();
        $users = $this->mrc->getUsers($room);
        $cols = max(20, (int)($state['cols'] ?? 80));
        $rows = $this->effectiveRows($state);

        $headerRow = $this->compactHeaderLine($room, $liveStatus, count($users), $cols);
        $separator = TelnetUtils::colorize(str_repeat('-', $cols), TelnetUtils::ANSI_DIM);

        // Budget: header (1) + top separator (1) + bottom separator (1, last
        // body row) + controls (1, renderFullScreen's own status-line row).
        $transcriptHeight = max(1, $rows - 4);
        $transcript = $this->buildTranscriptViewport($room, $cols, $transcriptHeight, $currentUsername);

        TelnetUtils::renderFullScreen(
            $conn,
            [$headerRow, $separator],
            array_merge($transcript, [$separator]),
            $this->participatingStatusLine($cols),
            $rows
        );
    }

    /**
     * Usable rows for a full-screen redraw of this screen, after reserving
     * space for the terminal client's own persistent status line.
     *
     * Reserves unconditionally rather than only when $state['terminal_type']
     * names SyncTerm, since SyncTerm-over-SSH reports its pty TERM instead
     * (e.g. "xterm-256color" -- see ssh/src/SshSession::getTermType()'s own
     * doc comment) and a terminal_type-based check silently missed that
     * transport. This is the confirmed fix for the original M1C-2A
     * controls-vs-persistent-status-bar collision; it is kept as a cheap,
     * safe margin but -- per the human M1C-2C SSH recheck -- is not by
     * itself a complete explanation for the separate top-of-frame garble
     * symptom (see {@see renderParticipatingScreen()}'s docblock).
     * {@see TelnetUtils}'s own selector-style screens (its private
     * getSelectorRows()) still key off the terminal-type string; that is a
     * pre-existing, broader-reaching mechanism out of scope for this
     * bounded MRC-only fix.
     */
    private function effectiveRows(array $state): int
    {
        $rows = max(10, (int)($state['rows'] ?? 24));
        return max(10, $rows - 1);
    }

    /**
     * The compact one-row header: "MRC // #room" on the left, connection
     * state + occupancy on the right -- so WHERE and STATE are both
     * immediately obvious without opening Users, in a single row rather
     * than a standalone branding row or a multi-row block. Falls back to
     * dropping the state/occupancy segment (never truncating mid-room-name)
     * if $cols is too narrow to fit both.
     */
    private function compactHeaderLine(string $room, array $status, int $userCount, int $cols): string
    {
        $connected = !empty($status['connected']);
        $left = 'MRC // #' . $room;
        $right = $connected ? ('CONNECTED  ' . $userCount . ' ONLINE') : 'DISCONNECTED';
        $rightColor = $connected ? TelnetUtils::ANSI_GREEN : (TelnetUtils::ANSI_RED . TelnetUtils::ANSI_BOLD);

        if (mb_strlen($left) + 1 + mb_strlen($right) > $cols) {
            $right = ''; // not enough room -- drop state/occupancy before truncating the room name
        }
        if (mb_strlen($left) > $cols) {
            $left = mb_substr($left, 0, $cols);
        }

        $gap = max(1, $cols - mb_strlen($left) - mb_strlen($right));
        $leftSeg = TelnetUtils::colorize($left, TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD);
        $rightSeg = $right !== '' ? TelnetUtils::colorize($right, $rightColor) : '';

        return $leftSeg . str_repeat(' ', $gap) . $rightSeg;
    }

    /**
     * The recent-transcript viewport: wrapped to the caller's real column
     * width, bottom-anchored on the newest message, sized to exactly the
     * rows available (never more, never fewer).
     *
     * Fetches a small bounded batch of recent messages -- not
     * MrcChatService's full 100-message ceiling, which is why the original
     * (pre-M1B-fix) layout dumped an oversized backlog -- wraps each to
     * $width, then keeps only the last $viewportHeight wrapped lines.
     * getRecentRoomMessages() already returns its batch oldest-to-newest, so
     * a tail slice is the newest content, satisfying "orient the caller to
     * CURRENT conversation" without any scrollback engine. Short/quiet rooms
     * are padded with blank lines ABOVE the content so the transcript stays
     * anchored to the bottom of the viewport, like a live-chat window --
     * never vertically centered, and never inserted BETWEEN messages.
     *
     * Semantic styling is applied per line via
     * {@see formatColoredTranscript()} before wrapping -- {@see
     * TelnetUtils::wrapTextLines()} measures ANSI escapes as zero-width and
     * never splits one across a line break, so colorizing first is safe.
     * formatColoredTranscript() also normalizes any embedded CR/LF out of a
     * message body before this wraps it, so one ordinary short message can
     * never itself expand into "content line + blank line" -- see its
     * docblock for the message-density fix this addresses.
     *
     * @return string[] exactly $viewportHeight lines
     */
    private function buildTranscriptViewport(string $room, int $width, int $viewportHeight, string $currentUsername): array
    {
        // Bounded, not tied 1:1 to the viewport: a little scrollback slack
        // for lines that don't wrap, without ever fetching/rendering the
        // service's full 100-message ceiling merely because it is allowed.
        $fetchCount = max(10, min(60, $viewportHeight * 3));
        $messages = $this->mrc->getRecentRoomMessages($room, $fetchCount);

        $wrapped = [];
        foreach ($this->formatColoredTranscript($messages, $currentUsername) as $line) {
            foreach (TelnetUtils::wrapTextLines($line, $width) as $wrappedLine) {
                $wrapped[] = $wrappedLine;
            }
        }

        if (count($wrapped) > $viewportHeight) {
            return array_slice($wrapped, -$viewportHeight);
        }
        if (count($wrapped) < $viewportHeight) {
            return array_merge(array_fill(0, $viewportHeight - count($wrapped), ''), $wrapped);
        }
        return $wrapped;
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

    /**
     * The controls row, built via {@see TelnetUtils::buildStatusBar()} --
     * the same colored-segment convention used by every other full-screen
     * viewer in the terminal server, so it reads as a distinct control
     * strip rather than more transcript text. Room/connection-state/
     * occupancy live in the header instead (see {@see compactHeaderLine()}),
     * so this row is controls only. $width is the full terminal column
     * count -- there is no border/frame to subtract for (M1C-2D).
     */
    private function participatingStatusLine(int $width): string
    {
        $statusBar = TelnetUtils::getDefaultStyleProfile()['status_bar'] ?? [];
        $key = $statusBar['key'] ?? TelnetUtils::ANSI_RED;
        $label = $statusBar['label'] ?? TelnetUtils::ANSI_BLUE;

        return TelnetUtils::buildStatusBar([
            ['text' => 'M', 'color' => $key], ['text' => 'essage  ', 'color' => $label],
            ['text' => 'R', 'color' => $key], ['text' => 'ooms  ', 'color' => $label],
            ['text' => 'U', 'color' => $key], ['text' => 'sers  ', 'color' => $label],
            ['text' => 'L', 'color' => $key], ['text' => 'eave  ', 'color' => $label],
            ['text' => 'Q', 'color' => $key], ['text' => 'Back', 'color' => $label],
        ], $width, $statusBar);
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
    /**
     * @param string $purpose 'view' (show()'s read-only viewer) or
     *                        'participate' (join a live room) -- selector
     *                        widget, occupancy/topic columns, and Enter/Q
     *                        controls are unchanged either way (M1C-2B Step 6:
     *                        cohesion only, not a redesign); only the title
     *                        wording reflects what picking a room actually
     *                        does in each context.
     */
    private function pickRoom($conn, array &$state, string $purpose = 'view'): ?string
    {
        $rooms = $this->mrc->getRoomList();
        $shell = TerminalShellFactory::create($this->server, $state);

        $items = array_map(static function (array $room): string {
            $label = '#' . $room['room_name'] . ' (' . (int)($room['user_count'] ?? 0) . ')';
            $topic = trim((string)($room['topic'] ?? ''));
            return $topic !== '' ? $label . ' — ' . $topic : $label;
        }, $rooms);

        $title = $purpose === 'participate' ? 'MRC — Join a Room' : 'MRC Rooms — View';
        $index = $shell->chooseFromList($conn, $state, $title, $items, [
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

    /**
     * M1C-2D message-density fix: collapse any embedded CR/LF in a message
     * body to a single space, and trim the result. MRC is a single-line-per-
     * message protocol -- a message body is never intentionally multi-line
     * the way an echomail body is -- so an embedded newline (from whatever
     * client/relay put it there) is noise, not formatting to preserve. Used
     * only by {@see formatColoredTranscript()} (the participate() screen);
     * show()'s original formatTranscript() is untouched.
     */
    private function collapseEmbeddedNewlines(string $text): string
    {
        return trim((string)preg_replace('/\r\n|\r|\n/', ' ', $text));
    }

    /**
     * Restrained ANSI foreground palette for deterministic per-handle color
     * (see {@see speakerColor()}). Excludes {@see TelnetUtils::ANSI_RED}
     * (reserved for the DISCONNECTED header state) and
     * {@see TelnetUtils::ANSI_YELLOW} (reserved for "concerns me" -- the
     * caller's own messages and mentions of their handle, see
     * {@see formatColoredTranscript()}), and excludes dim/default colors
     * that read poorly on a black background.
     */
    private const HANDLE_PALETTE = [
        TelnetUtils::ANSI_GREEN,
        TelnetUtils::ANSI_BLUE,
        TelnetUtils::ANSI_MAGENTA,
        TelnetUtils::ANSI_CYAN,
    ];

    /**
     * The M1C-2B colorized transcript for the participating screen.
     *
     * Web MRC's own room/public chat view (public_html/webdoors/mrc/mrc.js,
     * mrc.css) has exactly ONE semantic distinction today: SERVER/system
     * messages render muted/subordinate (`.message-system`: dim gray,
     * italic). It has NO per-user color and NO "own message" highlight for
     * room chat -- `.message-sent`/`.message-user` exist in the stylesheet
     * but are only ever applied to the local echo of a PRIVATE message
     * (mrc.js echoSentMessage()), which this room transcript never shows.
     * So this method mirrors ONE real Web semantic (SERVER muted) and adds
     * terminal-only enhancements human-directed for M1C-2B (stable per-
     * handle color, an own-message/mention accent) -- all derived only from
     * fields the data already has (from_user, message_body, the caller's own
     * resolved identity), nothing fabricated.
     *
     * M1C-2D message-density fix: an embedded CR/LF inside one message's raw
     * `message_body` (whatever its origin on the wire) previously reached
     * {@see TelnetUtils::wrapTextLines()} unchanged, which treats each
     * `\r?\n` as its own display line per its own documented contract --
     * turning ONE short chat message into "content line" + a trailing BLANK
     * line, which is exactly the "excessive blank vertical space between
     * messages" the M1C-2C human recheck reported. Every embedded CR/LF is
     * now collapsed to a single space before a message is built into a
     * line, so an ordinary one-line message can only ever wrap on real
     * WIDTH the way wrapTextLines() is meant to be used here, never on
     * incidental content newlines. Stored message text itself is untouched
     * -- this only affects what is built for display.
     *
     * @param array<int, array<string, mixed>> $messages
     */
    private function formatColoredTranscript(array $messages, string $currentUsername): array
    {
        if ($messages === []) {
            return [TelnetUtils::colorize('(no recent messages in this room)', TelnetUtils::ANSI_DIM)];
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
            $timeSegment = $when !== '' ? TelnetUtils::colorize($when, TelnetUtils::ANSI_DIM) : '';

            $from = (string)($message['from_user'] ?? '?');
            $body = $this->stripMrcPipeCodes((string)($message['message_body'] ?? ''));
            $body = $this->collapseEmbeddedNewlines($body);
            $category = $this->messageCategory($from, $body, $currentUsername);

            if ($category === 'join_leave' || $category === 'server') {
                // SERVER carries no separate handle to color -- the body IS
                // the notice/announcement text (mrc_daemon.php inserts these
                // with from_user literally 'SERVER'), so no duplicate-prefix
                // stripping applies here.
                $color = $category === 'join_leave'
                    ? TelnetUtils::ANSI_DIM . TelnetUtils::ANSI_GREEN
                    : TelnetUtils::ANSI_DIM;
                $lines[] = $timeSegment . TelnetUtils::colorize($body, $color);
                continue;
            }

            $body = $this->stripDuplicateSpeakerPrefix($body, $from);

            $handleColor = $category === 'own'
                ? TelnetUtils::ANSI_YELLOW . TelnetUtils::ANSI_BOLD
                : $this->speakerColor($from);
            $handle = TelnetUtils::colorize('<' . $from . '>', $handleColor);

            $bodyText = $category === 'mention'
                ? TelnetUtils::colorize($body, TelnetUtils::ANSI_YELLOW)
                : $body;

            $lines[] = $timeSegment . $handle . ' ' . $bodyText;
        }

        return $lines;
    }

    /**
     * Categorize one message for {@see formatColoredTranscript()}, using
     * only fields already on the row: 'join_leave' | 'server' | 'own' |
     * 'mention' | 'normal'. Join/leave/timeout detection reuses the same
     * textual shape mrc_daemon.php itself already parses for presence
     * bookkeeping (handleUserJoinAnnouncement()/handleUserPartAnnouncement()
     * in scripts/mrc_daemon.php) -- not a new invented signal, the same
     * announcement text read a second way for display.
     */
    private function messageCategory(string $from, string $body, string $currentUsername): string
    {
        if (strcasecmp($from, 'SERVER') === 0) {
            return preg_match('/\((?:Joining|Parting|Timeout|Leaving)\)|just left the server/i', $body)
                ? 'join_leave'
                : 'server';
        }
        if ($currentUsername !== '' && strcasecmp($from, $currentUsername) === 0) {
            return 'own';
        }
        if ($currentUsername !== '' && self::mentionsHandle($body, $currentUsername)) {
            return 'mention';
        }
        return 'normal';
    }

    /** Whole-word, case-insensitive: "keyop" matches "Hey Keyop!" but not "Keyopian". */
    private static function mentionsHandle(string $body, string $handle): bool
    {
        if (trim($handle) === '') {
            return false;
        }
        return (bool)preg_match('/(?<![A-Za-z0-9_])' . preg_quote($handle, '/') . '(?![A-Za-z0-9_])/i', $body);
    }

    /**
     * Deterministic per-handle color: the same normalized handle always maps
     * to the same {@see HANDLE_PALETTE} entry on every redraw, with no DB
     * persistence and no randomness -- just a stable hash of the handle
     * text itself, human-preferred over random assignment specifically so a
     * busy room stays scannable across refreshes.
     */
    private function speakerColor(string $handle): string
    {
        $normalized = strtolower(trim($handle));
        if ($normalized === '') {
            return TelnetUtils::ANSI_CYAN;
        }
        $index = crc32($normalized) % count(self::HANDLE_PALETTE);
        return self::HANDLE_PALETTE[$index];
    }

    /**
     * Strip a literal copy of the sender's own handle from the START of the
     * message body, when present -- ONLY when it demonstrably matches $from.
     *
     * Per MRC's spec, Field 7 (message_body) is "W1 W2+" where W1 is the
     * sender's own handle -- other MRC clients (Mystic, ZOC, etc.) send it
     * embedded this way so clients with no separate from_user column can
     * still display a name. We already show the name via our own structured
     * $from column, so a leading copy of it is redundant text, not distinct
     * content. This is a direct port of the same regex Web MRC's own (albeit
     * currently uncalled) stripUsernamePrefix() already defines for the
     * identical protocol duplication (public_html/webdoors/mrc/mrc.js) --
     * not a new invented transformation.
     *
     * Deliberately narrow: a prefix that does not exactly match $from (e.g.
     * "@Keyop>" when $from is "Keyop") is left completely alone, since its
     * meaning is distinct or uncertain, not proven-redundant.
     */
    private function stripDuplicateSpeakerPrefix(string $body, string $from): string
    {
        if ($body === '' || $from === '') {
            return $body;
        }
        $escaped = preg_quote($from, '/');

        // Bracket form: "<name>:? " (any MRC pipe-colour codes around it were
        // already stripped by stripMrcPipeCodes() above, matching mrc.js's
        // own ordering).
        if (preg_match('/^<' . $escaped . '>:?\s+/i', $body, $m)) {
            return substr($body, strlen($m[0]));
        }

        // Plain form: "name " at the very start.
        if (preg_match('/^' . $escaped . '\s+/i', $body, $m)) {
            return substr($body, strlen($m[0]));
        }

        return $body;
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
