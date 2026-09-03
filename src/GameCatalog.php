<?php
/**
 * Game Catalog
 *
 * Provides a unified view of playable experiences across BinkTermPHP.
 *
 * The catalog normalizes backend-specific door/game metadata into a common
 * Experience representation. Backend managers and manifests remain
 * authoritative for installation, configuration, and launch behavior.
 *
 * @package BinkTermPHP
 */

namespace BinktermPHP;

use BinktermPHP\Chat\ChatRoomService;

class GameCatalog
{
    private ChatRoomService $chatRooms;

    public function __construct(?ChatRoomService $chatRooms = null)
    {
        $this->chatRooms = $chatRooms ?? new ChatRoomService();
    }

    /**
     * Get enabled playable experiences for a presentation surface.
     *
     * @param array<string, mixed>|null $user
     * @return array<string, array<string, mixed>>
     */
    public function getEnabledGames(?array $user = null, string $surface = 'web'): array
    {
        $experiences = [];

        $this->addManagedDoors(
            $experiences,
            'dos',
            (new DoorManager())->getEnabledDoors(),
            $user,
            $surface
        );

        $this->addManagedDoors(
            $experiences,
            'native',
            (new NativeDoorManager())->getEnabledDoors(),
            $user,
            $surface
        );

        // RLogin doors are DB-backed (rlogin_doors table). Tolerate the table
        // being absent so discovery still works on installs that have not yet
        // run the RLogin migration — the doors simply do not appear until then.
        try {
            $this->addManagedDoors(
                $experiences,
                'rlogin',
                (new RLoginDoorManager())->getEnabledDoors(),
                $user,
                $surface
            );
        } catch (\Throwable $e) {
            // No RLogin doors available (e.g. pre-migration schema).
        }

        $this->addWebDoors($experiences, $user, $surface);
        $this->addJsdosDoors($experiences, $user, $surface);

        return $experiences;
    }

    /**
     * Normalize an optional Experience conversation capability.
     *
     * Manifests may reference a chat room by local numeric room_id or by the
     * portable room_name. The normalized Experience contract always exposes
     * the resolved numeric room_id used by the local BinkTerm installation.
     *
     * @param mixed $conversation
     * @return array{type:string,room_id:int}|null
     */
    private function normalizeConversationCapability(
        mixed $conversation
    ): ?array {
        if (!is_array($conversation)) {
            return null;
        }

        $type = (string)($conversation['type'] ?? '');

        if ($type !== 'chat_room') {
            return null;
        }

        $roomId = (int)($conversation['room_id'] ?? 0);

        if ($roomId <= 0) {
            $roomName = trim(
                (string)($conversation['room_name'] ?? '')
            );

            if ($roomName === '') {
                return null;
            }

            $room = $this->chatRooms
                ->resolveActiveRoomByName($roomName);

            if ($room === null) {
                return null;
            }

            $roomId = $room['id'];
        }

        return [
            'type' => 'chat_room',
            'room_id' => $roomId,
        ];
    }


