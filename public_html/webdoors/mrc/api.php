<?php

/**
 * MRC Chat WebDoor - API Endpoint
 *
 * Thin HTTP adapter over BinktermPHP\Mrc\MrcChatService (extracted in the
 * MRC Terminal Convergence M1A slice): $_GET/php://input/$_SESSION/$_SERVER
 * parsing, request validation -> jsonError(), delegation to the shared
 * service, and JSON response shaping. All MRC business/data logic lives in
 * the service so a future terminal MRC client can call it directly, without
 * a second network client and without terminal-side self-HTTP to this file.
 *
 * Routed via ?action=<action>.
 */

require_once __DIR__ . '/../_doorsdk/php/helpers.php';

use BinktermPHP\Database;
use BinktermPHP\Mrc\MrcChatService;
use BinktermPHP\Mrc\MrcClient;
use BinktermPHP\Mrc\MrcConfig;
use BinktermPHP\Realtime\BinkStream;

header('Content-Type: application/json');

$user = \WebDoorSDK\requireAuth();
$db   = \WebDoorSDK\getDatabase();
$mrc  = new MrcChatService($db);

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'status':   handleStatus($mrc);                  break;
        case 'rooms':    handleRooms($mrc);                   break;
        case 'messages': handleMessages($mrc, $user);          break;
        case 'private':  handlePrivateMessages($mrc, $user);   break;
        case 'private_unread': handlePrivateUnread($mrc, $user); break;
        case 'heartbeat': handleHeartbeat($mrc, $user);        break;
        case 'poll':     handlePoll($db, $mrc, $user);         break;
        case 'longpoll': handleLongPoll($db, $mrc, $user);     break;
        case 'command':  handleCommand($mrc, $user);           break;
        case 'users':    handleUsers($mrc);                   break;
        case 'send':       handleSend($mrc, $user);           break;
        case 'join':       handleJoin($mrc, $user);           break;
        case 'room_cursor': handleRoomCursor($mrc);           break;
        case 'connect':    handleConnect($mrc, $user);        break;
        case 'disconnect': handleDisconnect($db, $mrc, $user); break;
        default:
            \WebDoorSDK\jsonError('Unknown action', 400);
    }
} catch (\InvalidArgumentException $e) {
    \WebDoorSDK\jsonError($e->getMessage(), 400);
} catch (Exception $e) {
    \WebDoorSDK\jsonError($e->getMessage(), 500);
}

// ============================================================

function normalizeMrcHandle(MrcChatService $mrc, string $handle, array $user): string
{
    return $mrc->normalizeHandle($handle, (string)($user['username'] ?? ''));
}

function resolveMrcUsername(MrcChatService $mrc, array $user): string
{
    $sessionHandle = isset($_SESSION['mrc_username']) ? (string)$_SESSION['mrc_username'] : '';
    return normalizeMrcHandle($mrc, $sessionHandle, $user);
}

function localUserId(array $user): ?int
{
    $id = (int)($user['user_id'] ?? $user['id'] ?? 0);
    return $id > 0 ? $id : null;
}

function clientIp(): ?string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($ip === '') {
        return null;
    }
    return preg_replace('/[^0-9a-fA-F:\.]/', '', $ip);
}

/**
 * Emit a fresh room presence payload to all remaining local users in a room.
 * Web-realtime only (BinkStream reaches open browser tabs) -- not part of
 * the shared service; a terminal caller has no such push target.
 */
function emitPresenceForRoom(PDO $db, MrcChatService $mrc, string $room): void
{
    $refresh = $mrc->getPresenceRefreshForRoom($room);
    foreach ($refresh['target_user_ids'] as $targetUserId) {
        BinkStream::emit($db, 'mrc_presence', ['room' => $room, 'users' => $refresh['users']], $targetUserId);
    }
}

function handleStatus(MrcChatService $mrc): void
{
    \WebDoorSDK\jsonResponse(array_merge(['success' => true], $mrc->getStatus()));
}

function handleRooms(MrcChatService $mrc): void
{
    \WebDoorSDK\jsonResponse([
        'success' => true,
        'rooms'   => $mrc->getRoomList(),
    ]);
}

