<?php

namespace BinktermPHP\Terminal\Navigation;

/**
 * Presentation *intent* for a navigation node or item.
 *
 * These express what the sysop means ("this is the primary action", "group
 * these together", "prefer the short description on small screens") and never
 * how to draw it. Coordinates, raw ANSI, border geometry, and cursor
 * choreography are explicitly not representable here — BinktermPHP's renderer
 * decides the actual layout for the caller's geometry / charset / colour.
 */
final class PresentationHints
{
    public const EMPHASIS = ['normal', 'primary', 'muted', 'danger'];
    public const DESCRIPTION_MODES = ['compact', 'full'];

    private function __construct(
        public readonly string $emphasis,
        public readonly ?string $group,
        public readonly ?string $glyph,
        public readonly ?string $descriptionMode,
        public readonly ?string $art,
        public readonly ?string $badge = null,
    ) {
    }

    public static function none(): self
    {
        return new self('normal', null, null, null, null, null);
    }

    /**
     * @param mixed $raw decoded `"presentation"` object, or null
     */
    public static function fromConfig(mixed $raw, string $path = 'presentation'): self
    {
        if ($raw === null) {
            return self::none();
        }
        if (is_object($raw)) {
            $raw = (array) $raw;
        }
        if (!is_array($raw)) {
            throw new NavigationSchemaException('presentation hints must be an object', $path);
        }
        if ($raw === []) {
            return self::none();
        }
        if (array_is_list($raw)) {
            throw new NavigationSchemaException('presentation hints must be an object', $path);
        }

        $allowed = ['emphasis', 'group', 'glyph', 'description_mode', 'art', 'badge'];
        foreach (array_keys($raw) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new NavigationSchemaException("unknown presentation hint \"{$key}\"", $path);
            }
        }

        $emphasis = $raw['emphasis'] ?? 'normal';
        if (!in_array($emphasis, self::EMPHASIS, true)) {
            throw new NavigationSchemaException(
                'emphasis must be one of: ' . implode(', ', self::EMPHASIS),
                "{$path}.emphasis"
            );
        }

        $descMode = $raw['description_mode'] ?? null;
        if ($descMode !== null && !in_array($descMode, self::DESCRIPTION_MODES, true)) {
            throw new NavigationSchemaException(
                'description_mode must be one of: ' . implode(', ', self::DESCRIPTION_MODES),
                "{$path}.description_mode"
            );
        }

        foreach (['group', 'glyph', 'art', 'badge'] as $strKey) {
            if (isset($raw[$strKey]) && !is_string($raw[$strKey])) {
                throw new NavigationSchemaException("\"{$strKey}\" must be a string", "{$path}.{$strKey}");
            }
        }

        return new self(
            (string) $emphasis,
            isset($raw['group']) ? (string) $raw['group'] : null,
            isset($raw['glyph']) ? (string) $raw['glyph'] : null,
            $descMode !== null ? (string) $descMode : null,
            isset($raw['art']) ? (string) $raw['art'] : null,
            isset($raw['badge']) ? (string) $raw['badge'] : null,
        );
    }
}
