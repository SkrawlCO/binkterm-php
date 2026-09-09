<?php

namespace BinktermPHP\TelnetServer;

use BinktermPHP\Newscan\NewscanArea;
use BinktermPHP\Newscan\NewscanPlan;
use BinktermPHP\Newscan\UnifiedNewscanService;

/**
 * The unified terminal newscan — "what's new for me?".
 *
 * It composes canonical read state through {@see UnifiedNewscanService} (which
 * writes nothing), presents a summary, then walks the caller through their new
 * messages: unread netmail first, then each subscribed echomail area with new
 * messages, in a deterministic order. Each message is opened through the same
 * single-message seam the normal readers use, so opening marks it read exactly
 * as it would from the echomail / netmail lists — this handler adds no read
 * state and no shadow pointer.
 *
 * Bulletins are surfaced as a count with a hand-off to the existing unread
 * bulletins screen; they are not traversed message-by-message. QWK is not a
 * source (it is offline transport for the same messages).
 *
 * Skipping an area or the whole scan marks nothing read. "Catch up" on an area
 * is the one deliberate mark-read action, and it goes through the same
 * canonical bulk-read endpoint the web uses.
 */
final class NewscanHandler
{
    private ?UnifiedNewscanService $service;

    public function __construct(
        private readonly BbsSession $server,
        private readonly string $apiBase,
        private readonly NetmailHandler $netmail,
        private readonly EchomailHandler $echomail,
        private readonly BulletinsHandler $bulletins,
        ?UnifiedNewscanService $service = null,
    ) {
        // Lazy: this handler is constructed for every terminal session, but the
        // service opens a database connection and builds a MessageHandler, so it
        // is only created when the caller actually opens the scan.
        $this->service = $service;
    }

    private function service(): UnifiedNewscanService
    {
        return $this->service ??= new UnifiedNewscanService();
    }

    public function show($conn, array &$state, string $session): void
    {
        $locale = (string) ($state['locale'] ?? 'en');
        $user   = [
            'user_id'  => (int) ($state['user_id'] ?? 0),
            'is_admin' => !empty($state['is_admin']),
        ];

        $plan = $this->service()->plan($user);
        $this->server->logAction($state['username'] ?? 'unknown', sprintf(
            'Newscan: %d netmail, %d echomail across %d area(s), %d bulletin(s)%s',
            $plan->netmailCount(),
            $plan->echomailCount(),
            $plan->areaCount(),
            $plan->bulletinUnread,
            $plan->truncated ? ' [truncated]' : ''
        ));

        $shell = TerminalShellFactory::create($this->server, $state);
        $title = $this->server->t('ui.terminalserver.newscan.title', "What's New", [], $locale);

        if ($plan->isEmpty()) {
            $shell->showAlert(
                $conn,
                $state,
                $title,
                $this->server->t(
                    'ui.terminalserver.newscan.all_caught_up',
                    "You're all caught up - nothing new since your last visit.",
                    [],
                    $locale
                ),
                'info'
            );
            return;
        }

        while (true) {
            $summaryLines = $this->summaryLines($plan, $locale);
            $keys   = ['r', 'q'];
            $labels = [
                'r' => $this->server->t('ui.terminalserver.newscan.action_read', 'Read new', [], $locale),
                'q' => $this->server->t('ui.terminalserver.newscan.action_later', 'Later', [], $locale),
            ];
            if ($plan->bulletinUnread > 0) {
                $keys[] = 'b';
                $labels['b'] = $this->server->t('ui.terminalserver.newscan.action_bulletins', 'Bulletins', [], $locale);
            }
            if (!$plan->hasMessages()) {
                // Only bulletins are new — 'read' has nothing to do.
                $keys   = array_values(array_diff($keys, ['r']));
                unset($labels['r']);
            }

            $shell->renderPanel($conn, $state, $title, $summaryLines);
            $choice = $shell->promptKey(
                $conn,
                $state,
                $title,
                $this->server->t('ui.terminalserver.newscan.summary_prompt', 'What would you like to do?', [], $locale),
                $keys,
                [
                    'labels'    => $labels,
                    'default'   => in_array('r', $keys, true) ? 'r' : 'q',
                    'redraw_fn' => function () use ($conn, &$state, $shell, $title, $summaryLines): void {
                        $shell->renderPanel($conn, $state, $title, $summaryLines);
                    },
                ]
            );

            if ($choice === null || $choice === 'q') {
                return;
            }

            if ($choice === 'b') {
                $this->bulletins->showUnread($conn, $state, $session);
                $plan = $this->service()->plan($user);
                if ($plan->isEmpty()) {
                    return;
                }
                continue;
            }

            // 'r' — traverse the message queue.
            $outcome = $this->traverse($conn, $state, $session, $plan, $locale);
            $plan = $this->service()->plan($user);

            if ($outcome === 'quit' || $plan->isEmpty()) {
                if ($plan->isEmpty()) {
                    $shell->showAlert(
                        $conn,
                        $state,
                        $title,
                        $this->server->t('ui.terminalserver.newscan.scan_complete', 'Newscan complete - nothing else new.', [], $locale),
                        'info'
                    );
                }
                return;
            }
        }
    }

