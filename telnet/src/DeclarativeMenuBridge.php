<?php

namespace BinktermPHP\TelnetServer;

use BinktermPHP\BbsConfig;
use BinktermPHP\Config;
use BinktermPHP\Terminal\Navigation\AccessContext;
use BinktermPHP\Terminal\Navigation\NavigationConfig;
use BinktermPHP\Terminal\Navigation\NavigationRuntime;
use BinktermPHP\Terminal\Navigation\NavigationScreenBuilder;
use BinktermPHP\Terminal\Navigation\NavigationScreenRenderer;
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
            $definition = NavigationConfig::resolveDefinition();
            if ($definition === null) {
                $result = NavigationConfig::load();
                if (NavigationConfig::isRuntimeEnabled() && !$result->isOk()) {
                    $this->server->logInfo('Declarative navigation disabled — invalid definition: ' . $result->errorSummary());
                }

                return false;
            }

            $ctx = $this->server->getRenderContext();
            if ($ctx === null) {
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
            );

            $runtime = new NavigationRuntime($definition, $registry, $builder, new NavigationScreenRenderer());

            $readToken = function () use ($conn, &$state): array {
                [$key, $timedOut, $disconnect] = $this->server->readKeyWithTimeout($conn, $state, 30000);
                $ctx = $this->server->getRenderContext();
                $ctx?->setGeometry((int) ($state['cols'] ?? 80), (int) ($state['rows'] ?? 24));

                return [$key, $timedOut, $disconnect];
            };

            $this->server->logInfo('Declarative navigation runtime: definition "' . $definition->id . '"');
            $runtime->run($readToken, $ctx, $access, null, $locale);

            return true;
        } catch (\Throwable $e) {
            $this->server->logInfo('Declarative navigation failed, falling back to legacy menu: ' . $e->getMessage());

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
            }
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
