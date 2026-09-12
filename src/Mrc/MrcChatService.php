<?php

declare(strict_types=1);

namespace BinktermPHP\Mrc;

use InvalidArgumentException;
use PDO;

/**
 * Shared MRC (Multi Relay Chat) business/data layer.
 *
 * Extracted verbatim from public_html/webdoors/mrc/api.php (M1A of the MRC
 * Terminal Convergence slice plan) so a future terminal MRC client can call
 * the exact same operations the Web WebDoor uses -- same local Postgres
 * state/queue tables, same supervised mrc_daemon contract, same external MRC
 * network -- without a second network client and without terminal-side
 * self-HTTP to this WebDoor's own api.php.
 *
 * Deliberately NOT included here (left in public_html/webdoors/mrc/api.php,
 * which remains a thin HTTP adapter over this service):
 *
 *   - $_SESSION-backed identity resolution (resolveMrcUsername()) -- a
 *     terminal caller has no PHP session; it resolves its own handle from
 *     its own session state and passes the resolved username into this
 *     service like any other caller.
 *   - $_GET / php://input / $_SERVER parsing and \WebDoorSDK\jsonError()/
 *     jsonResponse() calls -- pure HTTP-transport concerns. Validation
 *     failures here throw InvalidArgumentException instead; the HTTP
 *     adapter translates that to a 400 JSON error.
 *   - BinkStream::emit() realtime pushes (mrc_presence, mrc_session_ended)
 *     -- these notify other open BROWSER tabs/windows for the same user and
 *     have no terminal equivalent; the caller (api.php today) fires them
 *     itself after calling the plain DB-mutation methods below.
 *   - handleLongPoll()'s HTTP long-poll loop (session_write_close(),
 *     set_time_limit(), the sleep-and-retry timing) -- a browser-latency
 *     optimization with no terminal equivalent (terminal MRC is expected to
 *     use a plain polling loop per the MRC Terminal Convergence recon); its
 *     single-shot snapshot logic reuses the same methods as handlePoll().
 */