    /**
     * Add DOS/native manager-backed experiences.
     *
     * @param array<string, array<string, mixed>> $experiences
     * @param array<string, array<string, mixed>> $doors
     * @param array<string, mixed>|null $user
     */
    private function addManagedDoors(
        array &$experiences,
        string $backendType,
        array $doors,
        ?array $user,
        string $surface
    ): void {
        foreach ($doors as $id => $door) {
            // Managers already return enabled doors, but retain this boundary
            // check so normalization cannot expose a disabled fixture/caller.
            if (empty($door['config']['enabled'])) {
                continue;
            }

            if (!empty($door['admin_only']) && empty($user['is_admin'])) {
                continue;
            }

            // This is an explicit operator visibility control, not merely a
            // non-runnable surface state. Do not disclose managed door
            // metadata through ordinary web discovery when it is set.
            if ($surface === 'web' && !empty($door['config']['hide_from_web'])) {
                continue;
            }

            $game = isset($door['game']) && is_array($door['game'])
                ? $door['game']
                : $door;

            $experience = $door['experience'] ?? [];

            // The DOS/native door managers hand us a FLATTENED manifest, so the
            // declared runtime mode lives at $door['terminal_mode']. Older or
            // nested callers/fixtures may still provide
            // $door['door']['terminal_mode']; accept both, preferring the
            // flattened production shape.
            $manifestTerminalMode = strtolower((string)(
                $door['terminal_mode']
                ?? ($door['door']['terminal_mode'] ?? '')
            ));

            // Operator-controlled hide_from_web removes the Experience from
            // web discovery entirely. All other managed modes, including the
            // generic native terminal_mode=line private-TCP runtime, use the
            // existing managed-door browser launch path.
            $webSurfaceState = 'full';
            if (!empty($door['config']['hide_from_web'])) {
                $webSurfaceState = 'unavailable';
            }

            $surfaces = [
                'web' => $webSurfaceState,
                'telnet' => 'full',
            ];
            $policy = [
                'enabled' => true,
                'admin_only' => !empty($door['admin_only']),
                'credit_cost' => (int)($door['config']['credit_cost'] ?? 0),
            ];
            $launchIdentity = [
                'backend' => [
                    'type' => $backendType,
                    'id' => $id,
                ],
                'surfaces' => $surfaces,
                'policy' => $policy,
            ];

            $experiences[$id] = [
                'id' => $id,
                'name' => $game['name'] ?? $door['name'] ?? $id,
                'description' => $game['description'] ?? $door['description'] ?? '',
                'category' => $experience['category'] ?? 'game',

                'backend' => [
                    'type' => $backendType,
                    'id' => $id,
                ],

                'author' => $game['author'] ?? $door['author'] ?? null,
                'version' => $game['version'] ?? $door['game_version'] ?? null,

                'capabilities' => [
                    'multiplayer' => (bool)($experience['multiplayer'] ?? false),
                    'participant_messaging' => (bool)($experience['participant_messaging'] ?? false),
                    'conversation' => $this->normalizeConversationCapability(
                        $experience['conversation'] ?? null
                    ),
                ],

                'actions' => [
                    'launch' => ExperienceLaunch::canLaunch(
                        $launchIdentity,
                        $surface
                    ),
                    'message_players' => (bool)($experience['participant_messaging'] ?? false),
                ],

                'participant_actions' => [
                    'profile' => true,
                    'message' => (bool)($experience['participant_messaging'] ?? false),
                ],

                'capacity' => [
                    // max_nodes is the runtime concurrency limit enforced
                    // by the door launch/session machinery. RLogin doors are
                    // DB-backed and carry the same limit as config.max_sessions.
                    'max_sessions' => isset($door['max_nodes'])
                        ? (int)$door['max_nodes']
                        : ((int)($door['config']['max_sessions'] ?? 0) > 0
                            ? (int)$door['config']['max_sessions']
                            : null),
                ],

                'terminal' => [
                    // Managed door manifests own the runtime mode. Normalize
                    // it here so terminal clients do not depend on manifest
                    // nesting or pass unknown modes into the relay path.
                    //
                    // RLogin backends are a live outbound terminal session to a
                    // remote host (e.g. Synchronet) with no notion of Doorway
                    // protocol — cursor/extended keys must pass through raw or
                    // remote navigation breaks. DOS and native doors keep the
                    // manifest-driven default (doorway unless terminal_mode is
                    // raw or line). 'line' is a native-only mode: a private
                    // TCP line-oriented service reached directly (no local
                    // process, no dosbox-bridge) — see DoorHandler's
                    // line-relay path. relay_host/relay_port/relay_adapter_class
                    // are only meaningful, and only ever populated, when mode
                    // is 'line'.
                    'mode' => (
                        $backendType === 'rlogin'
                        || $manifestTerminalMode === 'raw'
                    )
                        ? 'raw'
                        : ($manifestTerminalMode === 'line' && $backendType === 'native'
                            ? 'line'
                            : 'doorway'),
                    'relay_host' => ($manifestTerminalMode === 'line' && $backendType === 'native')
                        ? ($door['relay_host'] ?? null)
                        : null,
                    'relay_port' => ($manifestTerminalMode === 'line' && $backendType === 'native')
                        ? ($door['relay_port'] ?? null)
                        : null,
                    'relay_adapter_class' => ($manifestTerminalMode === 'line' && $backendType === 'native')
                        ? ($door['relay_adapter_class'] ?? null)
                        : null,
                ],

                'surfaces' => $surfaces,

                'presentation' => [
                    'icon' => $game['icon'] ?? null,
                    'icon_url' => "/door-assets/{$id}/icon",
                    'screenshot' => $game['screenshot'] ?? null,
                    'screenshot_url' => !empty($game['screenshot'])
                        ? "/door-assets/{$id}/screenshot"
                        : null,
                ],

                'policy' => $policy,

                // Compatibility field retained for the current Games UI.
                'players' => $game['players'] ?? null,

                'source' => [
                    'type' => $backendType,
                    'manifest' => $door,
                ],
            ];
        }
    }