function handleMessages(MrcChatService $mrc, array $user): void
{
    $room  = $_GET['room']  ?? '';
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
    $after = isset($_GET['after'])  ? (int)$_GET['after']           : 0;

    if (empty($room)) {
        \WebDoorSDK\jsonError('Room is required');
    }
    if (strpos($room, '~') !== false) {
        \WebDoorSDK\jsonError('Invalid character in room');
    }

    \WebDoorSDK\jsonResponse(['success' => true, 'messages' => $mrc->getRoomMessages($room, $after, $limit)]);
}

function handlePrivateMessages(MrcChatService $mrc, array $user): void
{
    $with  = $_GET['with']  ?? '';
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
    $after = isset($_GET['after'])  ? (int)$_GET['after']           : 0;

    if (empty($with)) {
        \WebDoorSDK\jsonError('Private chat user is required');
    }
    if (strpos($with, '~') !== false) {
        \WebDoorSDK\jsonError('Invalid character in user');
    }

    $username = resolveMrcUsername($mrc, $user);
    \WebDoorSDK\jsonResponse(['success' => true, 'messages' => $mrc->getPrivateMessages($username, $with, $after, $limit)]);
}

function handlePrivateUnread(MrcChatService $mrc, array $user): void
{
    $after = isset($_GET['after']) ? (int)$_GET['after'] : 0;
    $username = resolveMrcUsername($mrc, $user);

    \WebDoorSDK\jsonResponse(array_merge(['success' => true], $mrc->getPrivateUnread($username, $after)));
}

function handleHeartbeat(MrcChatService $mrc, array $user): void
{
    $room = $_GET['room'] ?? '';
    if (empty($room)) {
        \WebDoorSDK\jsonError('Room is required');
    }
    if (strpos($room, '~') !== false) {
        \WebDoorSDK\jsonError('Invalid character in room');
    }

    $config = MrcConfig::getInstance();
    $username = resolveMrcUsername($mrc, $user);
    $bbsName = MrcClient::sanitizeName($config->getBbsName());
    $mrc->recordHeartbeat(localUserId($user), $username, $bbsName, $room);
    \WebDoorSDK\jsonResponse(['success' => true]);
}

function handlePoll(PDO $db, MrcChatService $mrc, array $user): void
{
    $viewMode = $_GET['view_mode'] ?? 'room';
    $viewRoom = $_GET['view_room'] ?? '';
    $joinRoom = $_GET['join_room'] ?? '';
    $withUser = $_GET['with_user'] ?? '';
    $after = isset($_GET['after']) ? (int)$_GET['after'] : 0;
    $afterPrivate = isset($_GET['after_private']) ? (int)$_GET['after_private'] : 0;
    $afterUnread = isset($_GET['after_unread']) ? (int)$_GET['after_unread'] : 0;
    $unreadInit = isset($_GET['unread_init']) && $_GET['unread_init'] === '1';
    $includeRooms = isset($_GET['include_rooms']) && $_GET['include_rooms'] === '1';

    if (!empty($viewRoom) && strpos($viewRoom, '~') !== false) {
        \WebDoorSDK\jsonError('Invalid character in room');
    }
    if (!empty($joinRoom) && strpos($joinRoom, '~') !== false) {
        \WebDoorSDK\jsonError('Invalid character in room');
    }
    if (!empty($withUser) && strpos($withUser, '~') !== false) {
        \WebDoorSDK\jsonError('Invalid character in user');
    }

    $config = MrcConfig::getInstance();
    $bbsName = MrcClient::sanitizeName($config->getBbsName());
    $username = resolveMrcUsername($mrc, $user);
    $mrc->upsertLocalHandle(localUserId($user), $username, $bbsName);

    $response = ['success' => true];

    // Messages for current view
    if ($viewMode === 'private' && $withUser !== '') {
        $response['messages'] = $mrc->getPrivateMessages($username, MrcClient::sanitizeName($withUser), $afterPrivate, 200);
        $response['message_mode'] = 'private';
    } elseif ($viewRoom !== '') {
        $response['messages'] = $mrc->getRoomMessages(MrcClient::sanitizeName($viewRoom), $after, 200);
        $response['message_mode'] = 'room';
    } else {
        $response['messages'] = [];
        $response['message_mode'] = $viewMode === 'private' ? 'private' : 'room';
    }

    // Private unread counts
    if ($username !== '') {
        if ($unreadInit && $afterUnread === 0) {
            $response['private_unread'] = [
                'latest_id' => $mrc->getLatestPrivateMessageId($username),
                'counts' => [],
            ];
        } else {
            $response['private_unread'] = $mrc->getPrivateUnread($username, $afterUnread);
        }
    }

    // Users list for joined room
    $joinRoomSanitized = $joinRoom !== '' ? MrcClient::sanitizeName($joinRoom) : '';
    $response['users'] = $joinRoomSanitized !== '' ? $mrc->getUsers($joinRoomSanitized) : [];

    // Rooms list (optional)
    if ($includeRooms) {
        $response['rooms'] = $mrc->getRoomList();
    }

    // Heartbeat for joined room
    if ($joinRoomSanitized !== '') {
        $mrc->upsertLocalPresence(localUserId($user), $username, $bbsName, $joinRoomSanitized);
    }

    \WebDoorSDK\jsonResponse($response);
}

