<?php

namespace BinktermPHP\TelnetServer;

use BinktermPHP\Messaging\ActivityService;
use BinktermPHP\Messaging\SylcHydrator;
use BinktermPHP\Messaging\TelnetSylcPresenter;

/**
 * Telnet presentation for "Since Your Last Call" (Messaging Evolution
 * Slice 4). Consumes {@see ActivityService}/{@see ActivityPlan} directly —
 * the same shared, transport-agnostic model the Web dashboard card
 * (`SylcPulse`) consumes — via a small terminal-specific presentation
 * reducer, {@see TelnetSylcPresenter}. No SQL of its own, no read/visit
 * state mutation of any kind.
 *
 * Two surfaces:
 *  - {@see sidebarLine()} — the compact, always-visible main-menu dashboard
 *    widget line. Called on every dashboard render; returns null (row
 *    omitted) for a first-ever tracked visit or a quiet return, per
 *    /root/L33TEST_Messaging_Telnet_SYLC_Design_2026-09-14.md §9/§10.
 *  - {@see show()} — the optional, explicitly-triggered detail panel.
 *    Non-blocking display (`renderPanel()`), dismissed by a single keypress,
 *    never a traversal/reading flow like {@see NewscanHandler}.
 */
final class SylcHandler
{
    private ?ActivityService $service = null;
    private ?SylcHydrator $hydrator = null;

    public function __construct(
        private readonly BbsSession $server,
    ) {
    }

    private function service(): ActivityService
    {
        return $this->service ??= new ActivityService();
    }

    private function hydrator(): SylcHydrator
    {
        return $this->hydrator ??= new SylcHydrator();
    }

    /** @return array{user_id:int,is_admin:bool} */
    private function user(array $state): array
    {
        return [
            'user_id' => (int) ($state['user_id'] ?? 0),
            'is_admin' => !empty($state['is_admin']),
        ];
    }

    /**
     * One combined, read-only summary computed from a single
     * {@see ActivityService::plan()} call, feeding both:
     *  - `line` — the compact sidebar widget row, or null when there is
     *    nothing to show (first-ever visit, OR a quiet return — the sidebar
     *    omits the row rather than acknowledging "nothing happened", unlike
     *    the Web card; see the design doc §10 for the rationale).
     *  - `available` — whether the optional detail key/menu entry should be
     *    offered at all. True whenever the caller has previous-visit
     *    history, even if that history is quiet (pressing the key then
     *    honestly shows "Quiet since your last call." — Decision #2). False
     *    only for a genuine first-ever tracked visit (Decision #3: no fake
     *    previous-call history, no detail entry at all to open).
     *
     * Called once per dashboard-stats refresh cycle (same cadence as
     * {@see MailUtils::getDashboardStats()}), not on every keystroke/redraw.
     *
     * @return array{available:bool, line:?string}
     */
    public function summary(array $state): array
    {
        $userId = (int) ($state['user_id'] ?? 0);
        if ($userId <= 0) {
            return ['available' => false, 'line' => null];
        }
        $locale = (string) ($state['locale'] ?? 'en');
        try {
            $plan = $this->service()->plan($this->user($state));
        } catch (\Throwable $e) {
            $this->server->logInfo('SYLC summary unavailable: ' . $e->getMessage());
            return ['available' => false, 'line' => null];
        }

        $line = TelnetSylcPresenter::sidebarLine(
            $plan,
            fn (string $key, string $fallback, array $params = []): string =>
                $this->server->t($key, $fallback, $params, $locale)
        );

        return ['available' => $plan->hasPreviousVisit, 'line' => $line];
    }