    /**
     * Add enabled WebDoor experiences.
     *
     * @param array<string, array<string, mixed>> $experiences
     * @param array<string, mixed>|null $user
     */
    private function addWebDoors(
        array &$experiences,
        ?array $user,
        string $surface
    ): void {
        if (!GameConfig::isGameSystemEnabled()) {
            return;
        }

        foreach (WebDoorManifest::listManifests() as $entry) {
            $manifest = $entry['manifest'] ?? [];
            $game = $manifest['game'] ?? null;

            if (!is_array($game)) {
                continue;
            }

            $id = (string)($entry['id'] ?? $game['id'] ?? $entry['path']);

            if (!$this->isWebDoorDiscoverable($id, $manifest, $user)) {
                continue;
            }

            $config = GameConfig::getGameConfig($id) ?? [];

            $name = !empty($config['display_name'])
                ? $config['display_name']
                : ($game['name'] ?? $id);

            $description = !empty($config['display_description'])
                ? $config['display_description']
                : ($game['description'] ?? '');

            $multiplayer = false;
            if (isset($manifest['capabilities']['multiplayer'])) {
                $multiplayer = (bool)$manifest['capabilities']['multiplayer'];
            } elseif (isset($manifest['multiplayer']['enabled'])) {
                $multiplayer = (bool)$manifest['multiplayer']['enabled'];
            }

            $icon = $game['icon'] ?? 'icon.png';
            $path = (string)$entry['path'];
            $surfaces = [
                'web' => 'full',
                'telnet' => 'planned',
            ];
            $policy = [
                'enabled' => true,
                'admin_only' => !empty($manifest['requirements']['admin_only']),
                'credit_cost' => (int)($config['credit_cost'] ?? 0),
            ];
            $launchIdentity = [
                'backend' => [
                    'type' => 'web',
                    'id' => $id,
                ],
                'surfaces' => $surfaces,
                'policy' => $policy,
            ];

            $experiences[$id] = [
                'id' => $id,
                'name' => $name,
                'description' => $description,
                'category' => 'game',

                'backend' => [
                    'type' => 'web',
                    'id' => $id,
                ],

                'author' => $game['author'] ?? null,
                'version' => $game['version'] ?? null,

                'capabilities' => [
                    'multiplayer' => $multiplayer,
                    'participant_messaging' => false,
                    'conversation' => $this->normalizeConversationCapability(
                        $manifest['experience']['conversation'] ?? null
                    ),
                ],

                'actions' => [
                    'launch' => ExperienceLaunch::canLaunch(
                        $launchIdentity,
                        $surface
                    ),
                    'message_players' => false,
                ],

                'participant_actions' => [
                    'profile' => true,
                    'message' => false,
                ],

                'capacity' => [
                    'max_sessions' => null,
                ],

                'surfaces' => $surfaces,

                'presentation' => [
                    'icon' => $icon,
                    'icon_url' => "/webdoors/{$path}/{$icon}",
                    'screenshot' => $game['screenshot'] ?? null,
                    'screenshot_url' => !empty($game['screenshot'])
                        ? "/webdoors/{$path}/{$game['screenshot']}"
                        : null,
                ],

                'policy' => $policy,

                'source' => [
                    'type' => 'web',
                    'manifest' => $manifest,
                ],
            ];
        }
    }

