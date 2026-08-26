<?php

namespace BinktermPHP;

/**
 * Resolve a normalized Experience into its canonical backend launch target.
 *
 * This class does not launch anything and does not perform backend-specific
 * authorization or session creation. Existing backend routes remain
 * authoritative for those concerns.
 */
final class ExperienceLaunch
{
    /**
     * Determine whether a normalized Experience has a canonical launch target.
     *
     * This is intentionally derived from the same resolver used to produce
     * the launch target, so action availability cannot drift from launch
     * resolution.
     *
     * @param array<string,mixed> $experience
     */
    public static function canLaunch(array $experience): bool
    {
        return self::resolve($experience) !== null;
    }

    /**
     * Resolve the canonical launch target for a normalized Experience.
     *
     * @param array<string,mixed> $experience
     * @return array<string,string>|null
     */
    public static function resolve(array $experience): ?array
    {
        $backend = $experience['backend'] ?? null;

        if (!is_array($backend)) {
            return null;
        }

        $type = trim((string)($backend['type'] ?? ''));
        $id = trim((string)($backend['id'] ?? ''));

        if ($type === '' || $id === '') {
            return null;
        }

        return match ($type) {
            'native' => [
                'type' => 'native',
                'id' => $id,
                'url' => "/games/nativedoors/{$id}",
            ],
            'dos' => [
                'type' => 'dos',
                'id' => $id,
                'url' => "/games/dosdoors/{$id}",
            ],
            'jsdos' => [
                'type' => 'jsdos',
                'id' => $id,
                'url' => "/games/jsdos/{$id}",
            ],
            'web' => [
                'type' => 'web',
                'id' => $id,
                'url' => "/games/{$id}",
            ],
            default => null,
        };
    }
}