    /**
     * The optional detail view. Read-only: opening it does not mark any
     * Netmail/Echomail as read and does not touch the visit boundary —
     * unlike {@see NewscanHandler}, this is a display-only summary, not a
     * traversal into the actual messages (the caller reads those normally,
     * via the existing Netmail/Echomail menu entries).
     */
    public function show($conn, array &$state, string $session): void
    {
        $locale = (string) ($state['locale'] ?? 'en');
        $t = fn (string $key, string $fallback, array $params = []): string =>
            $this->server->t($key, $fallback, $params, $locale);
        $title = $t('ui.terminalserver.sylc.title', 'Since Your Last Call', []);

        $shell = TerminalShellFactory::create($this->server, $state);
        $plan = $this->service()->plan($this->user($state));

        if (!$plan->hasPreviousVisit) {
            // Not normally reachable — the menu entry is omitted for a
            // first-ever tracked visit — but stay correct if called anyway.
            $shell->showAlert(
                $conn,
                $state,
                $title,
                $t('ui.terminalserver.sylc.no_previous_visit', 'No previous call on record yet.', []),
                'info'
            );
            return;
        }

        $netmailIds = array_slice($plan->personal['netmailIds'] ?? [], -TelnetSylcPresenter::MAX_PERSONAL_ROWS);
        $replyIds = array_slice($plan->personal['replyIds'] ?? [], -TelnetSylcPresenter::MAX_PERSONAL_ROWS);
        $participatedIds = array_slice($plan->personal['participatedIds'] ?? [], -TelnetSylcPresenter::MAX_PERSONAL_ROWS);
        $netmailRows = $netmailIds ? $this->hydrator()->hydrateNetmail($netmailIds) : [];
        $replyRows = $replyIds ? $this->hydrator()->hydrateEchomailReplies($replyIds) : [];
        $participatedRows = $participatedIds ? $this->hydrator()->hydrateEchomailParticipated($participatedIds) : [];

        $detail = TelnetSylcPresenter::detail($plan, $netmailRows, $replyRows, $t, $participatedRows);

        if ($detail['quiet']) {
            $shell->showAlert(
                $conn,
                $state,
                $title,
                $t('ui.terminalserver.sylc.quiet', 'Quiet since your last call.', []),
                'info'
            );
            return;
        }

        $lines = [];
        // Personal always precedes ambient in this array — personal
        // relevance outranks ambient volume (human-approved principle).
        if ($detail['personal'] !== []) {
            $lines[] = $t('ui.terminalserver.sylc.personal_heading', 'For You:', []);
            foreach ($detail['personal'] as $item) {
                $tag = match ($item['type']) {
                    'netmail' => $t('ui.terminalserver.sylc.type_netmail', 'N', []),
                    'reply'   => $t('ui.terminalserver.sylc.type_reply', 'R', []),
                    default   => $t('ui.terminalserver.sylc.type_thread', 'T', []),
                };
                $lines[] = '  [' . $tag . '] ' . $item['label'];
            }
            if ($detail['personalMore']) {
                $lines[] = '  ' . $t('ui.terminalserver.sylc.more', '(more may be available)', []);
            }
        }

        if ($detail['ambient'] !== []) {
            if ($lines !== []) {
                $lines[] = '';
            }
            $lines[] = $t('ui.terminalserver.sylc.ambient_heading', 'Around the Board:', []);
            $areaParts = [];
            foreach ($detail['ambient'] as $area) {
                $areaParts[] = $area['tag'] . '(' . $area['count'] . ')';
            }
            $areaLine = '  ' . implode(', ', $areaParts);
            if ($detail['ambientMore']) {
                $areaLine .= ' ' . $t('ui.terminalserver.sylc.more', '(more may be available)', []);
            }
            $lines[] = $areaLine;
        }

        $shell->renderPanel($conn, $state, $title, $lines);
        $shell->promptKey(
            $conn,
            $state,
            $title,
            $t('ui.terminalserver.sylc.continue_prompt', 'Press a key to continue.', []),
            ['q'],
            [
                'labels' => ['q' => $t('ui.terminalserver.sylc.action_continue', 'Continue', [])],
                'default' => 'q',
            ]
        );
    }
}