    /**
     * Keep WebDoor enablement, platform requirements, and the manifest
     * admin_only boundary as discovery gates on every presentation surface.
     *
     * An admin_only WebDoor (manifest `requirements.admin_only: true`) is an
     * explicit operator visibility control, withheld from non-admin discovery
     * entirely rather than surfaced as a non-runnable state — the same
     * manifest-authoritative gate managed doors use in {@see addManagedDoors()}.
     * There is no site-config override.
     *
     * @param array<string,mixed>|null $user Authenticated viewer, or null when
     *     there is no viewer. Only `is_admin` is consulted.
     */
    private function isWebDoorDiscoverable(
        string $id,
        array $manifest,
        ?array $user = null
    ): bool {
        if (!GameConfig::isEnabled($id)) {
            return false;
        }

        if (!WebDoorSupport::requirementsSatisfied($manifest)) {
            return false;
        }

        if (
            !empty($manifest['requirements']['admin_only'])
            && empty($user['is_admin'])
        ) {
            return false;
        }

        return true;
    }

    /**
     * Add enabled JS-DOS experiences.
     *
     * @param array<string, array<string, mixed>> $experiences
     * @param array<string, mixed>|null $user
     */
    private function addJsdosDoors(
        array &$experiences,
        ?array $user,
        string $surface
    ): void {
        if (!JsdosDoorConfig::isConfigPresent()) {
            return;
        }

        foreach (JsdosDoorManifest::listManifests() as $entry) {
            $manifest = $entry['manifest'] ?? [];
            $id = (string)$entry['id'];

            if (!JsdosDoorConfig::isEnabled($id)) {
                continue;
            }

            $config = JsdosDoorConfig::getGameConfig($id) ?? [];
            $path = (string)$entry['path'];

            $name = !empty($config['display_name'])
                ? $config['display_name']
                : ($manifest['name'] ?? $id);

            $description = !empty($config['display_description'])
                ? $config['display_description']
                : ($manifest['description'] ?? '');

            $icon = $manifest['icon'] ?? 'icon.png';
            $surfaces = [
                'web' => 'full',
                'telnet' => 'planned',
            ];
            $policy = [
                'enabled' => true,
                'admin_only' => false,
                'credit_cost' => (int)($config['credit_cost'] ?? 0),
            ];
            $launchIdentity = [
                'backend' => [
                    'type' => 'jsdos',
                    'id' => $id,
                ],
                'surfaces' => $surfaces,
                'policy' => $policy,
            ];

            $experiences[$id] = [
                'id' => $id,
                'name' => $name,
                'description' => $description,
                'category' => 'game',

                'backend' => [
                    'type' => 'jsdos',
                    'id' => $id,
                ],

                'author' => $manifest['author'] ?? null,
                'version' => $manifest['version'] ?? null,

                'capabilities' => [
                    'multiplayer' => false,
                    'participant_messaging' => false,
                    'conversation' => $this->normalizeConversationCapability(
                        $manifest['experience']['conversation'] ?? null
                    ),
                ],

                'actions' => [
                    'launch' => ExperienceLaunch::canLaunch(
                        $launchIdentity,
                        $surface
                    ),
                    'message_players' => false,
                ],

                'participant_actions' => [
                    'profile' => true,
                    'message' => false,
                ],

                'capacity' => [
                    'max_sessions' => null,
                ],

                'surfaces' => $surfaces,

                'presentation' => [
                    'icon' => $icon,
                    'icon_url' => "/jsdos-doors/{$path}/{$icon}",
                    'screenshot' => $manifest['screenshot'] ?? null,
                    'screenshot_url' => !empty($manifest['screenshot'])
                        ? "/jsdos-doors/{$path}/{$manifest['screenshot']}"
                        : null,
                ],

                'policy' => $policy,

                'source' => [
                    'type' => 'jsdos',
                    'manifest' => $manifest,
                ],
            ];
        }
    }
}