final class MrcChatService
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * Daemon/network connection status for the status banner.
     *
     * @return array{enabled:bool,connected:bool,daemon_running:bool,server:string,bbs_name:string}
     */
    public function getStatus(): array
    {
        $config = MrcConfig::getInstance();

        $stmt = $this->db->prepare("SELECT key, value, updated_at FROM mrc_state WHERE key IN ('connected', 'daemon_heartbeat')");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stateMap = [];
        foreach ($rows as $row) {
            $stateMap[$row['key']] = $row;
        }

        $connected = ($stateMap['connected']['value'] ?? 'false') === 'true';

        // Consider the daemon running only if it has written a heartbeat within
        // the last 90 seconds (daemon writes every 30 s; allow 3 missed beats).
        $daemonRunning = false;
        if (!empty($stateMap['daemon_heartbeat']['updated_at'])) {
            $lastBeat = strtotime($stateMap['daemon_heartbeat']['updated_at']);
            $daemonRunning = $lastBeat !== false && (time() - $lastBeat) < 90;
        }

        return [
            'enabled'        => $config->isEnabled(),
            'connected'      => $connected,
            'daemon_running' => $daemonRunning,
            'server'         => $config->getServerHost() . ':' . $config->getServerPort(),
            'bbs_name'       => $config->getBbsName(),
        ];
    }

    /**
     * Query the room list, falling back to the configured default room if the
     * server returned no rooms (e.g. fresh connection before LIST has arrived).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRoomList(): array
    {
        // Count distinct users from both server-reported presence (mrc_users) and
        // local webdoor sessions (mrc_local_presence) to avoid undercounting.
        $stmt = $this->db->query("
            SELECT
                r.room_name,
                r.topic,
                r.topic_set_by,
                r.topic_set_at,
                r.last_activity,
                (
                    SELECT COUNT(DISTINCT username)
                    FROM (
                        SELECT username FROM mrc_users WHERE room_name = r.room_name
                        UNION
                        SELECT username FROM mrc_local_presence WHERE room_name = r.room_name
                    ) all_users
                ) AS user_count
            FROM mrc_rooms r
            ORDER BY r.room_name
        ");
        $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rooms)) {
            $defaultRoom = MrcConfig::getInstance()->getDefaultRoom();
            $rooms = [[
                'room_name'    => $defaultRoom,
                'topic'        => null,
                'topic_set_by' => null,
                'topic_set_at' => null,
                'user_count'   => 0,
                'last_activity' => null,
            ]];
        }

        return $rooms;
    }

    /**
     * Validate/sanitize a user-entered MRC handle.
     *
     * @throws InvalidArgumentException if the resulting handle is empty or reserved.
     */
    public function normalizeHandle(string $handle, string $fallbackUsername): string
    {
        $handle = trim($handle);
        if ($handle === '') {
            $handle = $fallbackUsername;
        }

        $handle = substr(MrcClient::sanitizeName($handle), 0, 30);
        if ($handle === '') {
            throw new InvalidArgumentException('Username is required');
        }

        if (in_array(strtoupper($handle), ['SERVER', 'CLIENT', 'NOTME'], true)) {
            throw new InvalidArgumentException('Invalid username');
        }

        return $handle;
    }

    /**
     * Normalize a user-entered MRC room name.
     * Accepts an optional leading '#' from the UI, then enforces the protocol's
     * room-name rules before anything is queued to the daemon.
     *
     * @throws InvalidArgumentException if the resulting room name is invalid.
     */
    public function normalizeRoomName(string $room): string
    {
        $room = trim($room);
        if (strncmp($room, '#', 1) === 0) {
            $room = substr($room, 1);
        }

        $room = MrcClient::sanitizeName($room);

        if ($room === '' || !preg_match('/^[A-Za-z0-9]{1,20}$/', $room)) {
            throw new InvalidArgumentException('Invalid room name');
        }

        return $room;
    }

    /** @return array<int, array<string, mixed>> */
    public function getRoomMessages(string $room, int $after, int $limit = 100): array
    {
        $limit = min(1000, $limit);
        $stmt = $this->db->prepare("
            SELECT
                id, from_user, from_site, from_room, to_user, to_room,
                message_body, msg_ext, is_private, received_at
            FROM mrc_messages
            WHERE (to_room = :room OR from_room = :room)
              AND id > :after
              AND is_private = false
            ORDER BY received_at ASC, id ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':room',  $room,  PDO::PARAM_STR);
        $stmt->bindValue(':after', $after, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * The most recent non-private messages for a room, oldest first (for an
     * initial history view). Distinct from {@see getRoomMessages()}, which is
     * a forward cursor from an `after` id for live polling and would return
     * the OLDEST messages first on a fresh (after=0) call if the room has
     * more than $limit messages -- not "recent" at all.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRecentRoomMessages(string $room, int $limit = 100): array
    {
        $limit = min(1000, max(1, $limit));
        $stmt = $this->db->prepare("
            SELECT
                id, from_user, from_site, from_room, to_user, to_room,
                message_body, msg_ext, is_private, received_at
            FROM mrc_messages
            WHERE (to_room = :room OR from_room = :room)
              AND is_private = false
            ORDER BY received_at DESC, id DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':room', $room, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<int, array<string, mixed>> */
    public function getPrivateMessages(string $me, string $with, int $after, int $limit = 100): array
    {
        $limit = min(1000, $limit);
        $withUser = MrcClient::sanitizeName($with);

        $stmt = $this->db->prepare("
            SELECT
                id, from_user, from_site, from_room, to_user, to_room,
                message_body, msg_ext, is_private, received_at
            FROM mrc_messages
            WHERE is_private = true
              AND id > :after
              AND (
                (from_user = :me AND to_user = :with_user)
                OR (from_user = :with_user AND to_user = :me)
              )
            ORDER BY received_at ASC, id ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':me', $me, PDO::PARAM_STR);
        $stmt->bindValue(':with_user', $withUser, PDO::PARAM_STR);
        $stmt->bindValue(':after', $after, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{latest_id:int,counts:array<string,int>} */
    public function getPrivateUnread(string $me, int $after): array
    {
        $stmt = $this->db->prepare("
            SELECT id, from_user
            FROM mrc_messages
            WHERE is_private = true
              AND to_user = :me
              AND id > :after
            ORDER BY id ASC
        ");
        $stmt->bindValue(':me', $me, PDO::PARAM_STR);
        $stmt->bindValue(':after', $after, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $counts = [];
        $latestId = $after;
        foreach ($rows as $row) {
            $from = $row['from_user'] ?? '';
            if ($from !== '') {
                $counts[$from] = ($counts[$from] ?? 0) + 1;
            }
            $latestId = max($latestId, (int)$row['id']);
        }

        return ['latest_id' => $latestId, 'counts' => $counts];
    }

    /** Latest max(id) of private messages to $me -- used to initialize an unread cursor without counting history. */
    public function getLatestPrivateMessageId(string $me): int
    {
        $stmt = $this->db->prepare("
            SELECT COALESCE(MAX(id), 0) AS max_id
            FROM mrc_messages
            WHERE is_private = true
              AND to_user = :me
        ");
        $stmt->bindValue(':me', $me, PDO::PARAM_STR);
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    public function upsertLocalHandle(?int $userId, string $username, string $bbsName): void
    {
        if ($userId === null || $userId <= 0 || $username === '') {
            return;
        }

        $this->db->prepare("
            INSERT INTO mrc_local_handles (user_id, username, bbs_name, connected_at, last_seen)
            VALUES (:user_id, :username, :bbs_name, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ON CONFLICT (user_id) DO UPDATE
            SET username = EXCLUDED.username,
                bbs_name = EXCLUDED.bbs_name,
                last_seen = CURRENT_TIMESTAMP
        ")->execute([
            'user_id' => $userId,
            'username' => $username,
            'bbs_name' => $bbsName,
        ]);
    }

    public function upsertLocalPresence(?int $userId, string $username, string $bbsName, string $room): void
    {
        $room = MrcClient::sanitizeName($room);

        $this->db->prepare("
            INSERT INTO mrc_rooms (room_name, last_activity)
            VALUES (:room, CURRENT_TIMESTAMP)
            ON CONFLICT (room_name) DO UPDATE SET last_activity = CURRENT_TIMESTAMP
        ")->execute(['room' => $room]);

        $this->db->prepare("
            INSERT INTO mrc_local_presence (user_id, username, bbs_name, room_name, last_seen)
            VALUES (:user_id, :username, :bbs_name, :room, CURRENT_TIMESTAMP)
            ON CONFLICT (user_id, room_name) DO UPDATE
            SET username = EXCLUDED.username,
                bbs_name = EXCLUDED.bbs_name,
                last_seen = CURRENT_TIMESTAMP
        ")->execute([
            'user_id' => $userId !== null && $userId > 0 ? $userId : null,
            'username' => $username,
            'bbs_name' => $bbsName,
            'room' => $room,
        ]);
    }

    /** Convenience: upsertLocalHandle() + upsertLocalPresence(), as handleHeartbeat() does. */
    public function recordHeartbeat(?int $userId, string $username, string $bbsName, string $room): void
    {
        $this->upsertLocalHandle($userId, $username, $bbsName);
        $this->upsertLocalPresence($userId, $username, $bbsName, $room);
    }

    /** @return array<int, array<string, mixed>> */
    public function getUsers(string $room): array
    {
        $stmt = $this->db->prepare("
            SELECT username, bbs_name, room_name, ip_address, connected_at, last_seen,
                   COALESCE(is_afk, false) AS is_afk, afk_message
            FROM mrc_users
            WHERE room_name = :room
            UNION ALL
            SELECT username, bbs_name, room_name, ip_address, connected_at, last_seen,
                   false AS is_afk, NULL AS afk_message
            FROM mrc_local_presence
            WHERE room_name = :room
            ORDER BY username
        ");
        $stmt->execute(['room' => $room]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Queue a slash-style MRC command (help/motd/rooms/topic/register/identify/
     * update/generic) for the daemon to send.
     *
     * @param array<int,string> $args
     * @throws InvalidArgumentException on an invalid/malformed command.
     */
    public function queueCommand(string $username, string $bbsName, string $command, string $room, array $args): void
    {
        $command = strtolower(trim($command));
        if ($command === '') {
            throw new InvalidArgumentException('Command is required');
        }
        if (!preg_match('/^[a-z]{1,20}$/', $command)) {
            throw new InvalidArgumentException('Invalid command');
        }

        $roomOptional = in_array($command, ['rooms', 'motd', 'register', 'identify', 'update', 'help'], true);
        if (!$roomOptional && empty($room)) {
            throw new InvalidArgumentException('Room is required');
        }

        $room = $command === 'rooms' ? '' : MrcClient::sanitizeName($room);

        $commandArgs = array_map('trim', $args);
        $commandArgs = array_values(array_filter($commandArgs, 'strlen'));

        // Build f7 and f6 per command.
        // REGISTER/IDENTIFY/UPDATE use f6='' (personal commands, not room-targeted).
        $f6 = $room;
        $f7 = '';

        switch ($command) {
            case 'help':
                $topic = !empty($commandArgs[0]) ? ' ' . substr(str_replace(['~', ' '], '', $commandArgs[0]), 0, 20) : '';
                $f7 = 'HELP' . $topic;
                break;

            case 'motd':
                $f7 = 'MOTD';
                break;

            case 'rooms':
                $f7 = 'LIST';
                $f6 = '';
                break;

            case 'topic':
                if (empty($commandArgs)) {
                    throw new InvalidArgumentException('Topic text is required');
                }
                $topicText = implode(' ', $commandArgs);
                $topicText = str_replace('~', '', $topicText);
                $topicText = preg_replace('/\\|[0-9A-Fa-f]{2}/', '', $topicText);
                $topicText = preg_replace('/\\|[A-Za-z]{2}/', '', $topicText);
                $topicText = trim(substr($topicText, 0, 55));
                if ($topicText === '') {
                    throw new InvalidArgumentException('Topic text is required');
                }
                $f7 = "NEWTOPIC:{$room}:{$topicText}";
                break;

            case 'register':
                if (empty($commandArgs)) {
                    $f7 = 'REGISTER';
                } else {
                    $password = substr(str_replace(['~', ' '], '', $commandArgs[0]), 0, 20);
                    $email = '';
                    if (!empty($commandArgs[1])) {
                        $email = substr(str_replace(['~', ' '], '', $commandArgs[1]), 0, 128);
                    }
                    $f7 = 'REGISTER' . ($password !== '' ? ' ' . $password : '') . ($email !== '' ? ' ' . $email : '');
                }
                $f6 = '';
                break;

            case 'identify':
                if (empty($commandArgs)) {
                    $f7 = 'IDENTIFY';
                } else {
                    $password = substr(str_replace(['~', ' '], '', $commandArgs[0]), 0, 20);
                    $f7 = 'IDENTIFY' . ($password !== '' ? ' ' . $password : '');
                }
                $f6 = '';
                break;

            case 'update':
                if (empty($commandArgs)) {
                    $f7 = 'UPDATE';
                } else {
                    $param = strtoupper(str_replace(['~', ' '], '', $commandArgs[0]));
                    $value = isset($commandArgs[1]) ? substr(str_replace('~', '', $commandArgs[1]), 0, 128) : '';
                    $f7 = 'UPDATE' . ($param !== '' ? ' ' . $param : '') . ($value !== '' ? ' ' . $value : '');
                }
                $f6 = '';
                break;

            default:
                // Generic passthrough: uppercase the command word and append any args
                $safeArgs = array_map(function ($a) {
                    return substr(str_replace('~', '', $a), 0, 140);
                }, $commandArgs);
                $f7 = strtoupper($command) . (!empty($safeArgs) ? ' ' . implode(' ', $safeArgs) : '');
                break;
        }

        $this->db->prepare("
            INSERT INTO mrc_outbound (field1, field2, field3, field4, field5, field6, field7, priority)
            VALUES (:f1, :f2, :f3, :f4, :f5, :f6, :f7, :priority)
        ")->execute([
            'f1' => $command === 'rooms' ? $bbsName : $username,
            'f2' => $bbsName,
            'f3' => $room,
            'f4' => 'SERVER',
            'f5' => '',
            'f6' => $f6,
            'f7' => $f7,
            'priority' => 5,
        ]);

        if ($command === 'rooms') {
            $this->db->prepare("
                INSERT INTO mrc_state (key, value, updated_at)
                VALUES ('list_refresh_started', :value, CURRENT_TIMESTAMP)
                ON CONFLICT (key) DO UPDATE
                SET value = EXCLUDED.value, updated_at = EXCLUDED.updated_at
            ")->execute(['value' => (string)time()]);

            $this->db->prepare("
                INSERT INTO mrc_state (key, value, updated_at)
                VALUES ('list_refresh_pending', 'true', CURRENT_TIMESTAMP)
                ON CONFLICT (key) DO UPDATE
                SET value = EXCLUDED.value, updated_at = EXCLUDED.updated_at
            ")->execute();
        }
    }

    /**
     * Queue a room or private chat message.
     *
     * @throws InvalidArgumentException on invalid input.
     */
    public function sendMessage(string $username, string $bbsName, string $room, string $message, string $toUser, int $maxLength): void
    {
        if ($message === '' || ($room === '' && $toUser === '')) {
            throw new InvalidArgumentException('Message and room or user are required');
        }

        $message = str_replace('~', '', $message);
        $message = substr($message, 0, $maxLength);
        $toUser  = $toUser !== '' ? MrcClient::sanitizeName($toUser) : '';

        $stmt = $this->db->prepare("
            INSERT INTO mrc_outbound (field1, field2, field3, field4, field5, field6, field7, priority)
            VALUES (:f1, :f2, :f3, :f4, :f5, :f6, :f7, :priority)
        ");

        // MRC spec (Field 7): Word 1 = handle of the user, Word 2+ = chat message.
        // Other clients (e.g. Mystic, ZOC) read W1 as the sender's name and
        // display it as "W1: rest".  Without this prefix, the first word of the
        // message text is treated as the sender name.
        $formattedMessage = '|03<|02' . $username . '|03> ' . $message;

        if ($toUser !== '') {
            $stmt->execute([
                'f1' => $username, 'f2' => $bbsName, 'f3' => '',
                'f4' => $toUser,   'f5' => '',        'f6' => '',
                'f7' => '|03<|02' . $username . '|03> (Private) ' . $message,
                'priority' => 0,
            ]);
        } else {
            $stmt->execute([
                'f1' => $username, 'f2' => $bbsName, 'f3' => $room,
                'f4' => '',        'f5' => '',        'f6' => $room,
                'f7' => $formattedMessage, 'priority' => 0,
            ]);
        }
    }

    /**
     * Join a room: queues NEWROOM (+ USERIP if known) for the daemon, records
     * local presence, and returns the current max non-private message id for
     * the room (used by the caller as its initial read cursor).
     */
    public function joinRoom(?int $userId, string $username, string $bbsName, string $room, string $fromRoom, ?string $clientIp): int
    {
        $outStmt = $this->db->prepare("
            INSERT INTO mrc_outbound (field1, field2, field3, field4, field5, field6, field7, priority)
            VALUES (:f1, :f2, :f3, :f4, :f5, :f6, :f7, :priority)
        ");
        $outStmt->execute([
            'f1' => $username, 'f2' => $bbsName, 'f3' => $fromRoom,
            'f4' => 'SERVER',  'f5' => '',        'f6' => $room,
            'f7' => "NEWROOM:{$fromRoom}:{$room}", 'priority' => 10,
        ]);

        if ($clientIp !== null && $clientIp !== '') {
            $outStmt->execute([
                'f1' => $username, 'f2' => $bbsName, 'f3' => '',
                'f4' => 'SERVER',  'f5' => '',        'f6' => '',
                'f7' => "USERIP:{$clientIp}", 'priority' => 9,
            ]);
        }

        // Track user as local presence so IAMHERE keepalives are sent for them
        $this->db->prepare("
            INSERT INTO mrc_rooms (room_name, last_activity)
            VALUES (:room, CURRENT_TIMESTAMP)
            ON CONFLICT (room_name) DO UPDATE SET last_activity = CURRENT_TIMESTAMP
        ")->execute(['room' => $room]);

        $this->db->prepare("
            INSERT INTO mrc_local_presence (user_id, username, bbs_name, room_name, ip_address, last_seen)
            VALUES (:user_id, :username, :bbs_name, :room, :ip_address, CURRENT_TIMESTAMP)
            ON CONFLICT (user_id, room_name) DO UPDATE
            SET username = EXCLUDED.username,
                bbs_name = EXCLUDED.bbs_name,
                last_seen = CURRENT_TIMESTAMP,
                ip_address = COALESCE(EXCLUDED.ip_address, mrc_local_presence.ip_address)
        ")->execute([
            'user_id' => $userId !== null && $userId > 0 ? $userId : null,
            'username' => $username,
            'bbs_name' => $bbsName,
            'room' => $room,
            'ip_address' => $clientIp,
        ]);

        return $this->getRoomCursor($room);
    }

    /** Latest non-private message id for a room -- used as an initial read cursor. */
    public function getRoomCursor(string $room): int
    {
        $stmt = $this->db->prepare("
            SELECT COALESCE(MAX(id), 0) AS max_id
            FROM mrc_messages
            WHERE is_private = false
              AND (to_room = :room OR from_room = :room)
        ");
        $stmt->execute(['room' => $room]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Establish an MRC session: queues USERIP to register presence with the
     * server, and optionally queues IDENTIFY if a password is provided.
     */
    public function connect(?int $userId, string $username, string $bbsName, ?string $password, ?string $clientIp): void
    {
        $this->upsertLocalHandle($userId, $username, $bbsName);

        $outStmt = $this->db->prepare("
            INSERT INTO mrc_outbound (field1, field2, field3, field4, field5, field6, field7, priority)
            VALUES (:f1, :f2, :f3, :f4, :f5, :f6, :f7, :priority)
        ");

        if ($clientIp !== null && $clientIp !== '') {
            $outStmt->execute([
                'f1' => $username, 'f2' => $bbsName, 'f3' => '',
                'f4' => 'SERVER',  'f5' => '',        'f6' => '',
                'f7' => "USERIP:{$clientIp}", 'priority' => 9,
            ]);
        }

        if ($password !== null && $password !== '') {
            $password = substr(str_replace(['~', ' '], '', $password), 0, 20);
            if ($password !== '') {
                $outStmt->execute([
                    'f1' => $username, 'f2' => $bbsName, 'f3' => '',
                    'f4' => 'SERVER',  'f5' => '',        'f6' => '',
                    'f7' => "IDENTIFY {$password}", 'priority' => 8,
                ]);
            }
        }
    }

    /**
     * Disconnect: queues LOGOFF for every room the user is currently in and
     * removes their local presence/handle so IAMHERE keepalives stop.
     *
     * @return array<int,string> The rooms the caller was in (sanitized names),
     *     so the caller can fire any web-realtime presence refresh itself.
     */
    /**
     * Leave exactly one room: queues LOGOFF for that room and removes only
     * this (user_id, room_name) presence row.
     *
     * Deliberately room-scoped, unlike {@see disconnect()} (whole-account,
     * every currently-joined room). MRC Terminal Convergence M1C-1: a caller
     * may have Web MRC and terminal MRC open simultaneously under the same
     * BinkTerm account, in different rooms -- calling the whole-account
     * disconnect() from one surface would incorrectly tear down the other
     * surface's independently-active room presence too (confirmed collision
     * risk, not fixed here; disconnect() is unchanged and this method exists
     * so the terminal lifecycle never needs to call it). mrc_local_handles
     * (the account's current MRC handle, not room-specific) is untouched --
     * harmless to leave, and connect() already keeps it fresh on every use.
     */
    public function leaveRoom(?int $userId, string $username, string $bbsName, string $room): void
    {
        $localUserId = $userId ?? 0;
        $room = MrcClient::sanitizeName($room);

        $this->db->prepare("
            INSERT INTO mrc_outbound (field1, field2, field3, field4, field5, field6, field7, priority)
            VALUES (:f1, :f2, :f3, :f4, :f5, :f6, :f7, :priority)
        ")->execute([
            'f1' => $username, 'f2' => $bbsName, 'f3' => $room,
            'f4' => 'SERVER',  'f5' => '',        'f6' => $room,
            'f7' => 'LOGOFF', 'priority' => 10,
        ]);

        $this->db->prepare("
            DELETE FROM mrc_local_presence WHERE user_id = :user_id AND room_name = :room
        ")->execute(['user_id' => $localUserId, 'room' => $room]);
    }

    public function disconnect(?int $userId, string $username, string $bbsName): array
    {
        $localUserId = $userId ?? 0;

        $stmt = $this->db->prepare("
            SELECT DISTINCT room_name FROM mrc_local_presence
            WHERE user_id = :user_id
              AND last_seen > CURRENT_TIMESTAMP - INTERVAL '10 minutes'
        ");
        $stmt->execute(['user_id' => $localUserId]);
        $rooms = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'room_name');

        $outStmt = $this->db->prepare("
            INSERT INTO mrc_outbound (field1, field2, field3, field4, field5, field6, field7, priority)
            VALUES (:f1, :f2, :f3, :f4, :f5, :f6, :f7, :priority)
        ");
        $sanitizedRooms = [];
        foreach ($rooms as $room) {
            $room = MrcClient::sanitizeName($room);
            $sanitizedRooms[] = $room;
            $outStmt->execute([
                'f1' => $username, 'f2' => $bbsName, 'f3' => $room,
                'f4' => 'SERVER',  'f5' => '',        'f6' => $room,
                'f7' => 'LOGOFF', 'priority' => 10,
            ]);
        }

        $this->db->prepare("
            DELETE FROM mrc_local_presence WHERE user_id = :user_id
        ")->execute(['user_id' => $localUserId]);
        $this->db->prepare("
            DELETE FROM mrc_local_handles WHERE user_id = :user_id
        ")->execute(['user_id' => $localUserId]);

        return $sanitizedRooms;
    }

    /**
     * The local users still in a room (for a web-realtime presence push) plus
     * the ids to notify. Pure DB read -- the caller decides whether/how to
     * push it (e.g. BinkStream::emit() for web browser tabs); a terminal
     * caller has no such push target and would not call this.
     *
     * @return array{target_user_ids:array<int,int>, users:array<int,array<string,mixed>>}
     */
    public function getPresenceRefreshForRoom(string $room): array
    {
        $room = MrcClient::sanitizeName($room);
        if ($room === '') {
            return ['target_user_ids' => [], 'users' => []];
        }

        $userIdStmt = $this->db->prepare("
            SELECT DISTINCT user_id AS id
            FROM mrc_local_presence
            WHERE room_name = :room
              AND user_id IS NOT NULL
              AND last_seen > CURRENT_TIMESTAMP - INTERVAL '10 minutes'
        ");
        $userIdStmt->execute(['room' => $room]);
        $targetUserIds = array_map('intval', array_column($userIdStmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
        if (empty($targetUserIds)) {
            return ['target_user_ids' => [], 'users' => []];
        }

        $localBbs = MrcClient::sanitizeName(MrcConfig::getInstance()->getBbsName());
        $usersStmt = $this->db->prepare("
            SELECT username, COALESCE(bbs_name, 'unknown') AS bbs_name, false AS is_afk
            FROM mrc_users
            WHERE room_name = :room
            UNION
            SELECT username, :local_bbs AS bbs_name, false AS is_afk
            FROM mrc_local_presence
            WHERE room_name = :room2
              AND last_seen > CURRENT_TIMESTAMP - INTERVAL '10 minutes'
        ");
        $usersStmt->execute([
            'room' => $room,
            'local_bbs' => $localBbs,
            'room2' => $room,
        ]);

        return [
            'target_user_ids' => $targetUserIds,
            'users' => $usersStmt->fetchAll(PDO::FETCH_ASSOC),
        ];
    }
}
