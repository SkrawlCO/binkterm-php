<?php

namespace BinktermPHP\Terminal\Navigation;

/**
 * Metadata for one named platform action a declarative navigation item may bind
 * to. The *behaviour* (a callable) is attached separately at runtime via
 * {@see ActionRegistry::bind()} — it is never part of configuration or of this
 * value object.
 */
final class TerminalAction
{
    /**
     * @param string           $id            stable identifier referenced from config
     * @param string           $labelKey      default i18n key (an item may override the label)
     * @param string           $labelFallback default literal label
     * @param AccessExpression $availability   when this action is usable at all (feature flags etc.)
     * @param string|null      $defaultHotkey suggested single-character hotkey
     * @param string           $group         coarse grouping hint ("messaging", "community", …)
     * @param bool              $terminates    true if invoking it ends the session (e.g. logout)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $labelKey,
        public readonly string $labelFallback,
        public readonly AccessExpression $availability,
        public readonly ?string $defaultHotkey = null,
        public readonly string $group = 'general',
        public readonly bool $terminates = false,
    ) {
    }
}