/**
 * Long-poll endpoint: holds the connection for up to 20 seconds and returns
 * as soon as new messages or unread DMs arrive, or when the timeout expires.
 *
 * The session lock is released immediately so concurrent requests from the
 * same user (e.g. sending a message) are never blocked by this handler.
 * Users and rooms are fetched once up-front and included in every response
 * so the client does not need a separate slow-poll interval.
 *
 * This HTTP-latency optimization (session_write_close(), set_time_limit(),
 * the sleep-and-retry loop) has no terminal equivalent -- a terminal MRC
 * client is expected to use a plain polling loop (see the MRC Terminal
 * Convergence recon) reusing the same single-shot service methods below.
 */
function handleLongPoll(PDO $db, MrcChatService $mrc, array $user): void
{
    // Release PHP session lock so send/join requests are not blocked.
    session_write_close();

    // Allow this script to run longer than the default PHP max_execution_time.
    set_time_limit(35);

    $viewMode     = $_GET['view_mode']     ?? 'room';
    $viewRoom     = $_GET['view_room']     ?? '';
    $joinRoom     = $_GET['join_room']     ?? '';
    $withUser     = $_GET['with_user']     ?? '';
    $after        = isset($_GET['after'])         ? (int)$_GET['after']         : 0;
    $afterPrivate = isset($_GET['after_private'])  ? (int)$_GET['after_private'] : 0;
    $afterUnread  = isset($_GET['after_unread'])   ? (int)$_GET['after_unread']  : 0;

    if (!empty($viewRoom) && strpos($viewRoom, '~') !== false) {
        \WebDoorSDK\jsonError('Invalid character in room');
    }
    if (!empty($joinRoom) && strpos($joinRoom, '~') !== false) {
        \WebDoorSDK\jsonError('Invalid character in room');
    }
    if (!empty($withUser) && strpos($withUser, '~') !== false) {
        \WebDoorSDK\jsonError('Invalid character in user');
    }

    $config = MrcConfig::getInstance();
    $bbsName = MrcClient::sanitizeName($config->getBbsName());
    $username = resolveMrcUsername($mrc, $user);
    $mrc->upsertLocalHandle(localUserId($user), $username, $bbsName);
    $viewRoom = $viewRoom !== '' ? MrcClient::sanitizeName($viewRoom) : '';
    $joinRoom = $joinRoom !== '' ? MrcClient::sanitizeName($joinRoom) : '';
    $withUser = $withUser !== '' ? MrcClient::sanitizeName($withUser) : '';

    // Update presence heartbeat at the start of each long-poll cycle.
    if ($joinRoom !== '' && $username !== '') {
        $mrc->upsertLocalPresence(localUserId($user), $username, $bbsName, $joinRoom);
    }

    // Fetch slow-changing data once up-front; included in every response.
    $users = $joinRoom !== '' ? $mrc->getUsers($joinRoom) : [];
    $rooms = $mrc->getRoomList();

    // Prepare message-fetch parameters based on view mode.
    $messageMode = 'room';
    $fetchMessages = static fn (int $after): array => [];

    if ($viewMode === 'private' && $withUser !== '' && $username !== '') {
        $messageMode = 'private';
        $fetchMessages = static fn (int $after): array => $mrc->getPrivateMessages($username, $withUser, $after, 200);
    } elseif ($viewRoom !== '') {
        $fetchMessages = static fn (int $after): array => $mrc->getRoomMessages($viewRoom, $after, 200);
    }

    $timeout  = 20.0;   // seconds
    $sleepUs  = 500000; // 500 ms
    $deadline = microtime(true) + $timeout;

    while (microtime(true) < $deadline) {
        $afterVal = ($messageMode === 'private') ? $afterPrivate : $after;
        $messages = $fetchMessages($afterVal);

        $unread = $username !== '' ? $mrc->getPrivateUnread($username, $afterUnread) : ['latest_id' => $afterUnread, 'counts' => []];

        if (!empty($messages) || !empty($unread['counts'])) {
            \WebDoorSDK\jsonResponse([
                'success'         => true,
                'messages'        => $messages,
                'message_mode'    => $messageMode,
                'private_unread'  => $unread,
                'users'           => $users,
                'rooms'           => $rooms,
            ]);
            return;
        }

        usleep($sleepUs);
    }

    // Timeout — return empty so the client reconnects immediately.
    \WebDoorSDK\jsonResponse([
        'success'        => true,
        'messages'       => [],
        'message_mode'   => $messageMode,
        'private_unread' => ['latest_id' => $afterUnread, 'counts' => []],
        'users'          => $users,
        'rooms'          => $rooms,
        'timed_out'      => true,
    ]);
}

