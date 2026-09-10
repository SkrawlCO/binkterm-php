<?php

namespace BinktermPHP\TelnetServer;

use BinktermPHP\Auth;
use BinktermPHP\BbsConfig;
use BinktermPHP\Config;
use BinktermPHP\Newscan\NewscanSnapshot;
use BinktermPHP\Newscan\TerminalNewscanLanding;
use BinktermPHP\Newscan\UnifiedNewscanService;
use BinktermPHP\Terminal\Navigation\AccessContext;
use BinktermPHP\Terminal\Navigation\NavigationConfig;
use BinktermPHP\Terminal\Navigation\NavigationRendererFactory;
use BinktermPHP\Terminal\Navigation\NavigationRuntime;
use BinktermPHP\Terminal\Navigation\NavigationScreenBuilder;
use BinktermPHP\Terminal\Navigation\TerminalActionCatalog;

/**
 * Glue between {@see BbsSession} and the generic declarative navigation runtime
 * ({@see \BinktermPHP\Terminal\Navigation}).
 *
 * It is only reached when a sysop has both provided a valid
 * `config/terminal_navigation.json` and switched `TERMINAL_NAV_RUNTIME` on;
 * otherwise {@see run()} returns false and the caller keeps the legacy menu.
 * Any error during setup or the loop is caught and also yields false, so a
 * misconfigured board still gets a working session.
 */
final class DeclarativeMenuBridge
{
    /** Lazily created only when a caller actually opens the Messages landing. */
    private ?UnifiedNewscanService $newscanService = null;

    public function __construct(private readonly BbsSession $server)
    {
    }

    /**
     * @param array<string,object> $handlers action-id => handler with show($conn,&$state,$session)
     * @return bool true if the declarative runtime ran (caller proceeds to logout)
     */
    public function run($conn, array &$state, ?string $session, array $handlers): bool
    {
        try {
            if (!NavigationConfig::isFlagEnabled()) {
                return false;
            }

            $result = NavigationConfig::load();
            if (!$result->isOk()) {
                // The sysop asked for the declarative runtime; tell them exactly
                // why it is not being used, then fall back safely.
                $this->server->logInfo(
                    'Declarative navigation NOT active (using built-in menu) — '
                    . NavigationConfig::path() . ': ' . $result->errorSummary()
                );

                return false;
            }
            $definition = $result->definition();

            $ctx = $this->server->getRenderContext();
            if ($ctx === null) {
                $this->server->logInfo('Declarative navigation NOT active — no render context on the session');

                return false;
            }

            $registry = TerminalActionCatalog::defaultRegistry();
            $this->bindActions($registry, $conn, $state, $session, $handlers);

            $featureResolver = $this->featureResolver($state);
            $baseCtx = new AccessContext(
                !empty($state['username']) && $state['username'] !== '_guest',
                !empty($state['is_admin']),
                empty($state['username']) || $state['username'] === '_guest',
                $featureResolver,
                static fn () => false,
                $this->capabilities($ctx),
            );
            $access = new AccessContext(
                $baseCtx->isAuthenticated(),
                $baseCtx->isAdmin(),
                $baseCtx->isGuest(),
                $featureResolver,
                static fn (string $a) => $registry->isAvailable($a, $baseCtx),
                $this->capabilities($ctx),
            );

            $locale  = (string) ($state['locale'] ?? 'en');

            // The authored Messages landing: one per-session snapshot of the
            // canonical newscan plan feeds both the STATUS summary and the
            // per-destination MENU badges. It is resolved lazily (only when the
            // caller actually opens Messages), reused across cursor movement,
            // and invalidated at the action boundary below so reading mail and
            // returning shows fresh counts.
            $messagesLanding = $this->messagesLanding($state);
            $presenceBadge   = $this->liveBadgeResolver($state);
            $badgeResolver   = static function (string $signal) use ($presenceBadge, $messagesLanding): ?string {
                return str_starts_with($signal, 'messages.')
                    ? ($messagesLanding['badge'])($signal)
                    : $presenceBadge($signal);
            };

            $builder = new NavigationScreenBuilder(
                $registry,
                fn (?string $key, string $fallback, string $loc) => $key === null || $key === ''
                    ? $fallback
                    : $this->server->t($key, $fallback, [], $loc),
                $badgeResolver,
                fn (string $loc): ?string => $this->server->recentCallersLine($loc, max(8, $ctx->cols() - 8)),
                $messagesLanding['summary'],
            );

            // Presentation: an optional, validated ANSI theme frames the
            // navigation for one geometry; every other case (no theme, disabled,
            // invalid, non-themed size) uses the accepted flowing renderer.
            $renderer = NavigationRendererFactory::create(fn (string $msg) => $this->server->logInfo($msg));

            $runtime = new NavigationRuntime($definition, $registry, $builder, $renderer);

            $readToken = function () use ($conn, &$state): array {
                [$key, $timedOut, $disconnect] = $this->server->readKeyWithTimeout($conn, $state, 30000);
                $ctx = $this->server->getRenderContext();
                $ctx?->setGeometry((int) ($state['cols'] ?? 80), (int) ($state['rows'] ?? 24));

                return [$key, $timedOut, $disconnect];
            };

            // Input ownership: a delegated legacy handler owns the input stream
            // while it runs. Its key readers can leave a look-ahead byte in the
            // shared pushback buffer (see BbsSession::readTelnetKeyWithTimeout);
            // once the handler returns, that byte belonged to its screen, not to
            // the R5 screen we are about to redraw. Drop it so it cannot be read
            // as an R5 keystroke (the reported "Q on a child screen logs you
            // off" bug).
            $onActionBoundary = function () use (&$state, $messagesLanding): void {
                $state['pushback'] = '';
                // A destination may have marked mail read; drop the landing
                // snapshot so the next Messages redraw reflects the new state.
                ($messagesLanding['invalidate'])();
            };

            $this->server->logInfo('Declarative navigation runtime: definition "' . $definition->id . '"');
            $runtime->run($readToken, $ctx, $access, null, $locale, null, $onActionBoundary);

            return true;
        } catch (\Throwable $e) {
            $definitionId = isset($definition) ? $definition->id : '(unresolved)';
            $this->server->logInfo(sprintf(
                'Declarative navigation runtime error (definition "%s"), falling back to built-in menu: %s: %s @ %s:%d',
                $definitionId,
                get_class($e),
                $e->getMessage(),
                basename($e->getFile()),
                $e->getLine()
            ));
            // Reset a possibly half-drawn screen so the built-in menu redraws clean.
            $this->server->getRenderContext()?->write("\033[0m\033[2J\033[H");

            return false;
        }
    }

