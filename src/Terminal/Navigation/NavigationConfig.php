<?php

namespace BinktermPHP\Terminal\Navigation;

use BinktermPHP\Config;

/**
 * Resolves the optional sysop-supplied declarative terminal navigation
 * definition and decides whether the runtime should use it.
 *
 * Zero-config behaviour is the existing menu: the declarative runtime is used
 * ONLY when the enable flag is on AND a definition file validates clean. Either
 * gate missing — a bad flag value, a missing/unreadable file, invalid JSON, an
 * unsupported schema, or any semantic error — leaves the terminal on the legacy
 * code path. Nothing here throws to its caller.
 *
 * Cache semantics: {@see load()} caches the parse result keyed by the file's
 * path + size + mtime. The telnet/SSH daemons fork one process per connection,
 * so in normal operation every session re-reads the file anyway; the mtime key
 * additionally means a non-forking daemon (single-connection / debug mode) still
 * picks up a replaced file on the next session without a restart. Editing the
 * file in place while a daemon runs is still best followed by a daemon restart
 * so all worker behaviour is consistent.
 */
final class NavigationConfig
{
    public const DEFAULT_FILENAME = 'terminal_navigation.json';
    public const ENABLE_ENV       = 'TERMINAL_NAV_RUNTIME';
    public const PATH_ENV         = 'TERMINAL_NAV_CONFIG';

    private static ?NavigationLoadResult $cached = null;
    private static ?string $cacheKey = null;

    public static function path(): string
    {
        try {
            $configured = (string) Config::env(self::PATH_ENV, '');
        } catch (\Throwable) {
            $configured = '';
        }
        if ($configured !== '') {
            return $configured;
        }

        return dirname(__DIR__, 3) . '/config/' . self::DEFAULT_FILENAME;
    }

    /**
     * Whether the sysop has switched the declarative runtime on. Does not look
     * at the file — {@see DeclarativeMenuBridge} owns the file diagnostics so a
     * missing / invalid file is logged rather than silently ignored.
     */
    public static function isFlagEnabled(): bool
    {
        try {
            $flag = strtolower(trim((string) Config::env(self::ENABLE_ENV, '')));
        } catch (\Throwable) {
            return false;
        }

        return in_array($flag, ['1', 'on', 'true', 'yes'], true);
    }

    /** True only when the flag is on AND the file exists. Never throws. */
    public static function isRuntimeEnabled(): bool
    {
        if (!self::isFlagEnabled()) {
            return false;
        }
        try {
            return is_file(self::path());
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Load + validate the configured definition. Cached, keyed on the file's
     * path/size/mtime so a replaced file is not served stale.
     */
    public static function load(): NavigationLoadResult
    {
        $path = self::path();
        $key  = self::keyFor($path);

        if (self::$cached !== null && self::$cacheKey === $key) {
            return self::$cached;
        }

        self::$cacheKey = $key;
        try {
            self::$cached = (new NavigationDefinitionLoader(TerminalActionCatalog::defaultRegistry()))
                ->fromFile($path);
        } catch (\Throwable $e) {
            self::$cached = NavigationLoadResult::failed([
                new ValidationError('load', 'could not load ' . $path . ': ' . $e->getMessage()),
            ]);
        }

        return self::$cached;
    }

    /**
     * The definition to drive the runtime with, or null to stay on the legacy
     * menu. Non-null only when the flag is on and the file validates clean.
     */
    public static function resolveDefinition(): ?NavigationDefinition
    {
        if (!self::isFlagEnabled()) {
            return null;
        }

        return self::load()->definition();
    }

    private static function keyFor(string $path): string
    {
        $stat = @stat($path);
        if ($stat === false) {
            return $path . '|absent';
        }

        return $path . '|' . $stat['size'] . '|' . $stat['mtime'];
    }

    /** Reset the process cache (tests only). */
    public static function reset(): void
    {
        self::$cached   = null;
        self::$cacheKey = null;
    }
}
