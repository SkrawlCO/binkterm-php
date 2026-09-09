<?php

namespace BinktermPHP\Terminal\Navigation;

use BinktermPHP\Config;

/**
 * Resolves the optional sysop-supplied declarative terminal navigation
 * definition and decides whether the runtime should use it.
 *
 * Zero-config behaviour is the existing menu: the declarative runtime is used
 * ONLY when a definition file exists AND it is explicitly switched on. Either
 * gate missing — or a definition that fails validation — leaves the terminal on
 * the legacy code path. A typo in the file can never break terminal login.
 */
final class NavigationConfig
{
    public const DEFAULT_FILENAME = 'terminal_navigation.json';
    public const ENABLE_ENV       = 'TERMINAL_NAV_RUNTIME';

    private static ?NavigationLoadResult $cached = null;
    private static bool $loaded = false;

    public static function path(): string
    {
        $configured = (string) Config::env('TERMINAL_NAV_CONFIG', '');
        if ($configured !== '') {
            return $configured;
        }

        return dirname(__DIR__, 3) . '/config/' . self::DEFAULT_FILENAME;
    }

    /** True only when the file exists and the enable flag is on. */
    public static function isRuntimeEnabled(): bool
    {
        $flag = strtolower(trim((string) Config::env(self::ENABLE_ENV, '')));
        if (!in_array($flag, ['1', 'on', 'true', 'yes'], true)) {
            return false;
        }

        return is_file(self::path());
    }

    /**
     * Load + validate the configured definition (cached per process). Returns
     * the {@see NavigationLoadResult} so callers can log the validation errors.
     */
    public static function load(): NavigationLoadResult
    {
        if (self::$loaded) {
            return self::$cached;
        }
        self::$loaded = true;
        self::$cached = (new NavigationDefinitionLoader(TerminalActionCatalog::defaultRegistry()))
            ->fromFile(self::path());

        return self::$cached;
    }

    /**
     * The definition to drive the runtime with, or null to stay on the legacy
     * menu. Only ever non-null when {@see isRuntimeEnabled()} and the file
     * validates clean.
     */
    public static function resolveDefinition(): ?NavigationDefinition
    {
        if (!self::isRuntimeEnabled()) {
            return null;
        }

        return self::load()->definition();
    }

    /** Reset the process cache (tests only). */
    public static function reset(): void
    {
        self::$cached = null;
        self::$loaded = false;
    }
}