    /**
     * @return string 'quit' if the caller quit the scan, 'done' otherwise
     */
    private function traverse($conn, array &$state, string $session, NewscanPlan $plan, string $locale): string
    {
        $viewer = new TerminalMessageQueueViewer($this->netmail, $this->echomail);
        $shell  = TerminalShellFactory::create($this->server, $state);

        // --- Phase 1: netmail ---
        if ($plan->netmailIds !== []) {
            $decision = $this->phasePrompt(
                $conn,
                $state,
                $shell,
                $this->server->t('ui.terminalserver.newscan.phase_netmail', 'Netmail', [], $locale),
                [
                    $this->server->t(
                        'ui.terminalserver.newscan.phase_netmail_count',
                        '{count} new netmail message(s).',
                        ['count' => count($plan->netmailIds)],
                        $locale
                    ),
                ],
                false,
                $locale
            );
            if ($decision === 'quit') {
                return 'quit';
            }
            if ($decision === 'read') {
                $queue = array_map(
                    static fn (int $id): array => ['type' => 'netmail', 'msg' => ['id' => $id]],
                    $plan->netmailIds
                );
                if ($viewer->run($conn, $state, $session, $queue) === 'quit') {
                    return 'quit';
                }
            }
        }

        // --- Phase 2: echomail, area by area ---
        foreach ($plan->areas as $area) {
            $lines = [
                $this->server->t(
                    'ui.terminalserver.newscan.phase_area_count',
                    '{count} new message(s) in {area}.',
                    ['count' => $area->count(), 'area' => $area->identifier()],
                    $locale
                ),
            ];
            if ($area->description !== '') {
                $lines[] = $this->server->encodeForTerminal($area->description);
            }

            $decision = $this->phasePrompt(
                $conn,
                $state,
                $shell,
                $area->identifier(),
                $lines,
                true,
                $locale
            );

            if ($decision === 'quit') {
                return 'quit';
            }
            if ($decision === 'catchup') {
                $this->catchUpArea($session, $state, $area);
                continue;
            }
            if ($decision !== 'read') {
                continue; // skip
            }

            $queue = array_map(
                static fn (int $id): array => [
                    'type' => 'echomail',
                    'msg'  => ['id' => $id, 'echoarea' => $area->tag, 'echoarea_domain' => $area->domain],
                ],
                $area->messageIds
            );
            if ($viewer->run($conn, $state, $session, $queue) === 'quit') {
                return 'quit';
            }
        }

        return 'done';
    }

    /**
     * A per-phase interstitial: "N new ... [R] Read [S] Skip [Q] Quit" (plus
     * "[C] Catch up" for echomail areas).
     *
     * @return string 'read' | 'skip' | 'catchup' | 'quit'
     */
    private function phasePrompt($conn, array &$state, $shell, string $title, array $lines, bool $allowCatchUp, string $locale): string
    {
        $keys   = ['r', 's', 'q'];
        $labels = [
            'r' => $this->server->t('ui.terminalserver.newscan.action_read', 'Read new', [], $locale),
            's' => $this->server->t('ui.terminalserver.newscan.action_skip', 'Skip', [], $locale),
            'q' => $this->server->t('ui.terminalserver.newscan.action_quit', 'Quit scan', [], $locale),
        ];
        if ($allowCatchUp) {
            $keys[] = 'c';
            $labels['c'] = $this->server->t('ui.terminalserver.newscan.action_catchup', 'Catch up (mark area read)', [], $locale);
        }

        $shell->renderPanel($conn, $state, $title, $lines);
        $choice = $shell->promptKey(
            $conn,
            $state,
            $title,
            $this->server->t('ui.terminalserver.newscan.phase_prompt', 'Read these now?', [], $locale),
            $keys,
            [
                'labels'    => $labels,
                'default'   => 'r',
                'redraw_fn' => function () use ($conn, &$state, $shell, $title, $lines): void {
                    $shell->renderPanel($conn, $state, $title, $lines);
                },
            ]
        );

        return match ($choice) {
            'r'     => 'read',
            'c'     => 'catchup',
            's'     => 'skip',
            default => 'quit', // 'q' or null (disconnect / esc)
        };
    }

    /**
     * Mark every new message in one area read, through the same canonical bulk
     * endpoint the web uses (writes `message_read_status` and advances the
     * per-area `last_read_id` watermark). A deliberate caller action only.
     */
    private function catchUpArea(string $session, array $state, NewscanArea $area): void
    {
        if ($area->messageIds === []) {
            return;
        }
        TelnetUtils::apiRequest(
            $this->apiBase,
            'POST',
            '/api/messages/echomail/read',
            ['messageIds' => array_values($area->messageIds)],
            $session,
            3,
            $state['csrf_token'] ?? null
        );
    }

    /**
     * @return string[]
     */
    private function summaryLines(NewscanPlan $plan, string $locale): array
    {
        $plus = $plan->truncated ? '+' : '';
        $lines = [];

        if ($plan->netmailCount() > 0) {
            $lines[] = $this->server->t(
                'ui.terminalserver.newscan.summary_netmail',
                'Netmail    {count}{plus} new',
                ['count' => $plan->netmailCount(), 'plus' => $plus],
                $locale
            );
        }
        if ($plan->areaCount() > 0) {
            $lines[] = $this->server->t(
                'ui.terminalserver.newscan.summary_echomail',
                'Echomail   {count}{plus} new across {areas} area(s)',
                ['count' => $plan->echomailCount(), 'plus' => $plus, 'areas' => $plan->areaCount()],
                $locale
            );
        }
        if ($plan->bulletinUnread > 0) {
            $lines[] = $this->server->t(
                'ui.terminalserver.newscan.summary_bulletins',
                'Bulletins  {count} new',
                ['count' => $plan->bulletinUnread],
                $locale
            );
        }

        return $lines;
    }
}
