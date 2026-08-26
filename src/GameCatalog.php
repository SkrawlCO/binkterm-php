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

class GameCatalog
{
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

        $this->addWebDoors($experiences, $user, $surface);
        $this->addJsdosDoors($experiences, $user, $surface);

        return $experiences;
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
            if (!empty($door['admin_only']) && empty($user['is_admin'])) {
                continue;
            }

            if ($surface === 'web' && !empty($door['config']['hide_from_web'])) {
                continue;
            }

            $game = isset($door['game']) && is_array($door['game'])
                ? $door['game']
                : $door;

            $experience = $door['experience'] ?? [];

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
                ],

                'actions' => [
                    'launch' => ExperienceLaunch::canLaunch([
                        'backend' => [
                            'type' => $backendType,
                            'id' => $id,
                        ],
                    ]),
                ],

                'capacity' => [
                    // max_nodes is the runtime concurrency limit enforced
                    // by the door launch/session machinery.
                    'max_sessions' => isset($door['max_nodes'])
                        ? (int)$door['max_nodes']
                        : null,
                ],

                'surfaces' => [
                    'web' => empty($door['config']['hide_from_web']) ? 'full' : 'unavailable',
                    'telnet' => 'full',
                ],

                'presentation' => [
                    'icon' => $game['icon'] ?? null,
                    'icon_url' => "/door-assets/{$id}/icon",
                    'screenshot' => $game['screenshot'] ?? null,
                ],

                'policy' => [
                    'enabled' => !empty($door['config']['enabled']),
                    'admin_only' => !empty($door['admin_only']),
                    'credit_cost' => (int)($door['config']['credit_cost'] ?? 0),
                ],

                // Compatibility fields for current consumers.
                'type' => $backendType . 'door',
                'path' => $id,
                'icon' => $game['icon'] ?? null,
                'icon_url' => "/door-assets/{$id}/icon",
                'players' => $game['players'] ?? null,
                'genre' => $game['genre'] ?? [],
                'experience' => [
                    'category' => $experience['category'] ?? 'game',
                    'featured' => (bool)($experience['featured'] ?? false),
                    'multiplayer' => (bool)($experience['multiplayer'] ?? false),
                ],

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
        if ($surface !== 'web' || !GameConfig::isGameSystemEnabled()) {
            return;
        }

        foreach (WebDoorManifest::listManifests() as $entry) {
            $manifest = $entry['manifest'] ?? [];
            $game = $manifest['game'] ?? null;

            if (!is_array($game)) {
                continue;
            }

            $id = (string)($entry['id'] ?? $game['id'] ?? $entry['path']);

            if (!GameConfig::isEnabled($id)) {
                continue;
            }

            if (!WebDoorSupport::requirementsSatisfied($manifest)) {
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
                ],

                'actions' => [
                    'launch' => ExperienceLaunch::canLaunch([
                        'backend' => [
                            'type' => 'web',
                            'id' => $id,
                        ],
                    ]),
                ],

                'capacity' => [
                    'max_sessions' => null,
                ],

                'surfaces' => [
                    'web' => 'full',
                    'telnet' => 'planned',
                ],

                'presentation' => [
                    'icon' => $icon,
                    'icon_url' => "/webdoors/{$path}/{$icon}",
                    'screenshot' => $game['screenshot'] ?? null,
                ],

                'policy' => [
                    'enabled' => true,
                    'admin_only' => false,
                    'credit_cost' => (int)($config['credit_cost'] ?? 0),
                ],

                'type' => 'webdoor',
                'path' => $path,
                'icon' => $icon,
                'icon_url' => "/webdoors/{$path}/{$icon}",
                'players' => null,
                'genre' => [],
                'experience' => [
                    'category' => 'game',
                    'featured' => false,
                    'multiplayer' => $multiplayer,
                ],

                'source' => [
                    'type' => 'web',
                    'manifest' => $manifest,
                ],
            ];
        }
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
        if ($surface !== 'web' || !JsdosDoorConfig::isConfigPresent()) {
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
                ],

                'actions' => [
                    'launch' => ExperienceLaunch::canLaunch([
                        'backend' => [
                            'type' => 'jsdos',
                            'id' => $id,
                        ],
                    ]),
                ],

                'capacity' => [
                    'max_sessions' => null,
                ],

                'surfaces' => [
                    'web' => 'full',
                    'telnet' => 'planned',
                ],

                'presentation' => [
                    'icon' => $icon,
                    'icon_url' => "/jsdos-doors/{$path}/{$icon}",
                    'screenshot' => $manifest['screenshot'] ?? null,
                ],

                'policy' => [
                    'enabled' => true,
                    'admin_only' => false,
                    'credit_cost' => (int)($config['credit_cost'] ?? 0),
                ],

                'type' => 'jsdosdoor',
                'path' => $id,
                'icon' => $icon,
                'icon_url' => "/jsdos-doors/{$path}/{$icon}",
                'players' => null,
                'genre' => [],
                'experience' => [
                    'category' => 'game',
                    'featured' => false,
                    'multiplayer' => false,
                ],

                'source' => [
                    'type' => 'jsdos',
                    'manifest' => $manifest,
                ],
            ];
        }
    }
}
