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

        $category = self::nonEmptyString($experience['category'] ?? null) ?? 'game';
        $multiplayer = !empty($experience['capabilities']['multiplayer']);
        // Player-mode descriptor. Only Game Experiences carry a single/multi
        // player label; a Gateway Experience is a destination whose internal
        // session model is opaque to Crossroads, so it is neither — consumers
        // must not fall back to "Single Player" merely because multiplayer is
        // false. Null means "no player-mode label applies".
        $playerMode = $category === 'game'
            ? ($multiplayer ? 'multiplayer' : 'single_player')
            : null;

        return [
            'id' => $id,
            'name' => $name,
            'description' => trim((string)($experience['description'] ?? '')),
            'category' => $category,
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
                'multiplayer' => $multiplayer,
                'player_mode' => $playerMode,
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

    /**
     * Anonymous-safe presentation projection.
     *
     * This is the read model for logged-out discovery surfaces. It composes the
     * same normalized metadata as build(), but the returned structure is
     * guaranteed to carry no viewer identity and no participation authority:
     *
     *   - no runtime.players (roster identities are never in anonymous output)
     *   - no viewer block at all
     *   - actions.play / return / end_participation are always false
     *   - no launch target/URL is produced or echoed
     *   - status.code is viewer-neutral only
     *     (available | at_capacity | planned | unavailable) — never participating
     *   - no source / backend manifest / raw backend id
     *
     * Callers must not have to strip anything themselves. A null viewer is the
     * only mode: an aggregate state snapshot (active / session_count /
     * player_count) may be supplied for occupancy, but any players[] it carries
     * is discarded before composition.
     *
     * @param array<string,mixed> $experience
     * @param array{active?:bool,session_count?:int,player_count?:int,...}|null $aggregateState
     * @return array<string,mixed>
     */
    public static function buildPublic(
        array $experience,
        string $surface,
        ?array $aggregateState = null
    ): array {
        $sanitizedState = null;
        if ($aggregateState !== null) {
            // Discard any roster/identity payload before it can reach build().
            $sanitizedState = [
                'active' => (bool)($aggregateState['active'] ?? false),
                'session_count' => max(0, (int)($aggregateState['session_count'] ?? 0)),
                'player_count' => max(0, (int)($aggregateState['player_count'] ?? 0)),
                'players' => [],
            ];
        }

        // Compose with an explicitly absent viewer. build() cannot return a
        // viewer-specific status or enable a participation action without a
        // viewerPlayer, but the projection below re-asserts every boundary so
        // the public contract does not depend on that internal detail.
        $view = self::build($experience, $surface, $sanitizedState, null);

        $publicStatus = in_array(
            $view['status']['code'] ?? '',
            ['available', 'at_capacity', 'planned', 'unavailable'],
            true
        )
            ? $view['status']['code']
            : 'unavailable';

        return [
            'id' => $view['id'],
            'name' => $view['name'],
            'description' => $view['description'],
            'category' => $view['category'],
            'author' => $view['author'],
            'version' => $view['version'],
            'presentation' => [
                'icon' => $view['presentation']['icon'] ?? null,
                'icon_url' => $view['presentation']['icon_url'] ?? null,
                'screenshot' => $view['presentation']['screenshot'] ?? null,
                'screenshot_url' => $view['presentation']['screenshot_url'] ?? null,
            ],
            'backend' => [
                // Human-readable label only. The raw backend id and the
                // source manifest are never part of anonymous output.
                'label' => $view['backend']['label'] ?? null,
            ],
            'capabilities' => [
                'multiplayer' => (bool)($view['capabilities']['multiplayer'] ?? false),
                'player_mode' => $view['capabilities']['player_mode'] ?? null,
            ],
            'capacity' => [
                'max_sessions' => $view['capacity']['max_sessions'],
                'limited' => (bool)($view['capacity']['limited'] ?? false),
                'at_capacity' => (bool)($view['capacity']['at_capacity'] ?? false),
            ],
            'cost' => [
                'credits' => max(0, (int)($view['cost']['credits'] ?? 0)),
                'free' => (bool)($view['cost']['free'] ?? true),
            ],
            'surfaces' => [
                'requested' => $view['surfaces']['requested'],
                'current' => $view['surfaces']['current'],
                'web' => $view['surfaces']['web'],
                'telnet' => $view['surfaces']['telnet'],
                'static_launchable' => (bool)($view['surfaces']['static_launchable'] ?? false),
            ],
            'runtime' => [
                'supplied' => $view['runtime']['supplied'],
                'active' => $view['runtime']['active'],
                'session_count' => $view['runtime']['session_count'],
                'player_count' => $view['runtime']['player_count'],
                // 'players' intentionally omitted — anonymous output never
                // contains identity-bearing roster data.
            ],
            'status' => [
                'code' => $publicStatus,
            ],
            'actions' => [
                // Anonymous visitors have no participation authority. These are
                // hard-coded false, not derived, so no viewer state can flip them.
                'play' => false,
                'return' => false,
                'end_participation' => false,
                'details' => false,
                'static_launchable' => (bool)($view['actions']['static_launchable'] ?? false),
            ],
            // No 'viewer' key. No 'launch' key. No 'source' key.
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
