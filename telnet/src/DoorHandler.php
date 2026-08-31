<?php

namespace BinktermPHP\TelnetServer;

use BinktermPHP\Config;
use BinktermPHP\ExperienceActivity;
use BinktermPHP\ExperienceParticipation;
use BinktermPHP\ExperiencePresentation;
use BinktermPHP\ExperienceState;

/**
 * DoorHandler - DOS door game access via telnet
 *
 * Connects telnet clients to DOS door games by acting as a WebSocket client
 * to the dosbox-bridge multiplexing server. Data is relayed bidirectionally
 * with CP437/UTF-8 encoding conversion and ANSI→Doorway protocol key mapping.
 *
 * The WebSocket URL is always built from DOSDOOR_WS_BIND_HOST and
 * DOSDOOR_WS_PORT so the daemon connects to the bridge directly over the
 * loopback interface, bypassing any public-facing SSL proxy.
 */
class DoorHandler
{
    private BbsSession $server;
    private string $apiBase;

    /**
     * @param BbsSession $server Telnet server instance for I/O operations
     * @param string $apiBase Base URL for API requests
     */
    public function __construct(BbsSession $server, string $apiBase)
    {
        $this->server = $server;
        $this->apiBase = $apiBase;
    }

    /**
     * Show door selection menu and launch the selected door
     *
     * @param resource $conn Socket connection to client
     * @param array $state Terminal state array
     * @param string $session Session token for authentication
     */
    public function show($conn, array &$state, string $session): void
    {
        $shell = TerminalShellFactory::create($this->server, $state);
        TelnetUtils::safeWrite($conn, "\033[2J\033[H");
        if (TelnetUtils::showScreenIfExists('doors.ans', $this->server, $conn)) {
            TelnetUtils::safeWrite($conn, "\r\n" . TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.server.press_any_key', 'Press any key to continue...', [], $state['locale']),
                TelnetUtils::ANSI_YELLOW
            ));

            while (true) {
                $key = $this->server->readKeyWithIdleCheck($conn, $state);
                if ($key !== '') {
                    break;
                }
            }
        }
        $locale = $state['locale'] ?? 'en';
        $t = function (string $key, array $params = [], string $fallback = '') use ($locale): string {
            return $this->server->t($key, $fallback, $params, $locale);
        };
        $modelUser = [
            'user_id' => (int)($state['user_id'] ?? 0),
            'id' => (int)($state['user_id'] ?? 0),
            'is_admin' => !empty($state['is_admin']),
        ];
        $experienceState = new ExperienceState();

