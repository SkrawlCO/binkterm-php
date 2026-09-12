<?php

declare(strict_types=1);

namespace BinktermPHP;

/** Adapt places and resolved members to the existing card partial, without I/O. */
final class CuratedPlacePresentation
{
    /**
     * Destination shelves only. $games is the existing caller-authorized catalog with
     * presentation attached; never replace the runtime catalog with this result.
     * Only a resolvable default entry can own a runtime's standalone card.
     */
    public static function shelfEntries(array $games, array $definitions): array
    {
        return array_merge(self::runtimeEntries($games, $definitions), self::cards($games, $definitions));
    }

    /** Filter only standalone shelf cards; the authorized runtime catalog is unchanged. */
    public static function runtimeEntries(array $games, array $definitions): array
    {
        $definitions = array_values(array_filter($definitions, static fn (array $place): bool =>
            ($place['enabled'] ?? false) === true
            && ($place['kind'] ?? null) === 'place'
            && ($place['parent'] ?? null) === 'curated'
        ));
        $catalog = array_column($games, null, 'id');
        $hidden = [];
        foreach ($definitions as $place) {
            foreach ($place['members'] as $member) {
                if (($member['primary_presentation'] ?? false) !== true
                    || !is_string($member['reference'] ?? null)) {
                    continue;
                }
                $resolved = CuratedPlaceCatalog::resolveReference($member['reference'], $catalog, 'web');
                if ($resolved !== null) {
                    $hidden[$resolved['experience_id']] = true;
                }
            }
        }
        $visible = array_values(array_filter($games, static fn (array $game): bool =>
            !isset($hidden[$game['id']])
        ));
        return $visible;
    }

    /** Place cards are shelf-only destinations, never runtime catalog rows. */
    public static function cards(array $games, array $definitions): array
    {
        $catalog = array_column($games, null, 'id');
        $cards = [];
        foreach ($definitions as $place) {
            if (($place['parent'] ?? null) !== 'curated' || ($place['enabled'] ?? false) !== true || ($place['kind'] ?? null) !== 'place') {
                continue;
            }
            $view = ExperiencePresentation::build([
                'id' => $place['id'],
                'name' => $place['name'],
                'description' => $place['description'],
                'category' => 'place',
                'curation' => ['curated' => true, 'order' => $place['order'] ?? PHP_INT_MAX],
                'surfaces' => self::memberSurfaces($place, $catalog),
                'presentation' => ['icon_url' => $place['icon'] ?? '/favicon.svg'],
            ], 'web');
            $cards[] = [
                'kind' => 'place',
                'destination_url' => '/places/' . rawurlencode($place['id']),
                'experience_presentation' => $view,
            ];
        }
        return $cards;
    }

    /**
     * A place matches a surface filter when at least one of its members is
     * genuinely available on that surface (any-member aggregation, not
     * all-member) -- resolved through the same CuratedPlaceCatalog truth the
     * place's own /places/<id> page uses per member, never inferred from
     * membership or game type alone.
     * @param array<string,mixed> $place
     * @param array<string,array<string,mixed>> $catalog Authorized catalog, keyed by id
     * @return array{web:string,telnet:string}
     */
    private static function memberSurfaces(array $place, array $catalog): array
    {
        $surfaces = ['web' => 'unavailable', 'telnet' => 'unavailable'];
        foreach ($place['members'] ?? [] as $member) {
            if (!is_array($member) || !is_string($member['reference'] ?? null)) {
                continue;
            }
            $resolved = CuratedPlaceCatalog::resolveReference($member['reference'], $catalog, 'web');
            if ($resolved === null) {
                continue;
            }
            foreach (['web', 'telnet'] as $targetSurface) {
                if (($resolved['surfaces'][$targetSurface] ?? null) === 'full') {
                    $surfaces[$targetSurface] = 'full';
                }
            }
        }
        return $surfaces;
    }

    /** Use the authorized runtime's presentation and unchanged launch target. */
    public static function members(array $place): array
    {
        $cards = [];
        foreach ($place['members'] as $member) {
            $experience = $member['experience'];
            $experience['name'] = $member['title'];
            $experience['description'] = $member['description'];
            $experience['surfaces'] = $member['surfaces'];
            $cards[] = [
                'reference' => $member['reference'],
                'destination_url' => isset($member['launch']['url'])
                    ? $member['launch']['url']
                        . (str_contains($member['launch']['url'], '?') ? '&' : '?')
                        . http_build_query(['parent_place_id' => $place['id']])
                    : null,
                'show_surfaces' => true,
                'experience_presentation' => ExperiencePresentation::build($experience, 'web'),
            ];
        }
        return $cards;
    }
}
