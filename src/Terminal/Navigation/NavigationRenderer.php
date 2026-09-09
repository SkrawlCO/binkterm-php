<?php

namespace BinktermPHP\Terminal\Navigation;

use BinktermPHP\TelnetServer\TerminalRenderContext;

/**
 * The rendering contract for a declarative navigation screen.
 *
 * The canonical implementation is {@see NavigationScreenRenderer} — the flowing,
 * geometry-adaptive composer that has been human-accepted as the live front
 * door and remains the fallback for every geometry and context. The optional
 * {@see ThemedNavigationRenderer} decorates that composer with an absolute-
 * positioned ANSI presentation for one geometry; it delegates all content
 * composition back to the canonical renderer and falls back to it whenever a
 * theme cannot be applied safely.
 *
 * The runtime ({@see NavigationRuntime}) depends on this interface, not on a
 * concrete class, so the two renderers are interchangeable at the seam.
 */
interface NavigationRenderer
{
    /**
     * Paint the screen to the context's sink.
     *
     * @param array<string,mixed> $opts 'clear' (bool), 'show_hotkeys' (bool),
     *                                  'cursor' (int|null lightbar index)
     */
    public function render(TerminalRenderContext $ctx, NavigationScreenModel $screen, array $opts = []): void;
}
