<?php

namespace BinktermPHP\TelnetServer;

use BinktermPHP\Auth;
use BinktermPHP\BbsConfig;
use BinktermPHP\Config;
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
            $builder = new NavigationScreenBuilder(
                $registry,
                fn (?string $key, string $fallback, string $loc) => $key === null || $key === ''
                    ? $fallback
                    : $this->server->t($key, $fallback, [], $loc),
                $this->liveBadgeResolver($state),
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
            $onActionBoundary = function () use (&$state): void {
                $state['pushback'] = '';
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
