<?php

namespace BinktermPHP;

/**
 * Backend-independent presentation/read model for a normalized Experience.
 *
 * GameCatalog, ExperienceState, ExperienceLaunch, and ExperienceParticipation
 * remain authoritative. This class only composes their read-side contracts
 * into a predictable shape for web and terminal presentation consumers.
 */
final class ExperiencePresentation
{
    /**
     * @param array<string,mixed> $experience
     * @param array<string,mixed>|null $state
     * @param array<string,mixed>|null $viewerPlayer
     * @return array<string,mixed>
     */
    public static function build(
        array $experience,
        string $surface,
        ?array $state = null,
        ?array $viewerPlayer = null
    ): array {
        $surface = self::normalizeSurface($surface);
        $id = trim((string)($experience['id'] ?? ''));
        $name = trim((string)($experience['name'] ?? ''));
        if ($name === '') {
            $name = $id;
        }

        $backendType = trim((string)($experience['backend']['type'] ?? ''));
        $surfaceState = self::surfaceState($experience, $surface);
        $viewerActions = ExperienceParticipation::viewerActions(
            $experience,
            $viewerPlayer,
            $surface
        );
        $staticLaunchable = $surfaceState === 'full'
            && ExperienceLaunch::canLaunch($experience, $surface);
        if (!$staticLaunchable) {
            $viewerActions['play'] = false;
            $viewerActions['return'] = false;
        }

        // Capacity is a read-side reflection of ExperienceState occupancy against
        // the normalized GameCatalog limit. Backend/wrapper routes remain the
        // runtime authority; this never gates an actual launch.
        $maxSessions = isset($experience['capacity']['max_sessions'])
            ? (int)$experience['capacity']['max_sessions']
            : null;
        $capacityLimited = $maxSessions !== null;
        $sessionCount = $state !== null
            ? (int)($state['session_count'] ?? 0)
            : null;
        $participating = $viewerPlayer !== null;
        // Viewer-neutral world state. False for unlimited capacity and false
        // when no runtime state was supplied (occupancy is unknown).
        $atCapacity = $capacityLimited
            && $sessionCount !== null
            && $sessionCount >= $maxSessions;
        // Viewer-action state: an existing participant may always Return, even
        // when the Experience is full (wrappers resume before capacity checks).
        $blockedByCapacity = $atCapacity && !$participating;
        $statusCode = self::statusCode(
            $surfaceState,
            $staticLaunchable,
            $participating,
            $atCapacity
        );

        return [
            'id' => $id,
            'name' => $name,
            'description' => trim((string)($experience['description'] ?? '')),
            'category' => self::nonEmptyString($experience['category'] ?? null)
                ?? 'game',
            'author' => self::nonEmptyString($experience['author'] ?? null),
            'version' => self::nonEmptyString($experience['version'] ?? null),
            'presentation' => [
                'icon' => $experience['presentation']['icon'] ?? null,
                'icon_url' => $experience['presentation']['icon_url'] ?? null,
                'screenshot' => $experience['presentation']['screenshot'] ?? null,
                'screenshot_url' => $experience['presentation']['screenshot_url'] ?? null,
            ],
            'backend' => [
                'type' => $backendType,
                'label' => self::backendLabel($backendType),
            ],
            'capabilities' => [
                'multiplayer' => !empty($experience['capabilities']['multiplayer']),
            ],
            'capacity' => [
                'max_sessions' => $maxSessions,
                'limited' => $capacityLimited,
                'at_capacity' => $atCapacity,
            ],
            'cost' => [
                'credits' => max(0, (int)($experience['policy']['credit_cost'] ?? 0)),
                'free' => (int)($experience['policy']['credit_cost'] ?? 0) <= 0,
            ],
            'surfaces' => [
                'requested' => $surface,
                'current' => $surfaceState,
                'web' => self::surfaceState($experience, 'web'),
                'telnet' => self::surfaceState($experience, 'telnet'),
                'static_launchable' => $staticLaunchable,
            ],
            'runtime' => [
                'supplied' => $state !== null,
                'active' => $state !== null
                    ? (bool)($state['active'] ?? false)
                    : null,
                'session_count' => $state !== null
                    ? (int)($state['session_count'] ?? 0)
                    : null,
                'player_count' => $state !== null
                    ? (int)($state['player_count'] ?? 0)
                    : null,
                'players' => is_array($state['players'] ?? null)
                    ? $state['players']
                    : [],
            ],
            'viewer' => [
                'participating' => $participating,
                'blocked_by_capacity' => $blockedByCapacity,
            ],
            'status' => [
                // Semantic status only. Consumers (Twig, JS) own the wording.
                'code' => $statusCode,
            ],
            'actions' => [
                'primary' => self::primaryAction(
                    $surfaceState,
                    $viewerActions
                ),
                'details' => true,
                'play' => $viewerActions['play'],
                'return' => $viewerActions['return'],
                'end_participation' => $viewerActions['end'],
                // This is static presentation eligibility only. Backend
                // routes remain authoritative for runtime launch decisions.
                'static_launchable' => $staticLaunchable,
            ],
        ];
    }

    /** @param array<string,mixed> $experience */
    private static function surfaceState(array $experience, string $surface): string
    {
        $state = strtolower(trim((string)($experience['surfaces'][$surface] ?? '')));

        return in_array($state, ['full', 'planned', 'unavailable'], true)
            ? $state
            : 'unavailable';
    }

    /**
     * Normalized semantic status for the current viewer and surface.
     *
     * This method owns only *which* status applies; the rendering layer owns
     * the wording. Precedence:
     *   1. planned surface        -> planned
     *   2. not static-launchable  -> unavailable
     *   3. viewer participating   -> participating
     *   4. at capacity            -> at_capacity
     *   5. otherwise              -> available
     *
     * @return 'participating'|'at_capacity'|'available'|'planned'|'unavailable'
     */
    private static function statusCode(
        string $surfaceState,
        bool $staticLaunchable,
        bool $participating,
        bool $atCapacity
    ): string {
        if ($surfaceState === 'planned') {
            return 'planned';
        }

        if (!$staticLaunchable) {
            return 'unavailable';
        }

        if ($participating) {
            return 'participating';
        }

        if ($atCapacity) {
            return 'at_capacity';
        }

        return 'available';
    }

    /** @param array{play:bool,return:bool,end:bool} $actions */
    private static function primaryAction(string $surfaceState, array $actions): string
    {
        if ($surfaceState === 'planned') {
            return 'planned';
        }

        if ($surfaceState !== 'full') {
            return 'unavailable';
        }

        if ($actions['return']) {
            return 'return';
        }

        if ($actions['play']) {
            return 'play';
        }

        return 'details';
    }

    private static function normalizeSurface(string $surface): string
    {
        $surface = strtolower(trim($surface));

        return $surface === 'terminal' ? 'telnet' : $surface;
    }

    private static function backendLabel(string $backendType): ?string
    {
        return match ($backendType) {
            'dos' => 'DOS',
            'native' => 'Native',
            'web' => 'WebDoor',
            'jsdos' => 'JS-DOS',
            default => $backendType !== '' ? $backendType : null,
        };
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        $value = trim((string)$value);

        return $value !== '' ? $value : null;
    }
}
