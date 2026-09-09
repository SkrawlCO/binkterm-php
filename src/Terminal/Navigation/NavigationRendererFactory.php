<?php

namespace BinktermPHP\Terminal\Navigation;

/**
 * Chooses the navigation renderer for a session or a preview.
 *
 * Zero-config, disabled, or an invalid theme all yield the canonical flowing
 * {@see NavigationScreenRenderer}. A valid, enabled theme yields a
 * {@see ThemedNavigationRenderer} wrapping it — the themed renderer then decides
 * per frame whether the current geometry is actually themed, so wrapping is
 * always safe.
 */
final class NavigationRendererFactory
{
    /**
     * @param callable(string):void|null $logger passed through to the themed
     *        renderer for a one-line "why fallback" log
     */
    public static function create(?callable $logger = null): NavigationRenderer
    {
        $inner  = new NavigationScreenRenderer();
        $result = NavigationThemeConfig::load();

        if ($result->isInvalid() && is_callable($logger)) {
            $logger('Terminal presentation theme NOT active (using flowing renderer) — '
                . NavigationThemeConfig::path() . ': ' . $result->errorSummary());
        }

        if (!$result->isOk()) {
            return $inner;
        }

        $theme = $result->theme();
        if ($theme === null || !$theme->isEnabled()) {
            return $inner;
        }

        return new ThemedNavigationRenderer($inner, $theme, $logger);
    }
}
