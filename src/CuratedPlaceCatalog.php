<?php

declare(strict_types=1);

namespace BinktermPHP;

/** Board-owned places over existing authorized runtimes; no shelf mutations. */
final class CuratedPlaceCatalog
{
    public function __construct(
        private ?GameCatalog $catalog = null,
        private string $definitionPath = __DIR__ . '/../config/crossroads/places.json'
    ) {
    }

    /** Enabled board definitions in file order; no runtime discovery. */
    public function getDefinitions(): array
    {
        if (!is_readable($this->definitionPath)) {
            return [];
        }
        $raw = json_decode((string)file_get_contents($this->definitionPath), true);
        $places = [];
        foreach (is_array($raw) ? array_keys($raw) : [] as $id) {
            $place = $this->getDefinition((string)$id);
            if ($place !== null) {
                $places[] = $place;
            }
        }
        return $places;
    }

    /**
     * Read enabled board metadata. Missing/malformed definitions fail closed.
     * @return array<string,mixed>|null
     */
    public function getDefinition(string $id): ?array
    {
        if (!is_readable($this->definitionPath)) {
            return null;
        }
        $definitions = json_decode((string)file_get_contents($this->definitionPath), true);
        $place = is_array($definitions) ? ($definitions[$id] ?? null) : null;
        if (!is_array($place)
            || ($place['id'] ?? null) !== $id
            || !self::validId($id)
            || ($place['kind'] ?? null) !== 'place'
            || ($place['enabled'] ?? false) !== true
            || !is_string($place['name'] ?? null)
            || !is_string($place['description'] ?? null)
            || !is_array($place['members'] ?? null)
            || !array_is_list($place['members'])
        ) {
            return null;
        }
        return $place;
    }

    /**
     * Preserve member order; hide missing, denied, disabled or unsupported
     * references. Authorized entries on another surface have a null launch.
     * @param array<string,mixed>|null $user Trusted current caller context
     * @return array<string,mixed>|null
     */
    public function getPlace(string $id, ?array $user, string $surface = 'web'): ?array
    {
        $surface = $surface === 'terminal' ? 'telnet' : $surface;
        if (!in_array($surface, ['web', 'telnet'], true)) {
            return null;
        }
        $place = $this->getDefinition($id);
        if ($place === null) {
            return null;
        }
        $catalog = ($this->catalog ??= new GameCatalog())->getEnabledGames($user, $surface);
        $members = [];
        foreach ($place['members'] as $member) {
            if (!is_array($member) || !is_string($member['reference'] ?? null)) {
                continue;
            }
            $resolved = self::resolveReference($member['reference'], $catalog, $surface);
            if ($resolved === null) {
                continue;
            }
            foreach (['title', 'description'] as $field) {
                if (is_string($member[$field] ?? null)) {
                    $resolved[$field] = $member[$field];
                }
            }
            $members[] = $resolved;
        }
        $place['members'] = $members;
        return $place;
    }

    /**
     * Pure resolution against caller-authorized GameCatalog output ONLY.
     * Never accept a caller-supplied catalog. The primary manifest's
     * experience.default_entry names the existing default on all supported
     * surfaces. Non-default entries need a future runtime selection contract.
     * @param array<string,array<string,mixed>> $catalog Authorized catalog
     * @return array<string,mixed>|null
     */
    public static function resolveReference(string $reference, array $catalog, string $surface): ?array
    {
        if (!in_array($surface, ['web', 'telnet'], true)) {
            return null;
        }
        $parts = explode('/', $reference);
        if (count($parts) > 2 || !self::validId($parts[0])
            || (isset($parts[1]) && !self::validId($parts[1]))) {
            return null;
        }
        $experienceId = $parts[0];
        $entryId = $parts[1] ?? null;
        $experience = $catalog[$experienceId] ?? null;
        if (!is_array($experience) || ($experience['id'] ?? null) !== $experienceId) {
            return null;
        }
        if ($entryId !== null
            && ($experience['source']['manifest']['experience']['default_entry'] ?? null) !== $entryId) {
            return null;
        }
        $launches = [];
        $surfaces = [];
        foreach (['web', 'telnet'] as $targetSurface) {
            $launches[$targetSurface] = ExperienceLaunch::resolve($experience, $targetSurface);
            $surfaces[$targetSurface] = $launches[$targetSurface] !== null ? 'full' : 'unavailable';
        }
        if ($launches['web'] === null && $launches['telnet'] === null) {
            return null;
        }
        return [
            'reference' => $reference,
            'experience' => $experience,
            'experience_id' => $experienceId,
            'entry_id' => $entryId,
            'title' => $experience['name'] ?? $experienceId,
            'description' => $experience['description'] ?? '',
            'surfaces' => $surfaces,
            'launch' => $launches[$surface],
        ];
    }

    /**
     * Navigation-only parent context. Invalid IDs or non-member launches fall
     * back to the host's normal destination; never accept a URL as context.
     */
    public function returnTarget(mixed $placeId, ?array $user, string $backendId, string $surface = 'web'): ?string
    {
        if (!is_string($placeId) || !self::validId($placeId)) {
            return null;
        }
        $place = $this->getPlace($placeId, $user, $surface);
        foreach ($place['members'] ?? [] as $member) {
            if (($member['launch']['id'] ?? null) === $backendId) {
                return '/places/' . rawurlencode($place['id']);
            }
        }
        return null;
    }

    private static function validId(string $id): bool
    {
        return preg_match('/\A[a-z0-9][a-z0-9_-]*\z/D', $id) === 1;
    }
}
