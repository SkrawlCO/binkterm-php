<?php

namespace BinktermPHP\TelnetServer;

use BinktermPHP\Newscan\NewscanPlan;
use BinktermPHP\Newscan\NewscanSnapshot;
use BinktermPHP\Newscan\UnifiedNewscanService;
use BinktermPHP\TelnetServer\TelnetServer;
use BinktermPHP\Terminal\Navigation\NavigationTheme;
use BinktermPHP\Terminal\Navigation\NavigationThemeConfig;
use BinktermPHP\Terminal\Presentation\DenseList;
use BinktermPHP\Terminal\Presentation\DenseListColumn;
use BinktermPHP\Terminal\Presentation\DenseListRow;
use BinktermPHP\Terminal\Presentation\DenseListView;
use BinktermPHP\Terminal\Presentation\ThemedDenseListView;

/**
 * EchomailHandler - Handles echomail (forum/echo) functionality for telnet daemon
 *
 * Provides methods for displaying echoareas, listing echomail messages, and composing
 * new echomail or replies. This handler encapsulates all echomail-specific functionality
 * that was previously in standalone functions within telnet_daemon.php.
 */
class EchomailHandler
{
    private const ALLOWED_SORTS = ['date_desc', 'date_asc', 'subject', 'author'];

    /** @var TelnetServer The telnet server instance */
    private BbsSession $server;

    /** @var string Base URL for API requests */
    private string $apiBase;

    /**
     * Canonical message service, lazily constructed. Used for read paths
     * (message-list pages and message detail) that would otherwise make a
     * serial localhost HTTP round trip per navigation keystroke. Same service
     * the matching `/api/messages/echomail/...` routes call.
     */
    private ?\BinktermPHP\MessageHandler $messageService = null;

    /** Network-free equivalent of the `GET /api/messages/echomail/{area}/{id}` fetch. */
    private ?TerminalMessageService $detailService = null;

    /** Canonical owner of the per-user terminal browser state (page positions, sort). */
    private ?\BinktermPHP\Terminal\TerminalMailState $mailState = null;

    /** Network-free equivalent of the `GET /api/echoareas` fetch. */
    private ?\BinktermPHP\Terminal\TerminalMenuData $menuData = null;

    /**
     * Create a new EchomailHandler instance
     *
     * @param BbsSession $server The telnet server instance for I/O operations
     * @param string $apiBase Base URL for API requests
     */
    public function __construct(BbsSession $server, string $apiBase)
    {
        $this->server = $server;
        $this->apiBase = $apiBase;
    }

    private function messageService(): \BinktermPHP\MessageHandler
    {
        return $this->messageService ??= new \BinktermPHP\MessageHandler();
    }

    protected function detailService(): TerminalMessageService
    {
        return $this->detailService ??= new TerminalMessageService($this->messageService());
    }

    protected function mailState(): \BinktermPHP\Terminal\TerminalMailState
    {
        return $this->mailState ??= new \BinktermPHP\Terminal\TerminalMailState();
    }

    /**
     * Fetch the user's echoareas without a `GET /api/echoareas` HTTP round trip.
     * Same envelope shape as {@see TelnetUtils::apiRequest()}.
     *
     * @return array{status:int,data:array<string,mixed>,error:?string}
     */
    private function fetchEchoareas(string $session, bool $subscribedOnly): array
    {
        $this->menuData ??= new \BinktermPHP\Terminal\TerminalMenuData();

        return $this->menuData->echoareas($session, $subscribedOnly);
    }

    /**
     * Display echoarea list with pagination and area selection
     *
     * Shows a list of available echoareas with options to:
     * - Navigate pages (n/p)
     * - Select echoarea by number to view messages
     * - Quit (q)
     *
     * @param resource $conn Socket connection to client
     * @param array $state Terminal state array (cols, rows, etc.)
     * @param string $session Session token for authentication
     * @return void
     */
    public function showEchoareas($conn, array &$state, string $session): void
    {
        // The area browser is an authored M2 space now (see the echoareas
        // surface theme); it flows straight in from Messages with no ceremonial
        // title/description/press-any-key screen in front of it.
        TelnetUtils::safeWrite($conn, "\033[2J\033[H");

        $savedState      = $this->loadSavedListState((int)($state['user_id'] ?? 0));
        $page            = $savedState['areas_page'];
        $perPage         = MailUtils::getMessagesPerPage($state);
        $showInterestKey = \BinktermPHP\Config::env('ENABLE_INTERESTS') === 'true';
        $searchFilter    = null;
        $allAreasMode    = false;
        $shell           = TerminalShellFactory::create($this->server, $state);

        // Canonical "what's new" state, shared with Messages / What's New. One
        // write-free snapshot per visit; recomputed only when the caller returns
        // from an area (or subscribes/unsubscribes), never on cursor movement.
        $newscan = $this->echoareaNewscan($state);

        while (true) {
            $locale = $state['locale'];
            $response = $this->fetchEchoareas($session, !$allAreasMode);
            $allAreas = $response['data']['echoareas'] ?? [];

            if (!$allAreas) {
                if (!$allAreasMode) {
                    // No subscribed areas — show a hint and let the user press A or Q
                    TelnetUtils::safeWrite($conn, "\033[2J\033[H");
                    TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                        $this->server->t('ui.terminalserver.echomail.no_areas', 'You are not subscribed to any areas.', [], $locale),
                        TelnetUtils::ANSI_YELLOW
                    ));
                    TelnetUtils::writeLine($conn, '');
                    TelnetUtils::writeLine($conn, $this->server->t(
                        'ui.terminalserver.echomail.no_areas_browse_hint',
                        'Press A to browse all areas, Q to quit.',
                        [],
                        $locale
                    ));
                    while (true) {
                        $key = $this->server->readKeyWithIdleCheck($conn, $state);
                        if ($key === null) {
                            return;
                        }
                        if (str_starts_with($key, 'CHAR:')) {
                            $char = strtolower(substr($key, 5));
                            if ($char === 'q') {
                                return;
                            }
                            if ($char === 'a') {
                                $allAreasMode = true;
                                $page = 1;
                                break;
                            }
                        }
                    }
                    continue;
                }
                TelnetUtils::writeLine($conn, $this->server->t('ui.terminalserver.echomail.no_areas_all', 'No echo areas available.', [], $locale));
                return;
            }

            $filteredAreas = $searchFilter !== null
                ? $this->filterAreas($allAreas, $searchFilter)
                : $allAreas;

            $headerKey      = $allAreasMode ? 'ui.terminalserver.echomail.areas_all_header' : 'ui.terminalserver.echomail.areas_header';
            $headerFallback = $allAreasMode ? 'All Echoareas (page {page}/{total}):' : 'Echoareas (page {page}/{total}):';

            // Canonical per-area NEW state. Only meaningful for the caller's
            // followed areas (a watermark concept) — absent in all-areas mode.
            $plan = ($allAreasMode || $newscan === null) ? null : $this->safePlan($newscan);

            $result = $this->pickEchoarea(
                $conn, $state, $filteredAreas, $page, $perPage,
                $this->server->t($headerKey, $headerFallback, [], $locale),
                $showInterestKey,
                function(int $newPage) use ($session, &$state) {
                    $this->saveEchoareasPage((int)($state['user_id'] ?? 0), $newPage);
                },
                $searchFilter,
                $allAreasMode,
                $this->buildEchoareaHelpItems($locale, $showInterestKey, $searchFilter !== null, $allAreasMode),
                $shell,
                [
                    'crumbs'   => [$this->server->t('ui.terminalserver.echomail.areas_crumb', 'Messages', [], $locale)],
                    'location' => $allAreasMode
                        ? $this->server->t('ui.terminalserver.echomail.areas_location_all', 'All Echomail Areas', [], $locale)
                        : $this->server->t('ui.terminalserver.echomail.areas_location', 'Echomail Areas', [], $locale),
                    'newscan'  => $plan === null ? null : [
                        'counts'    => $plan->areaNewCounts(),
                        'total'     => $plan->echomailCount(),
                        'areas'     => $plan->areaCount(),
                        'truncated' => $plan->truncated,
                    ],
                ]
            );
            $page = $result['page'];

