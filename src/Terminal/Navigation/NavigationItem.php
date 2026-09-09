<?php

namespace BinktermPHP\Terminal\Navigation;

/**
 * One selectable entry on a {@see NavigationNode}. Exactly one of
 * {@see $action} / {@see $submenu} is set.
 */
final class NavigationItem
{
    /**
     * @param string             $id            stable id, unique within its node
     * @param string             $labelKey      i18n key
     * @param string             $labelFallback literal fallback when the key is missing
     * @param string|null        $descriptionKey
     * @param string|null        $descriptionFallback
     * @param string|null        $hotkey        single character, case-insensitive
     * @param AccessExpression   $access        visibility gate
     * @param PresentationHints  $presentation
     * @param ActionReference|null $action
     * @param string|null        $submenu       target node id
     * @param bool               $enabled       false = shown but not selectable
     */
    public function __construct(
        public readonly string $id,
        public readonly string $labelKey,
        public readonly string $labelFallback,
        public readonly ?string $descriptionKey,
        public readonly ?string $descriptionFallback,
        public readonly ?string $hotkey,
        public readonly AccessExpression $access,
        public readonly PresentationHints $presentation,
        public readonly ?ActionReference $action,
        public readonly ?string $submenu,
        public readonly bool $enabled = true,
    ) {
    }

    public function isSubmenu(): bool
    {
        return $this->submenu !== null;
    }

    public function isAction(): bool
    {
        return $this->action !== null;
    }

    /**
     * @param array<string,mixed> $raw
     */
    public static function fromConfig(array $raw, string $path): self
    {
        $allowed = [
            'id', 'label', 'label_key', 'label_fallback', 'description', 'description_key',
            'description_fallback', 'hotkey', 'access', 'presentation', 'action', 'submenu', 'enabled',
        ];
        foreach (array_keys($raw) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new NavigationSchemaException("unknown item field \"{$key}\"", $path);
            }
        }

        $id = trim((string) ($raw['id'] ?? ''));
        if ($id === '') {
            throw new NavigationSchemaException('item.id is required', "{$path}.id");
        }

        [$labelKey, $labelFallback] = self::label($raw, "{$path}.label", true);
        [$descKey, $descFallback]   = self::label($raw, "{$path}.description", false, 'description');

        $hotkey = null;
        if (isset($raw['hotkey']) && $raw['hotkey'] !== '' && $raw['hotkey'] !== null) {
            $hk = (string) $raw['hotkey'];
            if (mb_strlen($hk) !== 1) {
                throw new NavigationSchemaException('hotkey must be a single character', "{$path}.hotkey");
            }
            $hotkey = $hk;
        }

        $hasAction  = array_key_exists('action', $raw) && $raw['action'] !== null;
        $hasSubmenu = array_key_exists('submenu', $raw) && $raw['submenu'] !== null && $raw['submenu'] !== '';
        if ($hasAction === $hasSubmenu) {
            throw new NavigationSchemaException(
                'item must have exactly one of "action" or "submenu"',
                $path
            );
        }

        $action  = $hasAction ? ActionReference::fromConfig($raw['action'], "{$path}.action") : null;
        $submenu = $hasSubmenu ? trim((string) $raw['submenu']) : null;

        $enabled = $raw['enabled'] ?? true;
        if (!is_bool($enabled)) {
            throw new NavigationSchemaException('item.enabled must be a boolean', "{$path}.enabled");
        }

        return new self(
            $id,
            $labelKey,
            $labelFallback,
            $descKey,
            $descFallback,
            $hotkey,
            AccessExpression::fromConfig($raw['access'] ?? null, "{$path}.access"),
            PresentationHints::fromConfig($raw['presentation'] ?? null, "{$path}.presentation"),
            $action,
            $submenu,
            $enabled,
        );
    }

    /**
     * Accept either `"<field>": {"key": "...", "fallback": "..."}` or the flat
     * `"<field>_key"` / `"<field>_fallback"` pair. Returns [key, fallback].
     *
     * @return array{0:string,1:string}|array{0:null,1:null}
     */
    private static function label(array $raw, string $path, bool $required, string $field = 'label'): array
    {
        $obj      = $raw[$field] ?? null;
        $flatKey  = $raw["{$field}_key"] ?? null;
        $flatBack = $raw["{$field}_fallback"] ?? null;

        if (is_array($obj)) {
            $key      = isset($obj['key']) ? (string) $obj['key'] : '';
            $fallback = isset($obj['fallback']) ? (string) $obj['fallback'] : '';
        } else {
            $key      = $flatKey !== null ? (string) $flatKey : '';
            $fallback = $flatBack !== null ? (string) $flatBack : (is_string($obj) ? $obj : '');
        }

        $key      = trim($key);
        $fallback = trim($fallback);

        if ($key === '' && $fallback === '') {
            if ($required) {
                throw new NavigationSchemaException("a {$field} (key and/or fallback) is required", $path);
            }

            return [null, null];
        }

        // A fallback is always required so a missing catalog entry never renders blank.
        if ($fallback === '') {
            throw new NavigationSchemaException("{$field} needs a literal fallback string", $path);
        }

        return [$key, $fallback];
    }
}
