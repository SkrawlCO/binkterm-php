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
            ], 'web', self::memberRuntimeState($place, $catalog));
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

    /**
     * A place is live when at least one of its members has active callers
     * (any-member, not all-member) -- player_count is the SUM across members,
     * not just a boolean. Reads the already-computed runtime state each
     * member's own catalog entry carries (built once, upstream, from the real
     * ExperienceState snapshot); this never re-queries presence itself.
     * @param array<string,mixed> $place
     * @param array<string,array<string,mixed>> $catalog Authorized catalog, keyed by id
     * @return array{active:bool,player_count:int}
     */
    private static function memberRuntimeState(array $place, array $catalog): array
    {
        $playerCount = 0;
        foreach ($place['members'] ?? [] as $member) {
            if (!is_array($member) || !is_string($member['reference'] ?? null)) {
                continue;
            }
            $resolved = CuratedPlaceCatalog::resolveReference($member['reference'], $catalog, 'web');
            if ($resolved === null) {
                continue;
            }
            $runtime = $catalog[$resolved['experience_id']]['experience_presentation']['runtime'] ?? null;
            if (is_array($runtime)) {
                $playerCount += max(0, (int)($runtime['player_count'] ?? 0));
            }
        }
        return ['active' => $playerCount > 0, 'player_count' => $playerCount];
    }

    /**
     * Use the authorized runtime's presentation and unchanged launch target.
     * Excludes any member marked `featured` -- see featuredMembers() -- so a
     * featured member renders exactly once, never duplicated into the
     * ordinary grid. A place with no featured member behaves exactly as
     * before (every member passes this filter).
     */
    public static function members(array $place): array
    {
        $cards = [];
        foreach ($place['members'] as $member) {
            if (($member['featured'] ?? false) === true) {
                continue;
            }
            $cards[] = self::buildMemberCard($place, $member);
        }
        return $cards;
    }

    /**
     * Slice 2 ("Featured / New in the Patch"): the subset of a place's
     * members marked `featured` in config/crossroads/places.json, in the
     * same resolved-member order as members() would otherwise produce.
     * Empty for every place until a board owner actually sets `featured`
     * on one of its members -- a place with none renders no featured band
     * at all (see curated_place.twig), not an empty decorated region.
     */
    public static function featuredMembers(array $place): array
    {
        $cards = [];
        foreach ($place['members'] as $member) {
            if (($member['featured'] ?? false) !== true) {
                continue;
            }
            $card = self::buildMemberCard($place, $member);
            $card['featured_presentation'] = $member['featured_presentation'] ?? [];
            $cards[] = $card;
        }
        return $cards;
    }

    /** Shared card-view builder for members()/featuredMembers() -- one source of the shape both use. */
    private static function buildMemberCard(array $place, array $member): array
    {
        $experience = $member['experience'];
        $experience['name'] = $member['title'];
        $experience['description'] = $member['description'];
        $experience['surfaces'] = $member['surfaces'];
        // Slice 4: an explicit `launch_url` override (see
        // CuratedPlaceCatalog::getPlace()) is used exactly as given -- the
        // member's own real canonical URL, no parent_place_id appended --
        // rather than the derived /games/{id} wrapper launch every other
        // member still gets.
        $destinationUrl = is_string($member['launch_url'] ?? null)
            ? $member['launch_url']
            : (isset($member['launch']['url'])
                ? $member['launch']['url']
                    . (str_contains($member['launch']['url'], '?') ? '&' : '?')
                    . http_build_query(['parent_place_id' => $place['id']])
                : null);
        return [
            'reference' => $member['reference'],
            'destination_url' => $destinationUrl,
            'show_surfaces' => true,
            'experience_presentation' => ExperiencePresentation::build($experience, 'web'),
        ];
    }
}