    /**
     * @param array<string,object|callable> $handlers  handler with show($conn,&$state,$session), or a plain callable
     */
    private function bindActions($registry, $conn, array &$state, ?string $session, array $handlers): void
    {
        foreach ($handlers as $actionId => $handler) {
            if (!$registry->has($actionId)) {
                continue;
            }
            if (is_callable($handler)) {
                $registry->bind($actionId, static function () use ($handler): void {
                    $handler();
                });
                continue;
            }
            if (is_object($handler) && method_exists($handler, 'show')) {
                $registry->bind($actionId, function () use ($handler, $conn, &$state, $session): void {
                    $handler->show($conn, $state, $session);
                });
                continue;
            }
            // The caller mapped this action to something the bridge cannot bind
            // (an object with no show() entrypoint, most likely). Make it loud
            // rather than a menu item that silently does nothing.
            $this->server->logInfo(sprintf(
                'Declarative navigation: action "%s" could not be bound (%s) — its menu item will be inert',
                $actionId,
                is_object($handler) ? get_class($handler) . ' has no show()' : gettype($handler)
            ));
        }
        // 'quit' terminates the runtime directly — it needs no binding.
    }

    /**
     * The authored Messages landing's per-session state seam.
     *
     * One lazily-resolved snapshot of the canonical {@see UnifiedNewscanService}
     * plan — a pure, write-free projection ({@see TerminalNewscanLanding}) — is
     * shared by:
     *   - `summary`    the STATUS "waiting for you" block for the `messages` node
     *   - `badge`      the `messages.*` per-destination MENU annotations
     *   - `invalidate` clears the snapshot; the runtime calls this at every
     *                  action boundary so returning from a destination that may
     *                  have marked mail read shows fresh counts on the next draw
     *
     * The snapshot survives cursor movement (the resolvers are called on every
     * redraw but only recompute the plan after an invalidation or the TTL
     * backstop, never per keystroke). Guests / unauthenticated sessions get no
     * summary and no badges, and the themed screen simply renders a blank
     * STATUS. Any failure to resolve the plan is logged and also yields nothing.
     *
     * @return array{summary:callable(string,string):?array<int,string>,badge:callable(string):?string,invalidate:callable():void}
     */
    private function messagesLanding(array $state): array
    {
        $userId  = (int) ($state['user_id'] ?? 0);
        $isAdmin = !empty($state['is_admin']);
        $isGuest = empty($state['username']) || $state['username'] === '_guest';
        $locale  = (string) ($state['locale'] ?? 'en');

        if ($isGuest || $userId <= 0) {
            // Guests get no summary and no badges; the themed screen simply
            // renders a blank STATUS.
            return [
                'summary'    => static fn (string $nodeId, string $loc): ?array => null,
                'badge'      => static fn (string $signal): ?string => null,
                'invalidate' => static function (): void {
                },
            ];
        }

        // TTL is a backstop only. onActionBoundary is the authoritative refresh;
        // this just bounds staleness for a caller who sits idle on the landing
        // while new mail arrives (the runtime redraws roughly every 30s).
        $snapshot = new NewscanSnapshot(
            fn (): \BinktermPHP\Newscan\NewscanPlan => ($this->newscanService ??= new UnifiedNewscanService())
                ->plan(['user_id' => $userId, 'is_admin' => $isAdmin]),
            90
        );

        // One projection per resolved plan, shared by the summary + badge
        // resolvers so a redraw never formats twice and never re-queries. It is
        // rebuilt only when the snapshot's generation advances (an invalidation
        // or the TTL backstop) — never on cursor movement.
        $projection = null; // array{summary:list<string>,badges:array<string,string>}|null
        $projectionGen = -1;
        $ensure = function () use (&$projection, &$projectionGen, $snapshot, $locale): ?array {
            try {
                $plan = $snapshot->plan();
            } catch (\Throwable $e) {
                $this->server->logInfo('Messages landing: newscan plan unavailable — ' . $e->getMessage());

                return null;
            }
            if ($projection === null || $projectionGen !== $snapshot->generation()) {
                $t = fn (string $k, string $f, array $args = []): string => $this->server->t($k, $f, $args, $locale);
                $projection = TerminalNewscanLanding::project($plan, $t);
                $projectionGen = $snapshot->generation();
            }

            return $projection;
        };

        return [
            'summary' => static function (string $nodeId, string $loc) use ($ensure): ?array {
                if ($nodeId !== 'messages') {
                    return null;
                }
                $p = $ensure();

                return $p === null ? null : ($p['summary'] ?? null);
            },
            'badge' => static function (string $signal) use ($ensure): ?string {
                $p = $ensure();

                return $p === null ? null : ($p['badges'][$signal] ?? null);
            },
            'invalidate' => static function () use (&$projection, $snapshot): void {
                $snapshot->invalidate();
                $projection = null;
            },
        ];
    }

