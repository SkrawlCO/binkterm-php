<?php

namespace BinktermPHP\Terminal\Navigation;

use BinktermPHP\Config;

/**
 * Resolves the optional sysop-supplied terminal presentation theme and the
 * trusted template assets it references.
 *
 * Zero-config behaviour is the flowing declarative renderer: a theme is used
 * only when `config/terminal_theme.json` exists, validates clean, and is not
 * disabled. Nothing here throws to its caller.
 *
 * Cache semantics mirror {@see NavigationConfig}: {@see load()} caches the
 * parse result keyed on the file's path + size + mtime, so the forking
 * telnet/SSH daemons re-read per connection and a replaced file is picked up on
 * the next session.
 */
final class NavigationThemeConfig
{
    public const DEFAULT_FILENAME = 'terminal_theme.json';
    public const PATH_ENV         = 'TERMINAL_NAV_THEME_CONFIG';

    /** Token -> resolved absolute path, guarded on the token charset. */
    private const TEMPLATE_TOKEN_RE = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/';

    private static ?NavigationThemeLoadResult $cached = null;
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

    public static function screensDir(): string
    {
        return dirname(__DIR__, 3) . '/telnet/screens';
    }

    /**
     * Optional named surface theme beside the selected navigation theme.
     * The application supplies the token; assets use the same trusted loader.
     * Loaded once by the directory invocation, never by the input loop.
     */
    public static function loadSurface(string $surface): NavigationThemeLoadResult
    {
        if (!preg_match(self::TEMPLATE_TOKEN_RE, $surface)) {
            return NavigationThemeLoadResult::invalid([
                new ValidationError('surface', 'invalid presentation surface token'),
            ]);
        }
        try {
            return (new NavigationThemeLoader())->fromFile(
                dirname(self::path()) . '/terminal_theme_' . $surface . '.json'
            );
        } catch (\Throwable $e) {
            return NavigationThemeLoadResult::invalid([new ValidationError('load', $e->getMessage())]);
        }
    }

    /**
     * Load + validate the configured theme. Cached, keyed on the file's
     * path/size/mtime so a replaced file is not served stale.
     */
    public static function load(): NavigationThemeLoadResult
    {
        $path = self::path();
        $key  = self::keyFor($path);

        if (self::$cached !== null && self::$cacheKey === $key) {
            return self::$cached;
        }

        self::$cacheKey = $key;
        try {
            self::$cached = (new NavigationThemeLoader())->fromFile($path);
        } catch (\Throwable $e) {
            self::$cached = NavigationThemeLoadResult::invalid([
                new ValidationError('load', 'could not load ' . $path . ': ' . $e->getMessage()),
            ]);
        }

        return self::$cached;
    }

    /**
     * Absolute path to a trusted template asset, or null when the token is
     * malformed or the file is missing. The token has already been validated by
     * the loader; this re-checks it so a hand-edited cache or a direct caller
     * cannot escape the screens directory.
     */
    public static function templatePath(string $token): ?string
    {
        if (!preg_match(self::TEMPLATE_TOKEN_RE, $token)) {
            return null;
        }
        $path = self::screensDir() . '/' . $token . '.ans';

        return is_file($path) ? $path : null;
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