function handleUsers(MrcChatService $mrc): void
{
    $room = $_GET['room'] ?? '';
    if (empty($room)) {
        \WebDoorSDK\jsonError('Room is required');
    }
    if (strpos($room, '~') !== false) {
        \WebDoorSDK\jsonError('Invalid character in room');
    }

    \WebDoorSDK\jsonResponse(['success' => true, 'users' => $mrc->getUsers($room)]);
}

function handleCommand(MrcChatService $mrc, array $user): void
{
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $command = strtolower(trim((string)($input['command'] ?? '')));
    $room = (string)($input['room'] ?? '');

    // Pre-validate before touching mrc_local_handles, matching the original
    // handler's ordering: a malformed request never bumps presence.
    if ($command === '') {
        \WebDoorSDK\jsonError('Command is required');
    }
    if (!preg_match('/^[a-z]{1,20}$/', $command)) {
        \WebDoorSDK\jsonError('Invalid command');
    }
    $roomOptional = in_array($command, ['rooms', 'motd', 'register', 'identify', 'update', 'help'], true);
    if (!$roomOptional && empty($room)) {
        \WebDoorSDK\jsonError('Room is required');
    }
    if (!empty($room) && strpos($room, '~') !== false) {
        \WebDoorSDK\jsonError('Invalid character in room');
    }

    $config   = MrcConfig::getInstance();
    $username = resolveMrcUsername($mrc, $user);
    $bbsName  = MrcClient::sanitizeName($config->getBbsName());
    $mrc->upsertLocalHandle(localUserId($user), $username, $bbsName);

    $commandArgs = $input['args'] ?? [];
    $commandArgs = is_array($commandArgs) ? $commandArgs : [];

    $mrc->queueCommand($username, $bbsName, $command, $room, $commandArgs);

    \WebDoorSDK\jsonResponse(['success' => true]);
}

