<?php

/**
 * WebDoor Routes
 *
 * Web pages and REST API endpoints for WebDoor (HTML5 BBS games)
 */

use BinktermPHP\ActivityTracker;
use BinktermPHP\Auth;
use BinktermPHP\BbsConfig;
use BinktermPHP\DoorManager;
use BinktermPHP\GameConfig;
use BinktermPHP\JsdosDoorConfig;
use BinktermPHP\JsdosDoorManifest;
use BinktermPHP\JsdosDoorSupport;
use BinktermPHP\Template;
use BinktermPHP\WebDoorController;
use BinktermPHP\WebDoorManifest;
use BinktermPHP\WebDoorSupport;
use Pecee\SimpleRouter\SimpleRouter;

function webdoorApiError(string $errorCode, string $message, int $status = 400): void
{
    http_response_code($status);
    echo json_encode([
        'success' => false,
        'error_code' => $errorCode,
        'error' => $message,
    ]);
}

/**
 * Resolve a JS-DOS game + mode request with access checks and normalized manifest data.
 *
 * @param array<string, mixed>|null $user
 * @return array<string, mixed>|null
 */
function resolveJsdosDoorRequest(string $gameId, string $modeId = 'play', ?array $user = null): ?array
{
    $entry = JsdosDoorManifest::getManifest($gameId);
    if (!$entry || !JsdosDoorConfig::isEnabled($entry['id'])) {
        return null;
    }

    $manifest = JsdosDoorSupport::normalizeManifest($entry['manifest']);
    $mode = JsdosDoorSupport::getMode($manifest, $modeId);
    if (!$mode || !JsdosDoorSupport::canUserAccessMode($mode, $user)) {
        return null;
    }

    return [
        'entry' => $entry,
        'manifest' => $manifest,
        'mode' => $mode,
        'mode_id' => $modeId,
    ];
}

/**
 * Load a JS-DOS session for the current user.
 *
 * @return array<string, mixed>|null
 */