        while (true) {
            // One collection read owns authorized terminal discovery plus the
            // Live Now and Your Places arrival snapshots. Returning from a
            // detail or arrival view reaches this boundary and refreshes it.
            $experienceStates = $experienceState->getExperienceStates(
                $modelUser,
                'terminal'
            );

            $doorList = [];
            foreach ($experienceStates as $experienceId => $snapshot) {
                $experience = $snapshot['experience'] ?? null;
                if (!is_array($experience) || empty($experience['actions']['launch'])) {
                    continue;
                }
                $doorList[] = ['id' => (string)$experienceId, 'data' => $experience];
            }

            if ($experienceStates === []) {
                $shell->showText(
                    $conn,
                    $state,
                    $t('ui.terminalserver.doors.title', [], 'Crossroads'),
                    [$t('ui.terminalserver.doors.no_doors', [], 'No games or experiences are currently available.')]
                );
                return;
            }

            $liveNow = self::composeLiveNow(
                $experienceStates,
                (int)$modelUser['user_id'],
                $t
            );
            $yourPlaces = self::composeYourPlaces(
                $experienceStates,
                (int)$modelUser['user_id'],
                $t
            );
            $items = [
                self::buildLiveNowArrivalItem($liveNow, $t),
                self::buildYourPlacesArrivalItem($yourPlaces, $t),
            ];
            foreach ($doorList as $catalogIndex => $entry) {
                $items[] = self::buildExperienceListItem(
                    $entry['id'],
                    $entry['data'],
                    $t,
                    $catalogIndex === 0
                );
            }

            $selected = $shell->chooseFromList(
                $conn,
                $state,
                $this->server->t('ui.terminalserver.doors.title', 'Crossroads', [], $state['locale']),
                $items,
                [
                    'prompt' => $this->server->t('ui.terminalserver.doors.enter_choice', 'Select an experience or Q to return: ', [], $state['locale']),
                    'empty_message' => $this->server->t('ui.terminalserver.doors.no_doors', 'No games or experiences are currently available.', [], $state['locale']),
                ]
            );
            if ($selected === null) {
                return;
            }

            if ($selected === 0) {
                $reloadLiveNow = function () use ($experienceState, $modelUser, $t): array {
                    return self::composeLiveNow(
                        $experienceState->getExperienceStates($modelUser, 'terminal'),
                        (int)$modelUser['user_id'],
                        $t
                    );
                };
                $openDetail = function (string $experienceId) use ($conn, &$state, $session, $shell): void {
                    $this->showExperienceDetail($conn, $state, $session, $experienceId, $shell);
                };
                $this->runLiveNowLoop($conn, $state, $shell, $reloadLiveNow, $openDetail, $t);
                continue;
            }

            if ($selected === 1) {
                $reloadYourPlaces = function () use ($experienceState, $modelUser, $t): array {
                    return self::composeYourPlaces(
                        $experienceState->getExperienceStates($modelUser, 'terminal'),
                        (int)$modelUser['user_id'],
                        $t
                    );
                };
                $openDetail = function (string $experienceId) use ($conn, &$state, $session, $shell): void {
                    $this->showExperienceDetail($conn, $state, $session, $experienceId, $shell);
                };
                $this->runYourPlacesLoop($conn, $state, $shell, $reloadYourPlaces, $openDetail, $t);
                continue;
            }

            $entry = $doorList[$selected - 2];

            // Selecting an experience now opens a terminal-native detail
            // screen instead of launching immediately. Play/Return happens
            // from there; Back returns here, to the Crossroads catalog list.
            $this->showExperienceDetail(
                $conn,
                $state,
                $session,
                (string)$entry['id'],
                $shell
            );
            continue;
        }
    }

    /**
     * Compose the terminal Live Now arrival snapshot from one authorized
     * collection-state read.
     *
     * Viewer-only occupancy is omitted because it communicates continuation,
     * not social activity. An Experience remains live when any other distinct
     * caller is present, including when the viewer is also participating.
     *
     * @param array<string,array<string,mixed>> $experienceStates
     * @param callable(string,array<string,mixed>,string):string $t
     * @return array{summary:string,entries:array<int,array<string,mixed>>,player_count:int,experience_count:int}
     */
    public static function composeLiveNow(
        array $experienceStates,
        int $viewerId,
        callable $t
    ): array {
        $entries = [];
        $activeUserIds = [];

        foreach ($experienceStates as $experienceId => $snapshot) {
            $experience = $snapshot['experience'] ?? null;
            if (
                !is_array($experience)
                || empty($experience['actions']['launch'])
                || (int)($snapshot['player_count'] ?? 0) <= 0
            ) {
                continue;
            }

            $distinctPlayers = [];
            foreach ($snapshot['players'] ?? [] as $player) {
                if (!is_array($player)) {
                    continue;
                }
                $userId = (int)($player['user_id'] ?? 0);
                if ($userId > 0 && !isset($distinctPlayers[$userId])) {
                    $distinctPlayers[$userId] = $player;
                }
            }

            if ($distinctPlayers === []) {
                continue;
            }

            if (
                count($distinctPlayers) === 1
                && $viewerId > 0
                && isset($distinctPlayers[$viewerId])
            ) {
                continue;
            }

            foreach (array_keys($distinctPlayers) as $userId) {
                $activeUserIds[$userId] = true;
            }

            $entries[] = [
                'id' => (string)$experienceId,
                'experience' => $experience,
                'player_count' => count($distinctPlayers),
                'session_count' => (int)($snapshot['session_count'] ?? 0),
                'players' => array_values($distinctPlayers),
                'item' => self::buildLiveNowListItem(
                    $experience,
                    count($distinctPlayers),
                    (int)($snapshot['session_count'] ?? 0),
                    array_values($distinctPlayers),
                    $t
                ),
            ];
        }

        usort(
            $entries,
            static fn(array $a, array $b): int => strcasecmp(
                (string)($a['experience']['name'] ?? $a['id']),
                (string)($b['experience']['name'] ?? $b['id'])
            )
        );

        $playerCount = count($activeUserIds);
        $experienceCount = count($entries);
        if ($experienceCount === 0) {
            $summary = $t(
                'ui.terminalserver.doors.live_now_quiet',
                [],
                'The Crossroads are quiet right now.'
            );
        } elseif ($playerCount === 1 && $experienceCount === 1) {
            $summary = $t(
                'ui.terminalserver.doors.live_now_summary_1p_1e',
                [],
                '1 caller in 1 Experience'
            );
        } elseif ($playerCount === 1) {
            $summary = $t(
                'ui.terminalserver.doors.live_now_summary_1p',
                ['experiences' => $experienceCount],
                '1 caller in {experiences} Experiences'
            );
        } elseif ($experienceCount === 1) {
            $summary = $t(
                'ui.terminalserver.doors.live_now_summary_1e',
                ['players' => $playerCount],
                '{players} callers in 1 Experience'
            );
        } else {
            $summary = $t(
                'ui.terminalserver.doors.live_now_summary',
                ['players' => $playerCount, 'experiences' => $experienceCount],
                '{players} callers in {experiences} Experiences'
            );
        }

        return [
            'summary' => $summary,
            'entries' => $entries,
            'player_count' => $playerCount,
            'experience_count' => $experienceCount,
        ];
    }

    /** @return array{label:string,detail:string} */
    public static function buildLiveNowArrivalItem(array $liveNow, callable $t): array
    {
        return [
            'label' => $t('ui.terminalserver.doors.live_now_title', [], 'Live Now'),
            'detail' => (string)($liveNow['summary'] ?? ''),
        ];
    }

    /**
     * Compose the caller's active terminal Experiences from the same
     * authorized collection snapshot used by Live Now and the catalog.
     *
     * Membership is owned exclusively by findViewerPlayer(). Viewer-only
     * occupancy therefore remains visible here, while other-caller-only
     * occupancy does not qualify.
     *
     * @param array<string,array<string,mixed>> $experienceStates
     * @param callable(string,array<string,mixed>,string):string $t
     * @return array{summary:string,entries:array<int,array<string,mixed>>,experience_count:int}
     */
    public static function composeYourPlaces(
        array $experienceStates,
        int $viewerId,
        callable $t
    ): array {
        $entries = [];

        foreach ($experienceStates as $experienceId => $snapshot) {
            $experience = $snapshot['experience'] ?? null;
            if (!is_array($experience)) {
                continue;
            }

            $viewerPlayer = ExperienceParticipation::findViewerPlayer($snapshot, $viewerId);
            if ($viewerPlayer === null) {
                continue;
            }

            $presentation = ExperiencePresentation::build(
                $experience,
                'telnet',
                $snapshot,
                $viewerPlayer
            );
            $entries[] = [
                'id' => (string)$experienceId,
                'experience' => $experience,
                'viewer_player' => $viewerPlayer,
                'presentation' => $presentation,
                'item' => self::buildYourPlacesListItem($presentation, $t),
            ];
        }

        usort(
            $entries,
            static fn(array $a, array $b): int => strcasecmp(
                (string)($a['experience']['name'] ?? $a['id']),
                (string)($b['experience']['name'] ?? $b['id'])
            )
        );

        $experienceCount = count($entries);
        if ($experienceCount === 0) {
            $summary = $t(
                'ui.terminalserver.doors.your_places_quiet',
                [],
                'You have no active places right now.'
            );
        } elseif ($experienceCount === 1) {
            $summary = $t(
                'ui.terminalserver.doors.your_places_summary_1',
                [],
                '1 active place'
            );
        } else {
            $summary = $t(
                'ui.terminalserver.doors.your_places_summary',
                ['count' => $experienceCount],
                '{count} active places'
            );
        }

        return [
            'summary' => $summary,
            'entries' => $entries,
            'experience_count' => $experienceCount,
        ];
    }

    /** @return array{label:string,detail:string} */
    public static function buildYourPlacesArrivalItem(array $yourPlaces, callable $t): array
    {
        return [
            'label' => $t('ui.terminalserver.doors.your_places_title', [], 'Your Places'),
            'detail' => (string)($yourPlaces['summary'] ?? ''),
        ];
    }

    /** @return array{label:string,detail:string} */
    private static function buildYourPlacesListItem(array $presentation, callable $t): array
    {
        $detail = $t(
            'ui.terminalserver.doors.your_places_participating',
            [],
            'Participating'
        );
        if (!empty($presentation['actions']['return'])) {
            $detail .= ' | ' . $t(
                'ui.terminalserver.doors.your_places_return_available',
                [],
                'Return available'
            );
        }

        return [
            'label' => (string)($presentation['name'] ?? $presentation['id'] ?? ''),
            'detail' => $detail,
        ];
    }

    /** @return array{label:string,detail:string} */
    private static function buildLiveNowListItem(
        array $experience,
        int $playerCount,
        int $sessionCount,
        array $players,
        callable $t
    ): array {
        $name = (string)($experience['name'] ?? $experience['id'] ?? '');
        $maxSessions = (int)($experience['capacity']['max_sessions'] ?? 0);
        $occupancy = $playerCount === 1
            ? $t('ui.terminalserver.doors.live_now_player', [], '1 caller')
            : $t(
                'ui.terminalserver.doors.live_now_players',
                ['count' => $playerCount],
                '{count} callers'
            );
        if ($maxSessions > 0) {
            $occupancy .= ' | ' . $t(
                'ui.terminalserver.doors.live_now_sessions',
                ['count' => $sessionCount, 'max' => $maxSessions],
                '{count}/{max} sessions'
            );
        }

        $names = [];
        foreach ($players as $player) {
            $username = trim((string)($player['username'] ?? ''));
            if ($username !== '') {
                $names[] = $username;
            }
            if (count($names) >= 3) {
                break;
            }
        }
        if ($names !== []) {
            $occupancy .= ' | ' . implode(', ', $names);
            if (count($players) > count($names)) {
                $occupancy .= $t(
                    'ui.terminalserver.doors.live_now_roster_more',
                    ['count' => count($players) - count($names)],
                    ' +{count} more'
                );
            }
        }

        return ['label' => $name, 'detail' => $occupancy];
    }

    /**
     * Refresh and navigate the Live Now view. Detail Back returns here; the
     * next loop iteration performs a fresh collection-state read.
     *
     * @param callable():array<string,mixed> $reload
     * @param callable(string):void $openDetail
     * @param callable(string,array<string,mixed>,string):string $t
     */
    private function runLiveNowLoop(
        $conn,
        array &$state,
        TerminalShellInterface $shell,
        callable $reload,
        callable $openDetail,
        callable $t
    ): void {
        while (true) {
            $liveNow = $reload();
            $entries = is_array($liveNow['entries'] ?? null) ? $liveNow['entries'] : [];
            $items = array_values(array_map(
                static fn(array $entry): array => $entry['item'],
                $entries
            ));

            $selected = $shell->chooseFromList(
                $conn,
                $state,
                $t('ui.terminalserver.doors.live_now_title', [], 'Live Now'),
                $items,
                [
                    'prompt' => $t('ui.terminalserver.doors.live_now_prompt', [], 'Select an Experience or Q to return: '),
                    'empty_message' => $t('ui.terminalserver.doors.live_now_empty', [], 'Nobody else is active in an Experience right now.'),
                ]
            );

            if ($selected === null) {
                return;
            }

            $experienceId = (string)($entries[$selected]['id'] ?? '');
            if ($experienceId !== '') {
                $openDetail($experienceId);
            }
        }
    }

    /**
     * Refresh and navigate Your Places. Detail Back returns here; the next
     * iteration reloads participation from the authorized collection state.
     *
     * @param callable():array<string,mixed> $reload
     * @param callable(string):void $openDetail
     * @param callable(string,array<string,mixed>,string):string $t
     */
    private function runYourPlacesLoop(
        $conn,
        array &$state,
        TerminalShellInterface $shell,
        callable $reload,
        callable $openDetail,
        callable $t
    ): void {
        while (true) {
            $yourPlaces = $reload();
            $entries = is_array($yourPlaces['entries'] ?? null) ? $yourPlaces['entries'] : [];
            $items = array_values(array_map(
                static fn(array $entry): array => $entry['item'],
                $entries
            ));

            $selected = $shell->chooseFromList(
                $conn,
                $state,
                $t('ui.terminalserver.doors.your_places_title', [], 'Your Places'),
                $items,
                [
                    'prompt' => $t('ui.terminalserver.doors.your_places_prompt', [], 'Select an Experience or Q to return: '),
                    'empty_message' => $t('ui.terminalserver.doors.your_places_empty', [], 'You have no active places right now.'),
                ]
            );

            if ($selected === null) {
                return;
            }

            $experienceId = (string)($entries[$selected]['id'] ?? '');
            if ($experienceId !== '') {
                $openDetail($experienceId);
            }
        }
    }

    /**
     * Terminal-native Crossroads experience detail screen.
     *
     * This is the first telnet Crossroads product slice: before launching, the
     * caller sees the shared Crossroads context for the experience (status,
     * occupancy, roster, recent activity, their own participation state) and
     * then chooses Play / Return / Back.
     *
     * All state is composed from the same shared read models the web experience
     * lobby uses — {@see ExperienceState::getExperienceState()},
     * {@see ExperiencePresentation::build()}, {@see ExperienceActivity::recent()}
     * and {@see ExperienceParticipation::findViewerPlayer()} — so telnet and web
     * cannot drift on availability, capacity, or participation semantics.
     *
     * The screen is recomposed on every loop iteration, so returning here after
     * a door exits reflects the caller's new session/participation state. Back
     * returns to the Crossroads catalog list without launching anything.
     *
     * @param resource $conn Socket connection to client
     * @param array<string,mixed> $state Terminal state array
     * @param string $session Session token for authentication
     * @param string $experienceId Canonical Experience identifier
     * @param TerminalShellInterface $shell Resolved shell for this session
     */
    private function showExperienceDetail(
        $conn,
        array &$state,
        string $session,
        string $experienceId,
        TerminalShellInterface $shell
    ): void {
        $locale = $state['locale'] ?? 'en';
        $t = function (string $key, array $params = [], string $fallback = '') use ($locale): string {
            return $this->server->t($key, $fallback, $params, $locale);
        };

        $modelUser = [
            'user_id'  => (int)($state['user_id'] ?? 0),
            'id'       => (int)($state['user_id'] ?? 0),
            'is_admin' => !empty($state['is_admin']),
        ];
        $viewerId = (int)($state['user_id'] ?? 0);
        $chatEnabled = \BinktermPHP\BbsConfig::isFeatureEnabled('chat');

        // Recompose the shared Crossroads read models on demand. The detail loop
        // calls this once per iteration, so the screen shown after a door exits
        // (or after a social view) reflects the caller's new
        // session/participation/roster state.
        $reload = function () use ($experienceId, $modelUser, $viewerId, $chatEnabled, $t): ?array {
            $experienceState = (new ExperienceState())->getExperienceState(
                $experienceId,
                $modelUser,
                'terminal'
            );

            if (!is_array($experienceState) || !is_array($experienceState['experience'] ?? null)) {
                return null;
            }

            $experience = $experienceState['experience'];
            $viewerPlayer = ExperienceParticipation::findViewerPlayer($experienceState, $viewerId);
            $presentation = ExperiencePresentation::build(
                $experience,
                'telnet',
                $experienceState,
                $viewerPlayer
            );
            $recentActivity = (new ExperienceActivity())->recent($experience, 5);

            return self::composeExperienceDetailView(
                $experience,
                $presentation,
                $experienceState,
                $recentActivity,
                $viewerPlayer,
                $t,
                $chatEnabled
            );
        };

        $onLaunch = function (array $view) use ($conn, &$state, $session, $experienceId): void {
            $experience = $view['experience'];
            $this->server->logAction(
                $state['username'] ?? 'unknown',
                "Doors: launched \"{$view['name']}\""
            );
            $this->launchDoor(
                $conn,
                $state,
                $session,
                $experienceId,
                $view['name'],
                self::resolveTerminalMode($experience),
                (string)($experience['backend']['type'] ?? '')
            );
        };

        $onSocial = function (string $kind, array $view) use ($conn, &$state, $session, $shell, $t): void {
            if ($kind === 'people') {
                $this->showExperiencePeople($conn, $state, $session, $view, $shell, $t);
            } elseif ($kind === 'conversation') {
                $this->openExperienceConversation($conn, $state, $session, $view, $shell, $t);
            }
        };

        $onEnd = function (array $view) use ($session, $experienceId, &$state): ?string {
            return $this->endExperienceParticipation(
                $session,
                $experienceId,
                $state['csrf_token'] ?? null
            );
        };

        $this->runExperienceDetailLoop($conn, $state, $shell, $reload, $onLaunch, $t, $onSocial, $onEnd);
    }

    /**
     * Contextual People view for one experience.
     *
     * The roster is the snapshot captured by {@see composeExperienceDetailView()}
     * for this visit — no new presence query. Selecting another caller opens a
     * small View profile / Send message flow built entirely on existing
     * infrastructure ({@see fetchPersonProfile()} -> the same public-profile
     * endpoint Who's Online uses, and {@see invokeDirectMessage()} -> ChatHandler).
     * Back returns to the experience detail screen, which is then recomposed.
     *
     * @param resource $conn
     * @param array<string,mixed> $state
     * @param array<string,mixed> $view Detail view model from composeExperienceDetailView()
     * @param callable(string,array<string,mixed>,string):string $t Translator: (key, params, fallback)
     */
    private function showExperiencePeople(
        $conn,
        array &$state,
        string $session,
        array $view,
        TerminalShellInterface $shell,
        callable $t
    ): void {
        $roster = is_array($view['roster'] ?? null) ? array_values($view['roster']) : [];
        $viewerId = (int)($state['user_id'] ?? 0);
        $title = $t(
            'ui.terminalserver.doors.people_title',
            ['name' => (string)($view['name'] ?? '')],
            '{name} - Who is here'
        );

        $onPerson = function (array $person) use ($conn, &$state, $session, $shell, $t): void {
            $this->showPersonActions($conn, $state, $session, $person, $shell, $t);
        };

        $this->runExperiencePeopleLoop($conn, $state, $shell, $roster, $viewerId, $title, $onPerson, $t);
    }

    /**
     * People selection loop. No I/O beyond the shell and the injected
     * $onPerson callback, so the "select a caller -> act -> Back -> People"
     * navigation is directly testable.
     *
     * @param resource $conn
     * @param array<string,mixed> $state
     * @param array<int,array<string,mixed>> $roster Snapshot roster (from the detail view model)
     * @param callable(array<string,mixed>):void $onPerson Invoked for a selected non-self caller
     * @param callable(string,array<string,mixed>,string):string $t Translator: (key, params, fallback)
     */
    private function runExperiencePeopleLoop(
        $conn,
        array &$state,
        TerminalShellInterface $shell,
        array $roster,
        int $viewerId,
        string $title,
        callable $onPerson,
        callable $t
    ): void {
        $roster = array_values(array_filter(
            $roster,
            static fn ($p): bool => is_array($p) && (int)($p['user_id'] ?? 0) > 0
        ));

        if ($roster === []) {
            $shell->showAlert(
                $conn,
                $state,
                $title,
                $t('ui.terminalserver.doors.people_empty', [], 'Nobody is here right now.'),
                'info'
            );
            return;
        }

        $items = array_map(function (array $player) use ($viewerId, $t): string {
            $username = trim((string)($player['username'] ?? ''));
            $node = $player['node'] ?? null;
            $label = $node !== null
                ? $t('ui.terminalserver.doors.people_node', ['username' => $username, 'node' => (int)$node], '{username}  |  node {node}')
                : $username;
            if ($viewerId > 0 && (int)($player['user_id'] ?? 0) === $viewerId) {
                $label .= '  ' . $t('ui.terminalserver.doors.detail_roster_you', [], '(you)');
            }
            return $label;
        }, $roster);

        $selected = 0;
        while (true) {
            $result = $shell->showSelectableDialog(
                $conn,
                $state,
                $title,
                $items,
                $t('ui.terminalserver.doors.people_select_hint', [], 'Select'),
                $t('ui.terminalserver.doors.detail_action_back', [], 'Back'),
                $selected,
                null
            );

            if ($result === null || ($result['action'] ?? '') !== 'select') {
                return;
            }

            $selected = (int)($result['index'] ?? 0);
            $person = $roster[$selected] ?? null;
            if (!is_array($person)) {
                continue;
            }

            if ((int)($person['user_id'] ?? 0) === $viewerId) {
                $shell->showAlert(
                    $conn,
                    $state,
                    (string)($person['username'] ?? ''),
                    $t('ui.terminalserver.doors.person_is_you', [], 'That is you.'),
                    'info'
                );
                continue;
            }

            $onPerson($person);
        }
    }

    /**
     * View profile / Send message flow for one roster-selected caller.
     *
     * The public profile is fetched once ({@see fetchPersonProfile()}); a 404
     * means the caller signed off between the roster snapshot and the
     * selection, surfaced as an alert. Profile rendering is delegated to
     * {@see TerminalShellInterface::showPublicProfileViewer()} and message
     * delivery to {@see ChatHandler::showDirectMessage()} — neither is
     * reimplemented here.
     *
     * @param resource $conn
     * @param array<string,mixed> $state
     * @param array<string,mixed> $person Roster entry ({user_id, username, ...})
     * @param callable(string,array<string,mixed>,string):string $t Translator: (key, params, fallback)
     */
    private function showPersonActions(
        $conn,
        array &$state,
        string $session,
        array $person,
        TerminalShellInterface $shell,
        callable $t
    ): void {
        $userId = (int)($person['user_id'] ?? 0);
        $username = trim((string)($person['username'] ?? ''));

        $profile = $this->fetchPersonProfile($session, $userId);
        if ($profile === null) {
            $shell->showAlert(
                $conn,
                $state,
                $username,
                $t('ui.terminalserver.doors.person_unavailable', [], 'That person is no longer available.'),
                'error'
            );
            return;
        }

        $onProfile = function () use ($conn, &$state, $shell, $profile): void {
            $shell->showPublicProfileViewer($conn, $state, $profile);
        };

        $onMessage = function () use ($conn, &$state, $session, $userId, $username): bool {
            return $this->invokeDirectMessage($conn, $state, $session, $userId, $username);
        };

        $this->runPersonActionLoop($conn, $state, $shell, $username, $onProfile, $onMessage, $t);
    }

    /**
     * Person action menu loop. No I/O beyond the shell and the injected
     * $onProfile / $onMessage callbacks.
     *
     * @param resource $conn
     * @param array<string,mixed> $state
     * @param callable():void $onProfile Show the person's profile
     * @param callable():bool $onMessage Open a DM; false surfaces a "not available" alert
     * @param callable(string,array<string,mixed>,string):string $t Translator: (key, params, fallback)
     */
    private function runPersonActionLoop(
        $conn,
        array &$state,
        TerminalShellInterface $shell,
        string $username,
        callable $onProfile,
        callable $onMessage,
        callable $t
    ): void {
        $items = [
            $t('ui.terminalserver.doors.person_action_profile', [], 'View profile'),
            $t('ui.terminalserver.doors.person_action_message', [], 'Send message'),
        ];

        $selected = 0;
        while (true) {
            $result = $shell->showSelectableDialog(
                $conn,
                $state,
                $username,
                $items,
                $t('ui.terminalserver.doors.people_select_hint', [], 'Select'),
                $t('ui.terminalserver.doors.detail_action_back', [], 'Back'),
                $selected,
                null
            );

            if ($result === null || ($result['action'] ?? '') !== 'select') {
                return;
            }

            $selected = (int)($result['index'] ?? 0);
            if ($selected === 0) {
                $onProfile();
                continue;
            }

            if (!$onMessage()) {
                $shell->showAlert(
                    $conn,
                    $state,
                    $username,
                    $t('ui.terminalserver.doors.person_message_unavailable', [], 'Messaging is not available right now.'),
                    'error'
                );
            }
        }
    }

    /**
     * Enter the experience's canonical conversation room via the existing
     * telnet chat client. On any normal exit (or when the room is no longer
     * accessible) control returns to the caller, and
     * {@see runExperienceDetailLoop()} recomposes the experience detail screen.
     *
     * @param resource $conn
     * @param array<string,mixed> $state
     * @param array<string,mixed> $view Detail view model from composeExperienceDetailView()
     * @param callable(string,array<string,mixed>,string):string $t Translator: (key, params, fallback)
     */
    private function openExperienceConversation(
        $conn,
        array &$state,
        string $session,
        array $view,
        TerminalShellInterface $shell,
        callable $t
    ): void {
        $roomId = (int)($view['actions']['conversation_room_id'] ?? 0);

        if ($roomId > 0 && $this->invokeRoomConversation($conn, $state, $session, $roomId)) {
            return;
        }

        $shell->showAlert(
            $conn,
            $state,
            (string)($view['name'] ?? ''),
            $t('ui.terminalserver.doors.conversation_unavailable', [], 'This conversation is not available right now.'),
            'error'
        );
    }

    /**
     * Fetch a caller's public profile for the People flow, or null when the
     * caller is no longer active. Same endpoint Who's Online uses.
     *
     * @return array<string,mixed>|null
     */
    protected function fetchPersonProfile(string $session, int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $resp = TelnetUtils::apiRequest(
            $this->apiBase,
            'GET',
            '/api/user/public-profile/' . $userId,
            null,
            $session
        );

        $profile = $resp['data']['profile'] ?? null;

        return (($resp['status'] ?? 0) === 200 && is_array($profile)) ? $profile : null;
    }

    /**
     * Open a direct-message conversation with a caller via the existing chat
     * client. Returns false when chat is unavailable.
     *
     * @param resource $conn
     * @param array<string,mixed> $state
     */
    protected function invokeDirectMessage(
        $conn,
        array &$state,
        string $session,
        int $userId,
        string $username
    ): bool {
        return (new ChatHandler($this->server, $this->apiBase))
            ->showDirectMessage($conn, $state, $session, $userId, $username);
    }

    /**
     * Open the experience's conversation room via the existing chat client.
     * Returns false when chat is disabled or the room is not accessible.
     *
     * @param resource $conn
     * @param array<string,mixed> $state
     */
    protected function invokeRoomConversation(
        $conn,
        array &$state,
        string $session,
        int $roomId
    ): bool {
        return (new ChatHandler($this->server, $this->apiBase))
            ->showRoom($conn, $state, $session, $roomId);
    }

    /**
     * End the caller's participation through the shared authenticated API.
     *
     * @return string|null Null on success, otherwise a user-facing error.
     */
    protected function endExperienceParticipation(
        string $session,
        string $experienceId,
        ?string $csrfToken
    ): ?string {
        $response = TelnetUtils::apiRequest(
            $this->apiBase,
            'POST',
            '/api/experiences/' . rawurlencode($experienceId) . '/end',
            null,
            $session,
            3,
            $csrfToken
        );

        if (
            ($response['status'] ?? 0) >= 200
            && ($response['status'] ?? 0) < 300
            && !empty($response['data']['success'])
        ) {
            return null;
        }

        $message = trim((string)($response['data']['error'] ?? $response['error'] ?? ''));

        return $message !== '' ? $message : 'Unable to end active participation';
    }

    /**
     * Drive the experience detail screen: render, read an action, and either
     * launch (then recompose and redraw — the Crossroads return destination)
     * or fall back to the catalog. No I/O beyond the shell and the injected
     * callables, so the whole catalog -> detail -> play -> detail -> back loop
     * is directly testable.
     *
     * @param resource $conn
     * @param array<string,mixed> $state
     * @param callable():(array<string,mixed>|null) $reload Recompose the detail view model, or null when gone
     * @param callable(array<string,mixed>):void $onLaunch Launch the experience for the given view model
     * @param callable(string,array<string,mixed>,string):string $t Translator: (key, params, fallback)
     * @param ?callable(string,array<string,mixed>):void $onSocial Handle a social action ('people'|'conversation')
     * @param ?callable(array<string,mixed>):(?string) $onEnd End participation; null means success, string means failure
     */
    private function runExperienceDetailLoop(
        $conn,
        array &$state,
        TerminalShellInterface $shell,
        callable $reload,
        callable $onLaunch,
        callable $t,
        ?callable $onSocial = null,
        ?callable $onEnd = null
    ): void {
        while (true) {
            $view = $reload();

            if (!is_array($view)) {
                $shell->showAlert(
                    $conn,
                    $state,
                    $t('ui.terminalserver.doors.title', [], 'Crossroads'),
                    $t('ui.terminalserver.doors.detail_not_found', [], 'That experience is no longer available.'),
                    'error'
                );
                return;
            }

            $actions = $view['actions'];
            $launchable = !empty($actions['can_play']) || !empty($actions['can_return']);
            $canPeople = $onSocial !== null && !empty($actions['can_people']);
            $canConversation = $onSocial !== null && !empty($actions['can_conversation']);
            $canEnd = $onEnd !== null && !empty($actions['can_end']);

            $extraKeys = [];
            if ($launchable) {
                $extraKeys['g'] = 'launch';
            }
            if ($canPeople) {
                $extraKeys['w'] = 'people';
            }
            if ($canConversation) {
                $extraKeys['c'] = 'conversation';
            }
            if ($canEnd) {
                $extraKeys['e'] = 'end_participation';
            }

            $action = $shell->showScrollablePanel(
                $conn,
                $state,
                (string)$view['name'],
                $view['lines'],
                [
                    'extra_keys'      => $extraKeys,
                    'status_segments' => $view['status_segments'],
                ]
            );

            if ($action === 'launch' && $launchable) {
                $onLaunch($view);
                continue;
            }
            if ($action === 'people' && $canPeople) {
                $onSocial('people', $view);
                continue;
            }
            if ($action === 'conversation' && $canConversation) {
                $onSocial('conversation', $view);
                continue;
            }
            if ($action === 'end_participation' && $canEnd) {
                $confirmed = $shell->showConfirmDialog(
                    $conn,
                    $state,
                    $t('ui.terminalserver.doors.end_confirm_title', [], 'End participation?'),
                    $t('ui.terminalserver.doors.end_confirm_message', ['name' => (string)$view['name']], 'End your active participation in {name}?'),
                    [
                        'y' => $t('ui.terminalserver.server.confirm_yes', [], 'Confirm'),
                        'n' => $t('ui.terminalserver.server.confirm_no', [], 'Cancel'),
                    ],
                    'n'
                );

                if ($confirmed === 'y') {
                    $error = $onEnd($view);
                    if (is_string($error) && $error !== '') {
                        $shell->showAlert(
                            $conn,
                            $state,
                            (string)$view['name'],
                            $t('ui.terminalserver.doors.end_failed', ['error' => $error], 'Unable to end participation: {error}'),
                            'error'
                        );
                    }
                }
                continue;
            }

            // 'quit' (Q / B / Enter / Esc) or any other exit: back to the catalog.
            return;
        }
    }

    /**
     * Assemble the detail screen view model from the shared read models.
     *
     * Pure: name, body lines, action set, and status bar segments are all
     * derived from {@see ExperiencePresentation::build()} output plus the
     * {@see ExperienceState::getExperienceState()} snapshot — no business state
     * is decided here.
     *
     * @param array<string,mixed> $experience Normalized catalog entry
     * @param array<string,mixed> $presentation ExperiencePresentation::build() result
     * @param array<string,mixed> $experienceState ExperienceState::getExperienceState() result
     * @param array<int,array<string,mixed>> $recentActivity ExperienceActivity::recent() rows
     * @param array<string,mixed>|null $viewerPlayer Viewer's player row when participating
     * @param callable(string,array<string,mixed>,string):string $t Translator: (key, params, fallback)
     * @param bool $chatEnabled Whether the local chat feature is enabled (gates the Conversation action)
     * @return array{experience:array<string,mixed>,name:string,lines:string[],roster:array<int,array<string,mixed>>,actions:array<string,mixed>,status_segments:array<int,array{text:string,color?:string}>}
     */
    public static function composeExperienceDetailView(
        array $experience,
        array $presentation,
        array $experienceState,
        array $recentActivity,
        ?array $viewerPlayer,
        callable $t,
        bool $chatEnabled = false
    ): array {
        $actions = self::resolveDetailActions($presentation, $experienceState, $chatEnabled);

        $segments = [];
        if ($actions['can_return']) {
            $segments[] = ['text' => 'G', 'color' => TelnetUtils::ANSI_RED];
            $segments[] = ['text' => ' ' . $t('ui.terminalserver.doors.detail_action_return', [], 'Return to your session') . '  ', 'color' => TelnetUtils::ANSI_BLUE];
        } elseif ($actions['can_play']) {
            $segments[] = ['text' => 'G', 'color' => TelnetUtils::ANSI_RED];
            $segments[] = ['text' => ' ' . $t('ui.terminalserver.doors.detail_action_play', [], 'Play') . '  ', 'color' => TelnetUtils::ANSI_BLUE];
        }
        if ($actions['can_people']) {
            $segments[] = ['text' => 'W', 'color' => TelnetUtils::ANSI_RED];
            $segments[] = ['text' => ' ' . $t('ui.terminalserver.doors.detail_action_people', [], 'People') . '  ', 'color' => TelnetUtils::ANSI_BLUE];
        }
        if ($actions['can_conversation']) {
            $segments[] = ['text' => 'C', 'color' => TelnetUtils::ANSI_RED];
            $segments[] = ['text' => ' ' . $t('ui.terminalserver.doors.detail_action_conversation', [], 'Conversation') . '  ', 'color' => TelnetUtils::ANSI_BLUE];
        }
        if ($actions['can_end']) {
            $segments[] = ['text' => 'E', 'color' => TelnetUtils::ANSI_RED];
            $segments[] = ['text' => ' ' . $t('ui.terminalserver.doors.detail_action_end', [], 'End Participation') . '  ', 'color' => TelnetUtils::ANSI_BLUE];
        }
        $segments[] = ['text' => 'Q', 'color' => TelnetUtils::ANSI_RED];
        $segments[] = ['text' => ' ' . $t('ui.terminalserver.doors.detail_action_back', [], 'Back'), 'color' => TelnetUtils::ANSI_BLUE];

        $roster = is_array($experienceState['players'] ?? null)
            ? array_values(array_filter(
                $experienceState['players'],
                static fn ($p): bool => is_array($p) && (int)($p['user_id'] ?? 0) > 0
            ))
            : [];

        return [
            'experience' => $experience,
            'name' => (string)($presentation['name'] ?? ($experience['id'] ?? '')),
            'roster' => $roster,
            'lines' => self::buildExperienceDetailLines(
                $presentation,
                $experienceState,
                $recentActivity,
                $viewerPlayer,
                $t
            ),
            'actions' => $actions,
            'status_segments' => $segments,
        ];
    }

    /**
     * Compose the compact Crossroads detail body for a telnet experience screen.
     *
     * Pure formatter: every value comes from the shared read models
     * ({@see ExperiencePresentation::build()} output, the
     * {@see ExperienceState::getExperienceState()} snapshot, and
     * {@see ExperienceActivity::recent()} rows). No business state is derived
     * here — availability, capacity, and participation are read straight from
     * the normalized presentation.
     *
     * @param array<string,mixed> $presentation ExperiencePresentation::build() result
     * @param array<string,mixed> $experienceState ExperienceState::getExperienceState() result
     * @param array<int,array<string,mixed>> $recentActivity ExperienceActivity::recent() rows
     * @param array<string,mixed>|null $viewerPlayer Viewer's player row when participating
     * @param callable(string,array<string,mixed>,string):string $t Translator: (key, params, fallback)
     * @return string[]
     */
    public static function buildExperienceDetailLines(
        array $presentation,
        array $experienceState,
        array $recentActivity,
        ?array $viewerPlayer,
        callable $t
    ): array {
        $lines = [];

        $description = trim((string)($presentation['description'] ?? ''));
        if ($description === '') {
            $description = $t('ui.terminalserver.doors.detail_description_none', [], '(No description provided.)');
        }
        foreach (explode("\n", wordwrap($description, 72, "\n", true)) as $wrapped) {
            $lines[] = $wrapped;
        }
        $lines[] = '';

        $category = strtolower((string)($presentation['category'] ?? 'game'));
        $categoryLabel = match ($category) {
            'gateway' => 'Gateway',
            'game'    => 'Game',
            default   => ucfirst($category),
        };
        if (!empty($presentation['capabilities']['multiplayer'])) {
            $categoryLabel .= ' / ' . $t('ui.terminalserver.doors.detail_multiplayer', [], 'Multiplayer');
        }
        $lines[] = $t('ui.terminalserver.doors.detail_type', ['type' => $categoryLabel], 'Type: {type}');

        $statusCode = (string)($presentation['status']['code'] ?? 'available');
        $statusText = match ($statusCode) {
            'participating' => $t('ui.terminalserver.doors.detail_status_playing', [], 'You are playing this now'),
            'at_capacity'   => $t('ui.terminalserver.doors.detail_status_full', [], 'Full'),
            'planned'       => $t('ui.terminalserver.doors.detail_status_planned', [], 'Not available on this terminal'),
            'unavailable'   => $t('ui.terminalserver.doors.detail_status_unavailable', [], 'Currently unavailable'),
            default         => $t('ui.terminalserver.doors.detail_status_available', [], 'Available'),
        };
        $lines[] = $t('ui.terminalserver.doors.detail_status', ['status' => $statusText], 'Status: {status}');

        $playerCount = (int)($experienceState['player_count'] ?? 0);
        $maxSessions = $presentation['capacity']['max_sessions'] ?? null;
        if ($maxSessions !== null && (int)$maxSessions > 0) {
            $lines[] = $t(
                'ui.terminalserver.doors.detail_players_capacity',
                ['count' => $playerCount, 'max' => (int)$maxSessions],
                'Players online: {count} / {max}'
            );
        } else {
            $lines[] = $t(
                'ui.terminalserver.doors.detail_players',
                ['count' => $playerCount],
                'Players online: {count}'
            );
        }

        $credits = (int)($presentation['cost']['credits'] ?? 0);
        if ($credits > 0) {
            $lines[] = $t(
                'ui.terminalserver.doors.detail_cost',
                ['credits' => $credits],
                'Cost: {credits} credits'
            );
        }

        $players = is_array($experienceState['players'] ?? null) ? $experienceState['players'] : [];
        if ($players !== []) {
            $lines[] = '';
            $lines[] = $t('ui.terminalserver.doors.detail_roster_heading', [], 'Who is here:');
            $viewerUserId = (int)($viewerPlayer['user_id'] ?? 0);
            $shown = 0;
            foreach ($players as $player) {
                if (!is_array($player)) {
                    continue;
                }
                if ($shown >= 8) {
                    $lines[] = $t(
                        'ui.terminalserver.doors.detail_roster_more',
                        ['count' => count($players) - $shown],
                        '  ... and {count} more'
                    );
                    break;
                }
                $username = trim((string)($player['username'] ?? ''));
                if ($username === '') {
                    continue;
                }
                $node = $player['node'] ?? null;
                $entryLine = $node !== null
                    ? $t(
                        'ui.terminalserver.doors.detail_roster_node',
                        ['username' => $username, 'node' => (int)$node],
                        '  {username} (node {node})'
                    )
                    : $t(
                        'ui.terminalserver.doors.detail_roster_entry',
                        ['username' => $username],
                        '  {username}'
                    );
                if ($viewerUserId > 0 && (int)($player['user_id'] ?? 0) === $viewerUserId) {
                    $entryLine .= ' ' . $t('ui.terminalserver.doors.detail_roster_you', [], '(you)');
                }
                $lines[] = $entryLine;
                $shown++;
            }
        }

        $lines[] = '';
        $lines[] = $t('ui.terminalserver.doors.detail_activity_heading', [], 'Recent activity:');
        $activityShown = 0;
        foreach ($recentActivity as $event) {
            if (!is_array($event)) {
                continue;
            }
            $username = trim((string)($event['username'] ?? ''));
            if ($username === '') {
                continue;
            }
            $lines[] = ((string)($event['type'] ?? 'play')) === 'first_play'
                ? $t(
                    'ui.terminalserver.doors.detail_activity_first',
                    ['username' => $username],
                    '  {username} played for the first time'
                )
                : $t(
                    'ui.terminalserver.doors.detail_activity_play',
                    ['username' => $username],
                    '  {username} played'
                );
            $activityShown++;
            if ($activityShown >= 5) {
                break;
            }
        }
        if ($activityShown === 0) {
            $lines[] = $t('ui.terminalserver.doors.detail_activity_none', [], '  Nothing yet - be the first.');
        }

        return $lines;
    }

    /**
     * Resolve the detail screen's action set from the shared read models.
     *
     * Play and Return are mutually exclusive in the normalized contract
     * ({@see ExperiencePresentation::build()} derives them from viewer
     * participation + static launchability); Return wins if both are ever set.
     *
     * Social actions are additive and read only from data that already exists:
     *  - People ('w') is offered when the {@see ExperienceState} roster is
     *    non-empty.
     *  - Conversation ('c') is offered when the normalized catalog entry carries
     *    a canonical `capabilities.conversation.room_id` and local chat is
     *    enabled (the flag is passed in so this stays a pure resolver).
     *
     * Keys avoid every character reserved by showScrollablePanel in either
     * shell (u/d/p/n/q/b, arrows, page/home/end), so 'g'/'w'/'c'/'e' work in
     * both TUI and line shells.
     *
     * @param array<string,mixed> $presentation ExperiencePresentation::build() result
     * @param array<string,mixed> $experienceState ExperienceState::getExperienceState() result
     * @param bool $chatEnabled Whether the local chat feature is enabled
     * @return array{can_play:bool,can_return:bool,can_end:bool,can_people:bool,can_conversation:bool,conversation_room_id:int,keys:string[],primary:string}
     */
    public static function resolveDetailActions(
        array $presentation,
        array $experienceState = [],
        bool $chatEnabled = false
    ): array {
        $canReturn = !empty($presentation['actions']['return']);
        $canPlay   = !$canReturn && !empty($presentation['actions']['play']);
        $canEnd    = !empty($presentation['actions']['end_participation']);

        $players = is_array($experienceState['players'] ?? null) ? $experienceState['players'] : [];
        $canPeople = $players !== [];

        $roomId = (int)(
            $experienceState['experience']['capabilities']['conversation']['room_id']
            ?? $presentation['conversation']['room_id']
            ?? 0
        );
        $canConversation = $chatEnabled && $roomId > 0;

        // Play and Return are mutually exclusive and share one launch key ('g').
        $keys = [];
        if ($canReturn || $canPlay) {
            $keys[] = 'g';
        }
        if ($canPeople) {
            $keys[] = 'w';
        }
        if ($canConversation) {
            $keys[] = 'c';
        }
        if ($canEnd) {
            $keys[] = 'e';
        }
        $keys[] = 'q';

        return [
            'can_play'             => $canPlay,
            'can_return'           => $canReturn,
            'can_end'              => $canEnd,
            'can_people'           => $canPeople,
            'can_conversation'     => $canConversation,
            'conversation_room_id' => $roomId,
            'keys'                 => $keys,
            'primary'              => ($canReturn || $canPlay) ? 'g' : 'q',
        ];
    }

    /**
     * Build a terminal chooser item from the normalized Experience contract.
     *
     * Genre is intentionally omitted until GameCatalog defines a normalized
     * genre or tags field.
     *
     * @param string $experienceId Canonical Experience identifier
     * @param array<string, mixed> $experience Normalized catalog entry
     * @param callable(string,array<string,mixed>,string):string|null $t
     * @return array{label: string, detail: string}
     */
    public static function buildExperienceListItem(
        string $experienceId,
        array $experience,
        ?callable $t = null,
        bool $startsCatalog = false
    ): array {
        $t ??= static fn(string $key, array $params = [], string $fallback = ''): string => $fallback;
        $presentation = ExperiencePresentation::build(
            ['id' => $experienceId] + $experience,
            'telnet'
        );
        $name = $presentation['name'];
        $category = strtolower($presentation['category']);

        $categoryLabel = match ($category) {
            'gateway' => $t('ui.terminalserver.doors.catalog_gateway', [], 'Gateway'),
            'game' => $t('ui.terminalserver.doors.catalog_game', [], 'Game'),
            default => ucfirst($category),
        };

        $metadata = [$categoryLabel];
        if ($category === 'game' && $presentation['capabilities']['multiplayer']) {
            $metadata = [$t('ui.terminalserver.doors.catalog_multiplayer', [], 'Multiplayer')];
        }

        $label = $name;
        if ($startsCatalog) {
            $label = $t('ui.terminalserver.doors.catalog_section', [], 'Experiences') . ' - ' . $label;
        }
        $label .= ' - ' . implode(' / ', $metadata);

        return [
            'label' => $label,
            'detail' => '',
        ];
    }

    /**
     * Resolve the normalized terminal relay mode with a legacy-safe fallback.
     *
     * @param array<string, mixed> $experience Normalized catalog entry
     */
    public static function resolveTerminalMode(array $experience): string
    {
        return ($experience['terminal']['mode'] ?? null) === 'raw'
            ? 'raw'
            : 'doorway';
    }

    /**
     * Launch a door game: call the API to create a session, then relay data
     * bidirectionally between the telnet client and the dosbox-bridge WebSocket server.
     *
     * @param resource $conn
     * @param array $state
     * @param string $session Auth session cookie value
     * @param string $doorId Door identifier (e.g. "lord")
     * @param string $doorName Human-readable door name for display
     * @param string $terminalMode Terminal input mode: doorway (legacy) or raw
     * @param string $backendType Catalog backend type ('native', 'rlogin', 'dos', ...),
     *                            used only to scope raw-mode cursor-key normalization
     */
    private function launchDoor(
        $conn,
        array &$state,
        string $session,
        string $doorId,
        string $doorName,
        string $terminalMode = 'doorway',
        string $backendType = ''
    ): void
    {
        TelnetUtils::safeWrite($conn, "\033[2J\033[H");
        TelnetUtils::writeLine($conn, TelnetUtils::colorize($this->server->t('ui.terminalserver.doors.launching', 'Launching {name}...', ['name' => $doorName], $state['locale']), TelnetUtils::ANSI_CYAN));
        TelnetUtils::writeLine($conn, '');

        $apiResult = $this->callDoorLaunchApi($session, $doorId, $state['csrf_token'] ?? null);

        if (empty($apiResult['success'])) {
            $msg = $apiResult['message'] ?? $apiResult['error'] ?? 'Failed to start door session';
            TelnetUtils::writeLine($conn, TelnetUtils::colorize($this->server->t('ui.terminalserver.doors.launch_error', 'Error: {error}', ['error' => $msg], $state['locale']), TelnetUtils::ANSI_RED));
            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, TelnetUtils::colorize($this->server->t('ui.terminalserver.server.press_any_key', 'Press any key to return...', [], $state['locale']), TelnetUtils::ANSI_YELLOW));
            $this->server->readKeyWithIdleCheck($conn, $state);
            return;
        }

        $doorSession = $apiResult['session'];
        $wsToken = $doorSession['ws_token'];
        $sessionId = $doorSession['session_id'];

        // Connect directly to the bridge over loopback using .env settings.
        // This bypasses any public-facing SSL proxy (DOSDOOR_WS_URL is for browsers).
        $wsHost = Config::env('DOSDOOR_WS_BIND_HOST', '127.0.0.1');
        $wsPort = (int) Config::env('DOSDOOR_WS_PORT', '6001');

        TelnetUtils::writeLine($conn, TelnetUtils::colorize($this->server->t('ui.terminalserver.doors.connecting', 'Connecting to game server...', [], $state['locale']), TelnetUtils::ANSI_DIM));

        $wsSock = $this->wsConnect($wsHost, $wsPort, $wsToken);
        if ($wsSock === null) {
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.doors.connect_failed', 'Could not connect to game bridge. Is the DOS door bridge running?', [], $state['locale']),
                TelnetUtils::ANSI_RED
            ));
            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, TelnetUtils::colorize($this->server->t('ui.terminalserver.server.press_any_key', 'Press any key to return...', [], $state['locale']), TelnetUtils::ANSI_YELLOW));
            $this->server->readKeyWithIdleCheck($conn, $state);
            return;
        }

        TelnetUtils::writeLine($conn, TelnetUtils::colorize($this->server->t('ui.terminalserver.doors.connected', 'Connected! Starting game...', [], $state['locale']), TelnetUtils::ANSI_GREEN));
        sleep(1);

        // Suppress daemon-layer echo — the door game drives all display output
        $this->server->safeWrite($conn, chr(255) . chr(251) . chr(1)); // IAC WILL ECHO
        $this->server->safeWrite($conn, chr(255) . chr(254) . chr(1)); // IAC DONT ECHO

        // Modern/raw experiences should inherit the caller's actual terminal
        // geometry rather than the native adapter's configured/default PTY size.
        if ($terminalMode === 'raw') {
            $this->sendTerminalResize($wsSock, $state);
        }

        $this->relayLoop($conn, $state, $wsSock, $terminalMode, $backendType);

        // Notify the API before closing the WebSocket so the active door
        // session still exists when /api/door/end performs authorization
        // and lifecycle cleanup. The bridge remains a safety-net cleanup
        // path when the WebSocket closes unexpectedly.
        $this->callDoorEndApi($session, $sessionId, $state['csrf_token'] ?? null);

        // Send WebSocket close frame and release the socket
        $this->wsSendClose($wsSock);
        @fclose($wsSock);

        // Restore echo state
        $this->server->safeWrite($conn, chr(255) . chr(251) . chr(1)); // IAC WILL ECHO
        $this->server->safeWrite($conn, chr(255) . chr(254) . chr(1)); // IAC DONT ECHO

        // Door sessions can leave the terminal in an application/private mode
        // (alternate screen, hidden cursor, keypad mode, half-finished DCS)
        // that breaks the next BBS screen. Restore a normal text-terminal state
        // before we redraw and wait for more input.
        $this->resetTerminalAfterDoor($conn);

        // Door sessions can also leave buffered keystrokes or trailing TELNET
        // chatter queued on the client socket, or straggling replies to a
        // request the door itself made right before exiting (see the docblock
        // on drainPendingInput()). Wait up to 250ms of quiet, capped at 1.5s
        // total, so those stragglers are absorbed instead of confusing the
        // next termserver screen.
        $this->drainPendingInput($conn, $state, 250000, 1500000);

        TelnetUtils::safeWrite($conn, "\033[2J\033[H");
        TelnetUtils::writeLine($conn, '');
        TelnetUtils::writeLine($conn, TelnetUtils::colorize($this->server->t('ui.terminalserver.doors.returned', 'Returned from {name}.', ['name' => $doorName], $state['locale']), TelnetUtils::ANSI_CYAN));
        TelnetUtils::writeLine($conn, TelnetUtils::colorize($this->server->t('ui.terminalserver.server.press_continue', 'Press any key to continue...', [], $state['locale']), TelnetUtils::ANSI_YELLOW));
        $this->server->readKeyWithIdleCheck($conn, $state);
        $this->drainPendingInput($conn, $state);
    }

    /**
     * Bidirectional relay loop between the telnet client and the dosbox-bridge WebSocket.
     *
     * Uses stream_select on both streams simultaneously so neither side blocks the other.
     * Telnet client data: IAC-stripped → ANSI→Doorway key conversion → CP437→UTF-8 → WebSocket
     * Bridge data:        WebSocket frame → UTF-8→CP437 → telnet client
     *
     * @param resource $conn Telnet client socket
     * @param array $state Terminal state (updated by NAWS sequences)
     * @param resource $wsSock WebSocket TCP socket
     * @param string $terminalMode Terminal input mode: doorway (legacy) or raw.
     *                             RLogin experiences (and any door whose manifest
     *                             sets terminal_mode=raw) resolve to raw so ANSI
     *                             cursor keys are preserved rather than rewritten
     *                             to Doorway protocol scan codes.
     * @param string $backendType Catalog backend type; only 'native' enables the
     *                             raw-mode cursor-key normalization in
     *                             processRawTelnetInput().
     */
    private function relayLoop(
        $conn,
        array &$state,
        $wsSock,
        string $terminalMode = 'doorway',
        string $backendType = ''
    ): void
    {
        $connMeta = stream_get_meta_data($conn);
        $wsMeta = stream_get_meta_data($wsSock);
        $connWasBlocking = (bool)($connMeta['blocked'] ?? true);
        $wsWasBlocking = (bool)($wsMeta['blocked'] ?? true);

        stream_set_blocking($conn, false);
        stream_set_blocking($wsSock, false);

        $wsBuf = '';

        try {
            while (true) {
                if (!is_resource($conn) || feof($conn)) {
                    break;
                }
                if (!is_resource($wsSock) || feof($wsSock)) {
                    break;
                }

                $read = [$conn, $wsSock];
                $w = $e = null;

                if (@stream_select($read, $w, $e, 0, 50000) === false) {
                    break;
                }

                foreach ($read as $ready) {
                    if ($ready === $conn) {
                        // --- Telnet client → bridge ---
                        $raw = @fread($conn, 4096);
                        if ($raw === false || ($raw === '' && feof($conn))) {
                            return;
                        }
                        if ($raw === '') {
                            continue;
                        }

                        if ($terminalMode === 'raw') {
                            // Modern terminal mode: remove Telnet protocol framing and
                            // capture NAWS, but preserve ANSI/xterm and UTF-8 payload.
                            $oldCols = (int)($state['cols'] ?? 0);
                            $oldRows = (int)($state['rows'] ?? 0);

                            $processed = $this->processRawTelnetInput($raw, $state, $backendType);

                            $newCols = (int)($state['cols'] ?? 0);
                            $newRows = (int)($state['rows'] ?? 0);

                            if ($newCols > 0 && $newRows > 0
                                && ($newCols !== $oldCols || $newRows !== $oldRows)) {
                                $this->sendTerminalResize($wsSock, $state);
                            }

                            if ($processed !== '') {
                                $this->wsSend($wsSock, $processed);
                            }
                        } else {
                            // Legacy door mode: convert terminal keys to Doorway scan
                            // codes and translate the CP437 client stream to UTF-8.
                            $processed = $this->processTelnetInput($raw, $state);
                            if ($processed === '') {
                                continue;
                            }

                            $utf8 = function_exists('iconv')
                                ? iconv('CP437', 'UTF-8//IGNORE', $processed)
                                : $processed;

                            if ($utf8 !== '' && $utf8 !== false) {
                                $this->wsSend($wsSock, $utf8);
                            }
                        }
                    } else {
                        // --- Bridge → telnet client ---
                        $chunk = @fread($wsSock, 4096);
                        if ($chunk === false || ($chunk === '' && feof($wsSock))) {
                            return;
                        }
                        if ($chunk === '') {
                            continue;
                        }

                        $wsBuf .= $chunk;

                        // Consume all complete WebSocket frames from the buffer
                        while (true) {
                            $result = $this->wsParseFrame($wsBuf);
                            if ($result['type'] === 'incomplete') {
                                break;
                            }
                            $wsBuf = $result['remaining'];

                            if ($result['type'] === 'close') {
                                return;
                            }
                            if ($result['type'] === 'ping') {
                                $this->wsSendPong($wsSock, $result['payload']);
                                continue;
                            }
                            if ($result['type'] === 'pong' || $result['payload'] === '') {
                                continue;
                            }

                            if ($terminalMode === 'raw') {
                                // Raw experiences speak modern UTF-8/ANSI, but the
                                // connected BBS terminal may still be CP437 or ASCII.
                                // Preserve control/ANSI bytes while transcoding printable
                                // UTF-8 characters for the caller's configured charset.
                                $output = $this->encodeRawTerminalOutput($result['payload']);

                                if ($output !== '') {
                                    $this->server->safeWrite($conn, $output);
                                }
                            } else {
                                // Legacy door output is presented to the Telnet
                                // client using the traditional CP437 path.
                                $cp437 = function_exists('iconv')
                                    ? iconv('UTF-8', 'CP437//IGNORE', $result['payload'])
                                    : $result['payload'];

                                if ($cp437 !== '' && $cp437 !== false) {
                                    $this->server->safeWrite($conn, $cp437);
                                }
                            }
                        }
                    }
                }
            }
        } finally {
            if (is_resource($conn)) {
                stream_set_blocking($conn, $connWasBlocking);
            }
            if (is_resource($wsSock)) {
                stream_set_blocking($wsSock, $wsWasBlocking);
            }
        }
    }

    /**
     * Strip Telnet protocol framing while preserving modern terminal payload.
     *
     * Unlike processTelnetInput(), this does not translate ANSI escape
     * sequences to Doorway scan codes, remap DEL, or perform character-set
     * conversion. NAWS is still consumed so terminal dimensions remain known.
     *
     * One narrow compatibility rewrite is applied for native doors only
     * (`$backendType === 'native'`, telnet transport): the four exact cursor
     * sequences ESC[A/B/C/D are rewritten to their SS3 form ESC O A/B/C/D.
     * A native door's PTY runs under TERM=xterm-256color, whose ncurses
     * terminfo defines the cursor keys solely in application form
     * (kcuf1=\EOC, ...) and asks the terminal to switch via smkx (DECCKM).
     * Classic BBS telnet clients that cannot honour DECCKM keep sending the
     * normal-mode CSI form, which the door's ncurses then never matches.
     * RLogin/SSH sessions manage their own cursor mode and are left alone,
     * as is every non-navigation CSI sequence.
     *
     * @param string $data Raw bytes from the Telnet client
     * @param array $state Terminal state (cols/rows updated if NAWS seen)
     * @param string $backendType Catalog backend type; 'native' enables the
     *                            cursor-key normalization described above
     * @return string Raw terminal payload ready for the bridge
     */
    private function processRawTelnetInput(string $data, array &$state, string $backendType = ''): string
    {
        // Only native doors run the local xterm-256color PTY that expects SS3
        // cursor keys; SSH clients negotiate DECCKM themselves.
        $normalizeCursorKeys = ($backendType === 'native') && empty($state['isSsh']);

        $out = '';
        $len = strlen($data);
        $i = 0;

        while ($i < $len) {
            $byte = ord($data[$i]);

            // Telnet IAC (0xFF) command sequence.
            if ($byte === 255) {
                $i++;
                if ($i >= $len) {
                    break;
                }

                $cmd = ord($data[$i++]);

                // Escaped IAC means a literal 0xFF data byte.
                if ($cmd === 255) {
                    $out .= chr(255);
                    continue;
                }

                // WILL/WONT/DO/DONT consume their option byte.
                if ($cmd >= 251 && $cmd <= 254) {
                    if ($i < $len) {
                        $i++;
                    }
                    continue;
                }

                // Subnegotiation: consume through IAC SE.
                if ($cmd === 250) {
                    $opt = ($i < $len) ? ord($data[$i++]) : null;
                    $sbData = '';

                    while ($i < $len) {
                        $b = ord($data[$i++]);

                        if ($b === 255 && $i < $len && ord($data[$i]) === 240) {
                            $i++;
                            break;
                        }

                        $sbData .= chr($b);
                    }

                    // NAWS (option 31): retain terminal dimensions.
                    if ($opt === 31 && strlen($sbData) >= 4) {
                        $w = (ord($sbData[0]) << 8) + ord($sbData[1]);
                        $h = (ord($sbData[2]) << 8) + ord($sbData[3]);

                        if ($w > 0) {
                            $state['cols'] = $w;
                        }
                        if ($h > 0) {
                            $state['rows'] = $h;
                        }
                    }

                    continue;
                }

                // Other Telnet commands contain no application payload.
                continue;
            }

            // Decode Telnet NVT Enter framing while preserving a normal CR
            // for the downstream terminal application.
            if ($byte === 13) {
                $i++;
                $out .= chr(13);

                if ($i < $len && (ord($data[$i]) === 10 || ord($data[$i]) === 0)) {
                    $i++;
                }

                continue;
            }

            // Native-door cursor-key compatibility: rewrite the four exact
            // normal-mode CSI cursor sequences to SS3 so an xterm-256color
            // ncurses door recognizes them from clients that do not honour
            // DECCKM. Any other ESC[... sequence (and a truncated ESC[ at the
            // end of a read) is passed through byte-for-byte below.
            if (
                $normalizeCursorKeys
                && $byte === 27
                && ($i + 2) < $len
                && $data[$i + 1] === '['
                && (
                    $data[$i + 2] === 'A' || $data[$i + 2] === 'B'
                    || $data[$i + 2] === 'C' || $data[$i + 2] === 'D'
                )
            ) {
                $out .= "\x1bO" . $data[$i + 2];
                $i += 3;
                continue;
            }

            // Everything else is modern terminal payload and passes unchanged.
            $out .= $data[$i++];
        }

        return $out;
    }

    /**
     * Strip telnet IAC command sequences from raw input and convert ANSI terminal
     * escape sequences (ESC[...) to Doorway protocol scan codes (0x00 + scan_code).
     *
     * Doorway protocol is the standard used by DOS BBS door games (and Native
     * doors, which are also legacy drop-file executables) for extended keys.
     * RLogin doors are a real outbound terminal session to a remote system
     * (e.g. Synchronet) that does its own ANSI cursor-key handling and has no
     * notion of Doorway protocol, so cursor/extended keys must pass through
     * unmodified for that door type — rewriting them silently breaks arrow
     * keys in remote door games (e.g. Synchronet's Minesweeper).
     *
     * NAWS subnegotiations are parsed to keep the terminal size in sync.
     *
     * @param string $data Raw bytes from the telnet client
     * @param array $state Terminal state (cols/rows updated if NAWS seen)
     * @param string $doorType Door type ('dos', 'native', or 'rlogin')
     * @return string Processed bytes ready to send to the bridge
     */
    private function processTelnetInput(string $data, array &$state, string $doorType = 'dos'): string
    {
        $out = '';
        $len = strlen($data);
        $i = 0;

        while ($i < $len) {
            $byte = ord($data[$i]);

            // Telnet IAC (0xFF) command sequence
            if ($byte === 255) {
                $i++;
                if ($i >= $len) {
                    break;
                }
                $cmd = ord($data[$i++]);

                // Escaped IAC — literal 0xFF in data stream
                if ($cmd === 255) {
                    $out .= chr(255);
                    continue;
                }

                // WILL/WONT/DO/DONT (251-254) — consume one option byte
                if ($cmd >= 251 && $cmd <= 254) {
                    $i++;
                    continue;
                }

                // SB (250) — subnegotiation, consume until IAC SE (255 240)
                if ($cmd === 250) {
                    $opt = ($i < $len) ? ord($data[$i++]) : null;
                    $sbData = '';
                    while ($i < $len) {
                        $b = ord($data[$i++]);
                        if ($b === 255 && $i < $len && ord($data[$i]) === 240) {
                            $i++; // consume SE
                            break;
                        }
                        $sbData .= chr($b);
                    }
                    // NAWS (option 31) — update terminal dimensions
                    if ($opt === 31 && strlen($sbData) >= 4) {
                        $w = (ord($sbData[0]) << 8) + ord($sbData[1]);
                        $h = (ord($sbData[2]) << 8) + ord($sbData[3]);
                        if ($w > 0) {
                            $state['cols'] = $w;
                        }
                        if ($h > 0) {
                            $state['rows'] = $h;
                        }
                    }
                    continue;
                }

                continue; // Unrecognised IAC command — skip
            }

            // ANSI escape sequence — convert to Doorway protocol if possible.
            // RLogin doors are a real remote terminal session, not a Doorway-
            // protocol drop-file program, so pass ANSI sequences through as-is.
            if ($doorType !== 'rlogin' && $byte === 27 && ($i + 1) < $len && $data[$i + 1] === '[') {
                $i += 2; // skip ESC[
                $params = '';
                while ($i < $len && !ctype_alpha($data[$i])) {
                    $params .= $data[$i++];
                }
                $final = ($i < $len) ? $data[$i++] : '';

                $scanCode = $this->ansiToScanCode($params, $final);
                if ($scanCode !== null) {
                    $out .= "\x00" . chr($scanCode); // Doorway protocol extended key
                } else {
                    $out .= chr(27) . '[' . $params . $final; // pass through unknown sequence
                }
                continue;
            }

            // CR — strip the trailing LF or NUL that telnet clients append.
            // Telnet sends \r\n or \r\0 for Enter; door games expect bare \r.
            if ($byte === 13) {
                $i++; // consume the CR itself
                $out .= chr(13);
                if ($i < $len && (ord($data[$i]) === 10 || ord($data[$i]) === 0)) {
                    $i++; // consume the trailing LF or NUL
                }
                continue;
            }

            // NUL — strip bare null bytes (telnet CR+NUL padding, not our generated Doorway codes)
            if ($byte === 0) {
                $i++; // consume the NUL
                continue;
            }

            // DEL (0x7F) — modern terminals send DEL for Backspace; DOS doors expect Ctrl-H (0x08)
            if ($byte === 127) {
                $i++;
                $out .= chr(8);
                continue;
            }

            $out .= $data[$i++];
        }

        return $out;
    }

    /**
     * Discard any telnet-side input that is already queued, or arrives shortly
     * after, without blocking the session indefinitely.
     *
     * After a DOS door exits, some clients leave trailing keypresses or protocol
     * bytes readable on the socket. If we do not clear them here, the next BBS
     * menu or submenu can immediately consume them and appear to auto-exit.
     *
     * Some doors also have their own in-flight request/response chatter with the
     * client that is still outstanding at exit — e.g. SyncDOOM asks the terminal
     * to confirm each rendered frame and paces itself on that round-trip, so a
     * player quitting on a laggy link can leave several confirmations still in
     * transit. Those replies land on the socket a beat after the door is gone
     * and, undrained, get misread as garbage keystrokes by the next screen,
     * which is what makes the following menu look unresponsive. $idleMicros
     * keeps this call open (bounded by $maxTotalMicros) for a short quiet
     * window after the door exits so those stragglers get absorbed too; leave
     * both at 0 for the original instantaneous, single-pass drain.
     *
     * @param resource $conn
     */
    private function drainPendingInput($conn, array &$state, int $idleMicros = 0, int $maxTotalMicros = 0): void
    {
        if (!is_resource($conn)) {
            return;
        }

        $meta = stream_get_meta_data($conn);
        $previousBlocking = (bool)($meta['blocked'] ?? true);
        stream_set_blocking($conn, false);

        $deadline = $maxTotalMicros > 0 ? microtime(true) + ($maxTotalMicros / 1_000_000) : null;
        $timeoutSec = intdiv($idleMicros, 1_000_000);
        $timeoutUsec = $idleMicros % 1_000_000;

        try {
            while (true) {
                $read = [$conn];
                $write = $except = null;
                $ready = @stream_select($read, $write, $except, $timeoutSec, $timeoutUsec);
                if ($ready === false || $ready === 0) {
                    break;
                }

                $raw = @fread($conn, 4096);
                if ($raw === false || $raw === '') {
                    if (feof($conn)) {
                        break;
                    }
                    continue;
                }

                // Reuse the door input parser so NAWS updates are still applied
                // while all buffered keystrokes are discarded.
                $this->processTelnetInput($raw, $state);

                if ($deadline !== null && microtime(true) >= $deadline) {
                    break;
                }
            }
        } finally {
            stream_set_blocking($conn, $previousBlocking);
        }
    }

    /**
     * Restore a conservative "normal terminal" state after a DOS door exits.
     *
     * Some door clients leave the terminal in alternate-screen, application
     * keypad/cursor, hidden-cursor, or unfinished DCS/sixel modes. Avoid a full
     * RIS hard reset here; it is more disruptive than necessary. A soft reset plus
     * explicit normal-mode toggles is enough to make the next BBS screen usable.
     *
     * @param resource $conn
     */
    private function resetTerminalAfterDoor($conn): void
    {
        if (!is_resource($conn)) {
            return;
        }

        // Disable SyncTERM/CTerm physical key event reporting AND restore normal
        // translated key input, first, before anything else. Per the CTerm manual:
        // CSI=1h enables physical key press/release reports (keystrokes arrive as
        // ESC[=<evdev-code>K / ...k instead of normal characters); CSI=2h
        // separately suppresses normal translated key input, which the manual
        // notes can be left enabled *alongside* CSI=1h. A door that needs real
        // key-up events for movement (e.g. SyncDOOM) can enable both together.
        // Disabling only CSI=1 (as an earlier version of this fix did) leaves
        // translated input suppressed, so no keystrokes reach the server at all
        // afterward -- not even the discarded chatter that let earlier read loops
        // register *something* was pressed. Both must be turned off. Harmless
        // no-op on terminals that don't implement this CTerm extension.
        TelnetUtils::safeWrite($conn, "\033[=1l\033[=2l");

        // End any still-open synchronized-output batch (DECSET 2026) next. Frame-based doors (e.g. SyncDOOM) commonly wrap each
        // rendered frame in a begin/end pair so the terminal paints it atomically;
        // if the door exits between the begin and the matching end, a terminal
        // that supports this mode holds every subsequent write in an unflushed
        // buffer forever, making the client look completely frozen. Unsupported
        // on a given terminal, this is a harmless no-op private-mode reset.
        TelnetUtils::safeWrite($conn, "\033[?2026l");

        // End any stray DCS/sixel payload still being parsed.
        TelnetUtils::safeWrite($conn, "\033\\");

        // Leave alternate screen buffers before we clear/redraw the normal BBS UI.
        TelnetUtils::safeWrite($conn, "\033[?1049l\033[?1048l\033[?1047l");

        // Turn off xterm-mouse reporting and bracketed paste. Some doors (e.g.
        // SyncDOOM's steer/follow mouse-look) enable these for gameplay; if a
        // client like SyncTERM is left with mouse tracking on, subsequent
        // clicks/movement are swallowed as mouse escape sequences instead of
        // reaching the next BBS menu.
        TelnetUtils::safeWrite($conn, "\033[?1000l\033[?1002l\033[?1003l\033[?1005l\033[?1006l\033[?1015l\033[?2004l");

        // DECSTR soft reset plus a few explicit "normal mode" toggles that doors
        // commonly disturb.
        TelnetUtils::safeWrite($conn, "\033[!p");
        TelnetUtils::safeWrite($conn, "\033[0m\017\033(B\033[r\033>\033[?1l\033[?7h");
        TelnetUtils::setCursorVisible($conn, true);
    }

    /**
     * Map an ANSI CSI escape sequence to an IBM PC keyboard scan code.
     *
     * @param string $params Parameter string between ESC[ and the final byte
     * @param string $final  Final byte of the escape sequence (letter or ~)
     * @return int|null PC scan code, or null if the sequence is not recognised
     */
    private function ansiToScanCode(string $params, string $final): ?int
    {
        // Cursor keys: ESC[A / ESC[B / ESC[C / ESC[D and ESC[H / ESC[F
        if ($params === '') {
            return match($final) {
                'A' => 0x48, // Up
                'B' => 0x50, // Down
                'C' => 0x4D, // Right
                'D' => 0x4B, // Left
                'H' => 0x47, // Home
                'F' => 0x4F, // End
                default => null,
            };
        }

        // Extended keys via ESC[{n}~ (xterm / VT220 format)
        if ($final === '~') {
            return match($params) {
                '1', '7' => 0x47, // Home
                '2'      => 0x52, // Insert
                '3'      => 0x53, // Delete
                '4', '8' => 0x4F, // End
                '5'      => 0x49, // Page Up
                '6'      => 0x51, // Page Down
                '11'     => 0x3B, // F1
                '12'     => 0x3C, // F2
                '13'     => 0x3D, // F3
                '14'     => 0x3E, // F4
                '15'     => 0x3F, // F5
                '17'     => 0x40, // F6
                '18'     => 0x41, // F7
                '19'     => 0x42, // F8
                '20'     => 0x43, // F9
                '21'     => 0x44, // F10
                '23'     => 0x85, // F11
                '24'     => 0x86, // F12
                default  => null,
            };
        }

        return null;
    }

    // ===== WebSocket CLIENT =====

    /**
     * Open a TCP connection and perform the WebSocket HTTP upgrade handshake.
     *
     * @param string $host Bridge bind host (from DOSDOOR_WS_BIND_HOST)
     * @param int    $port Bridge port (from DOSDOOR_WS_PORT)
     * @param string $token Session auth token to pass as a query parameter
     * @return resource|null Connected socket, or null on failure
     */
    private function wsConnect(string $host, int $port, string $token): mixed
    {
        $sock = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 5);
        if (!$sock) {
            return null;
        }

        stream_set_blocking($sock, true);
        stream_set_timeout($sock, 5);

        $key = base64_encode(random_bytes(16));

        $handshake = "GET /?token=" . urlencode($token) . " HTTP/1.1\r\n"
            . "Host: {$host}:{$port}\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "\r\n";

        fwrite($sock, $handshake);

        // Read until the end of HTTP headers
        $response = '';
        $deadline = time() + 5;
        while (!str_contains($response, "\r\n\r\n")) {
            if (time() > $deadline || feof($sock)) {
                fclose($sock);
                return null;
            }
            $chunk = fread($sock, 1024);
            if ($chunk === false || $chunk === '') {
                fclose($sock);
                return null;
            }
            $response .= $chunk;
        }

        // Must receive 101 Switching Protocols
        if (!str_contains($response, '101')) {
            fclose($sock);
            return null;
        }

        return $sock;
    }

    /**
     * Send a WebSocket text frame to the server (client frames must be masked).
     *
     * @param resource $sock
     * @param string   $payload UTF-8 text payload
     */
    /**
     * Forward terminal dimensions to the bridge for PTY-backed raw experiences.
     */
    /**
     * Encode a modern UTF-8/ANSI terminal stream for the connected BBS client.
     *
     * ANSI/control sequences are preserved byte-for-byte. Printable UTF-8 text
     * spans are passed through BbsSession::encodeForTerminal(), allowing the
     * session's utf8/cp437/ascii setting to remain authoritative.
     */
    private function encodeRawTerminalOutput(string $data): string
    {
        if ($data === '') {
            return '';
        }

        $charset = method_exists($this->server, 'getTerminalCharset')
            ? $this->server->getTerminalCharset()
            : 'utf8';

        if ($charset === 'utf8') {
            return $data;
        }

        $encodeText = function (string $text): string {
            if ($text === '') {
                return '';
            }

            if (method_exists($this->server, 'encodeForTerminal')) {
                return $this->server->encodeForTerminal($text);
            }

            return $text;
        };

        $out = '';
        $text = '';
        $len = strlen($data);
        $i = 0;

        $flushText = function () use (&$text, &$out, $encodeText): void {
            if ($text !== '') {
                $out .= $encodeText($text);
                $text = '';
            }
        };

        while ($i < $len) {
            $byte = ord($data[$i]);

            // ESC introduces ANSI/VT control sequences. Preserve them exactly.
            if ($byte === 0x1B) {
                $flushText();

                $start = $i++;
                if ($i >= $len) {
                    $out .= substr($data, $start, 1);
                    break;
                }

                $next = ord($data[$i]);

                // CSI: ESC [ ... final-byte
                if ($next === 0x5B) {
                    $i++;

                    while ($i < $len) {
                        $b = ord($data[$i++]);

                        // ANSI final byte range.
                        if ($b >= 0x40 && $b <= 0x7E) {
                            break;
                        }
                    }

                    $out .= substr($data, $start, $i - $start);
                    continue;
                }

                // OSC: ESC ] ... BEL or ST (ESC \)
                if ($next === 0x5D) {
                    $i++;

                    while ($i < $len) {
                        $b = ord($data[$i++]);

                        if ($b === 0x07) {
                            break;
                        }

                        if ($b === 0x1B && $i < $len && ord($data[$i]) === 0x5C) {
                            $i++;
                            break;
                        }
                    }

                    $out .= substr($data, $start, $i - $start);
                    continue;
                }

                // Other two-byte ESC forms.
                $i++;
                $out .= substr($data, $start, $i - $start);
                continue;
            }

            // C0 controls and DEL are transport/control bytes, not text.
            if ($byte < 0x20 || $byte === 0x7F) {
                $flushText();
                $out .= $data[$i++];
                continue;
            }

            // Printable UTF-8 byte. Accumulate until the next control sequence.
            $text .= $data[$i++];
        }

        $flushText();

        return $out;
    }

    private function sendTerminalResize($wsSock, array $state): void
    {
        $cols = max(20, min(500, (int)($state['cols'] ?? 80)));
        $rows = max(5, min(200, (int)($state['rows'] ?? 25)));

        $payload = json_encode([
            'type' => 'resize',
            'cols' => $cols,
            'rows' => $rows,
        ]);

        if ($payload !== false) {
            $this->wsSend($wsSock, $payload);
        }
    }

    private function wsSend($sock, string $payload): void
    {
        if (!is_resource($sock)) {
            return;
        }

        $len = strlen($payload);
        $mask = random_bytes(4);

        if ($len < 126) {
            $header = chr(0x81) . chr(0x80 | $len) . $mask;
        } elseif ($len < 65536) {
            $header = chr(0x81) . chr(0x80 | 126) . pack('n', $len) . $mask;
        } else {
            $header = chr(0x81) . chr(0x80 | 127) . pack('J', $len) . $mask;
        }

        $masked = '';
        for ($i = 0; $i < $len; $i++) {
            $masked .= $payload[$i] ^ $mask[$i % 4];
        }

        @fwrite($sock, $header . $masked);
    }

    /**
     * Send a WebSocket pong frame in response to a ping.
     *
     * @param resource $sock
     * @param string   $payload Echo the ping payload back
     */
    private function wsSendPong($sock, string $payload = ''): void
    {
        if (!is_resource($sock)) {
            return;
        }

        $len = min(strlen($payload), 125); // pong payload must be ≤ 125 bytes
        $mask = random_bytes(4);
        $header = chr(0x8A) . chr(0x80 | $len) . $mask;

        $masked = '';
        for ($i = 0; $i < $len; $i++) {
            $masked .= $payload[$i] ^ $mask[$i % 4];
        }

        @fwrite($sock, $header . $masked);
    }

    /**
     * Send a WebSocket connection close frame.
     *
     * @param resource $sock
     */
    private function wsSendClose($sock): void
    {
        if (!is_resource($sock)) {
            return;
        }
        $mask = random_bytes(4);
        @fwrite($sock, chr(0x88) . chr(0x80) . $mask); // close, masked, no payload
    }

    /**
     * Parse one complete WebSocket frame from a raw byte buffer.
     *
     * Returns an associative array:
     *   type      => 'data' | 'ping' | 'pong' | 'close' | 'incomplete'
     *   payload   => string (frame payload, empty for close/pong/incomplete)
     *   remaining => string (buffer bytes after this frame)
     *
     * @param string $buf Raw bytes accumulated from fread
     * @return array{type: string, payload: string, remaining: string}
     */
    private function wsParseFrame(string $buf): array
    {
        $incomplete = ['type' => 'incomplete', 'payload' => '', 'remaining' => $buf];

        if (strlen($buf) < 2) {
            return $incomplete;
        }

        $byte1  = ord($buf[0]);
        $byte2  = ord($buf[1]);
        $opcode = $byte1 & 0x0F;
        $masked = ($byte2 & 0x80) !== 0;
        $len    = $byte2 & 0x7F;
        $pos    = 2;

        if ($len === 126) {
            if (strlen($buf) < 4) {
                return $incomplete;
            }
            $len = (ord($buf[2]) << 8) + ord($buf[3]);
            $pos = 4;
        } elseif ($len === 127) {
            if (strlen($buf) < 10) {
                return $incomplete;
            }
            $len = unpack('J', substr($buf, 2, 8))[1];
            $pos = 10;
        }

        $maskLen  = $masked ? 4 : 0;
        $totalLen = $pos + $maskLen + $len;

        if (strlen($buf) < $totalLen) {
            return $incomplete;
        }

        $remaining = substr($buf, $totalLen);
        $payload   = substr($buf, $pos + $maskLen, $len);

        if ($masked) {
            $maskBytes = substr($buf, $pos, 4);
            $unmasked  = '';
            for ($i = 0; $i < strlen($payload); $i++) {
                $unmasked .= $payload[$i] ^ $maskBytes[$i % 4];
            }
            $payload = $unmasked;
        }

        $type = match($opcode) {
            0x0, 0x1, 0x2 => 'data',  // continuation, text, binary
            0x8            => 'close',
            0x9            => 'ping',
            0xA            => 'pong',
            default        => 'data',
        };

        return ['type' => $type, 'payload' => $payload, 'remaining' => $remaining];
    }

    // ===== API HELPERS =====

    /**
     * Call POST /api/door/launch with form-encoded body.
     *
     * The door launch API reads $_POST (not JSON), so we send
     * application/x-www-form-urlencoded rather than using TelnetUtils::apiRequest.
     *
     * @param string $session Auth session cookie value
     * @param string $doorId  Door identifier
     * @return array Decoded JSON response
     */
    private function callDoorLaunchApi(string $session, string $doorId, ?string $csrfToken = null): array
    {
        $headers = array_merge(['Content-Type: application/x-www-form-urlencoded'], TelnetUtils::clientContextHeaders());
        if ($csrfToken !== null) {
            $headers[] = 'X-CSRF-Token: ' . $csrfToken;
        }
        $ch = curl_init(rtrim($this->apiBase, '/') . '/api/door/launch');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'door' => $doorId,
                'surface' => 'terminal',
            ]),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_COOKIE         => 'binktermphp_session=' . $session,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_USERAGENT      => 'BinktermPHP-Telnet/1.10.2',
        ]);

        $response = curl_exec($ch);
        $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $status === 0) {
            return ['success' => false, 'error' => 'API request failed'];
        }

        return json_decode($response, true) ?? ['success' => false, 'error' => 'Invalid API response'];
    }

    /**
     * Call POST /api/door/end (best-effort — bridge also cleans up on WebSocket close).
     *
     * @param string $session   Auth session cookie value
     * @param string $sessionId Door session UUID
     */
    private function callDoorEndApi(string $session, string $sessionId, ?string $csrfToken = null): void
    {
        $headers = array_merge(['Content-Type: application/x-www-form-urlencoded'], TelnetUtils::clientContextHeaders());
        if ($csrfToken !== null) {
            $headers[] = 'X-CSRF-Token: ' . $csrfToken;
        }
        $ch = curl_init(rtrim($this->apiBase, '/') . '/api/door/end');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query(['session_id' => $sessionId]),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_COOKIE         => 'binktermphp_session=' . $session,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_USERAGENT      => 'BinktermPHP-Telnet/1.10.2',
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}
