<?php

namespace BinktermPHP\Terminal\Navigation;

/**
 * The built-in navigation definition: a single flat root menu whose items and
 * gating mirror today's terminal main menu
 * ({@see \BinktermPHP\TelnetServer\BbsSession}).
 *
 * It exists so the declarative framework has a generic, board-agnostic
 * definition to exercise in tests and previews, and so the runtime has a safe
 * definition to fall back to when a sysop's custom file is missing or invalid.
 * Installing BinktermPHP with no custom navigation config keeps the legacy menu
 * code path — this definition is not silently substituted for it.
 */
final class DefaultNavigationDefinition
{
    /**
     * @return array<string,mixed> the definition as it would appear on disk
     */
    public static function toArray(): array
    {
        $items = [];
        foreach (TerminalActionCatalog::descriptors() as $d) {
            // The built-in definition intentionally uses plain literal labels
            // (no i18n key): it is a board-agnostic safety net / fixture. A real
            // sysop definition supplies its own `label_key` per item.
            $items[] = [
                'id'        => $d['id'],
                'label_fallback' => $d['fallback'],
                'hotkey'    => $d['hotkey'],
                'action'    => $d['id'],
                'access'    => $d['availability'],
                'presentation' => [
                    'group'    => $d['group'],
                    'emphasis' => $d['id'] === 'quit' ? 'muted' : 'normal',
                ],
            ];
        }

        return [
            'schema' => NavigationDefinition::CURRENT_SCHEMA,
            'id'     => 'binkterm.default',
            'root'   => 'main',
            'nodes'  => [
                [
                    'id'        => 'main',
                    'label_fallback' => 'Main Menu',
                    'items'     => $items,
                ],
            ],
        ];
    }

    public static function build(): NavigationDefinition
    {
        return NavigationDefinition::fromArray(self::toArray(), 'built-in default');
    }

    /** A loader-validated result using the default action registry. */
    public static function load(): NavigationLoadResult
    {
        return (new NavigationDefinitionLoader(TerminalActionCatalog::defaultRegistry()))
            ->fromArray(self::toArray(), 'built-in default');
    }
}