function loadJsdosSessionForUser(string $sessionId, int $userId): ?array
{
    $db = \BinktermPHP\Database::getInstance()->getPdo();
    $stmt = $db->prepare("
        SELECT session_id, door_id, user_id, door_type, user_data, ended_at, expires_at
          FROM door_sessions
         WHERE session_id = ?
            AND user_id = ?
            AND door_type = 'jsdos'
            AND ended_at IS NULL
          LIMIT 1
    ");
    $stmt->execute([$sessionId, $userId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    $userData = [];
    if (!empty($row['user_data'])) {
        if (is_array($row['user_data'])) {
            $userData = $row['user_data'];
        } elseif (is_string($row['user_data'])) {
            $decoded = json_decode($row['user_data'], true);
            if (is_array($decoded)) {
                $userData = $decoded;
            }
        }
    }

    $row['user_data'] = $userData;
    $row['mode'] = (string)($userData['mode'] ?? 'play');

    return $row;
}

/**
 * Web Routes for WebDoor
 */

// GET /games - List available games
SimpleRouter::get('/games', function() {
    $auth = new Auth();
    $user = $auth->getCurrentUser();

    if (!$user) {
        return SimpleRouter::response()->redirect('/login');
    }

     if(GameConfig::isGameSystemEnabled()==false){
         $template = new Template();
         $template->renderResponse('error.twig', [
             'error_code' => 'ui.webdoors.errors.system_disabled'
         ]);
         exit;
     }

    // Discover all enabled web experiences through the unified catalog.
    //
    // GameCatalog owns backend discovery and normalization. The route consumes
    // the compatibility fields exposed by the Experience contract so rendering,
    // leaderboard lookup, and launch URLs remain unchanged.
    $catalog = new \BinktermPHP\GameCatalog();
    $games = array_values($catalog->getEnabledGames($user, 'web'));

    // Resolve each normalized Experience through the canonical launch
    // contract. The template should not reconstruct backend URLs.
    foreach ($games as &$game) {
        $game['launch'] = \BinktermPHP\ExperienceLaunch::resolve($game, 'web');
    }
    unset($game);

    // Crossroads provides one collection-level live state read for the
    // discovery page. The existing browser presence polling remains in place
    // for live updates; this server-side snapshot gives the template a
    // normalized initial state without one query per Experience.
    $experienceStates = (new \BinktermPHP\ExperienceState())->getExperienceStates(
        $user,
        'web'
    );

    // Slice 5E: "Around the Crossroads" — a one-line, server-rendered snapshot
    // of live community presence, aggregated from the already-authorized
    // ExperienceState above. No extra query, no session lookup: active
    // Experiences are those the canonical state reports with players present,
    // and the player total is a distinct-user count across those rosters so a
    // caller in several Experiences at once is counted once.
    $aroundActiveExperiences = 0;
    $aroundActiveUserIds = [];
    foreach ($experienceStates as $aroundState) {
        if ((int)($aroundState['player_count'] ?? 0) > 0) {
            $aroundActiveExperiences++;
        }
        foreach ($aroundState['players'] ?? [] as $aroundPlayer) {
            $aroundUserId = (int)($aroundPlayer['user_id'] ?? 0);
            if ($aroundUserId > 0) {
                $aroundActiveUserIds[$aroundUserId] = true;
            }
        }
    }
    $aroundActivePlayers = count($aroundActiveUserIds);

    $currentUserId = (int)($user['user_id'] ?? $user['id'] ?? 0);
    $continuePlaying = [];
    $liveExperiences = [];
    $viewerIsParticipating = false;

    foreach ($games as &$game) {
        $experienceState = $experienceStates[$game['id']] ?? null;
        $viewerPlayer = is_array($experienceState)
            ? \BinktermPHP\ExperienceParticipation::findViewerPlayer(
                $experienceState,
                $currentUserId
            )
            : null;

        $game['experience_presentation'] =
            \BinktermPHP\ExperiencePresentation::build(
                $game,
                'web',
                $experienceState,
                $viewerPlayer
            );

        if ($viewerPlayer !== null) {
            $viewerIsParticipating = true;
            $continuePlaying[] = $game;
        } elseif (
            (int)($game['experience_presentation']['runtime']['player_count'] ?? 0) > 0
        ) {
            // Continue Playing already communicates the viewer's live entry;
            // avoid repeating it in the adjacent Live Now section. All
            // Experiences remains the complete canonical collection below.
            $liveExperiences[] = $game;
        }
    }
    unset($game);

    // Crossroads 5G: the global live count adds no community information when
    // the authenticated viewer is the only distinct active player. Keep the
    // quiet state and every state involving another player unchanged.
    $showGlobalPresenceSummary = !(
        $viewerIsParticipating
        && count($aroundActiveUserIds) === 1
        && isset($aroundActiveUserIds[$currentUserId])
    );

    // Sort all games by name
    usort($games, function($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });
    usort($continuePlaying, function($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });
    usort($liveExperiences, function($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });

    // Build game lookup table for leaderboard (includes display_name overrides)
    $gameLookup = [];
    foreach ($games as $game) {
        $gameLookup[$game['id']] = [
            'name' => $game['name'],  // Already has display_name override applied if configured
            'launch' => $game['launch'] ?? null
        ];
    }

    $monthOffset = 0;
    if (isset($_GET['month_offset'])) {
        $monthOffset = (int)$_GET['month_offset'];
        if ($monthOffset < 0) {
            $monthOffset = 0;
        }
        if ($monthOffset > 120) {
            $monthOffset = 120;
        }
    }

    $monthStart = new \DateTimeImmutable('first day of this month 00:00:00');
    if ($monthOffset > 0) {
        $monthStart = $monthStart->modify("-{$monthOffset} months");
    }
    $monthEnd = $monthStart->modify('+1 month');
    $leaderboardMonthLabel = $monthStart->format('F Y');

    $scoreboardExpanded = ($_GET['scoreboard'] ?? '') === 'full';
    $leaderboard = [];
    try {
        $db = \BinktermPHP\Database::getInstance()->getPdo();
        $leaderboard = (new \BinktermPHP\ExperienceScoreboard())->getMonthlyScores(
            $db,
            $gameLookup,
            $monthStart,
            $monthEnd,
            $scoreboardExpanded
        );
    } catch (\Throwable $e) {
        getServerLogger()->error('Failed to load WebDoor leaderboard: ' . $e->getMessage());
    }

    $template = new Template();
    $template->renderResponse('webdoors.twig', [
        'games' => $games,
        'continue_playing' => $continuePlaying,
        'live_experiences' => $liveExperiences,
        'experience_states' => $experienceStates,
        'around_active_players' => $aroundActivePlayers,
        'around_active_experiences' => $aroundActiveExperiences,
        'show_global_presence_summary' => $showGlobalPresenceSummary,
        'leaderboard' => $leaderboard,
        'leaderboard_month_label' => $leaderboardMonthLabel,
        'leaderboard_month_offset' => $monthOffset,
        'scoreboard_expanded' => $scoreboardExpanded,
    ]);
});

// GET /games/dosdoors/{doorid} - Play a DOS door game
SimpleRouter::get('/games/dosdoors/{doorid}', function($doorid) {
    $auth = new Auth();
    $user = $auth->getCurrentUser();

    if (!$user) {
        return SimpleRouter::response()->redirect('/login');
    }
    if(GameConfig::isGameSystemEnabled()==false){
        $template = new Template();
        $template->renderResponse('error.twig', [
            'error_code' => 'ui.webdoors.errors.system_disabled'
        ]);
        exit;
    }

    // Verify door exists and is enabled
    $doorManager = new DoorManager();
    $door = $doorManager->getDoor($doorid);

    if (!$door || empty($door['config']['enabled']) || !empty($door['config']['hide_from_web'])) {
        http_response_code(404);
        $template = new Template();
        $template->renderResponse('404.twig', [
            'requested_url' => "/games/dosdoors/{$doorid}"
        ]);
        return;
    }

    // Block admin-only doors for non-admins
    if (!empty($door['admin_only']) && empty($user['is_admin'])) {
        http_response_code(403);
        $template = new Template();
        $template->renderResponse('error.twig', [
            'error_title_code' => 'ui.error.access_error',
            'error_code' => 'ui.webdoors.errors.admin_only'
        ]);
        return;
    }

    // Include the DOS door player.
    $doorId = $doorid; // For the player script
    $returnUrl = '/games';
    $doorIsNativeTerminal = false; // DOS doors use the Doorway protocol for nav keys
    require __DIR__ . '/../public_html/webdoors/dosdoors/index.php';
});

// GET /games/nativedoors/{doorid} - Play a native Linux door game
SimpleRouter::get('/games/nativedoors/{doorid}', function($doorid) {
    $auth = new Auth();
    $user = $auth->getCurrentUser();

    if (!$user) {
        return SimpleRouter::response()->redirect('/login');
    }
    if (GameConfig::isGameSystemEnabled() == false) {
        $template = new Template();
        $template->renderResponse('error.twig', [
            'error_code' => 'ui.webdoors.errors.system_disabled'
        ]);
        exit;
    }

    // Verify door exists and is enabled
    $nativeDoorManager = new \BinktermPHP\NativeDoorManager();
    $door = $nativeDoorManager->getDoor($doorid);

    if (!$door || empty($door['config']['enabled']) || !empty($door['config']['hide_from_web'])) {
        http_response_code(404);
        $template = new Template();
        $template->renderResponse('404.twig', [
            'requested_url' => "/games/nativedoors/{$doorid}"
        ]);
        return;
    }

    // Block admin-only doors for non-admins
    if (!empty($door['admin_only']) && empty($user['is_admin'])) {
        http_response_code(403);
        $template = new Template();
        $template->renderResponse('error.twig', [
            'error_title_code' => 'ui.error.access_error',
            'error_code' => 'ui.webdoors.errors.admin_only'
        ]);
        return;
    }

    // Reuse the DOS door terminal player (same WebSocket protocol).
    // Native doors are canonical Experiences, so normal exit returns
    // to that Experience rather than dropping the user at /games.
    $doorId = $doorid;
    $returnUrl = '/experiences/' . rawurlencode((string)$doorid);
    $doorIsNativeTerminal = true; // Native doors expect raw xterm/ANSI key sequences
    require __DIR__ . '/../public_html/webdoors/dosdoors/index.php';
});

// GET /games/rlogindoors/{doorid} - Play an rlogin door
SimpleRouter::get('/games/rlogindoors/{doorid}', function($doorid) {
    $auth = new Auth();
    $user = $auth->getCurrentUser();

    if (!$user) {
        return SimpleRouter::response()->redirect('/login');
    }
    if (GameConfig::isGameSystemEnabled() == false) {
        $template = new Template();
        $template->renderResponse('error.twig', [
            'error_code' => 'ui.webdoors.errors.system_disabled'
        ]);
        exit;
    }

    // Verify door exists and is enabled
    $rloginDoorManager = new \BinktermPHP\RLoginDoorManager();
    $door = $rloginDoorManager->getDoor($doorid);

    if (!$door || empty($door['config']['enabled']) || !empty($door['config']['hide_from_web'])) {
        http_response_code(404);
        $template = new Template();
        $template->renderResponse('404.twig', [
            'requested_url' => "/games/rlogindoors/{$doorid}"
        ]);
        return;
    }

    // Block admin-only doors for non-admins
    if (!empty($door['admin_only']) && empty($user['is_admin'])) {
        http_response_code(403);
        $template = new Template();
        $template->renderResponse('error.twig', [
            'error_title_code' => 'ui.error.access_error',
            'error_code' => 'ui.webdoors.errors.admin_only'
        ]);
        return;
    }

    $doorId = $doorid;
    require __DIR__ . '/../public_html/webdoors/rlogindoors/index.php';
});

// GET /games/jsdos/{gameId} - JS-DOS door player (or coming-soon for custom emulators)
SimpleRouter::get('/games/jsdos/{gameId}', function(string $gameId) {
    $auth = new Auth();
    $user = $auth->getCurrentUser();

    if (!$user) {
        return SimpleRouter::response()->redirect('/login');
    }
    if (GameConfig::isGameSystemEnabled() == false) {
        $template = new Template();
        $template->renderResponse('error.twig', [
            'error_code' => 'ui.webdoors.errors.system_disabled'
        ]);
        exit;
    }

    $requestedMode = trim((string)($_GET['mode'] ?? 'play'));
    if ($requestedMode === '') {
        $requestedMode = 'play';
    }

    $entry = JsdosDoorManifest::getManifest($gameId);
    if (!$entry || !JsdosDoorConfig::isEnabled($entry['id'])) {
        http_response_code(404);
        $template = new Template();
        $template->renderResponse('404.twig', [
            'requested_url' => "/games/jsdos/{$gameId}"
        ]);
        return;
    }

    $resolved = resolveJsdosDoorRequest($gameId, $requestedMode, $user);
    if (!$resolved) {
        http_response_code(403);
        $template = new Template();
        $template->renderResponse('error.twig', [
            'error_title_code' => 'ui.error.access_error',
            'error_code' => 'ui.webdoors.errors.admin_only'
        ]);
        return;
    }

    $entry = $resolved['entry'];
    $manifest = $resolved['manifest'];
    $mode = $resolved['mode'];
    $gameConfig = JsdosDoorConfig::getGameConfig($entry['id']);
    $name = $gameConfig['display_name'] ?? $manifest['name'] ?? $entry['id'];
    $description = $gameConfig['display_description'] ?? $manifest['description'] ?? '';
    $gameData = array_merge($manifest, ['name' => $name, 'description' => $description]);

    $emulator = $mode['emulator'] ?? ($manifest['emulator'] ?? 'jsdos');
    $template = new Template();

    if ($emulator === 'jsdos') {
        $template->renderResponse('jsdosdoor_play.twig', [
            'game'      => $gameData,
            'game_id'   => $entry['id'],
            'game_path' => $entry['path'],
            'mode'      => $mode,
            'mode_id'   => $requestedMode,
        ]);
    } else {
        // Phase 5: custom emulator support — show placeholder for now
        $template->renderResponse('jsdosdoor_coming_soon.twig', [
            'game'      => $gameData,
            'game_path' => $entry['path'],
        ]);
    }
});

// POST /api/jsdoor/session - Create a JS-DOS game session
SimpleRouter::post('/api/jsdoor/session', function() {
    header('Content-Type: application/json');

    $auth = new Auth();
    $user = $auth->getCurrentUser();
    if (!$user) {
        http_response_code(401);
        webdoorApiError('errors.auth.authentication_required', 'Authentication required', 401);
        return;
    }
    if (!GameConfig::isGameSystemEnabled()) {
        webdoorApiError('errors.webdoor.feature_disabled', 'Game system is not enabled', 500);
        return;
    }

    $body = json_decode(file_get_contents('php://input'), true);
    $gameId = trim((string)($body['game_id'] ?? ''));
    $requestedMode = trim((string)($body['mode'] ?? 'play'));

    if ($gameId === '') {
        http_response_code(400);
        webdoorApiError('errors.jsdosdoor.game_not_found', 'Game ID required', 400);
        return;
    }

    if ($requestedMode === '') {
        $requestedMode = 'play';
    }

    $entry = JsdosDoorManifest::getManifest($gameId);
    if (!$entry || !JsdosDoorConfig::isEnabled($entry['id'])) {
        http_response_code(404);
        webdoorApiError('errors.jsdosdoor.game_not_found', 'Game not found', 404);
        return;
    }

    $resolved = resolveJsdosDoorRequest($gameId, $requestedMode, $user);
    if (!$resolved) {
        http_response_code(403);
        webdoorApiError('errors.door.admin_only', 'This door is restricted to administrators', 403);
        return;
    }

    $entry = $resolved['entry'];
    $manifest = $resolved['manifest'];
    $sessionCost = (int)($manifest['credits']['session_cost'] ?? 0);
    if ($sessionCost > 0) {
        http_response_code(400);
        webdoorApiError('errors.jsdosdoor.session_create_failed', 'Credit-gated sessions are not yet supported', 400);
        return;
    }

    $userId = (int)($user['user_id'] ?? $user['id'] ?? 0);
    $sessionId = bin2hex(random_bytes(16));
    $expiresAt = date('Y-m-d H:i:s', time() + 14400); // 4 hours

    try {
        $db = \BinktermPHP\Database::getInstance()->getPdo();

        // Close any existing active jsdos session for this user + game
        $stmt = $db->prepare("
            UPDATE door_sessions
               SET ended_at = NOW()
             WHERE user_id = ?
               AND door_id = ?
               AND door_type = 'jsdos'
               AND ended_at IS NULL
        ");
        $stmt->execute([$userId, $entry['id']]);

        // Create new session record
        $stmt = $db->prepare("
            INSERT INTO door_sessions (session_id, user_id, door_id, expires_at, door_type, user_data)
            VALUES (?, ?, ?, ?, 'jsdos', ?::jsonb)
        ");
        $userDataJson = json_encode(['mode' => $requestedMode]);
        $stmt->execute([$sessionId, $userId, $entry['id'], $expiresAt, $userDataJson]);

        ActivityTracker::track($userId, ActivityTracker::TYPE_WEBDOOR_PLAY, null, $entry['id']);

        echo json_encode([
            'success'    => true,
            'session_id' => $sessionId,
            'expires_at' => $expiresAt,
            'mode'       => $requestedMode,
        ]);
    } catch (\Throwable $e) {
        getServerLogger()->error('Failed to create jsdos session: ' . $e->getMessage());
        http_response_code(500);
        webdoorApiError('errors.jsdosdoor.session_create_failed', 'Failed to create session', 500);
    }
});

// POST /api/jsdoor/session/{sessionId}/end - End a JS-DOS game session
SimpleRouter::post('/api/jsdoor/session/{sessionId}/end', function(string $sessionId) {
    header('Content-Type: application/json');

    $auth = new Auth();
    $user = $auth->getCurrentUser();
    if (!$user) {
        http_response_code(401);
        webdoorApiError('errors.auth.authentication_required', 'Authentication required', 401);
        return;
    }

    $userId = (int)($user['user_id'] ?? $user['id'] ?? 0);

    try {
        $db = \BinktermPHP\Database::getInstance()->getPdo();
        $stmt = $db->prepare("
            UPDATE door_sessions
               SET ended_at = NOW()
             WHERE session_id = ?
               AND user_id = ?
               AND door_type = 'jsdos'
               AND ended_at IS NULL
        ");
        $stmt->execute([$sessionId, $userId]);

        echo json_encode(['success' => true]);
    } catch (\Throwable $e) {
        getServerLogger()->error('Failed to end jsdos session: ' . $e->getMessage());
        http_response_code(500);
        webdoorApiError('errors.jsdosdoor.session_end_failed', 'Failed to end session', 500);
    }
});

// GET /api/jsdoor/files/{gameId} - Load JS-DOS synced files for the active session
SimpleRouter::get('/api/jsdoor/files/{gameId}', function(string $gameId) {
    header('Content-Type: application/json');

    $auth = new Auth();
    $user = $auth->getCurrentUser();
    if (!$user) {
        http_response_code(401);
        webdoorApiError('errors.auth.authentication_required', 'Authentication required', 401);
        return;
    }

    $sessionId = trim((string)($_GET['session_id'] ?? ''));
    if ($sessionId === '') {
        http_response_code(400);
        webdoorApiError('errors.jsdosdoor.session_create_failed', 'Session ID required', 400);
        return;
    }

    $userId = (int)($user['user_id'] ?? $user['id'] ?? 0);
    $session = loadJsdosSessionForUser($sessionId, $userId);
    if (!$session || (string)$session['door_id'] !== $gameId) {
        http_response_code(404);
        webdoorApiError('errors.jsdosdoor.game_not_found', 'Active session not found', 404);
        return;
    }

    $resolved = resolveJsdosDoorRequest($gameId, (string)$session['mode'], $user);
    if (!$resolved) {
        http_response_code(403);
        webdoorApiError('errors.door.admin_only', 'This door is restricted to administrators', 403);
        return;
    }

    $manifest = $resolved['manifest'];
    $mode = $resolved['mode'];
    $modeSaveConfig = JsdosDoorSupport::getSaveConfig($mode);
    $files = [];

    foreach (JsdosDoorSupport::getSharedSaveModes($manifest) as $sharedMode) {
        $sharedFiles = JsdosDoorSupport::loadStoredFiles(
            JsdosDoorSupport::getSharedStorageDirectory($gameId),
            $sharedMode['saves']
        );
        foreach ($sharedFiles as $dosPath => $contents) {
            $files[$dosPath] = $contents;
        }
    }

    if (!empty($modeSaveConfig['enabled'])) {
        $baseDir = $modeSaveConfig['scope'] === 'shared'
            ? JsdosDoorSupport::getSharedStorageDirectory($gameId)
            : JsdosDoorSupport::getUserStorageDirectory($userId, $gameId);
        $modeFiles = JsdosDoorSupport::loadStoredFiles($baseDir, $modeSaveConfig);
        foreach ($modeFiles as $dosPath => $contents) {
            $files[$dosPath] = $contents;
        }
    }

    echo json_encode([
        'success' => true,
        'files' => array_map(static function(string $dosPath, string $contents): array {
            return [
                'dos_path' => $dosPath,
                'content_b64' => base64_encode($contents),
            ];
        }, array_keys($files), array_values($files)),
    ]);
});

// POST /api/jsdoor/files/{gameId} - Save modified JS-DOS files for the active session
SimpleRouter::post('/api/jsdoor/files/{gameId}', function(string $gameId) {
    header('Content-Type: application/json');

    $auth = new Auth();
    $user = $auth->getCurrentUser();
    if (!$user) {
        http_response_code(401);
        webdoorApiError('errors.auth.authentication_required', 'Authentication required', 401);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $sessionId = trim((string)($input['session_id'] ?? ''));
    $payloadFiles = is_array($input['files'] ?? null) ? $input['files'] : [];
    $endSession = !empty($input['end_session']);

    if ($sessionId === '') {
        http_response_code(400);
        webdoorApiError('errors.jsdosdoor.session_create_failed', 'Session ID required', 400);
        return;
    }

    $userId = (int)($user['user_id'] ?? $user['id'] ?? 0);
    $session = loadJsdosSessionForUser($sessionId, $userId);
    if (!$session || (string)$session['door_id'] !== $gameId) {
        http_response_code(404);
        webdoorApiError('errors.jsdosdoor.game_not_found', 'Active session not found', 404);
        return;
    }

    $resolved = resolveJsdosDoorRequest($gameId, (string)$session['mode'], $user);
    if (!$resolved) {
        http_response_code(403);
        webdoorApiError('errors.door.admin_only', 'This door is restricted to administrators', 403);
        return;
    }

    $modeSaveConfig = JsdosDoorSupport::getSaveConfig($resolved['mode']);
    if (empty($modeSaveConfig['enabled'])) {
        if ($endSession) {
            $db = \BinktermPHP\Database::getInstance()->getPdo();
            $stmt = $db->prepare("
                UPDATE door_sessions
                   SET ended_at = NOW()
                 WHERE session_id = ?
                   AND user_id = ?
                   AND door_type = 'jsdos'
                   AND ended_at IS NULL
            ");
            $stmt->execute([$sessionId, $userId]);
        }
        echo json_encode(['success' => true, 'saved' => 0]);
        return;
    }

    $isSharedScope = $modeSaveConfig['scope'] === 'shared';

    // Shared saves may only come from admin users operating an admin_only mode.
    // Non-admin callers or non-admin modes are blocked regardless of the scope value.
    if ($isSharedScope && (empty($resolved['mode']['admin_only']) || empty($user['is_admin']))) {
        http_response_code(403);
        webdoorApiError('errors.door.admin_only', 'Shared saves require an admin mode', 403);
        return;
    }

    $baseDir = $isSharedScope
        ? ''
        : JsdosDoorSupport::getUserStorageDirectory($userId, $gameId);

    try {
        $saved = 0;
        $daemonClient = $isSharedScope ? new \BinktermPHP\Admin\AdminDaemonClient() : null;
        foreach ($payloadFiles as $payloadFile) {
            if (!is_array($payloadFile)) {
                continue;
            }

            $dosPath = trim((string)($payloadFile['dos_path'] ?? ''));
            if ($dosPath === '') {
                continue;
            }

            if ($isSharedScope) {
                $contentB64 = (string)($payloadFile['content_b64'] ?? '');
                $daemonClient->saveJsdosSharedFile($gameId, $dosPath, $contentB64, !empty($payloadFile['deleted']));
                $saved++;
                continue;
            }

            if (!empty($payloadFile['deleted'])) {
                JsdosDoorSupport::deleteStoredFile($baseDir, $modeSaveConfig, $dosPath);
                $saved++;
                continue;
            }

            $contentB64 = (string)($payloadFile['content_b64'] ?? '');
            $contents = base64_decode($contentB64, true);
            if ($contents === false) {
                throw new \RuntimeException('Invalid base64 file payload');
            }

            JsdosDoorSupport::writeStoredFile($baseDir, $modeSaveConfig, $dosPath, $contents);
            $saved++;
        }

        if ($endSession) {
            $db = \BinktermPHP\Database::getInstance()->getPdo();
            $stmt = $db->prepare("
                UPDATE door_sessions
                   SET ended_at = NOW()
                 WHERE session_id = ?
                   AND user_id = ?
                   AND door_type = 'jsdos'
                   AND ended_at IS NULL
            ");
            $stmt->execute([$sessionId, $userId]);
        }

        echo json_encode(['success' => true, 'saved' => $saved]);
    } catch (\Throwable $e) {
        getServerLogger()->error('Failed to save jsdos files: ' . $e->getMessage());
        http_response_code(400);
        webdoorApiError('errors.jsdosdoor.session_end_failed', 'Failed to save files', 400);
    }
});

// GET /games/{game} - Play a specific game (DOS door or web door)
SimpleRouter::get('/games/{game}', function($game) {
    $auth = new Auth();
    $user = $auth->getCurrentUser();

    if (!$user) {
        return SimpleRouter::response()->redirect('/login');
    }
    if(GameConfig::isGameSystemEnabled()==false){
        $template = new Template();
        $template->renderResponse('error.twig', [
            'error_code' => 'ui.webdoors.errors.system_disabled'
        ]);
        exit;
    }

    // Check if this is a DOS door first
    $doorManager = new DoorManager();
    $door = $doorManager->getDoor($game);

    if ($door && !empty($door['config']['enabled'])) {
        if (!empty($door['config']['hide_from_web'])) {
            http_response_code(404);
            $template = new Template();
            $template->renderResponse('404.twig');
            return;
        }

        // Block admin-only doors for non-admins
        if (!empty($door['admin_only']) && empty($user['is_admin'])) {
            http_response_code(403);
            $template = new Template();
            $template->renderResponse('error.twig', [
                'error_title_code' => 'ui.error.access_error',
                'error_code' => 'ui.webdoors.errors.admin_only'
            ]);
            return;
        }

        // This is a DOS door - render with embedded player
        $template = new Template();
        $template->renderResponse('dosdoor_play.twig', [
            'door' => $door,
            'door_id' => $game,
            'player_url' => "/games/dosdoors/{$game}",
            'return_url' => '/games'
        ]);
        return;
    }

    // Check if this is a native door
    $nativeDoorManager = new \BinktermPHP\NativeDoorManager();
    $nativeDoor = $nativeDoorManager->getDoor($game);

    if ($nativeDoor && !empty($nativeDoor['config']['enabled'])) {
        if (!empty($nativeDoor['config']['hide_from_web'])) {
            http_response_code(404);
            $template = new Template();
            $template->renderResponse('404.twig');
            return;
        }

        // Block admin-only doors for non-admins
        if (!empty($nativeDoor['admin_only']) && empty($user['is_admin'])) {
            http_response_code(403);
            $template = new Template();
            $template->renderResponse('error.twig', [
                'error_title_code' => 'ui.error.access_error',
                'error_code' => 'ui.webdoors.errors.admin_only'
            ]);
            return;
        }

        $template = new Template();
        $template->renderResponse('dosdoor_play.twig', [
            'door' => $nativeDoor,
            'door_id' => $game,
            'player_url' => "/games/nativedoors/{$game}",
            'return_url' => '/experiences/' . rawurlencode((string)$game)
        ]);
        return;
    }

    // Check if this is an rlogin door
    $rloginDoorManager = new \BinktermPHP\RLoginDoorManager();
    $rloginDoor = $rloginDoorManager->getDoor($game);

    if ($rloginDoor && !empty($rloginDoor['config']['enabled'])) {
        // Block admin-only doors for non-admins
        if (!empty($rloginDoor['admin_only']) && empty($user['is_admin'])) {
            http_response_code(403);
            $template = new Template();
            $template->renderResponse('error.twig', [
                'error_title_code' => 'ui.error.access_error',
                'error_code' => 'ui.webdoors.errors.admin_only'
            ]);
            return;
        }

        $template = new Template();
        $template->renderResponse('dosdoor_play.twig', [
            'door' => $rloginDoor,
            'door_id' => $game,
            'player_url' => "/games/rlogindoors/{$game}"
        ]);
        return;
    }

    // Check if this is a JS-DOS door
    $jsdosEntry = JsdosDoorManifest::getManifest($game);
    if ($jsdosEntry && JsdosDoorConfig::isEnabled($jsdosEntry['id'])) {
        $requestedMode = trim((string)($_GET['mode'] ?? 'play'));
        if ($requestedMode === '') {
            $requestedMode = 'play';
        }

        $resolved = resolveJsdosDoorRequest($game, $requestedMode, $user);
        if (!$resolved) {
            http_response_code(403);
            $template = new Template();
            $template->renderResponse('error.twig', [
                'error_title_code' => 'ui.error.access_error',
                'error_code' => 'ui.webdoors.errors.admin_only'
            ]);
            return;
        }

        $manifest = $resolved['manifest'];
        $mode = $resolved['mode'];
        $gameConfig = JsdosDoorConfig::getGameConfig($jsdosEntry['id']);
        $name = $gameConfig['display_name'] ?? $manifest['name'] ?? $jsdosEntry['id'];
        $description = $gameConfig['display_description'] ?? $manifest['description'] ?? '';
        $modeLabel = (string)($mode['label'] ?? '');
        $playerUrl = "/games/jsdos/{$jsdosEntry['id']}" . ($requestedMode !== 'play' ? ('?mode=' . urlencode($requestedMode)) : '');
        $secondaryUrl = null;
        $secondaryLabelKey = null;

        if (!empty($user['is_admin']) && JsdosDoorSupport::hasMode($manifest, 'config')) {
            if ($requestedMode === 'config') {
                $secondaryUrl = "/games/{$game}";
                $secondaryLabelKey = 'ui.webdoor_play.play_mode';
            } else {
                $secondaryUrl = "/games/{$game}?mode=config";
                $secondaryLabelKey = 'ui.webdoor_play.admin_config';
            }
        }

        $template = new Template();
        $template->renderResponse('dosdoor_play.twig', [
            'door' => [
                'name' => $requestedMode === 'play' || $modeLabel === '' ? $name : ($name . ' - ' . $modeLabel),
                'description' => $description,
            ],
            'door_id' => $game,
            'player_url' => $playerUrl,
            'secondary_url' => $secondaryUrl,
            'secondary_label_key' => $secondaryLabelKey,
            'show_fullscreen_button' => false,
            'return_url' => '/experiences/' . rawurlencode((string)$game),
        ]);
        return;
    }

    // Check if this is a web door
    if (!GameConfig::isEnabled((string)$game)) {
        http_response_code(404);
        $template = new Template();
        $template->renderResponse('404.twig', [
            'requested_url' => "/games/{$game}"
        ]);
        return;
    }

    $gameDir = __DIR__ . '/../public_html/webdoors/' . basename($game);
    $manifestPath = $gameDir . '/webdoor.json';

    if (!file_exists($manifestPath)) {
        http_response_code(404);
        $template = new Template();
        $template->renderResponse('404.twig', [
            'requested_url' => "/games/{$game}"
        ]);
        return;
    }

    $manifest = json_decode(file_get_contents($manifestPath), true);

    // Check if all required features are available
    if (!WebDoorSupport::requirementsSatisfied($manifest)) {
        $template = new Template();
        $template->renderResponse('error.twig', [
            'error_code' => 'ui.webdoors.errors.requirements_not_met'
        ]);
        return;
    }

    $entryPoint = $manifest['game']['entry_point'] ?? 'index.html';
    $gameUrl = "/webdoors/{$game}/{$entryPoint}";

    // Apply display_name and display_description overrides if configured
    $gameData = $manifest['game'];
    $gameConfig = GameConfig::getGameConfig($game);
    if ($gameConfig) {
        // Check top level (config/webdoors.json uses flat structure)
        $displayName = $gameConfig['display_name'] ?? null;
        $displayDesc = $gameConfig['display_description'] ?? null;

        if (!empty($displayName)) {
            $gameData['name'] = $displayName;
        }
        if (!empty($displayDesc)) {
            $gameData['description'] = $displayDesc;
        }
    }

    $template = new Template();
    $template->renderResponse('webdoor_play.twig', [
        'game' => $gameData,
        'game_url' => $gameUrl,
        'game_id' => $game,
        'return_url' => '/experiences/' . rawurlencode((string)$game)
    ]);
});

/**
 * WebDoor API Routes
 */

// GET /api/webdoor/session - Get or create session
SimpleRouter::get('/api/webdoor/session', function() {
    header('Content-Type: application/json');
    if(GameConfig::isGameSystemEnabled()==false){
        webdoorApiError('errors.webdoor.feature_disabled', 'Game system is not enabled', 500);
        exit;
    }

    $controller = new WebDoorController();
    $result = $controller->getSession();

    // Track webdoor play session (only when the session endpoint returns successfully)
    if (!empty($result['session_id'])) {
        $auth = new Auth();
        $user = $auth->getCurrentUser();
        if ($user) {
            $userId = $user['user_id'] ?? $user['id'] ?? null;
            $gameId = $result['game']['id'] ?? null;
            ActivityTracker::track($userId, ActivityTracker::TYPE_WEBDOOR_PLAY, null, $gameId);
        }
    }

    echo json_encode($result);
});

// POST /api/webdoor/session/end - End session
SimpleRouter::post('/api/webdoor/session/end', function() {
    header('Content-Type: application/json');
    if(GameConfig::isGameSystemEnabled()==false){
        webdoorApiError('errors.webdoor.feature_disabled', 'Game system is not enabled', 500);
        exit;
    }

    $controller = new WebDoorController();
    $result = $controller->endSession();

    echo json_encode($result);
});

// GET /api/webdoor/storage - List all saves
SimpleRouter::get('/api/webdoor/storage', function() {
    header('Content-Type: application/json');
    if(GameConfig::isGameSystemEnabled()==false){
        webdoorApiError('errors.webdoor.feature_disabled', 'Game system is not enabled', 500);
        exit;
    }

    $controller = new WebDoorController();
    $result = $controller->listSaves();

    echo json_encode($result);
});

// GET /api/webdoor/storage/{slot} - Load specific save
SimpleRouter::get('/api/webdoor/storage/{slot}', function($slot) {
    header('Content-Type: application/json');
    if(GameConfig::isGameSystemEnabled()==false){
        webdoorApiError('errors.webdoor.feature_disabled', 'Game system is not enabled', 500);
        exit;
    }

    $controller = new WebDoorController();
    $result = $controller->loadSave((int)$slot);

    if ($result === null) {
        webdoorApiError('errors.webdoor.save_not_found', 'Save not found', 404);
        return;
    }

    echo json_encode($result);
});

// PUT /api/webdoor/storage/{slot} - Save game data
SimpleRouter::put('/api/webdoor/storage/{slot}', function($slot) {
    header('Content-Type: application/json');
    if(GameConfig::isGameSystemEnabled()==false){
        webdoorApiError('errors.webdoor.feature_disabled', 'Game system is not enabled', 500);
        exit;
    }

    $controller = new WebDoorController();
    $result = $controller->saveGame((int)$slot);

    echo json_encode($result);
});

// DELETE /api/webdoor/storage/{slot} - Delete save
SimpleRouter::delete('/api/webdoor/storage/{slot}', function($slot) {
    header('Content-Type: application/json');
    if(GameConfig::isGameSystemEnabled()==false){
        webdoorApiError('errors.webdoor.feature_disabled', 'Game system is not enabled', 500);
        exit;
    }

    $controller = new WebDoorController();
    $result = $controller->deleteSave((int)$slot);

    echo json_encode($result);
});

// GET /api/webdoor/leaderboard/{board} - Get leaderboard
SimpleRouter::get('/api/webdoor/leaderboard/{board}', function($board) {
    header('Content-Type: application/json');
    if(GameConfig::isGameSystemEnabled()==false){
        webdoorApiError('errors.webdoor.feature_disabled', 'Game system is not enabled', 500);
        exit;
    }

    $controller = new WebDoorController();
    $result = $controller->getLeaderboard($board);

    echo json_encode($result);
});

// POST /api/webdoor/leaderboard/{board} - Submit score
SimpleRouter::post('/api/webdoor/leaderboard/{board}', function($board) {
    header('Content-Type: application/json');
    if(GameConfig::isGameSystemEnabled()==false){
        webdoorApiError('errors.webdoor.feature_disabled', 'Game system is not enabled', 500);
        exit;
    }

    $controller = new WebDoorController();
    $result = $controller->submitScore($board);

    echo json_encode($result);
});