            switch ($result['action']) {
                case 'quit':
                    return;

                case 'allareas':
                    $allAreasMode = !$allAreasMode;
                    $page         = 1;
                    $searchFilter = null;
                    break;

                case 'filter':
                    $searchFilter = $result['filter'];
                    $page         = 1;
                    break;

                case 'interests':
                    $this->browseByInterest($conn, $state, $session);
                    break;

                case 'ignorerules':
                    $this->showIgnoreRules($conn, $state, $session);
                    break;

                case 'search':
                    $this->searchAllAreas($conn, $state, $session);
                    break;

                case 'unsubscribe':
                    $area = $result['area'] ?? null;
                    if (!$area || empty($area['subscribed'])) {
                        break; // nothing to unsubscribe
                    }
                    $areaLabel = $this->formatEchoareaIdentifier($area['tag'] ?? '', $area['domain'] ?? '');
                    $choice    = $shell->showConfirmDialog(
                        $conn, $state,
                        $this->server->t('ui.terminalserver.echomail.unsubscribe_title', 'Unsubscribe', [], $locale),
                        $this->server->t('ui.terminalserver.echomail.unsubscribe_prompt', 'Unsubscribe from {area}?', ['area' => $areaLabel], $locale),
                        [
                            'y' => $this->server->t('ui.terminalserver.server.confirm_yes', 'Confirm', [], $locale),
                            'n' => $this->server->t('ui.terminalserver.server.confirm_no',  'Cancel',  [], $locale),
                        ],
                        'n'
                    );
                    if ($choice === 'y') {
                        $ok  = $this->callUnsubscribeArea($session, (int)($area['id'] ?? 0), $state['csrf_token'] ?? null);
                        $msg = $ok
                            ? $this->server->t('ui.terminalserver.echomail.unsubscribe_success', 'Unsubscribed from {area}.', ['area' => $areaLabel], $locale)
                            : $this->server->t('ui.terminalserver.echomail.unsubscribe_failed', 'Failed to unsubscribe from {area}.', ['area' => $areaLabel], $locale);
                        $shell->showAlert($conn, $state, 'Unsubscribe', $msg, $ok ? 'info' : 'error');
                        if ($ok) {
                            $newscan?->invalidate();
                            $this->server->logAction($state['username'] ?? 'unknown', "Echomail: unsubscribed from {$areaLabel}");
                        }
                    }
                    break;

                case 'select':
                    $area      = $result['area'];
                    $tag       = $area['tag'] ?? '';
                    $domain    = $area['domain'] ?? '';
                    $areaLabel = $this->formatEchoareaIdentifier($tag, $domain);

                    if ($allAreasMode && empty($area['subscribed'])) {
                        $choice = $shell->showConfirmDialog(
                            $conn, $state,
                            $this->server->t('ui.terminalserver.echomail.subscribe_title', 'Subscribe?', [], $locale),
                            $this->server->t('ui.terminalserver.echomail.subscribe_prompt', 'Subscribe to {area}?', ['area' => $areaLabel], $locale),
                            [
                                's' => $this->server->t('ui.terminalserver.echomail.subscribe_and_browse', 'Subscribe & Browse', [], $locale),
                                'b' => $this->server->t('ui.terminalserver.echomail.browse_only', 'Browse Only', [], $locale),
                                'q' => $this->server->t('ui.terminalserver.server.confirm_no', 'Cancel', [], $locale),
                            ],
                            'b'
                        );
                        if ($choice === 'q') {
                            break;
                        }
                        if ($choice === 's') {
                            $ok = $this->callSubscribeArea($session, (int)($area['id'] ?? 0), $state['csrf_token'] ?? null);
                            if (!$ok) {
                                $shell->showAlert($conn, $state, 'Subscribe',
                                    $this->server->t('ui.terminalserver.echomail.subscribe_failed', 'Failed to subscribe to {area}.', ['area' => $areaLabel], $locale),
                                    'error');
                                break;
                            }
                            $newscan?->invalidate();
                            $this->server->logAction($state['username'] ?? 'unknown', "Echomail: subscribed to {$areaLabel}");
                        }
                    }

                    $this->server->logAction($state['username'] ?? 'unknown', "Echomail: entered area {$areaLabel}");
                    $this->showMessages($conn, $state, $session, $tag, $domain);
                    // The caller may have read messages in there — the per-area
                    // NEW counts on the browser must reflect the advanced
                    // watermark on the next draw.
                    $newscan?->invalidate();
                    break;
            }
        }
    }

    /**
     * A per-visit {@see NewscanSnapshot} for the authored area browser, or null
     * when the caller is not an authenticated user. The snapshot is lazy and
     * only recomputes {@see UnifiedNewscanService::plan()} after {@see
     * NewscanSnapshot::invalidate()} — the browser calls that when the caller
     * returns from an area or (un)subscribes, never on cursor movement.
     */
    private function echoareaNewscan(array $state): ?NewscanSnapshot
    {
        $userId  = (int) ($state['user_id'] ?? 0);
        $isGuest = empty($state['username']) || $state['username'] === '_guest';
        if ($isGuest || $userId <= 0) {
            return null;
        }
        $isAdmin = !empty($state['is_admin']);

        return new NewscanSnapshot(
            static fn (): NewscanPlan => (new UnifiedNewscanService())
                ->plan(['user_id' => $userId, 'is_admin' => $isAdmin]),
            0 // no TTL: invalidation is explicit, tied to the child-flow boundary
        );
    }

    /** {@see NewscanSnapshot::plan()}, but a resolution failure yields null (logged). */
    private function safePlan(NewscanSnapshot $snapshot): ?NewscanPlan
    {
        try {
            return $snapshot->plan();
        } catch (\Throwable $e) {
            $this->server->logInfo('Echoarea browser: newscan plan unavailable — ' . $e->getMessage());

            return null;
        }
    }

    /**
     * The STATUS block for the authored area browser: one honest line —
     * "<scope> · <canonical new state> · Page N/M". The new-state clause is
     * omitted when nothing is new (zero counts stay quiet); a scan-limit cap is
     * stated rather than hidden.
     *
     * @param array{total?:int,areas?:int,truncated?:bool}|null $newscan
     * @return list<string>
     */
    private function echoareaStatusLines(
        string $locale,
        int $areaTotal,
        int $page,
        int $totalPages,
        ?string $searchFilter,
        bool $allAreasMode,
        ?array $newscan
    ): array {
        if ($searchFilter !== null) {
            $scope = $this->server->t(
                'ui.terminalserver.echomail.areas_context_filter',
                'Filter: {term} - {count} matching',
                ['term' => $searchFilter, 'count' => $areaTotal],
                $locale
            );
        } elseif ($allAreasMode) {
            $scope = $this->server->t(
                'ui.terminalserver.echomail.areas_context_all',
                'All areas - {count} total',
                ['count' => $areaTotal],
                $locale
            );
        } else {
            $scope = $this->server->t(
                'ui.terminalserver.echomail.areas_context_subscribed',
                'Areas you follow - {count}',
                ['count' => $areaTotal],
                $locale
            );
        }

        $parts = [$scope];

        $newTotal = (int)($newscan['total'] ?? 0);
        if ($newTotal > 0) {
            $clause = $this->server->t(
                'ui.terminalserver.echomail.browser.status_new',
                '{new} new across {areas} area(s)',
                ['new' => $newTotal, 'areas' => (int)($newscan['areas'] ?? 0)],
                $locale
            );
            if (!empty($newscan['truncated'])) {
                $clause .= ' (' . $this->server->t(
                    'ui.terminalserver.echomail.browser.status_truncated',
                    'scan limit reached',
                    [],
                    $locale
                ) . ')';
            }
            $parts[] = $clause;
        }

        $parts[] = $this->server->t(
            'ui.terminalserver.list.dense_page_indicator',
            'Page {page}/{total}',
            ['page' => $page, 'total' => max(1, $totalPages)],
            $locale
        );

        return [implode('   ' . "\u{00B7}" . '   ', $parts)];
    }

    /**
     * Subscribe the current user to an echoarea via the subscriptions API.
     *
     * @return bool True on success
     */
    private function callSubscribeArea(string $session, int $echoareaId, ?string $csrfToken): bool
    {
        $result = TelnetUtils::apiRequest(
            $this->apiBase, 'POST', '/api/subscriptions/user',
            ['action' => 'subscribe', 'echoarea_id' => $echoareaId],
            $session, 3, $csrfToken
        );
        return ($result['status'] === 200) && !empty($result['data']['success']);
    }

    /**
     * Unsubscribe the current user from an echoarea via the subscriptions API.
     *
     * @return bool True on success
     */
    private function callUnsubscribeArea(string $session, int $echoareaId, ?string $csrfToken): bool
    {
        $result = TelnetUtils::apiRequest(
            $this->apiBase, 'POST', '/api/subscriptions/user',
            ['action' => 'unsubscribe', 'echoarea_id' => $echoareaId],
            $session, 3, $csrfToken
        );
        return ($result['status'] === 200) && !empty($result['data']['success']);
    }

    /**
     * Prompt for a search term and display matching messages within a single echoarea.
     *
     * @param string $area Echoarea identifier (tag@domain)
     */
    private function searchInArea($conn, array &$state, string $session, string $area): void
    {
        $locale = $state['locale'];
        $shell  = TerminalShellFactory::create($this->server, $state);
        $term = $shell->promptText(
            $conn,
            $state,
            $this->server->t('ui.terminalserver.echomail.search_title', 'Search', [], $locale),
            $this->server->t('ui.terminalserver.echomail.search_messages_prompt', 'Search messages:', [], $locale),
            ['prefill' => '']
        );
        if ($term === null) {
            return;
        }
        $term = trim($term);

        if (strlen($term) < 2) {
            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.echomail.search_term_too_short', 'Search term must be at least 2 characters.', [], $locale),
                TelnetUtils::ANSI_YELLOW
            ));
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.server.press_any_key', 'Press any key to return...', [], $locale),
                TelnetUtils::ANSI_YELLOW
            ));
            $this->server->readKeyWithIdleCheck($conn, $state);
            return;
        }

        $shell->showWorkingOverlay($conn, $state, 'Searching...');

        $response = TelnetUtils::apiRequest(
            $this->apiBase, 'GET',
            '/api/messages/search?' . http_build_query(['q' => $term, 'type' => 'echomail', 'echoarea' => $area]),
            null, $session
        );

        $messages = $response['data']['messages'] ?? [];

        if (empty($messages)) {
            $shell->showAlert($conn, $state, 'Search',
                $this->server->t('ui.terminalserver.echomail.search_no_results', 'No messages found for \'{term}\'.', ['term' => $term], $locale),
                'info');
            return;
        }

        $this->showAreaSearchResults($conn, $state, $session, $term, $area, $messages);
    }

    /**
     * Display a paginated list of within-area search results and allow reading individual messages.
     *
     * Uses the standard message list format (no area tag prefix since all results share the same area).
     * Prev/next in the viewer navigates the flat search result list, not the area message list.
     *
     * @param string $area       Echoarea identifier (tag@domain), used for compose
     * @param array  $allMessages Full flat list of search result messages from the API
     */
    private function showAreaSearchResults($conn, array &$state, string $session, string $term, string $area, array $allMessages): void
    {
        $perPage    = MailUtils::getMessagesPerPage($state);
        $totalPages = max(1, (int)ceil(count($allMessages) / $perPage));
        $page       = 1;
        $selectedIndex = 0;
        $selectedMessageId = null;

        while (true) {
            $offset       = ($page - 1) * $perPage;
            $pageMessages = array_slice($allMessages, $offset, $perPage);

            if ($selectedMessageId !== null) {
                $restoredIndex = $this->findMessageIndexById($pageMessages, $selectedMessageId);
                if ($restoredIndex !== null) {
                    $selectedIndex = $restoredIndex;
                }
                $selectedMessageId = null;
            }
            if ($selectedIndex < 0 || $selectedIndex >= count($pageMessages)) {
                $selectedIndex = 0;
            }

            $title = TelnetUtils::colorize(
                $this->server->t(
                    'ui.terminalserver.echomail.search_results_header',
                    'Search: {term} (page {page}/{total})',
                    ['term' => $term, 'page' => $page, 'total' => $totalPages],
                    $state['locale']
                ),
                TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD
            );

            $shell = TerminalShellFactory::create($this->server, $state);
            $result = $shell->showMessageList(
                $conn, $state, $title, $pageMessages, $page, $totalPages, $selectedIndex
            );
            $selectedIndex = $result['selectedIndex'];

            switch ($result['action']) {
                case 'disconnect':
                case 'quit':
                    return;
                case 'prev':
                    if ($page > 1) { $page--; $selectedIndex = 0; }
                    break;
                case 'next':
                    if ($page < $totalPages) { $page++; $selectedIndex = 0; }
                    break;
                case 'compose':
                    $this->compose($conn, $state, $session, $area, null);
                    break;
                case 'read':
                    $absIndex    = $offset + $result['index'];
                    $selectedMessageId = isset($allMessages[$absIndex]['id']) ? (int)$allMessages[$absIndex]['id'] : null;
                    $newAbsIndex = $this->displaySearchMessage($conn, $state, $session, $allMessages, $absIndex, $term);
                    $page        = max(1, (int)floor($newAbsIndex / $perPage) + 1);
                    $selectedIndex = $newAbsIndex - ($page - 1) * $perPage;
                    $selectedMessageId = isset($allMessages[$newAbsIndex]['id']) ? (int)$allMessages[$newAbsIndex]['id'] : $selectedMessageId;
                    break;
            }
        }
    }

    /**
     * Prompt for a search term and display matching echomail messages across all subscribed areas.
     *
     * Calls GET /api/messages/search?q=<term>&type=echomail, then presents results
     * in a paginated selectable list.  Selecting a message opens it in the viewer.
     */
    private function searchAllAreas($conn, array &$state, string $session): void
    {
        $locale = $state['locale'];
        $shell  = TerminalShellFactory::create($this->server, $state);
        $term = $shell->promptText(
            $conn,
            $state,
            $this->server->t('ui.terminalserver.echomail.search_title', 'Search', [], $locale),
            $this->server->t('ui.terminalserver.echomail.search_messages_prompt', 'Search messages:', [], $locale),
            ['prefill' => '']
        );
        if ($term === null) {
            return;
        }
        $term = trim($term);

        if (strlen($term) < 2) {
            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.echomail.search_term_too_short', 'Search term must be at least 2 characters.', [], $locale),
                TelnetUtils::ANSI_YELLOW
            ));
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.server.press_any_key', 'Press any key to return...', [], $locale),
                TelnetUtils::ANSI_YELLOW
            ));
            $this->server->readKeyWithIdleCheck($conn, $state);
            return;
        }

        $shell->showWorkingOverlay($conn, $state, 'Searching...');

        $response = TelnetUtils::apiRequest(
            $this->apiBase, 'GET',
            '/api/messages/search?' . http_build_query(['q' => $term, 'type' => 'echomail']),
            null, $session
        );

        $messages = $response['data']['messages'] ?? [];

        if (empty($messages)) {
            $shell->showAlert($conn, $state, 'Search',
                $this->server->t('ui.terminalserver.echomail.search_no_results', 'No messages found for \'{term}\'.', ['term' => $term], $locale),
                'info');
            return;
        }

        $this->showSearchResults($conn, $state, $session, $term, $messages);
    }

    /**
     * Display a paginated list of echomail search results and allow reading individual messages.
     *
     * @param array $allMessages Full flat list of search result messages (from API)
     */
    private function showSearchResults($conn, array &$state, string $session, string $term, array $allMessages): void
    {
        $perPage      = MailUtils::getMessagesPerPage($state);
        $totalCount   = count($allMessages);
        $totalPages   = max(1, (int)ceil($totalCount / $perPage));
        $page         = 1;
        $selectedIndex = 0;
        $selectedMessageId = null;

        while (true) {
            $offset       = ($page - 1) * $perPage;
            $pageMessages = array_slice($allMessages, $offset, $perPage);
            $locale       = $state['locale'];

            if ($selectedMessageId !== null) {
                $restoredIndex = $this->findMessageIndexById($pageMessages, $selectedMessageId);
                if ($restoredIndex !== null) {
                    $selectedIndex = $restoredIndex;
                }
                $selectedMessageId = null;
            }
            if ($selectedIndex < 0 || $selectedIndex >= count($pageMessages)) {
                $selectedIndex = 0;
            }

            // Prepend the echoarea tag to the from_name so the area is visible in the list.
            $displayMessages = array_map(function (array $msg): array {
                $copy = $msg;
                $copy['from_name'] = '[' . ($msg['echoarea'] ?? '?') . '] ' . ($msg['from_name'] ?? '');
                return $copy;
            }, $pageMessages);

            $title = TelnetUtils::colorize(
                $this->server->t(
                    'ui.terminalserver.echomail.search_results_header',
                    'Search: {term} (page {page}/{total})',
                    ['term' => $term, 'page' => $page, 'total' => $totalPages],
                    $locale
                ),
                TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD
            );

            $shell = TerminalShellFactory::create($this->server, $state);
            $result = $shell->showMessageList(
                $conn,
                $state,
                $title,
                $displayMessages,
                $page,
                $totalPages,
                $selectedIndex
            );
            $selectedIndex = $result['selectedIndex'];

            switch ($result['action']) {
                case 'disconnect':
                case 'quit':
                    return;
                case 'prev':
                    if ($page > 1) { $page--; $selectedIndex = 0; }
                    break;
                case 'next':
                    if ($page < $totalPages) { $page++; $selectedIndex = 0; }
                    break;
                case 'read':
                    $absIndex    = $offset + $result['index'];
                    $selectedMessageId = isset($allMessages[$absIndex]['id']) ? (int)$allMessages[$absIndex]['id'] : null;
                    $newAbsIndex = $this->displaySearchMessage($conn, $state, $session, $allMessages, $absIndex, $term);
                    $page        = max(1, (int)floor($newAbsIndex / $perPage) + 1);
                    $selectedIndex = $newAbsIndex - ($page - 1) * $perPage;
                    $selectedMessageId = isset($allMessages[$newAbsIndex]['id']) ? (int)$allMessages[$newAbsIndex]['id'] : $selectedMessageId;
                    break;
            }
        }
    }

    /**
     * Open a single echomail search result in the message viewer.
     *
     * Prev/next navigate the flat $allMessages array across area boundaries.
     * Returns the index of the message that was active when the user quit.
     *
     * @param array $allMessages Full flat search result list
     * @param int   $index       Zero-based index into $allMessages to open
     * @return int The index active when the viewer was closed
     */
    private function displaySearchMessage($conn, array &$state, string $session, array $allMessages, int $index, string $searchTerm = ''): int
    {
        while (true) {
            $msg = $allMessages[$index] ?? null;
            if (!$msg) {
                return $index;
            }

            $result = $this->viewSingleMessage($conn, $state, $session, $msg, ['search_term' => $searchTerm]);

            switch ($result['action']) {
                case 'quit':
                case 'reply':
                case 'forward':
                    return $index;
                case 'prev':
                    if ($index > 0) {
                        $index--;
                    }
                    break;
                case 'next':
                    if ($index < count($allMessages) - 1) {
                        $index++;
                    }
                    break;
            }
        }
    }

    /**
     * Open one already-listed echomail message in the full message viewer and
     * run its read loop (scroll, headers, images, save, download, e-mail
     * forward, help) until the caller navigates away.
     *
     * This is the single-message seam shared by echomail search and the unified
     * newscan queue viewer ({@see \BinktermPHP\TelnetServer\TerminalMessageQueueViewer}).
     * Fetching the message marks it read through the same canonical path the web
     * uses (`GET /api/messages/echomail/{area}/{id}`); this method adds no read
     * state of its own.
     *
     * @param array<string,mixed> $msg  a list row (needs `id`, `echoarea`,
     *                                   `echoarea_domain`, `from_name`,
     *                                   `from_address`, `subject`, `to_name`,
     *                                   `date_written`)
     * @param array<string,mixed> $opts `search_term` (string) to highlight;
     *                                  `context` (string) for logging
     * @return array{action:string,detail:array} action is one of
     *         quit|prev|next|reply|forward. reply/forward are handled here before
     *         returning (the caller only needs to unwind).
     */
    public function viewSingleMessage($conn, array &$state, string $session, array $msg, array $opts = []): array
    {
        $searchTerm = (string)($opts['search_term'] ?? '');
        $context    = (string)($opts['context'] ?? 'list');
        $shell      = TerminalShellFactory::create($this->server, $state);

        while (true) {
            $id     = (int)($msg['id'] ?? 0);
            $tag    = (string)($msg['echoarea'] ?? '');
            $domain = (string)($msg['echoarea_domain'] ?? '');
            $area   = $this->formatEchoareaIdentifier($tag, $domain);

            $this->server->logAction($state['username'] ?? 'unknown', "Echomail {$context}: read message #{$id} in {$area}");

            $detail       = $this->detailService()->echomailDetail($area, (int) $id, ((int) ($state['user_id'] ?? 0)) ?: null);
            if ($context === 'newscan') {
                // The newscan queue supplies only {id, echoarea, echoarea_domain};
                // fill the header fields the viewer needs from the fetched message.
                $msg = array_merge(is_array($detail['data'] ?? null) ? $detail['data'] : [], $msg);
            }
            // GHSA-4225: strip every terminal control sequence except SGR colour
            // before the body or kludges reach the reader. stripNonDisplayAnsi()
            // now delegates to BinktermPHP\TerminalTextSanitizer::sanitize(); the
            // ANSI-art fidelity layer (ArtFormatDetector gate, clipArtLines,
            // visible-unit wrap, CP437 repair) runs after this, unchanged.
            $body         = TerminalMarkupRenderer::stripNonDisplayAnsi($detail['data']['message_text'] ?? '');
            $markupFormat = $detail['data']['markup_format'] ?? null;
            $artFormat    = \BinktermPHP\ArtFormatDetector::detectArtFormat(
                $body,
                $detail['data']['message_charset'] ?? null
            );
            $rawKludges   = TerminalMarkupRenderer::stripNonDisplayAnsi(($detail['data']['kludge_lines'] ?? '') . "\n" . ($detail['data']['bottom_kludges'] ?? ''));
            $kludgeLines  = TerminalMarkupRenderer::extractKludgeLines($rawKludges);
            $kludgeLines  = array_map(fn(string $line): string => $this->server->encodeForTerminal($line), $kludgeLines);
            $imageRefs    = TerminalMarkupRenderer::extractImageRefs((string)($markupFormat ?? ''), $body);
            $isSaved      = (bool)($detail['data']['is_saved'] ?? false);

            $fromName    = (string)($msg['from_name'] ?? 'Unknown');
            $fromAddress = (string)($msg['from_address'] ?? '');

            $sbProfile   = TelnetUtils::getDefaultStyleProfile()['status_bar'];
            $keyColor    = $sbProfile['key']   ?? TelnetUtils::ANSI_RED;
            $lblColor    = $sbProfile['label'] ?? TelnetUtils::ANSI_BLUE;

            $buildView = function (array $s) use ($msg, $body, $markupFormat, $artFormat, $area, $fromName, $fromAddress, $imageRefs, $searchTerm, $keyColor, $lblColor): array {
                $cols     = $s['cols'] ?? 80;
                $width    = max(10, $cols - 2);
                $charset  = $this->server->getTerminalCharset();

                $segments = [
                    ['text' => 'U/D',          'color' => $keyColor],
                    ['text' => ' Scroll  ',    'color' => $lblColor],
                    ['text' => 'L/R',          'color' => $keyColor],
                    ['text' => ' Prev/Next  ', 'color' => $lblColor],
                    ['text' => 'R',            'color' => $keyColor],
                    ['text' => ' Reply  ',     'color' => $lblColor],
                    ['text' => 'F',            'color' => $keyColor],
                    ['text' => ' ' . $this->server->t('ui.terminalserver.echomail.status_forward', 'Fwd', [], $s['locale'] ?? 'en') . '  ', 'color' => $lblColor],
                    ['text' => 'Ctrl-K',       'color' => $keyColor],
                    ['text' => ' Help  ',      'color' => $lblColor],
                    ['text' => 'Q',            'color' => $keyColor],
                    ['text' => ' Quit',        'color' => $lblColor],
                ];

                $safeBody = TerminalMarkupRenderer::stripNonDisplayAnsi($body);
                if ($markupFormat !== null) {
                    $wrappedLines = TerminalMarkupRenderer::render($markupFormat, $body, $width);
                } elseif ($artFormat !== null) {
                    // ANSI artwork: keep authored rows, clip (never reflow) to the
                    // full terminal width less one guard column.
                    $wrappedLines = TelnetUtils::clipArtLines($safeBody, max(10, $cols - 1));
                } else {
                    $wrappedLines = TelnetUtils::wrapTextLines($safeBody, $width);
                }
                $wrappedLines = array_map(fn(string $line): string => $this->server->encodeForTerminal($line), $wrappedLines);
                if ($searchTerm !== '') {
                    $wrappedLines = $this->highlightSearchTerm($wrappedLines, $searchTerm);
                }

                return [
                    'headerLines'  => TelnetUtils::buildCompactMessageHeader($width, [
                        'from'         => $fromName,
                        'from_address' => $fromAddress,
                        'to'           => $msg['to_name'] ?? 'All',
                        'area'         => $area,
                        'date'         => TelnetUtils::formatUserDate($msg['date_written'] ?? '', $s, false),
                        'subject'      => $msg['subject'] ?? 'Message',
                    ], $charset),
                    'wrappedLines' => $wrappedLines,
                    'statusLine'   => TelnetUtils::buildStatusBar($segments, $width),
                ];
            };

            $apiBase = $this->apiBase;
            $server  = $this->server;
            $imageFn = !empty($imageRefs)
                ? static function (int $idx) use ($conn, &$state, $server, $imageRefs, $apiBase): void {
                    TelnetUtils::showSixelImageViewer($conn, $state, $server, $imageRefs[$idx], count($imageRefs), $apiBase);
                }
                : null;

            $view      = $buildView($state);
            $locale    = $state['locale'] ?? 'en';
            $helpItems = [
                ['key' => 'PgUp / PgDn', 'label' => $this->server->t('ui.terminalserver.message.help_page',       'Scroll one page',            [], $locale)],
                ['key' => 'H',           'label' => $this->server->t('ui.terminalserver.message.help_headers',    'View message headers',        [], $locale)],
                ['key' => 'B',           'label' => $this->server->t('ui.terminalserver.echomail.help_bookmark',  'Bookmark / unsave message',   [], $locale)],
                ['key' => 'T',           'label' => $this->server->t('ui.terminalserver.echomail.help_text_dl',   'Download as .txt (ZMODEM)',   [], $locale)],
                ['key' => 'E',           'label' => $this->server->t('ui.terminalserver.echomail.help_email_fwd', 'Forward to my email address', [], $locale)],
                ['key' => 'F',           'label' => $this->server->t('ui.terminalserver.echomail.help_forward',   'Forward message',             [], $locale)],
            ];
            if (!empty($imageRefs)) {
                $helpItems[] = ['key' => 'I', 'label' => $this->server->t('ui.terminalserver.message.help_images', 'View inline image(s)', [], $locale)];
            }

            $result = $shell->showMessageViewer(
                $conn, $state,
                $view['headerLines'], $view['wrappedLines'], $view['statusLine'],
                $state['rows'] ?? 24, 0, false, $kludgeLines, $buildView,
                $imageRefs, $imageFn, ['b' => 'save', 't' => 'download', 'e' => 'emailforward', 'f' => 'forward'], $helpItems,
                ['help_overlay' => TelnetUtils::getDefaultStyleProfile()['help_overlay']]
            );

            switch ($result['action']) {
                case 'quit':
                    return ['action' => 'quit', 'detail' => $detail];
                case 'prev':
                    return ['action' => 'prev', 'detail' => $detail];
                case 'next':
                    return ['action' => 'next', 'detail' => $detail];
                case 'reply':
                    TelnetUtils::safeWrite($conn, "\033[2J\033[H");
                    $this->compose($conn, $state, $session, $area, $detail['data'] ?? $msg);
                    TelnetUtils::setCursorVisible($conn, true);
                    return ['action' => 'reply', 'detail' => $detail];
                case 'forward':
                    $this->forwardMessage($conn, $state, $session, $area, $msg, $detail['data'] ?? $msg);
                    return ['action' => 'forward', 'detail' => $detail];
                case 'save':
                    $csrfToken = $state['csrf_token'] ?? null;
                    if ($isSaved) {
                        TelnetUtils::apiRequest($this->apiBase, 'DELETE', '/api/messages/echomail/' . $id . '/save', null, $session, 3, $csrfToken);
                        $confirmMsg = 'Message removed from saved.';
                    } else {
                        TelnetUtils::apiRequest($this->apiBase, 'POST', '/api/messages/echomail/' . $id . '/save', null, $session, 3, $csrfToken);
                        $confirmMsg = 'Message saved.';
                    }
                    $isSaved = !$isSaved;
                    $detail['data']['is_saved'] = $isSaved;
                    $shell->showAlert($conn, $state, 'Bookmark', $confirmMsg, 'info');
                    break;
                case 'download':
                    $this->downloadAsText($conn, $state, $session, $id, $msg['subject'] ?? 'message');
                    break;
                case 'emailforward':
                    $csrfToken = $state['csrf_token'] ?? null;
                    $shell->showWorkingOverlay($conn, $state, 'Forwarding message to email...');
                    $fwdResult = TelnetUtils::apiRequest($this->apiBase, 'POST', '/api/messages/echomail/' . $id . '/forward-email', null, $session, 3, $csrfToken);
                    if ($fwdResult['status'] === 200) {
                        $shell->showAlert($conn, $state, 'Email Forward', 'Forwarded to your email address.', 'info');
                    } else {
                        $errMsg = $fwdResult['data']['error'] ?? 'Failed to forward message.';
                        $shell->showAlert($conn, $state, 'Email Forward', $errMsg, 'error');
                    }
                    break;
            }
        }
    }

    /**
     * Wrap each case-insensitive match of $term in the given lines with ANSI yellow highlighting.
     *
     * The replacement is ANSI-aware: ANSI escape sequences are passed through unchanged so that
     * existing color codes in markup-rendered bodies are not accidentally matched.
     *
     * @param string[] $lines Encoded, post-render body lines (may contain ANSI escape sequences)
     * @param string   $term  Search term entered by the user
     * @return string[]
     */
    private function highlightSearchTerm(array $lines, string $term): array
    {
        if ($term === '') {
            return $lines;
        }
        $pattern   = '/' . preg_quote($term, '/') . '/iu';
        $ansiSplit = '/(\033\[[0-9;]*m)/';
        return array_map(function (string $line) use ($pattern, $ansiSplit): string {
            // Split on ANSI escape sequences, keeping them as captured delimiters.
            $parts  = preg_split($ansiSplit, $line, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$line];
            $result = '';
            foreach ($parts as $part) {
                if (preg_match($ansiSplit, $part)) {
                    $result .= $part; // ANSI escape — pass through unchanged
                } else {
                    $result .= preg_replace_callback($pattern, static function (array $m): string {
                        return "\033[43;97m" . $m[0] . "\033[0m";
                    }, $part) ?? $part;
                }
            }
            return $result;
        }, $lines);
    }

    /**
     * Filter echoareas by a case-insensitive search term matching tag, domain, or description.
     *
     * @param array $areas List of echoarea arrays
     * @param string $term Search term
     * @return array Filtered list (re-indexed)
     */
    private function filterAreas(array $areas, string $term): array
    {
        $term = mb_strtolower(trim($term));
        if ($term === '') {
            return $areas;
        }
        return array_values(array_filter($areas, function (array $area) use ($term): bool {
            return str_contains(mb_strtolower((string)($area['tag'] ?? '')), $term)
                || str_contains(mb_strtolower((string)($area['domain'] ?? '')), $term)
                || str_contains(mb_strtolower((string)($area['description'] ?? '')), $term);
        }));
    }

    /**
     * Show a numbered interest list, let the user pick one, then display
     * that interest's echo areas for selection.
     */
    private function browseByInterest($conn, array &$state, string $session): void
    {
        $locale  = $state['locale'];
        $perPage = MailUtils::getMessagesPerPage($state);
        $shell   = TerminalShellFactory::create($this->server, $state);

        // ── Interest picker ───────────────────────────────────────────────────
        $userId        = (int)($state['user_id'] ?? 0);
        $interestMgr   = new \BinktermPHP\InterestManager();
        $interests     = $interestMgr->getInterests(true);
        $subscribedIds = $userId > 0
            ? array_flip($interestMgr->getUserSubscribedInterestIds($userId))
            : [];
        foreach ($interests as &$_i) {
            $_i['subscribed'] = isset($subscribedIds[(int)$_i['id']]);
        }
        unset($_i);

        if (empty($interests)) {
            TelnetUtils::safeWrite($conn, "\033[2J\033[H");
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.echomail.interests_none', 'No interests available.', [], $locale),
                TelnetUtils::ANSI_YELLOW
            ));
            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.server.press_any_key', 'Press any key to return...', [], $locale),
                TelnetUtils::ANSI_YELLOW
            ));
            $this->server->readKeyWithIdleCheck($conn, $state);
            return;
        }

        $interestRows = [];
        foreach ($interests as $idx => $interest) {
            $name       = (string)($interest['name'] ?? '');
            $subscribed = !empty($interest['subscribed']);
            $badge      = $subscribed
                ? TelnetUtils::colorize('[+]', TelnetUtils::ANSI_GREEN)
                : TelnetUtils::colorize('[ ]', TelnetUtils::ANSI_DIM);
            $interestRows[] = ' '
                . TelnetUtils::colorize(sprintf('%2d', $idx + 1), TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD)
                . TelnetUtils::colorize(')', TelnetUtils::ANSI_BLUE)
                . ' ' . $badge . ' ' . $name;
        }

        $interestTitle = TelnetUtils::colorize(
            $this->server->t('ui.terminalserver.echomail.interests_title', 'Browse by Interest', [], $locale),
            TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD
        );
        $interestStatusBar = [
            ['text' => 'U/D',      'color' => TelnetUtils::ANSI_RED],
            ['text' => ' Move  ',  'color' => TelnetUtils::ANSI_BLUE],
            ['text' => 'Enter',    'color' => TelnetUtils::ANSI_RED],
            ['text' => ' Select  ', 'color' => TelnetUtils::ANSI_BLUE],
            ['text' => 'Q',        'color' => TelnetUtils::ANSI_RED],
            ['text' => ' Quit',    'color' => TelnetUtils::ANSI_BLUE],
        ];

        $interestResult = $shell->showSelectableList(
            $conn, $state,
            $interestTitle, $interestRows, 1, 1, 0,
            $interestStatusBar
        );

        if ($interestResult['action'] === 'disconnect' || $interestResult['action'] === 'quit') {
            return;
        }
        $idx = $interestResult['index'];
        if (!isset($interests[$idx])) {
            return;
        }

        $interest   = $interests[$idx];
        $interestId = (int)($interest['id'] ?? 0);
        $interestName = (string)($interest['name'] ?? '');

        // ── Area list for chosen interest ─────────────────────────────────────
        $resp  = TelnetUtils::apiRequest($this->apiBase, 'GET', "/api/interests/{$interestId}/echoareas", null, $session);
        $areas = $resp['data']['echoareas'] ?? [];

        if (empty($areas)) {
            TelnetUtils::safeWrite($conn, "\033[2J\033[H");
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.interests.no_areas', 'No echo areas assigned to this interest.', [], $locale),
                TelnetUtils::ANSI_YELLOW
            ));
            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.server.press_any_key', 'Press any key to return...', [], $locale),
                TelnetUtils::ANSI_YELLOW
            ));
            $this->server->readKeyWithIdleCheck($conn, $state);
            return;
        }

        $page  = 1;
        $title = $this->server->t(
            'ui.terminalserver.echomail.interest_areas_header',
            '{name} (page {page}/{total}):',
            ['name' => $interestName],
            $locale
        );

        while (true) {
            $result = $this->pickEchoarea(
                $conn, $state, $areas, $page, $perPage, $title, false, null, null, false, [], $shell
            );
            $page = $result['page'];

            if ($result['action'] === 'quit') {
                return;
            }
            if ($result['action'] === 'redraw') {
                continue;
            }
            if ($result['action'] === 'select') {
                $area   = $result['area'];
                $tag    = $area['tag'] ?? '';
                $domain = $area['domain'] ?? '';
                $areaLabel = $this->formatEchoareaIdentifier($tag, $domain);
                $this->server->logAction($state['username'] ?? 'unknown', "Echomail: entered area {$areaLabel} via interest \"{$interestName}\"");
                $this->showMessages($conn, $state, $session, $tag, $domain);
            }
        }
    }

    /**
     * Render a paginated echo area list using the standard selectable-list widget.
     *
     * Returns an array with:
     *   'action' => 'quit' | 'select' | 'interests' | 'redraw' | 'filter' | 'search'
     *   'area'   => array   (only when action === 'select')
     *   'filter' => ?string (only when action === 'filter'; null means clear)
     *   'page'   => int     (current page after the action)
     *
     * @param callable|null $onPageChange Called with the new page number when page changes (for persistence)
     * @param string|null   $searchFilter Active search filter string, or null if none
     */
    private function pickEchoarea(
        $conn,
        array &$state,
        array $allAreas,
        int $page,
        int $perPage,
        string $title,
        bool $showInterestKey,
        ?callable $onPageChange,
        ?string $searchFilter = null,
        bool $allAreasMode = false,
        array $helpItems = [],
        ?TerminalShellInterface $shell = null,
        ?array $identity = null
    ): array {
        $locale     = $state['locale'];
        $totalPages = max(1, (int)ceil(count($allAreas) / $perPage));
        $page       = max(1, min($page, $totalPages));
        $offset     = ($page - 1) * $perPage;
        $areas      = array_slice($allAreas, $offset, $perPage);

        $shell ??= TerminalShellFactory::create($this->server, $state);
        $ctx   = $this->server->getRenderContext();

        // Canonical per-area NEW state (echoareaId => count), passed by the
        // caller from one write-free UnifiedNewscanService::plan(); empty in
        // all-areas mode and for guests.
        $newscan   = is_array($identity['newscan'] ?? null) ? $identity['newscan'] : null;
        $newCounts = is_array($newscan['counts'] ?? null) ? $newscan['counts'] : [];

        $extraKeys = ['/' => 'filter', 's' => 'search', 'a' => 'allareas', 'u' => 'unsubscribe', 'g' => 'ignorerules'];
        if ($showInterestKey) {
            $extraKeys['i'] = 'interests';
        }
        if ($searchFilter !== null) {
            $extraKeys['c'] = 'clearfilter';
        }

        $statusBar = [
            ['text' => 'U/D',        'color' => TelnetUtils::ANSI_RED],
            ['text' => ' Move  ',    'color' => TelnetUtils::ANSI_BLUE],
            ['text' => 'L/R',        'color' => TelnetUtils::ANSI_RED],
            ['text' => ' Page  ',    'color' => TelnetUtils::ANSI_BLUE],
            ['text' => 'Enter',      'color' => TelnetUtils::ANSI_RED],
            ['text' => ' Select  ',  'color' => TelnetUtils::ANSI_BLUE],
            ['text' => 'Q',          'color' => TelnetUtils::ANSI_RED],
            ['text' => ' Quit  ',    'color' => TelnetUtils::ANSI_BLUE],
            ['text' => 'Ctrl-K',     'color' => TelnetUtils::ANSI_RED],
            ['text' => ' ' . $this->server->t('ui.terminalserver.list.status_help', 'Help', [], $locale), 'color' => TelnetUtils::ANSI_BLUE],
        ];

        // ── Location identity + compact context line (Terminal Experience
        //    Unification dense-list primitive). The caller may pass an explicit
        //    identity; otherwise the location is derived from the legacy title
        //    and the context reflects the active mode.
        $location = $identity['location'] ?? rtrim(
            (string)preg_replace('/\s*\(page\b.*$/i', '', $title),
            ": \t"
        );
        $crumbs = $identity['crumbs'] ?? [];

        if (isset($identity['context'])) {
            $context = (string)$identity['context'];
        } elseif ($searchFilter !== null) {
            $context = $this->server->t(
                'ui.terminalserver.echomail.areas_context_filter',
                'Filter: {term} - {count} matching',
                ['term' => $searchFilter, 'count' => count($allAreas)],
                $locale
            );
        } elseif ($allAreasMode) {
            $context = $this->server->t(
                'ui.terminalserver.echomail.areas_context_all',
                'All areas - {count} total',
                ['count' => count($allAreas)],
                $locale
            );
        } else {
            $context = $this->server->t(
                'ui.terminalserver.echomail.areas_context_subscribed',
                'Areas you follow - {count}',
                ['count' => count($allAreas)],
                $locale
            );
        }

        $columns = [
            new DenseListColumn('tag', $allAreasMode ? 16 : 20),
            new DenseListColumn('net', 10),
            new DenseListColumn('desc', 0),
        ];

        $newLabel = fn (int $n): string => $this->server->t(
            'ui.terminalserver.echomail.area_new_count', '{count} new', ['count' => $n], $locale
        );

        $buildList = function (array $pageAreas, int $pageNum) use (
            $columns, $location, $crumbs, $context, $totalPages, $allAreasMode, $newCounts, $newLabel
        ): DenseList {
            $rows = [];
            foreach ($pageAreas as $area) {
                $subscribed = !empty($area['subscribed']);
                $newCount   = (int)($newCounts[(int)($area['id'] ?? 0)] ?? 0);
                $rows[] = new DenseListRow(
                    [
                        'tag'  => (string)($area['tag'] ?? ''),
                        'net'  => (string)($area['domain'] ?? ''),
                        'desc' => (string)($area['description'] ?? ''),
                    ],
                    $area,
                    $allAreasMode ? ($subscribed ? '[+]' : '[ ]') : null,
                    $allAreasMode ? ($subscribed ? TelnetUtils::ANSI_GREEN : TelnetUtils::ANSI_DIM) : null,
                    $newCount > 0 ? $newLabel($newCount) : null
                );
            }

            return new DenseList($location, $crumbs, $context, $columns, $rows, $pageNum, $totalPages);
        };

        // Fallback used only when there is no render context (pre-auth paths);
        // reproduces the historical flat row + plain header.
        $legacyRows = function (array $pageAreas) use ($allAreasMode): array {
            $out = [];
            foreach ($pageAreas as $idx => $area) {
                $tagWidth = $allAreasMode ? 16 : 20;
                $row = $this->renderEchoAreaSelectionLine(
                    $idx + 1,
                    (string)substr($area['tag'] ?? '', 0, $tagWidth),
                    (string)substr($area['domain'] ?? '', 0, 10),
                    (string)substr($area['description'] ?? '', 0, 38),
                    $allAreasMode,
                    !empty($area['subscribed'])
                );
                if (method_exists($this->server, 'encodeForTerminal')) {
                    $row = $this->server->encodeForTerminal($row);
                }
                $out[] = $row;
            }

            return $out;
        };

        $listOptions = [];

        if ($ctx !== null) {
            $denseList   = $buildList($areas, $page);
            $composed    = DenseListView::compose($denseList, $ctx);
            $listTitle   = $composed['title'];
            $listRows    = $composed['rows'];
            $headerLines = $composed['headerLines'];

            if ($areas === []) {
                $headerLines[] = $ctx->colorize(
                    $ctx->encodeForTerminal($this->server->t(
                        'ui.terminalserver.echomail.areas_no_results',
                        'No areas match your search.',
                        [],
                        $locale
                    )),
                    TelnetUtils::ANSI_YELLOW
                );
            }

            $rebuildFn = function (array &$s) use ($buildList, $areas, $page): array {
                $ctx = $this->server->getRenderContext();
                $ctx->setGeometry((int)($s['cols'] ?? 80), (int)($s['rows'] ?? 24));
                $c = DenseListView::compose($buildList($areas, $page), $ctx);

                return ['rows' => $c['rows'], 'title' => $c['title'], 'header_lines' => $c['headerLines']];
            };

            // Authored M2 frame — optional presentation over the exact same
            // structured list. A missing / invalid / non-fitting surface theme,
            // or a non-80x24 / mono terminal, transparently yields to the dense
            // renderer above (runSelectableList owns selection/paging/dispatch).
            $surface = NavigationThemeConfig::loadSurface('echoareas')->theme();
            if ($surface !== null && $surface->isEnabled() && $surface->schema === NavigationTheme::COMPOSITION_SCHEMA) {
                $statusLines = $this->echoareaStatusLines(
                    $locale, count($allAreas), $page, $totalPages, $searchFilter, $allAreasMode, $newscan
                );
                $describe = function (DenseListRow $r): string {
                    $tag  = trim((string)($r->cells['tag'] ?? ''));
                    $net  = trim((string)($r->cells['net'] ?? ''));
                    $desc = trim((string)($r->cells['desc'] ?? ''));
                    $id   = $net !== '' ? "{$tag}@{$net}" : $tag;

                    return $desc !== '' ? "{$id}  -  {$desc}" : $id;
                };
                $themedView = new ThemedDenseListView($denseList, $surface, $statusLines, $describe);
                $listOptions['frame_renderer'] = function (int $selected, int $cols, int $rows, array $statusBar) use ($themedView): bool {
                    $ctx = $this->server->getRenderContext();
                    if ($ctx === null) {
                        return false;
                    }
                    $ctx->setGeometry($cols, $rows);

                    return $themedView->tryRender($ctx, $selected, $statusBar);
                };
            }
        } else {
            $header = str_replace(['{page}', '{total}'], [$page, $totalPages], $title);
            if ($searchFilter !== null) {
                $header .= ' - ' . $context;
            }
            $listTitle   = TelnetUtils::colorize($header, TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD);
            $listRows    = $legacyRows($areas);
            $headerLines = [];
            if ($listRows === []) {
                $listRows = [TelnetUtils::colorize(
                    $this->server->t('ui.terminalserver.echomail.areas_no_results', 'No areas match your search.', [], $locale),
                    TelnetUtils::ANSI_YELLOW
                )];
            }
            $rebuildFn = function (array &$s) use ($legacyRows, $areas, $listTitle): array {
                return ['rows' => $legacyRows($areas), 'title' => $listTitle];
            };
        }

        $result = $shell->showSelectableList(
            $conn, $state,
            $listTitle, $listRows, $page, $totalPages, 0,
            $statusBar, $extraKeys, $rebuildFn,
            ['header_lines' => $headerLines] + $listOptions,
            $helpItems
        );

        switch ($result['action']) {
            case 'disconnect':
            case 'quit':
                return ['action' => 'quit', 'page' => $page];

            case 'next':
                if ($page < $totalPages) {
                    $page++;
                    if ($onPageChange) { ($onPageChange)($page); }
                }
                return ['action' => 'redraw', 'page' => $page];

            case 'prev':
                if ($page > 1) {
                    $page--;
                    if ($onPageChange) { ($onPageChange)($page); }
                }
                return ['action' => 'redraw', 'page' => $page];

            case 'select':
                $index = $result['index'];
                if (isset($areas[$index])) {
                    return ['action' => 'select', 'area' => $areas[$index], 'page' => $page];
                }
                return ['action' => 'redraw', 'page' => $page];

            case 'allareas':
                return ['action' => 'allareas', 'page' => $page];

            case 'unsubscribe':
                $index = $result['index'];
                if (isset($areas[$index])) {
                    return ['action' => 'unsubscribe', 'area' => $areas[$index], 'page' => $page];
                }
                return ['action' => 'redraw', 'page' => $page];

            case 'interests':
                return ['action' => 'interests', 'page' => $page];

            case 'ignorerules':
                return ['action' => 'ignorerules', 'page' => $page];

            case 'filter':
                TelnetUtils::writeLine($conn, '');
                TelnetUtils::safeWrite($conn, $this->server->t(
                    'ui.terminalserver.echomail.areas_search_prompt', 'Search: ', [], $locale
                ));
                $term = $this->server->readLineWithIdleCheck($conn, $state);
                if ($term === null) {
                    return ['action' => 'quit', 'page' => $page];
                }
                $term = trim($term);
                return ['action' => 'filter', 'filter' => ($term !== '' ? $term : null), 'page' => 1];

            case 'clearfilter':
                return ['action' => 'filter', 'filter' => null, 'page' => 1];

            case 'search':
                return ['action' => 'search', 'page' => $page];

            default:
                return ['action' => 'redraw', 'page' => $page];
        }
    }

    /**
     * Build secondary echoarea-list help items for the Ctrl-K overlay.
     *
     * @return array<int, array{key:string,label:string}>
     */
    private function buildEchoareaHelpItems(string $locale, bool $showInterestKey, bool $hasSearchFilter, bool $allAreasMode): array
    {
        $items = [
            ['key' => '/', 'label' => $this->server->t('ui.terminalserver.echomail.help_filter_areas', 'Filter areas', [], $locale)],
            ['key' => 'S', 'label' => $this->server->t('ui.terminalserver.echomail.help_search_areas', 'Search areas', [], $locale)],
            ['key' => 'A', 'label' => $this->server->t(
                'ui.terminalserver.echomail.help_toggle_allareas',
                $allAreasMode ? 'Switch to My Areas' : 'Switch to All Areas',
                [],
                $locale
            )],
            ['key' => 'U', 'label' => $this->server->t('ui.terminalserver.echomail.help_unsubscribe_area', 'Unsubscribe selected area', [], $locale)],
            ['key' => 'G', 'label' => $this->server->t('ui.terminalserver.echomail.help_ignore_rules',    'Manage ignore rules',        [], $locale)],
        ];

        if ($hasSearchFilter) {
            $items[] = ['key' => 'C', 'label' => $this->server->t('ui.terminalserver.echomail.help_clear_filter', 'Clear filter', [], $locale)];
        }

        if ($showInterestKey) {
            $items[] = ['key' => 'I', 'label' => $this->server->t('ui.terminalserver.echomail.help_browse_interests', 'Browse by interest', [], $locale)];
        }

        return $items;
    }

    /**
     * Display echomail message list for a specific echoarea
     *
     * Shows a list of echomail messages for the selected area with options to:
     * - Navigate pages (n/p)
     * - Read messages by number
     * - Compose new messages (c)
     * - Reply to messages (r)
     * - Quit (q)
     *
     * @param resource $conn Socket connection to client
     * @param array $state Terminal state array (cols, rows, etc.)
     * @param string $session Session token for authentication
     * @param string $tag Echoarea tag
     * @param string $domain Echoarea domain
     * @return void
     */
    public function showMessages($conn, array &$state, string $session, string $tag, string $domain): void
    {
        $area          = $this->formatEchoareaIdentifier($tag, $domain);
        $this->server->logAction($state['username'] ?? 'unknown', "Echomail: read message list for {$area}");
        $savedState    = $this->loadSavedListState((int)($state['user_id'] ?? 0));
        $positions     = $savedState['positions'];
        $sort          = $savedState['sort'];
        $areaPosition  = $positions[$area] ?? null;
        $page          = max(1, (int)($areaPosition['page'] ?? 1));
        $perPage       = MailUtils::getMessagesPerPage($state);
        $selectedIndex = 0;
        $selectedMessageId = isset($areaPosition['selected_message_id'])
            ? (int)$areaPosition['selected_message_id']
            : null;
        if ($selectedMessageId !== null && $selectedMessageId < 1) {
            $selectedMessageId = null;
        }
        $selectedMessageIds = [];

        while (true) {
            [$messages, $totalPages, $meta] = $this->fetchMessagesPage($session, $area, $page, $perPage, $sort, (int)($state['user_id'] ?? 0));

            if (!$messages) {
                if ($page > 1 && $totalPages > 0) {
                    $page = min($page, $totalPages);
                    $selectedIndex = 0;
                    $selectedMessageId = null;
                    continue;
                }
                // Area is empty — show the message list UI anyway so the user can compose.
                $totalPages = 1;
            }

            if ($selectedMessageId !== null) {
                $restoredIndex = $this->findMessageIndexById($messages, $selectedMessageId);
                if ($restoredIndex !== null) {
                    $selectedIndex = $restoredIndex;
                }
                $selectedMessageId = null;
            }

            if ($selectedIndex < 0 || $selectedIndex >= count($messages)) {
                $selectedIndex = 0;
            }

            $title  = TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.echomail.messages_header', 'Echomail: {area} (page {page}/{total})', ['area' => $area, 'page' => $page, 'total' => $totalPages], $state['locale']),
                TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD
            );
            $shell = TerminalShellFactory::create($this->server, $state);

            $listOptions = [
                'multiSelect' => true,
                'toggleKey' => ' ',
                'selectedMessageIds' => $selectedMessageIds,
            ];
            $frame = $this->echomailListFrameRenderer(
                $state, $area, $tag, $domain, $messages, $meta, $sort, $page, $totalPages, $selectedMessageIds
            );
            if ($frame !== null) {
                $listOptions['frame_renderer'] = $frame;
            }

            $result = $shell->showMessageList(
                $conn, $state, $title, $messages, $page, $totalPages, $selectedIndex,
                ['m' => 'mark_selected_read', 'o' => 'order', 's' => 'search'],
                [
                    ['text' => 'S', 'color' => TelnetUtils::ANSI_RED],
                    ['text' => ' Search', 'color' => TelnetUtils::ANSI_BLUE],
                ],
                $listOptions,
                [
                    ['key' => 'Space', 'label' => $this->server->t('ui.terminalserver.list.help_toggle_selection', 'Toggle selection', [], $state['locale'])],
                    ['key' => 'M', 'label' => $this->server->t('ui.terminalserver.echomail.mark_selected_status', 'Mark Read', [], $state['locale'])],
                    ['key' => 'O', 'label' => $this->server->t('ui.terminalserver.echomail.sort_title', 'Sort Order', [], $state['locale'])],
                ]
            );
            $selectedIndex = $result['selectedIndex'];
            $currentSelectedId = isset($messages[$selectedIndex]['id']) ? (int)$messages[$selectedIndex]['id'] : null;
            $this->saveEchomailState((int)($state['user_id'] ?? 0), $positions, $area, $page, $currentSelectedId, $sort);

            switch ($result['action']) {
                case 'disconnect':
                    return;
                case 'quit':
                    return;
                case 'prev':
                    $page--;
                    $selectedIndex = 0;
                    break;
                case 'next':
                    $page++;
                    $selectedIndex = 0;
                    break;
                case 'compose':
                    $this->compose($conn, $state, $session, $area, null);
                    break;
                case 'read':
                    [$page, $selectedIndex] = $this->displayMessage($conn, $state, $session, $area, $page, $perPage, $totalPages, $result['index'], $sort);
                    break;
                case 'toggle_select':
                    $messageId = isset($messages[$result['index']]['id']) ? (int)$messages[$result['index']]['id'] : 0;
                    if ($messageId > 0) {
                        if (in_array($messageId, $selectedMessageIds, true)) {
                            $selectedMessageIds = array_values(array_filter(
                                $selectedMessageIds,
                                static fn(int $id): bool => $id !== $messageId
                            ));
                        } else {
                            $selectedMessageIds[] = $messageId;
                        }
                    }
                    break;
                case 'mark_selected_read':
                    $selectedCount = count($selectedMessageIds);
                    if ($selectedCount === 0) {
                        $shell->showAlert(
                            $conn,
                            $state,
                            $this->server->t('ui.terminalserver.echomail.mark_selected_title', 'Mark Selected Read', [], $state['locale']),
                            $this->server->t('ui.terminalserver.echomail.mark_selected_none', 'No messages are selected.', [], $state['locale']),
                            'error'
                        );
                        break;
                    }
                    $choice = $shell->showConfirmDialog(
                        $conn,
                        $state,
                        $this->server->t('ui.terminalserver.echomail.mark_selected_title', 'Mark Selected Read', [], $state['locale']),
                        $this->server->t('ui.terminalserver.echomail.mark_selected_prompt', 'Mark {count} selected message(s) as read?', ['count' => $selectedCount], $state['locale']),
                        [
                            'y' => $this->server->t('ui.terminalserver.server.confirm_yes', 'Confirm', [], $state['locale']),
                            'n' => $this->server->t('ui.terminalserver.server.confirm_no', 'Cancel', [], $state['locale']),
                        ],
                        'n'
                    );
                    if ($choice === 'y') {
                        $markResult = $this->markSelectedMessagesRead($session, $selectedMessageIds, $state['locale'], $state['csrf_token'] ?? null);
                        $shell->showAlert(
                            $conn,
                            $state,
                            $this->server->t('ui.terminalserver.echomail.mark_selected_title', 'Mark Selected Read', [], $state['locale']),
                            $markResult['message'],
                            $markResult['success'] ? 'info' : 'error'
                        );
                        if ($markResult['success']) {
                            $selectedMessageIds = [];
                            $this->server->logAction($state['username'] ?? 'unknown', "Echomail: marked {$selectedCount} selected messages read in {$area}");
                        }
                    }
                    break;
                case 'order':
                    $newSort = $this->promptForSort($conn, $state, $sort, $title, $messages, $selectedIndex);
                    if ($newSort !== $sort) {
                        $sort = $newSort;
                        $this->saveEchomailState((int)($state['user_id'] ?? 0), $positions, $area, $page, $currentSelectedId, $sort);
                    }
                    break;
                case 'search':
                    $this->searchInArea($conn, $state, $session, $area);
                    break;
            }
        }
    }

    /**
     * Compose new echomail or reply to existing message
     *
     * Prompts user for recipient name, subject, and message body.
     * If replying, pre-fills recipient info and quotes original message.
     *
     * @param resource $conn Socket connection to client
     * @param array $state Terminal state array (cols, rows, etc.)
     * @param string $session Session token for authentication
     * @param string $area Echoarea tag@domain
     * @param array|null $reply Reply data from original message (null for new message)
     * @return void
     */
    public function compose($conn, array &$state, string $session, string $area, ?array $reply = null): void
    {
        $hasReplyContext = $reply !== null;
        $reply = $reply ?? [];
        $composeMode = $reply['compose_mode'] ?? ($hasReplyContext ? 'reply' : 'new');
        $isReply = $composeMode === 'reply';
        $isForward = $composeMode === 'forward';
        $action = match ($composeMode) {
            'reply'   => "Echomail: composing reply to msg #{$reply['id']} in {$area}",
            'forward' => "Echomail: forwarding msg #{$reply['id']} to {$area}",
            default   => "Echomail: composing new message in {$area}",
        };
        $this->server->logAction($state['username'] ?? 'unknown', $action);
        $currentDraftId = 0;
        $draftToken = bin2hex(random_bytes(8));
        $selectedTagline = '';
        $crossPostAreas = [];
        $initialText = '';

        if ($isReply) {
            $originalBody = $reply['message_text'] ?? '';
            $originalAuthor = $reply['from_name'] ?? 'Unknown';
            if ($originalBody !== '') {
                $initialText = MailUtils::quoteMessage($originalBody, $originalAuthor, $state);
            }
        } elseif ($isForward) {
            $originalBody   = $reply['message_text'] ?? '';
            $originalAuthor = (string)($reply['from_name'] ?? 'Unknown');
            $originalArea   = (string)($reply['_original_area'] ?? '');
            $forwardHeader  = $originalArea !== ''
                ? '--- Forwarded from ' . $originalArea . ' by ' . $originalAuthor . ' ---'
                : '--- Forwarded message from ' . $originalAuthor . ' ---';
            $initialText = $forwardHeader;
            if ($originalBody !== '') {
                $initialText .= "\n\n" . MailUtils::quoteMessage($originalBody, $originalAuthor, $state);
            }
        }

        $existingDrafts = MailUtils::getDrafts($this->apiBase, $session, 'echomail');
        if (!$isReply && !$isForward && !empty($existingDrafts)) {
            $shell = TerminalShellFactory::create($this->server, $state);
            while (true) {
                $choice = $shell->showConfirmDialog(
                    $conn,
                    $state,
                    $this->server->t('ui.terminalserver.compose.drafts_prompt_title', 'Drafts Found', [], $state['locale']),
                    $this->server->t('ui.terminalserver.compose.drafts_prompt_message', 'Resume a saved draft or start a new message?', [], $state['locale']),
                    [
                        'r' => $this->server->t('ui.terminalserver.compose.resume_draft', 'Resume Draft', [], $state['locale']),
                        'n' => $this->server->t('ui.terminalserver.compose.new_message', 'New Message', [], $state['locale']),
                        'c' => $this->server->t('ui.terminalserver.compose.cancel_compose', 'Cancel', [], $state['locale']),
                    ],
                    'n'
                );

                if ($choice === 'c') {
                    return;
                }
                if ($choice === 'n') {
                    break;
                }

                $shell = TerminalShellFactory::create($this->server, $state);
                $picked = MailUtils::pickDraft(
                    $conn,
                    $state,
                    $this->server,
                    $shell,
                    $this->apiBase,
                    $session,
                    'echomail',
                    $state['csrf_token'] ?? null
                );
                if ($picked === null) {
                    return;
                }
                if (($picked['action'] ?? '') !== 'resume' || !is_array($picked['draft'] ?? null)) {
                    continue;
                }

                $draft = $picked['draft'];
                $currentDraftId = (int)($draft['id'] ?? 0);
                $area = (string)($draft['echoarea'] ?? $area);
                $initialText = (string)($draft['message_text'] ?? $initialText);
                if (is_array($draft['meta'] ?? null)) {
                    $selectedTagline = (string)($draft['meta']['tagline'] ?? $selectedTagline);
                    $crossPostAreas = is_array($draft['meta']['cross_post_areas'] ?? null) ? array_values($draft['meta']['cross_post_areas']) : $crossPostAreas;
                    $draftToken = (string)($draft['meta']['terminal_draft_token'] ?? $draftToken);
                }
                if (!empty($draft['reply_to_id'])) {
                    $reply['id'] = (int)$draft['reply_to_id'];
                }
                $reply['from_name'] = (string)($draft['to_name'] ?? ($reply['from_name'] ?? 'All'));
                $reply['subject'] = (string)($draft['subject'] ?? ($reply['subject'] ?? ''));
                break;
            }
        }

        TelnetUtils::safeWrite($conn, "\033[2J\033[H");
        TelnetUtils::writeLine($conn, TelnetUtils::colorize($this->server->t('ui.terminalserver.echomail.compose_title', '=== Compose Echomail ===', [], $state['locale']), TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD));
        TelnetUtils::writeLine($conn, TelnetUtils::colorize($this->server->t('ui.terminalserver.echomail.area_label', 'Area: {area}', ['area' => $area], $state['locale']), TelnetUtils::ANSI_MAGENTA));

        if (empty($reply['id'])) {
            $bbsConfig    = \BinktermPHP\BbsConfig::getConfig();
            $maxCrossPost = (int)($bbsConfig['max_cross_post_areas'] ?? 5);
            if ($maxCrossPost >= 2 && $currentDraftId === 0) {
                $cpAnswer = $this->server->prompt(
                    $conn, $state,
                    TelnetUtils::colorize($this->server->t('ui.terminalserver.echomail.crosspost_prompt', 'Cross-post to other areas? [y/N]: ', [], $state['locale']), TelnetUtils::ANSI_CYAN),
                    true
                );
                if ($cpAnswer === null) {
                    return;
                }
                if (strtolower(trim($cpAnswer)) === 'y') {
                    $picked = $this->pickCrossPostAreas($conn, $state, $session, $area, $maxCrossPost);
                    if ($picked === null) {
                        return;
                    }
                    $crossPostAreas = $picked;
                }
            }
        }

        TelnetUtils::writeLine($conn, '');

        if ($isReply && !empty($reply['id'])) {
            $detail = $this->detailService()->echomailDetail($area, (int) $reply['id'], ((int) ($state['user_id'] ?? 0)) ?: null);
            if (($detail['status'] ?? 0) === 200 && !empty($detail['data']['message_text'])) {
                $reply['message_text'] = $detail['data']['message_text'];
            }
        }

        if ($isForward) {
            $toNameDefault  = 'All';
            $subjectDefault = 'Fwd: ' . MailUtils::normalizeSubject((string)($reply['subject'] ?? ''));
        } else {
            $toNameDefault  = $reply['from_name'] ?? 'All';
            $subjectDefault = $isReply ? 'Re: ' . MailUtils::normalizeSubject((string)($reply['subject'] ?? '')) : '';
        }
        if ($currentDraftId > 0) {
            $toNameDefault = (string)($reply['from_name'] ?? $toNameDefault);
            $subjectDefault = (string)($reply['subject'] ?? $subjectDefault);
        }

        $shell = TerminalShellFactory::create($this->server, $state);

        $toNamePrompt = TelnetUtils::colorize($this->server->t('ui.terminalserver.compose.to_name', 'To Name: ', [], $state['locale']), TelnetUtils::ANSI_CYAN);
        $toName = $shell->promptText($conn, $state, $this->server->t('ui.terminalserver.echomail.compose_title', 'Compose Echomail', [], $state['locale']), $toNamePrompt, ['prefill' => $toNameDefault, 'inline_prompt' => true]);
        if ($toName === null) {
            return;
        }
        if (trim($toName) === '') {
            if ($toNameDefault !== '') {
                $toName = $toNameDefault;
            } else {
                TelnetUtils::writeLine($conn, TelnetUtils::colorize($this->server->t('ui.terminalserver.compose.no_recipient', 'Recipient name required. Message cancelled.', [], $state['locale']), TelnetUtils::ANSI_YELLOW));
                return;
            }
        }

        $subjectPrompt = TelnetUtils::colorize($this->server->t('ui.terminalserver.compose.subject', 'Subject: ', [], $state['locale']), TelnetUtils::ANSI_CYAN);
        $subject = $shell->promptText($conn, $state, $this->server->t('ui.terminalserver.echomail.compose_title', 'Compose Echomail', [], $state['locale']), $subjectPrompt, ['prefill' => $subjectDefault, 'inline_prompt' => true]);
        if ($subject === null) {
            return;
        }
        if ($subject === '' && $subjectDefault !== '') {
            $subject = $subjectDefault;
        }

        // TelnetUtils::writeLine($conn, '');
        // TelnetUtils::writeLine($conn, TelnetUtils::colorize($this->server->t('ui.terminalserver.compose.enter_message', 'Enter your message below:', [], $state['locale']), TelnetUtils::ANSI_GREEN));

        $cols = $state['cols'] ?? 80;

        $taglines = MailUtils::getTaglines($this->apiBase, $session);
        $defaultTagline = MailUtils::getUserDefaultTagline($this->apiBase, $session);
        if (!empty($taglines)) {
            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, TelnetUtils::colorize($this->server->t('ui.terminalserver.compose.select_tagline', 'Select a tagline:', [], $state['locale']), TelnetUtils::ANSI_CYAN));
            TelnetUtils::writeLine($conn, TelnetUtils::colorize($this->server->t('ui.terminalserver.compose.no_tagline', ' 0) None', [], $state['locale']), TelnetUtils::ANSI_YELLOW));
            foreach ($taglines as $idx => $tagline) {
                TelnetUtils::writeLine($conn, sprintf(' %d) %s', $idx + 1, $tagline));
            }
            $defaultIndex = 0;
            if ($selectedTagline !== '') {
                foreach ($taglines as $idx => $tagline) {
                    if (trim($tagline) === $selectedTagline) {
                        $defaultIndex = $idx + 1;
                        break;
                    }
                }
            } elseif ($defaultTagline !== '') {
                foreach ($taglines as $idx => $tagline) {
                    if (trim($tagline) === $defaultTagline) {
                        $defaultIndex = $idx + 1;
                        break;
                    }
                }
            }
            $taglineChoices = ['0) None'];
            foreach ($taglines as $idx => $tagline) {
                $taglineChoices[] = sprintf('%d) %s', $idx + 1, $tagline);
            }
            $choiceIndex = $shell->chooseFromList(
                $conn,
                $state,
                $this->server->t('ui.terminalserver.compose.tagline_title', 'Tagline', [], $state['locale']),
                $taglineChoices,
                ['selected_index' => $defaultIndex]
            );
            if ($choiceIndex === null) {
                return;
            }
            if ($choiceIndex > 0) {
                $selectedTagline = $taglines[$choiceIndex - 1] ?? '';
            }
        }

        if ($currentDraftId === 0) {
            $signature = MailUtils::getUserSignature($this->apiBase, $session);
            $initialText = MailUtils::appendSignatureToCompose($initialText, $signature);
        }

        $saveDraftHandler = function(string $draftText) use (
            $session,
            $state,
            $area,
            $toName,
            $subject,
            $selectedTagline,
            $crossPostAreas,
            &$currentDraftId,
            $draftToken,
            $reply
        ): array {
            $payload = [
                'type' => 'echomail',
                'draft_id' => $currentDraftId > 0 ? $currentDraftId : null,
                'echoarea' => $area,
                'to_name' => $toName,
                'subject' => $subject,
                'message_text' => $draftText,
                'reply_to_id' => !empty($reply['id']) ? $reply['id'] : null,
                'meta' => [
                    'tagline' => $selectedTagline !== '' ? $selectedTagline : null,
                    'cross_post_areas' => $crossPostAreas,
                    'terminal_draft_token' => $draftToken,
                ],
            ];
            $result = MailUtils::saveDraft($this->apiBase, $session, $payload, $state['csrf_token'] ?? null);
            if (!empty($result['success']) && !empty($result['draft_id'])) {
                $currentDraftId = (int)$result['draft_id'];
            }
            return $result;
        };

        $messageText = $this->server->readMultiline($conn, $state, $cols, $initialText, [
            'save_handler' => $saveDraftHandler,
        ]);
        if ($messageText === '') {
            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, TelnetUtils::colorize($this->server->t('ui.terminalserver.compose.message_cancelled', 'Message cancelled (empty).', [], $state['locale']), TelnetUtils::ANSI_YELLOW));
            return;
        }

        $payload = [
            'type'         => 'echomail',
            'echoarea'     => $area,
            'to_name'      => $toName,
            'subject'      => $subject,
            'message_text' => $messageText,
        ];
        if (!empty($reply['id'])) {
            $payload['reply_to_id'] = $reply['id'];
        }
        if ($selectedTagline !== '') {
            $payload['tagline'] = $selectedTagline;
        }
        if (!empty($crossPostAreas)) {
            $payload['cross_post_areas'] = $crossPostAreas;
        }

        TelnetUtils::writeLine($conn, '');
        if (!empty($crossPostAreas)) {
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.echomail.crosspost_posting_to', 'Also posting to: {areas}', ['areas' => implode(', ', $crossPostAreas)], $state['locale']),
                TelnetUtils::ANSI_MAGENTA
            ));
        }
        TelnetUtils::writeLine($conn, TelnetUtils::colorize($this->server->t('ui.terminalserver.echomail.posting', 'Posting echomail...', [], $state['locale']), TelnetUtils::ANSI_CYAN));
        $result = MailUtils::sendMessage($this->apiBase, $session, $payload, $state['csrf_token'] ?? null);
        if ($result['success']) {
            if ($currentDraftId > 0) {
                MailUtils::deleteDraft($this->apiBase, $session, $currentDraftId, $state['csrf_token'] ?? null);
            }
            $this->server->logAction($state['username'] ?? 'unknown', "Echomail: posted message to {$area} subject=\"{$subject}\"");
            TelnetUtils::writeLine($conn, TelnetUtils::colorize($this->server->t('ui.terminalserver.echomail.post_success', '✓ Echomail posted successfully!', [], $state['locale']), TelnetUtils::ANSI_GREEN . TelnetUtils::ANSI_BOLD));
        } else {
            $this->server->logAction($state['username'] ?? 'unknown', "Echomail: failed to post to {$area}: " . ($result['error'] ?? 'unknown'));
            TelnetUtils::writeLine($conn, TelnetUtils::colorize($this->server->t('ui.terminalserver.echomail.post_failed', '✗ Failed to post echomail: {error}', ['error' => $result['error'] ?? 'Unknown error'], $state['locale']), TelnetUtils::ANSI_RED));
        }
        TelnetUtils::writeLine($conn, '');
        TelnetUtils::writeLine($conn, TelnetUtils::colorize($this->server->t('ui.terminalserver.server.press_any_key', 'Press any key to return...', [], $state['locale']), TelnetUtils::ANSI_YELLOW));
        $this->server->readKeyWithIdleCheck($conn, $state);
    }

    /**
     * Interactive multi-select echoarea picker for cross-posting.
     *
     * Shows subscribed areas (excluding the primary) as a checkbox list.
     * Space bar toggles each area; Enter confirms the current selection; Q skips cross-posting.
     *
     * @param resource $conn        Socket connection to client
     * @param array    &$state      Terminal state
     * @param string   $session     Session token
     * @param string   $primaryArea Primary echoarea identifier (tag@domain)
     * @param int      $maxAreas    Maximum number of additional cross-post areas allowed
     * @return array|null           Selected 'tag@domain' strings (empty = no cross-post), null on disconnect
     */
    private function pickCrossPostAreas($conn, array &$state, string $session, string $primaryArea, int $maxAreas): ?array
    {
        $locale   = $state['locale'];
        $shell    = TerminalShellFactory::create($this->server, $state);
        $response = $this->fetchEchoareas($session, true);
        $allAreas = array_values(array_filter(
            $response['data']['echoareas'] ?? [],
            fn(array $a): bool => $this->formatEchoareaIdentifier($a['tag'] ?? '', $a['domain'] ?? '') !== $primaryArea
        ));

        if (empty($allAreas)) {
            $shell->showAlert(
                $conn, $state,
                $this->server->t('ui.terminalserver.echomail.compose_title', '=== Compose Echomail ===', [], $locale),
                $this->server->t('ui.terminalserver.echomail.crosspost_no_areas', 'No other subscribed areas available.', [], $locale),
                'info'
            );
            return [];
        }

        $items = [];
        foreach ($allAreas as $a) {
            $tag    = str_pad(substr($a['tag']    ?? '', 0, 20), 20);
            $domain = str_pad(substr($a['domain'] ?? '', 0, 10), 10);
            $desc   = substr($a['description'] ?? '', 0, 30);
            $items[] = "{$tag}  {$domain}  {$desc}";
        }

        $titleFn = function (int $count) use ($maxAreas, $locale): string {
            return $this->server->t(
                'ui.terminalserver.echomail.crosspost_header',
                'Cross-post ({count}/{max} selected):',
                ['count' => $count, 'max' => $maxAreas],
                $locale
            );
        };
        $redrawFn = static function (array &$dialogState) use ($conn): void {
            unset($dialogState);
            TelnetUtils::safeWrite($conn, "\033[2J\033[H");
        };

        $result = $shell->showCheckboxListDialog(
            $conn, $state,
            $titleFn,
            $items,
            [],
            $maxAreas,
            $this->server->t('ui.terminalserver.echomail.crosspost_at_limit', 'Cross-post limit ({max}) reached.', ['max' => $maxAreas], $locale),
            $this->server->t('ui.terminalserver.echomail.crosspost_help_confirm', 'Confirm and continue', [], $locale),
            $this->server->t('ui.terminalserver.echomail.crosspost_help_skip', 'Skip cross-posting', [], $locale),
            $redrawFn
        );

        if ($result === null) {
            return null;
        }
        if ($result['action'] === 'quit') {
            return [];
        }

        // Map selected indices back to tag@domain strings
        $selectedTags = [];
        foreach ($result['selected'] as $idx) {
            $area = $allAreas[$idx] ?? null;
            if ($area !== null) {
                $selectedTags[] = $this->formatEchoareaIdentifier($area['tag'] ?? '', $area['domain'] ?? '');
            }
        }
        return $selectedTags;
    }

    /**
     * Display a single echomail message with reply option
     *
     * Shows message subject, sender, recipient, and body with pagination.
     * Offers option to reply or return to message list.
     *
     * @param resource $conn Socket connection to client
     * @param array $state Terminal state array (cols, rows, etc.)
     * @param string $session Session token for authentication
     * @param array $msg Message summary data
     * @param int $id Message ID for fetching full details
     * @param string $area Echoarea tag@domain
     * @return void
     */
    private function displayMessage($conn, array &$state, string $session, string $area, int $page, int $perPage, int $totalPages, int $index, string $sort): array
    {
        $shell = TerminalShellFactory::create($this->server, $state);
        while (true) {
            [$messages, $totalPages] = $this->fetchMessagesPage($session, $area, $page, $perPage, $sort, (int)($state['user_id'] ?? 0));
            $msg = $messages[$index] ?? null;
            if (!$msg) {
                return [$page, 0];
            }
            $id = $msg['id'] ?? null;
            if (!$id) {
                return [$page, $index];
            }

            $this->server->logAction($state['username'] ?? 'unknown', "Echomail: read message #{$id} in {$area}");
            // GHSA-4225: sanitize body + kludges (stripNonDisplayAnsi delegates
            // to TerminalTextSanitizer::sanitize) before the art-fidelity layer.
            $detail       = $this->detailService()->echomailDetail($area, (int) $id, ((int) ($state['user_id'] ?? 0)) ?: null);
            $body         = TerminalMarkupRenderer::stripNonDisplayAnsi($detail['data']['message_text'] ?? '');
            $markupFormat = $detail['data']['markup_format'] ?? null;
            $artFormat    = \BinktermPHP\ArtFormatDetector::detectArtFormat(
                $body,
                $detail['data']['message_charset'] ?? null
            );
            $rawKludges   = TerminalMarkupRenderer::stripNonDisplayAnsi(($detail['data']['kludge_lines'] ?? '') . "\n" . ($detail['data']['bottom_kludges'] ?? ''));
            $kludgeLines  = TerminalMarkupRenderer::extractKludgeLines($rawKludges);
            $kludgeLines  = array_map(fn(string $line): string => $this->server->encodeForTerminal($line), $kludgeLines);
            $imageRefs    = TerminalMarkupRenderer::extractImageRefs((string)($markupFormat ?? ''), $body);
            $isSaved      = (bool)($detail['data']['is_saved'] ?? false);

            $fromName    = $msg['from_name'] ?? 'Unknown';
            $fromAddress = $msg['from_address'] ?? '';

            $sbProfile   = TelnetUtils::getDefaultStyleProfile()['status_bar'];
            $keyColor    = $sbProfile['key']   ?? TelnetUtils::ANSI_RED;
            $lblColor    = $sbProfile['label'] ?? TelnetUtils::ANSI_BLUE;

            // Closure that rebuilds all layout-dependent view components from current $state.
            // Called once on open and again whenever the terminal is resized.
            $buildView = function(array $s) use ($msg, $body, $markupFormat, $artFormat, $area, $fromName, $fromAddress, $imageRefs, $keyColor, $lblColor): array {
                $cols     = $s['cols'] ?? 80;
                $width    = max(10, $cols - 2);
                $charset  = $this->server->getTerminalCharset();

                $segments = [
                    ['text' => 'U/D',          'color' => $keyColor],
                    ['text' => ' Scroll  ',    'color' => $lblColor],
                    ['text' => 'L/R',          'color' => $keyColor],
                    ['text' => ' Prev/Next  ', 'color' => $lblColor],
                    ['text' => 'R',            'color' => $keyColor],
                    ['text' => ' Reply  ',     'color' => $lblColor],
                    ['text' => 'F',            'color' => $keyColor],
                    ['text' => ' ' . $this->server->t('ui.terminalserver.echomail.status_forward', 'Fwd', [], $s['locale'] ?? 'en') . '  ', 'color' => $lblColor],
                    ['text' => 'Ctrl-K',       'color' => $keyColor],
                    ['text' => ' Help  ',      'color' => $lblColor],
                    ['text' => 'Q',            'color' => $keyColor],
                    ['text' => ' Quit',        'color' => $lblColor],
                ];

                $safeBody = TerminalMarkupRenderer::stripNonDisplayAnsi($body);
                if ($markupFormat !== null) {
                    $wrappedLines = TerminalMarkupRenderer::render($markupFormat, $body, $width);
                } elseif ($artFormat !== null) {
                    // ANSI artwork: keep authored rows, clip (never reflow) to the
                    // full terminal width less one guard column.
                    $wrappedLines = TelnetUtils::clipArtLines($safeBody, max(10, $cols - 1));
                } else {
                    $wrappedLines = TelnetUtils::wrapTextLines($safeBody, $width);
                }
                $wrappedLines = array_map(fn(string $line): string => $this->server->encodeForTerminal($line), $wrappedLines);

                return [
                    'headerLines'  => TelnetUtils::buildCompactMessageHeader($width, [
                        'from'         => $fromName,
                        'from_address' => $fromAddress,
                        'to'           => $msg['to_name'] ?? 'All',
                        'area'         => $area,
                        'date'         => TelnetUtils::formatUserDate($msg['date_written'] ?? '', $s, false),
                        'subject'      => $msg['subject'] ?? 'Message',
                    ], $charset),
                    'wrappedLines' => $wrappedLines,
                    'statusLine'   => TelnetUtils::buildStatusBar($segments, $width),
                ];
            };

            $apiBase   = $this->apiBase;
            $server    = $this->server;
            $imageFn   = !empty($imageRefs)
                ? static function(int $idx) use ($conn, &$state, $server, $imageRefs, $apiBase): void {
                    TelnetUtils::showSixelImageViewer($conn, $state, $server, $imageRefs[$idx], count($imageRefs), $apiBase);
                }
                : null;

            $view   = $buildView($state);

            $prevRepaintFn = $state['repaint_fn'] ?? null;
            $connRef       = $conn;
            $state['repaint_fn'] = function(array &$s) use ($connRef, $buildView): void {
                $v = $buildView($s);
                TelnetUtils::renderFullScreen($connRef, $v['headerLines'], $v['wrappedLines'], $v['statusLine'], $s['rows'] ?? 24);
            };

            $locale = $state['locale'] ?? 'en';
            $helpItems = [
                ['key' => 'PgUp / PgDn', 'label' => $this->server->t('ui.terminalserver.message.help_page',      'Scroll one page',             [], $locale)],
                ['key' => 'H',           'label' => $this->server->t('ui.terminalserver.message.help_headers',   'View message headers',         [], $locale)],
                ['key' => 'B',           'label' => $this->server->t('ui.terminalserver.echomail.help_bookmark',  'Bookmark / unsave message',    [], $locale)],
                ['key' => 'T',           'label' => $this->server->t('ui.terminalserver.echomail.help_text_dl',  'Download as .txt (ZMODEM)',    [], $locale)],
                ['key' => 'E',           'label' => $this->server->t('ui.terminalserver.echomail.help_email_fwd', 'Forward to my email address',  [], $locale)],
                ['key' => 'F',           'label' => $this->server->t('ui.terminalserver.echomail.help_forward',  'Forward message',              [], $locale)],
                ['key' => 'G',           'label' => $this->server->t('ui.terminalserver.echomail.help_ignore',   'Ignore sender',                [], $locale)],
            ];
            if (!empty($imageRefs)) {
                $helpItems[] = ['key' => 'I', 'label' => $this->server->t('ui.terminalserver.message.help_images', 'View inline image(s)', [], $locale)];
            }

            try {
                $shell = TerminalShellFactory::create($this->server, $state);
                $result = $shell->showMessageViewer(
                    $conn, $state,
                    $view['headerLines'], $view['wrappedLines'], $view['statusLine'],
                    $state['rows'] ?? 24, 0, false, $kludgeLines, $buildView,
                    $imageRefs, $imageFn, ['b' => 'save', 't' => 'download', 'e' => 'emailforward', 'f' => 'forward', 'g' => 'ignore'], $helpItems,
                    ['help_overlay' => TelnetUtils::getDefaultStyleProfile()['help_overlay']]
                );

                switch ($result['action']) {
                    case 'quit':
                        return [$page, $index];
                    case 'prev':
                        if ($index > 0) { $index--; break; }
                        if ($page > 1)  { $page--; $index = max(0, $perPage - 1); }
                        break;
                    case 'next':
                        if ($index < count($messages) - 1) { $index++; break; }
                        if ($page < $totalPages)            { $page++; $index = 0; }
                        break;
                    case 'reply':
                        TelnetUtils::safeWrite($conn, "\033[2J\033[H");
                        $this->compose($conn, $state, $session, $area, $detail['data'] ?? $msg);
                        TelnetUtils::setCursorVisible($conn, true);
                        return [$page, $index];
                    case 'forward':
                        $this->forwardMessage($conn, $state, $session, $area, $msg, $detail['data'] ?? $msg);
                        return [$page, $index];
                    case 'save':
                        $csrfToken = $state['csrf_token'] ?? null;
                        if ($isSaved) {
                            TelnetUtils::apiRequest($this->apiBase, 'DELETE', '/api/messages/echomail/' . $id . '/save', null, $session, 3, $csrfToken);
                            $confirmMsg = 'Message removed from saved.';
                        } else {
                            TelnetUtils::apiRequest($this->apiBase, 'POST', '/api/messages/echomail/' . $id . '/save', null, $session, 3, $csrfToken);
                            $confirmMsg = 'Message saved.';
                        }
                        $isSaved = !$isSaved;
                        $detail['data']['is_saved'] = $isSaved;
                        $shell->showAlert($conn, $state, 'Bookmark', $confirmMsg, 'info');
                        break;
                    case 'download':
                        $this->downloadAsText($conn, $state, $session, (int)$id, $msg['subject'] ?? 'message');
                        break;
                    case 'emailforward':
                        $csrfToken = $state['csrf_token'] ?? null;
                        $shell->showWorkingOverlay($conn, $state, 'Forwarding message to email...');
                        $fwdResult = TelnetUtils::apiRequest($this->apiBase, 'POST', '/api/messages/echomail/' . $id . '/forward-email', null, $session, 3, $csrfToken);
                        if ($fwdResult['status'] === 200) {
                            $shell->showAlert($conn, $state, 'Email Forward', 'Forwarded to your email address.', 'info');
                        } else {
                            $errMsg = $fwdResult['data']['error'] ?? 'Failed to forward message.';
                            $shell->showAlert($conn, $state, 'Email Forward', $errMsg, 'error');
                        }
                        break;
                    case 'ignore':
                        $this->quickIgnoreFromMessage($conn, $state, $session, $fromName, $fromAddress, $msg['subject'] ?? '');
                        break;
                }
            } finally {
                $state['repaint_fn'] = $prevRepaintFn;
            }
        }
    }

    /**
     * Present a sub-menu to create an ignore rule from the currently viewed message.
     *
     * @param resource $conn
     */
    private function quickIgnoreFromMessage($conn, array &$state, string $session, string $fromName, string $fromAddress, string $subject): void
    {
        $locale    = $state['locale'] ?? 'en';
        $shell     = TerminalShellFactory::create($this->server, $state);
        $title     = $this->server->t('ui.terminalserver.echomail.ignore_title', 'Ignore', [], $locale);
        $cancelKey = $this->server->t('ui.terminalserver.server.cancel', 'Cancel', [], $locale);

        $hasAddress = $fromAddress !== '';
        if ($hasAddress) {
            $options = [
                '1' => $this->server->t('ui.terminalserver.echomail.ignore_by_name',    'By sender name: {name}',                    ['name' => $fromName],                          $locale),
                '2' => $this->server->t('ui.terminalserver.echomail.ignore_by_address', 'Name + FTN address: {name} <{address}>',    ['name' => $fromName, 'address' => $fromAddress], $locale),
                '3' => $this->server->t('ui.terminalserver.echomail.ignore_by_subject', 'Subject keyword (name + address)',           [],                                             $locale),
                'q' => $cancelKey,
            ];
        } else {
            $options = [
                '1' => $this->server->t('ui.terminalserver.echomail.ignore_by_name',    'By sender name: {name}', ['name' => $fromName], $locale),
                '2' => $this->server->t('ui.terminalserver.echomail.ignore_by_subject', 'Subject keyword',        [],                   $locale),
                'q' => $cancelKey,
            ];
        }

        $choice = $shell->showConfirmDialog($conn, $state, $title, '', $options, 'q');

        $senderName    = $fromName;
        $senderAddress = '';
        $subjectKw     = '';

        if ($choice === 'q' || $choice === null) {
            return;
        }

        if ($hasAddress) {
            if ($choice === '1') {
                $confirm = $shell->showConfirmDialog(
                    $conn, $state, $title,
                    $this->server->t('ui.terminalserver.echomail.ignore_confirm_name', 'Ignore all messages from {name}?', ['name' => $fromName], $locale),
                    [
                        'y' => $this->server->t('ui.terminalserver.server.confirm_yes', 'Confirm', [], $locale),
                        'n' => $cancelKey,
                    ],
                    'n'
                );
                if ($confirm !== 'y') { return; }
            } elseif ($choice === '2') {
                $senderAddress = $fromAddress;
                $confirm = $shell->showConfirmDialog(
                    $conn, $state, $title,
                    $this->server->t('ui.terminalserver.echomail.ignore_confirm_address', 'Ignore {name} at {address}?', ['name' => $fromName, 'address' => $fromAddress], $locale),
                    [
                        'y' => $this->server->t('ui.terminalserver.server.confirm_yes', 'Confirm', [], $locale),
                        'n' => $cancelKey,
                    ],
                    'n'
                );
                if ($confirm !== 'y') { return; }
            } elseif ($choice === '3') {
                $senderAddress = $fromAddress;
                $kw = $shell->promptText(
                    $conn,
                    $state,
                    $this->server->t('ui.terminalserver.echomail.ignore_title', 'Ignore', [], $locale),
                    $this->server->t('ui.terminalserver.echomail.ignore_subject_prompt', 'Keyword to ignore:', [], $locale),
                    ['prefill' => $subject]
                );
                if ($kw === null || trim($kw) === '') { return; }
                $subjectKw = trim($kw);
            } else {
                return;
            }
        } else {
            if ($choice === '1') {
                $confirm = $shell->showConfirmDialog(
                    $conn, $state, $title,
                    $this->server->t('ui.terminalserver.echomail.ignore_confirm_name', 'Ignore all messages from {name}?', ['name' => $fromName], $locale),
                    [
                        'y' => $this->server->t('ui.terminalserver.server.confirm_yes', 'Confirm', [], $locale),
                        'n' => $cancelKey,
                    ],
                    'n'
                );
                if ($confirm !== 'y') { return; }
            } elseif ($choice === '2') {
                $kw = $shell->promptText(
                    $conn,
                    $state,
                    $this->server->t('ui.terminalserver.echomail.ignore_title', 'Ignore', [], $locale),
                    $this->server->t('ui.terminalserver.echomail.ignore_subject_prompt', 'Keyword to ignore:', [], $locale),
                    ['prefill' => $subject]
                );
                if ($kw === null || trim($kw) === '') { return; }
                $subjectKw = trim($kw);
            } else {
                return;
            }
        }

        $csrfToken = $state['csrf_token'] ?? null;
        $res = TelnetUtils::apiRequest(
            $this->apiBase, 'POST', '/api/messages/echomail/ignore-rules',
            ['sender_name' => $senderName, 'sender_address' => $senderAddress, 'subject_contains' => $subjectKw],
            $session, 3, $csrfToken
        );

        if (!empty($res['data']['success'])) {
            $shell->showAlert(
                $conn, $state, $title,
                $this->server->t('ui.terminalserver.echomail.ignore_saved', 'Ignore rule saved.', [], $locale),
                'info'
            );
        } else {
            $shell->showAlert(
                $conn, $state, $title,
                $this->server->t('ui.terminalserver.echomail.ignore_failed', 'Failed to save ignore rule.', [], $locale),
                'error'
            );
        }
    }

    /**
     * Display a paginated list of the user's echomail ignore rules with delete support.
     *
     * Accessible from the echoarea list via the G key.
     *
     * @param resource $conn
     */
    private function showIgnoreRules($conn, array &$state, string $session): void
    {
        $locale  = $state['locale'] ?? 'en';
        $perPage = MailUtils::getMessagesPerPage($state);
        $page    = 1;
        $selectedIndex = 0;

        while (true) {
            $res   = TelnetUtils::apiRequest($this->apiBase, 'GET', '/api/user/echomail-ignore-rules', null, $session);
            $rules = $res['data']['rules'] ?? [];

            $totalCount = count($rules);
            $totalPages = max(1, (int)ceil($totalCount / $perPage));
            $page       = max(1, min($page, $totalPages));
            $offset     = ($page - 1) * $perPage;
            $pageRules  = array_slice($rules, $offset, $perPage);

            $titleText = $this->server->t(
                'ui.terminalserver.echomail.ignore_rules_title',
                'Ignore Rules (page {page}/{total}):',
                ['page' => $page, 'total' => $totalPages],
                $locale
            );
            $encodedTitle = method_exists($this->server, 'encodeForTerminal')
                ? $this->server->encodeForTerminal(TelnetUtils::colorize($titleText, TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD))
                : TelnetUtils::colorize($titleText, TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD);

            $buildRows = function (array $ruleSet) use ($locale): array {
                if (empty($ruleSet)) {
                    return [TelnetUtils::colorize(
                        $this->server->t('ui.terminalserver.echomail.ignore_rules_none', 'No ignore rules defined.', [], $locale),
                        TelnetUtils::ANSI_YELLOW
                    )];
                }
                $rows = [];
                foreach ($ruleSet as $idx => $rule) {
                    $label = ($idx + 1) . '. ' . ($rule['sender_name'] ?? '');
                    if (!empty($rule['sender_address'])) {
                        $label .= ' <' . $rule['sender_address'] . '>';
                    }
                    if (!empty($rule['subject_contains'])) {
                        $label .= ' [subj: ' . $rule['subject_contains'] . ']';
                    }
                    if (method_exists($this->server, 'encodeForTerminal')) {
                        $label = $this->server->encodeForTerminal($label);
                    }
                    $rows[] = $label;
                }
                return $rows;
            };

            $rows = $buildRows($pageRules);
            $server = $this->server;
            $rebuildFn = static function (array &$s) use ($pageRules, $server, $encodedTitle, $locale): array {
                $newRows = [];
                if (empty($pageRules)) {
                    $newRows = [TelnetUtils::colorize(
                        $server->t('ui.terminalserver.echomail.ignore_rules_none', 'No ignore rules defined.', [], $locale),
                        TelnetUtils::ANSI_YELLOW
                    )];
                } else {
                    foreach ($pageRules as $idx => $rule) {
                        $label = ($idx + 1) . '. ' . ($rule['sender_name'] ?? '');
                        if (!empty($rule['sender_address'])) {
                            $label .= ' <' . $rule['sender_address'] . '>';
                        }
                        if (!empty($rule['subject_contains'])) {
                            $label .= ' [subj: ' . $rule['subject_contains'] . ']';
                        }
                        if (method_exists($server, 'encodeForTerminal')) {
                            $label = $server->encodeForTerminal($label);
                        }
                        $newRows[] = $label;
                    }
                }
                return ['rows' => $newRows, 'title' => $encodedTitle];
            };

            $statusBar = [
                ['text' => 'U/D',    'color' => TelnetUtils::ANSI_RED],
                ['text' => ' Move  ', 'color' => TelnetUtils::ANSI_BLUE],
                ['text' => 'L/R',    'color' => TelnetUtils::ANSI_RED],
                ['text' => ' Page  ', 'color' => TelnetUtils::ANSI_BLUE],
                ['text' => 'Del',    'color' => TelnetUtils::ANSI_RED],
                ['text' => ' Delete  ', 'color' => TelnetUtils::ANSI_BLUE],
                ['text' => 'Q',      'color' => TelnetUtils::ANSI_RED],
                ['text' => ' Quit',  'color' => TelnetUtils::ANSI_BLUE],
            ];

            $shell = TerminalShellFactory::create($this->server, $state);
            $result = $shell->showSelectableList(
                $conn, $state,
                $encodedTitle, $rows, $page, $totalPages, $selectedIndex,
                $statusBar, ['d' => 'delete'], $rebuildFn
            );
            $selectedIndex = $result['selectedIndex'] ?? 0;

            switch ($result['action']) {
                case 'disconnect':
                case 'quit':
                    return;

                case 'prev':
                    if ($page > 1) { $page--; $selectedIndex = 0; }
                    break;

                case 'next':
                    if ($page < $totalPages) { $page++; $selectedIndex = 0; }
                    break;

                case 'select':
                case 'delete':
                    $ruleIdx = $offset + ($result['index'] ?? $selectedIndex);
                    $rule    = $rules[$ruleIdx] ?? null;
                    if (!$rule) { break; }

                    $ruleName = $rule['sender_name'] ?? '?';
                    $choice   = $shell->showConfirmDialog(
                        $conn, $state,
                        $this->server->t('ui.terminalserver.echomail.ignore_rules_delete_title', 'Delete Rule', [], $locale),
                        $this->server->t('ui.terminalserver.echomail.ignore_rules_delete_confirm', 'Delete rule for {name}?', ['name' => $ruleName], $locale),
                        [
                            'y' => $this->server->t('ui.terminalserver.server.confirm_yes', 'Confirm', [], $locale),
                            'n' => $this->server->t('ui.terminalserver.server.confirm_no',  'Cancel',  [], $locale),
                        ],
                        'n'
                    );

                    if ($choice === 'y') {
                        $csrfToken = $state['csrf_token'] ?? null;
                        $delRes    = TelnetUtils::apiRequest(
                            $this->apiBase, 'DELETE',
                            '/api/user/echomail-ignore-rules/' . (int)$rule['id'],
                            null, $session, 3, $csrfToken
                        );
                        if (($delRes['status'] ?? 0) === 200) {
                            $shell->showAlert(
                                $conn, $state,
                                $this->server->t('ui.terminalserver.echomail.ignore_rules_delete_title', 'Delete Rule', [], $locale),
                                $this->server->t('ui.terminalserver.echomail.ignore_rules_deleted', 'Ignore rule deleted.', [], $locale),
                                'info'
                            );
                            if ($selectedIndex > 0 && $selectedIndex >= count($pageRules) - 1) {
                                $selectedIndex--;
                            }
                        } else {
                            $shell->showAlert(
                                $conn, $state,
                                $this->server->t('ui.terminalserver.echomail.ignore_rules_delete_title', 'Delete Rule', [], $locale),
                                $this->server->t('ui.terminalserver.echomail.ignore_rules_delete_failed', 'Failed to delete ignore rule.', [], $locale),
                                'error'
                            );
                        }
                    }
                    break;
            }
        }
    }

    /**
     * Offer a forward-type picker (Echomail or Netmail) then delegate to the appropriate flow.
     *
     * @param resource $conn
     * @param array    $msg       Message summary row (from the list page)
     * @param array    $msgDetail Full message data (from the detail API response)
     */
    private function forwardMessage($conn, array &$state, string $session, string $area, array $msg, array $msgDetail): void
    {
        $locale = $state['locale'] ?? 'en';
        $shell = TerminalShellFactory::create($this->server, $state);

        $choice = $shell->showConfirmDialog(
            $conn, $state,
            $this->server->t('ui.terminalserver.echomail.forward_type_title', 'Forward As', [], $locale),
            $this->server->t('ui.terminalserver.echomail.forward_type_prompt', 'How would you like to forward this message?', [], $locale),
            [
                'e' => $this->server->t('ui.terminalserver.echomail.forward_type_echomail', 'Echomail (another area)', [], $locale),
                'n' => $this->server->t('ui.terminalserver.echomail.forward_type_netmail',  'Netmail (FTN address)',   [], $locale),
                'q' => $this->server->t('ui.terminalserver.server.cancel', 'Cancel', [], $locale),
            ],
            'e'
        );

        if ($choice === 'e') {
            $this->forwardToEchoarea($conn, $state, $session, $area, $msg, $msgDetail);
        } elseif ($choice === 'n') {
            $this->forwardToNetmail($conn, $state, $session, $area, $msg, $msgDetail);
        }
    }

    /**
     * Forward an echomail message to a user-selected subscribed echoarea.
     *
     * @param resource $conn
     * @param array    $msg       Message summary row
     * @param array    $msgDetail Full message data
     */
    private function forwardToEchoarea($conn, array &$state, string $session, string $sourceArea, array $msg, array $msgDetail): void
    {
        $locale  = $state['locale'] ?? 'en';
        $perPage = MailUtils::getMessagesPerPage($state);
        $shell   = TerminalShellFactory::create($this->server, $state);

        $response = $this->fetchEchoareas($session, true);
        $allAreas = array_values(array_filter(
            $response['data']['echoareas'] ?? [],
            fn(array $a): bool => $this->formatEchoareaIdentifier($a['tag'] ?? '', $a['domain'] ?? '') !== $sourceArea
        ));

        if (empty($allAreas)) {
            $shell->showAlert(
                $conn, $state,
                $this->server->t('ui.terminalserver.echomail.forward_type_title', 'Forward As', [], $locale),
                $this->server->t('ui.terminalserver.echomail.forward_no_areas', 'No other subscribed areas to forward to.', [], $locale),
                'info'
            );
            return;
        }

        $page = 1;
        while (true) {
            $result = $this->pickEchoarea(
                $conn, $state, $allAreas, $page, $perPage,
                $this->server->t('ui.terminalserver.echomail.forward_pick_area_title', 'Forward to Area (page {page}/{total}):', [], $locale),
                false, null, null, false, [], $shell
            );
            $page = $result['page'];

            if ($result['action'] === 'quit') {
                return;
            }
            if ($result['action'] === 'select') {
                $destArea    = $this->formatEchoareaIdentifier($result['area']['tag'] ?? '', $result['area']['domain'] ?? '');
                $forwardData = [
                    'compose_mode'  => 'forward',
                    'id'            => $msg['id'] ?? null,
                    'subject'       => $msg['subject'] ?? '',
                    'from_name'     => $msg['from_name'] ?? 'Unknown',
                    'message_text'  => $msgDetail['message_text'] ?? '',
                    '_original_area' => $sourceArea,
                ];
                TelnetUtils::safeWrite($conn, "\033[2J\033[H");
                $this->compose($conn, $state, $session, $destArea, $forwardData);
                TelnetUtils::setCursorVisible($conn, true);
                return;
            }
        }
    }

    /**
     * Forward an echomail message as a netmail to an FTN address chosen by the user.
     *
     * @param resource $conn
     * @param array    $msg       Message summary row
     * @param array    $msgDetail Full message data
     */
    private function forwardToNetmail($conn, array &$state, string $session, string $sourceArea, array $msg, array $msgDetail): void
    {
        $forwardData = array_merge($msg, $msgDetail);
        $forwardData['compose_mode']        = 'forward';
        $forwardData['_forwarded_from_area'] = $sourceArea;
        unset($forwardData['replyto_name'], $forwardData['replyto_address']);

        TelnetUtils::safeWrite($conn, "\033[2J\033[H");
        (new NetmailHandler($this->server, $this->apiBase))->compose($conn, $state, $session, $forwardData);
        TelnetUtils::setCursorVisible($conn, true);
    }

    /**
     * Download the current echomail message as a plain-text .txt file via ZMODEM.
     *
     * @param resource $conn
     * @param array    $state
     * @param string   $session
     * @param int      $id      Message ID
     * @param string   $subject Message subject (used to derive the filename)
     */
    private function downloadAsText($conn, array &$state, string $session, int $id, string $subject): void
    {
        $locale = $state['locale'] ?? 'en';

        if (!ZmodemTransfer::canDownload()) {
            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t(
                    'ui.terminalserver.files.transfer_unavailable',
                    'ZMODEM disabled: install lrzsz (sz/rz) on the server to enable transfers.',
                    [],
                    $locale
                ),
                TelnetUtils::ANSI_YELLOW
            ));
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.server.press_any_key', 'Press any key to return...', [], $locale),
                TelnetUtils::ANSI_DIM
            ));
            $this->server->readKeyWithIdleCheck($conn, $state);
            return;
        }

        $response = TelnetUtils::apiRequest($this->apiBase, 'GET', '/api/messages/echomail/' . $id . '/download', null, $session);
        $content  = $response['data']['raw'] ?? null;

        if ($response['status'] !== 200 || $content === null || $content === '') {
            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t(
                    'ui.terminalserver.netmail.text_download_fetch_failed',
                    'Could not fetch message text.',
                    [],
                    $locale
                ),
                TelnetUtils::ANSI_RED
            ));
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.server.press_any_key', 'Press any key to return...', [], $locale),
                TelnetUtils::ANSI_DIM
            ));
            $this->server->readKeyWithIdleCheck($conn, $state);
            return;
        }

        $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $subject);
        $safeName = trim((string)$safeName, '_');
        if ($safeName === '') {
            $safeName = 'message';
        }
        $filename = $safeName . '.txt';
        $tmpPath  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'binkterm_' . uniqid() . '_' . $filename;

        if (file_put_contents($tmpPath, $content) === false) {
            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t(
                    'ui.terminalserver.netmail.text_download_fetch_failed',
                    'Could not fetch message text.',
                    [],
                    $locale
                ),
                TelnetUtils::ANSI_RED
            ));
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.server.press_any_key', 'Press any key to return...', [], $locale),
                TelnetUtils::ANSI_DIM
            ));
            $this->server->readKeyWithIdleCheck($conn, $state);
            return;
        }

        TelnetUtils::writeLine($conn, '');
        TelnetUtils::writeLine($conn, TelnetUtils::colorize(
            $this->server->t('ui.terminalserver.files.download_starting', 'Starting ZMODEM download: {name}', ['name' => $filename], $locale),
            TelnetUtils::ANSI_CYAN
        ));
        TelnetUtils::writeLine($conn, TelnetUtils::colorize(
            $this->server->t('ui.terminalserver.files.download_hint', 'Start ZMODEM receive in your terminal now...', [], $locale),
            TelnetUtils::ANSI_DIM
        ));
        sleep(1);

        $ok = ZmodemTransfer::send($conn, $tmpPath, $filename, !($state['isSsh'] ?? false));
        @unlink($tmpPath);

        TelnetUtils::writeLine($conn, '');
        if ($ok) {
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.files.download_done', 'Transfer complete.', [], $locale),
                TelnetUtils::ANSI_GREEN
            ));
        } else {
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.files.download_failed', 'Transfer failed or was cancelled.', [], $locale),
                TelnetUtils::ANSI_RED
            ));
        }

        TelnetUtils::writeLine($conn, TelnetUtils::colorize(
            $this->server->t('ui.terminalserver.server.press_any_key', 'Press any key to return...', [], $locale),
            TelnetUtils::ANSI_DIM
        ));
        $this->server->readKeyWithIdleCheck($conn, $state);
    }

    /**
     * Build the authored M2 frame for the Echomail message list, or null when
     * the `echomsgs` surface theme is absent / invalid / disabled. Presentation
     * only: the flat message-list runtime keeps every keystroke, and this frame
     * renders ZERO read-state — the list still marks nothing.
     *
     * @param array<int,array<string,mixed>> $messages the current page
     * @param array{unread:int,total:int}    $meta     from fetchMessagesPage()
     * @param list<int>                      $selectedMessageIds  multi-select set
     */
    private function echomailListFrameRenderer(
        array $state,
        string $area,
        string $tag,
        string $domain,
        array $messages,
        array $meta,
        string $sort,
        int $page,
        int $totalPages,
        array $selectedMessageIds
    ): ?callable {
        $surface = NavigationThemeConfig::loadSurface('echomsgs')->theme();
        if ($surface === null || !$surface->isEnabled() || $surface->schema !== NavigationTheme::COMPOSITION_SCHEMA) {
            return null;
        }

        $locale  = (string) ($state['locale'] ?? 'en');
        $markSet = array_fill_keys(array_map('intval', $selectedMessageIds), true);
        $utf8    = $this->server->getTerminalCharset() === 'utf8';
        $thread  = $utf8 ? "\u{203A}" : '>';

        $columns = [
            new DenseListColumn('from', 18),
            new DenseListColumn('subj', 0),
            new DenseListColumn('date', 16, DenseListColumn::ALIGN_RIGHT),
        ];

        $rows = [];
        foreach ($messages as $msg) {
            $id       = (int) ($msg['id'] ?? 0);
            $isRead   = !empty($msg['is_read']);
            $isMarked = isset($markSet[$id]);
            $rows[] = new DenseListRow(
                [
                    'from' => \BinktermPHP\TerminalTextSanitizer::sanitize((string) ($msg['from_name'] ?? 'Unknown')),
                    'subj' => \BinktermPHP\TerminalTextSanitizer::sanitize((string) ($msg['subject'] ?? '(no subject)')),
                    'date' => TelnetUtils::formatUserDate((string) ($msg['date_written'] ?? ''), $state, false),
                ],
                $msg,
                $isMarked ? '*' : ' ',
                $isMarked ? (TelnetUtils::ANSI_GREEN . TelnetUtils::ANSI_BOLD) : null,
                !empty($msg['reply_to_id']) ? $thread : null,
                $isRead ? null : TelnetUtils::ANSI_BOLD,
            );
        }

        $sortLabel = [
            'date_desc' => $this->server->t('ui.terminalserver.echomail.sort_newest', 'Newest', [], $locale),
            'date_asc'  => $this->server->t('ui.terminalserver.echomail.sort_oldest', 'Oldest', [], $locale),
            'subject'   => $this->server->t('ui.terminalserver.echomail.sort_subject', 'Subject', [], $locale),
            'author'    => $this->server->t('ui.terminalserver.echomail.sort_author', 'Author', [], $locale),
        ][$this->normalizeSort($sort)] ?? '';

        $identifier = $domain !== '' ? "{$tag} @ {$domain}" : $tag;
        $parts = [$identifier];
        $unread = (int) ($meta['unread'] ?? 0);
        $total  = (int) ($meta['total'] ?? count($messages));
        $parts[] = $unread > 0
            ? $this->server->t('ui.terminalserver.echomail.msglist.unread_of', '{unread} unread of {total}', ['unread' => $unread, 'total' => $total], $locale)
            : $this->server->t('ui.terminalserver.echomail.msglist.all_read', 'all {total} read', ['total' => $total], $locale);
        if ($sortLabel !== '') {
            $parts[] = $sortLabel;
        }
        $parts[] = $this->server->t('ui.terminalserver.list.dense_page_indicator', 'Page {page}/{total}', ['page' => $page, 'total' => max(1, $totalPages)], $locale);
        $statusLines = [implode('   ' . "\u{00B7}" . '   ', $parts)];

        $describe = function (DenseListRow $r): string {
            $subj = trim((string) ($r->cells['subj'] ?? ''));
            $msg  = is_array($r->value) ? $r->value : [];
            $from = trim((string) ($msg['from_name'] ?? ''));
            $addr = trim((string) ($msg['from_address'] ?? ''));
            $who  = $addr !== '' ? "{$from} <{$addr}>" : $from;
            $mid  = trim((string) ($msg['message_id'] ?? ''));
            $line = $subj !== '' ? $subj : '(no subject)';
            if ($who !== '') {
                $line .= '  ' . "\u{00B7}" . '  ' . $who;
            }
            if ($mid !== '') {
                $line .= '  ' . "\u{00B7}" . '  ' . $mid;
            }

            return $line;
        };

        $list = new DenseList($identifier, ['Messages', 'Echomail'], $statusLines[0], $columns, $rows, $page, $totalPages);
        $view = new ThemedDenseListView($list, $surface, $statusLines, $describe);

        return function (int $selected, int $cols, int $rows, array $statusBar) use ($view): bool {
            $ctx = $this->server->getRenderContext();
            if ($ctx === null) {
                return false;
            }
            $ctx->setGeometry($cols, $rows);

            return $view->tryRender($ctx, $selected, $statusBar);
        };
    }

    /**
     * Fetch a page of echomail messages for an area.
     *
     * @return array{0:array<int,array<string,mixed>>,1:int,2:array{unread:int,total:int}}
     *         [messages, totalPages, meta]
     */
    protected function fetchMessagesPage(string $session, string $area, int $page, int $perPage, string $sort, int $userId): array
    {
        $sort = $this->normalizeSort($sort);
        [$tag, $domain] = array_pad(explode('@', $area, 2), 2, '');

        // Same work GET /api/messages/echomail/{echoarea} performs: the canonical
        // MessageHandler service (subscription check disabled, per-user page size
        // via limit=null), the terminal's own per_page slice, and the area-view
        // activity record the route writes. Calling it directly removes one
        // localhost HTTP round trip per list/reader navigation.
        $result = $this->messageService()->getEchomail(
            $tag,
            $domain,
            max(1, $page),
            null,
            $userId > 0 ? $userId : null,
            'all',
            false,
            false,
            $sort
        );
        $this->trackAreaView($userId > 0 ? $userId : null, $tag);

        $allMessages = $result['messages'] ?? [];
        $totalPages  = $result['pagination']['pages'] ?? 1;
        $messages    = array_slice($allMessages, 0, $perPage);

        // Third element: presentation state the canonical fetch already
        // computed (existing callers that destructure `[$messages, $totalPages]`
        // are unaffected). `unread` is the area's individually-unopened count
        // (message_read_status.read_at IS NULL) — NOT Newscan "new".
        $meta = [
            'unread' => (int) ($result['unreadCount'] ?? 0),
            'total'  => (int) ($result['pagination']['total'] ?? count($allMessages)),
        ];

        return [$messages, (int) $totalPages, $meta];
    }

    /**
     * Record an echoarea-view in the activity log, mirroring the side effect of
     * the GET /api/messages/echomail/{echoarea} route. Isolated so the network-
     * free read path can be exercised in tests without a DB write.
     */
    protected function trackAreaView(?int $userId, string $tag): void
    {
        \BinktermPHP\ActivityTracker::track(
            $userId,
            \BinktermPHP\ActivityTracker::TYPE_ECHOMAIL_AREA_VIEW,
            null,
            $tag
        );
    }

    /**
     * Mark a selected set of echomail messages as read for the current user.
     *
     * @param int[] $messageIds
     * @return array{success:bool,message:string}
     */
    private function markSelectedMessagesRead(string $session, array $messageIds, string $locale, ?string $csrfToken): array
    {
        $messageIds = array_values(array_unique(array_filter(array_map('intval', $messageIds), static fn(int $id): bool => $id > 0)));

        if ($messageIds === []) {
            return [
                'success' => false,
                'message' => $this->server->t('ui.terminalserver.echomail.mark_selected_none', 'No messages are selected.', [], $locale),
            ];
        }

        $result = TelnetUtils::apiRequest(
            $this->apiBase,
            'POST',
            '/api/messages/echomail/read',
            ['messageIds' => $messageIds],
            $session,
            3,
            $csrfToken
        );

        if (($result['status'] ?? 0) === 200 && !empty($result['data']['success'])) {
            return [
                'success' => true,
                'message' => $this->server->t('ui.terminalserver.echomail.mark_selected_success', 'Selected messages marked as read.', [], $locale),
            ];
        }

        return [
            'success' => false,
            'message' => $this->server->t('ui.terminalserver.echomail.mark_selected_failed', 'Failed to mark selected messages as read.', [], $locale),
        ];
    }

    /**
     * Render one echoarea option with cyan number hotkey and blue ")" accent.
     *
     * @param bool $showBadge  When true, prefix a [+] / [ ] subscription badge
     * @param bool $subscribed Used with $showBadge to choose which badge to display
     */
    private function renderEchoAreaSelectionLine(int $num, string $tag, string $domain, string $desc, bool $showBadge = false, bool $subscribed = true): string
    {
        $badge = '';
        if ($showBadge) {
            $badge = $subscribed
                ? TelnetUtils::colorize('[+]', TelnetUtils::ANSI_GREEN) . ' '
                : TelnetUtils::colorize('[ ]', TelnetUtils::ANSI_DIM) . ' ';
        }
        $tagWidth = $showBadge ? 16 : 20;
        $suffix   = sprintf(' %-' . $tagWidth . 's %-10s %s', $tag, $domain, $desc);
        return ' '
            . TelnetUtils::colorize(sprintf('%2d', $num), TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD)
            . TelnetUtils::colorize(')', TelnetUtils::ANSI_BLUE)
            . ' ' . $badge . ltrim($suffix);
    }

    /**
     * Format an echoarea identifier for terminal display and API routes.
     */
    private function formatEchoareaIdentifier(string $tag, string $domain): string
    {
        $domain = trim($domain);

        return $domain !== '' ? $tag . '@' . $domain : $tag;
    }

    /**
     * Load saved echomail browser state from user meta.
     *
     * @return array{areas_page:int, positions:array<string,array{page:int,selected_message_id:?int}>, sort:string}
     */
    private function loadSavedListState(int $userId): array
    {
        $settings = $this->mailState()->load($userId);
        $areasPage = (int)($settings['terminal_echomail_areas_page'] ?? 1);
        $sort = $this->normalizeSort(is_string($settings['terminal_echomail_sort'] ?? null) ? $settings['terminal_echomail_sort'] : null);
        $positionsRaw = $settings['terminal_echomail_positions'] ?? '';
        $positions = [];
        if (is_string($positionsRaw) && trim($positionsRaw) !== '') {
            $decoded = json_decode($positionsRaw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $area => $item) {
                    if (!is_string($area) || !is_array($item)) {
                        continue;
                    }
                    $page = max(1, (int)($item['page'] ?? 1));
                    $selected = $item['selected_message_id'] ?? null;
                    if ($selected !== null) {
                        $selected = (int)$selected;
                        if ($selected < 1) {
                            $selected = null;
                        }
                    }
                    $positions[$area] = [
                        'page' => $page,
                        'selected_message_id' => $selected,
                    ];
                }
            }
        }

        return [
            'areas_page' => max(1, $areasPage),
            'positions' => $positions,
            'sort' => $sort,
        ];
    }

    /**
     * Save echomail message-list state (per area).
     */
    private function saveEchomailState(
        int $userId,
        array &$positions,
        string $area,
        int $page,
        ?int $selectedMessageId,
        string $sort
    ): void
    {
        $positions[$area] = [
            'page' => max(1, $page),
            'selected_message_id' => ($selectedMessageId !== null && $selectedMessageId > 0) ? $selectedMessageId : null,
        ];

        $this->mailState()->save($userId, [
            'terminal_echomail_positions' => $positions,
            'terminal_echomail_sort' => $this->normalizeSort($sort),
        ]);
    }

    /**
     * Save current echoarea listing page.
     */
    private function saveEchoareasPage(int $userId, int $page): void
    {
        $this->mailState()->save($userId, ['terminal_echomail_areas_page' => max(1, $page)]);
    }

    /**
     * Find index of a message id in the current message page.
     */
    private function findMessageIndexById(array $messages, int $messageId): ?int
    {
        foreach ($messages as $idx => $msg) {
            if ((int)($msg['id'] ?? 0) === $messageId) {
                return $idx;
            }
        }

        return null;
    }

    private function normalizeSort(?string $sort): string
    {
        return in_array($sort, self::ALLOWED_SORTS, true) ? $sort : 'date_desc';
    }

    private function promptForSort($conn, array &$state, string $currentSort, string $title, array $messages, int $selectedIndex): string
    {
        $locale = $state['locale'] ?? 'en';
        $currentSort = $this->normalizeSort($currentSort);
        $shell = TerminalShellFactory::create($this->server, $state);
        $sortLabels = [
            'date_desc' => $this->server->t('ui.terminalserver.echomail.sort_newest', 'Newest', [], $locale),
            'date_asc' => $this->server->t('ui.terminalserver.echomail.sort_oldest', 'Oldest', [], $locale),
            'subject' => $this->server->t('ui.terminalserver.echomail.sort_subject', 'Subject', [], $locale),
            'author' => $this->server->t('ui.terminalserver.echomail.sort_author', 'Author', [], $locale),
        ];
        $sortKeys = [
            'date_desc' => '1',
            'date_asc' => '2',
            'subject' => '3',
            'author' => '4',
        ];
        $choiceToSort = array_flip($sortKeys);
        $redrawFn = function (array &$dialogState) use ($conn, $title, $messages, $selectedIndex): void {
            TelnetUtils::renderMessageListScreen(
                $conn,
                $dialogState,
                $this->server,
                $title,
                $messages,
                $selectedIndex,
                [
                    ['text' => 'O', 'color' => TelnetUtils::ANSI_RED],
                    ['text' => ' Sort  ', 'color' => TelnetUtils::ANSI_BLUE],
                    ['text' => 'S', 'color' => TelnetUtils::ANSI_RED],
                    ['text' => ' Search', 'color' => TelnetUtils::ANSI_BLUE],
                ]
            );
        };

        $choice = $shell->showConfirmDialog(
            $conn,
            $state,
            $this->server->t('ui.terminalserver.echomail.sort_title', 'Sort Order', [], $locale),
            $this->server->t(
                'ui.terminalserver.echomail.sort_prompt',
                'Current: {sort}',
                ['sort' => $sortLabels[$currentSort] ?? $sortLabels['date_desc']],
                $locale
            ),
            [
                '1' => $sortLabels['date_desc'],
                '2' => $sortLabels['date_asc'],
                '3' => $sortLabels['subject'],
                '4' => $sortLabels['author'],
                'q' => $this->server->t('ui.terminalserver.server.cancel', 'Cancel', [], $locale),
            ],
            $sortKeys[$currentSort] ?? '1',
            $redrawFn
        );

        if ($choice === 'q') {
            return $currentSort;
        }

        return $this->normalizeSort($choiceToSort[$choice] ?? $currentSort);
    }

}