function handleSend(MrcChatService $mrc, array $user): void
{
    $input   = json_decode(file_get_contents('php://input'), true) ?? [];
    $room    = (string)($input['room']    ?? '');
    $message = (string)($input['message'] ?? '');
    $toUser  = (string)($input['to_user'] ?? '');

    // Pre-validate before touching mrc_local_handles, matching the original
    // handler's ordering: a malformed request never bumps presence.
    if ($message === '' || ($room === '' && $toUser === '')) {
        \WebDoorSDK\jsonError('Message and room or user are required');
    }
    if (strpos($message, '~') !== false) {
        \WebDoorSDK\jsonError('Invalid character in message');
    }
    if (!empty($room) && strpos($room, '~') !== false) {
        \WebDoorSDK\jsonError('Invalid character in room');
    }
    if (!empty($toUser) && strpos($toUser, '~') !== false) {
        \WebDoorSDK\jsonError('Invalid character in user');
    }

    $config   = MrcConfig::getInstance();
    $username = resolveMrcUsername($mrc, $user);
    $bbsName  = MrcClient::sanitizeName($config->getBbsName());
    $mrc->upsertLocalHandle(localUserId($user), $username, $bbsName);

    $mrc->sendMessage($username, $bbsName, $room, $message, $toUser, $config->getMaxMessageLength());

    \WebDoorSDK\jsonResponse(['success' => true]);
}

function handleJoin(MrcChatService $mrc, array $user): void
{
    $input    = json_decode(file_get_contents('php://input'), true) ?? [];
    $room     = (string)($input['room']      ?? '');
    $fromRoom = (string)($input['from_room'] ?? '');

    if (empty($room)) {
        \WebDoorSDK\jsonError('Room is required');
    }
    if (strpos($room, '~') !== false || strpos($fromRoom, '~') !== false) {
        \WebDoorSDK\jsonError('Invalid character in room');
    }

    $config   = MrcConfig::getInstance();
    $username = resolveMrcUsername($mrc, $user);
    $bbsName  = MrcClient::sanitizeName($config->getBbsName());
    $mrc->upsertLocalHandle(localUserId($user), $username, $bbsName);

    $room     = $mrc->normalizeRoomName($room);
    $fromRoom = trim($fromRoom);
    if ($fromRoom !== '') {
        $fromRoom = $mrc->normalizeRoomName($fromRoom);
    }

    $lastMessageId = $mrc->joinRoom(localUserId($user), $username, $bbsName, $room, $fromRoom, clientIp());

    \WebDoorSDK\jsonResponse([
        'success' => true,
        'last_message_id' => $lastMessageId,
    ]);
}

/**
 * Get the latest non-private message id for a room.
 */
function handleRoomCursor(MrcChatService $mrc): void
{
    $room = $_GET['room'] ?? '';
    if (empty($room)) {
        \WebDoorSDK\jsonError('Room is required');
    }
    if (strpos($room, '~') !== false) {
        \WebDoorSDK\jsonError('Invalid character in room');
    }

    \WebDoorSDK\jsonResponse([
        'success' => true,
        'last_message_id' => $mrc->getRoomCursor(MrcClient::sanitizeName($room)),
    ]);
}

function handleConnect(MrcChatService $mrc, array $user): void
{
    $input    = json_decode(file_get_contents('php://input'), true) ?? [];
    $password = isset($input['password']) ? trim((string)$input['password']) : '';
    $username = normalizeMrcHandle($mrc, (string)($input['username'] ?? ''), $user);
    $_SESSION['mrc_username'] = $username;

    $config  = MrcConfig::getInstance();
    $bbsName = MrcClient::sanitizeName($config->getBbsName());

    $mrc->connect(localUserId($user), $username, $bbsName, $password !== '' ? $password : null, clientIp());

    \WebDoorSDK\jsonResponse([
        'success' => true,
        'username' => $username,
    ]);
}

function handleDisconnect(PDO $db, MrcChatService $mrc, array $user): void
{
    $config   = MrcConfig::getInstance();
    $username = resolveMrcUsername($mrc, $user);
    $bbsName  = MrcClient::sanitizeName($config->getBbsName());
    $localUserId = localUserId($user);

    $rooms = $mrc->disconnect($localUserId, $username, $bbsName);

    foreach ($rooms as $room) {
        emitPresenceForRoom($db, $mrc, $room);
    }

    unset($_SESSION['mrc_username']);

    // Notify all other tabs/windows for this user so they return to the
    // connect screen instead of running against a terminated session.
    if ($localUserId !== null) {
        BinkStream::emit($db, 'mrc_session_ended', [], $localUserId);
    }

    \WebDoorSDK\jsonResponse(['success' => true]);
}