    /**
     * @return callable(string):bool
     */
    private function featureResolver(array $state): callable
    {
        $isAdmin = !empty($state['is_admin']);

        return static function (string $feature) use ($isAdmin): bool {
            return match ($feature) {
                'file_areas' => \BinktermPHP\FileAreaManager::isFeatureEnabled(),
                'freq'       => \BinktermPHP\Freq\FreqWebAccess::isEnabledFor($isAdmin),
                'interests'  => Config::env('ENABLE_INTERESTS') === 'true',
                'nodelist'   => true, // presence check happens in the handler
                default      => BbsConfig::isFeatureEnabled($feature),
            };
        };
    }

    /**
     * Resolver for `presentation.badge` live-context signals on the front door.
     *
     * It maps a small, board-agnostic vocabulary of platform signals to a short
     * status string, from BinktermPHP's canonical presence store (user_sessions,
     * via {@see Auth::getOnlineSessions()} — the same source as Who's Online). One
     * snapshot is shared by every signal and cached for a few seconds, so paging
     * the lightbar around the menu never touches the database and the counts
     * only move between navigation steps, not while the caller sits still.
     *
     * Recognised signals:
     *   - `callers_online`     other distinct callers active in the last 15 min
     *   - `experiences_active` other callers currently in a Crossroads Experience
     *
     * Any failure (DB down, unexpected shape) yields null for every signal, so
     * the menu simply renders without badges.
     *
     * @return callable(string):?string
     */
    private function liveBadgeResolver(array $state): callable
    {
        $selfId  = (int) ($state['user_id'] ?? 0);
        $snapshot = null;
        $takenAt  = 0;
        $ttl      = 8;

        return function (string $signal) use (&$snapshot, &$takenAt, $ttl, $selfId): ?string {
            $now = time();
            if ($snapshot === null || ($now - $takenAt) >= $ttl) {
                $snapshot = $this->presenceSnapshot($selfId);
                $takenAt  = $now;
            }

            return $snapshot[$signal] ?? null;
        };
    }

    /**
     * @return array{callers_online:?string,experiences_active:?string}
     */
    private function presenceSnapshot(int $selfId): array
    {
        $empty = ['callers_online' => null, 'experiences_active' => null];

        try {
            $rows = (new Auth())->getOnlineSessions(15);
        } catch (\Throwable $e) {
            return $empty;
        }

        $others  = [];
        $playing = [];
        foreach ($rows as $row) {
            $uid = (int) ($row['user_id'] ?? 0);
            if ($uid <= 0 || $uid === $selfId) {
                continue;
            }
            $others[$uid] = true;
            if (str_starts_with((string) ($row['public_activity'] ?? ''), 'Playing ')) {
                $playing[$uid] = true;
            }
        }

        $onlineCount  = count($others);
        $playingCount = count($playing);

        return [
            'callers_online'     => $onlineCount > 0 ? $onlineCount . ' online' : null,
            'experiences_active' => $playingCount > 0 ? $playingCount . ' playing' : null,
        ];
    }

    private function capabilities(TerminalRenderContext $ctx): array
    {
        $caps = $ctx->capabilities();

        return [
            'color' => $ctx->isColorEnabled(),
            'utf8'  => $ctx->effectiveCharset() === 'utf8',
            'sixel' => $caps->sixelSupported,
        ];
    }
}
