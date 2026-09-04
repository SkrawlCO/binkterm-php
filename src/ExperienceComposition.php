<?php

namespace BinktermPHP;

/**
 * Pure normalization of several backend catalog entries that belong to one
 * product Experience into a single normalized Experience.
 *
 * Existing model: one Experience = one catalog id = one backend, and that
 * backend declares which surfaces it serves (a native line/raw door serves both
 * web and telnet as two transports of the same runtime; a WebDoor serves web).
 * That is correct when the two surfaces are transports of one implementation.
 *
 * Some product Experiences instead have two *distinct* implementations, one per
 * surface -- e.g. a graphical browser client (a WebDoor) and a terminal client (a
 * NativeDoor) that share the same upstream service/world/identity. They are not
 * aliases (they launch different things); they are two surface-implementations
 * of one Experience. This helper composes them into one normalized entry.
 *
 * Opt-in metadata, in a manifest's existing `experience` block:
 *
 *   experience.group    string          shared product-Experience key. Every
 *                                       discovered entry carrying the same value
 *                                       is one Experience; the normalized entry
 *                                       is keyed by this value (its canonical
 *                                       id -- used for /experiences/{id},
 *                                       curation and shelves).
 *   experience.primary  bool (opt)      exactly one member of a group must be
 *                                       primary. The primary supplies the
 *                                       card's identity/presentation (name,
 *                                       description, icon, category, author,
 *                                       version) deterministically, regardless
 *                                       of discovery order, and is the default
 *                                       backend for surface-less launch
 *                                       resolution.
 *   experience.surface  "web"|"telnet"  the launch surface this backend
 *                                       contributes to the group. The member
 *                                       must itself be 'full' on that surface.
 *
 * Compatibility: when no discovered entry carries `experience.group`, compose()
 * returns its input array unchanged (identical). Entries without the metadata
 * are never rewritten. No manifest needs migration.
 *
 * Fail closed: a group is dropped whole (and a warning logged) when it is
 * ambiguous -- zero or more than one primary member, two members claiming the
 * same surface, or a member whose declared surface is invalid or is not 'full'
 * in that member's own surfaces map. Nothing is silently chosen by discovery
 * order.
 *
 * No I/O, renders nothing. GameCatalog::getEnabledGames() calls compose() after
 * backend discovery and before applyCuration().
 */
final class ExperienceComposition
{
    private const SURFACES = ['web', 'telnet'];

    /**
     * @param array<string,array<string,mixed>> $experiences discovered entries,
     *     keyed by backend/catalog id
     * @return array<string,array<string,mixed>> normalized entries; ungrouped
     *     entries pass through unchanged, grouped members collapse into one
     *     entry keyed by the group's canonical id
     */
    public static function compose(array $experiences): array
    {
        $groups = [];
        $passthrough = [];

        foreach ($experiences as $id => $experience) {
            $group = is_array($experience)
                ? trim((string)($experience['grouping']['group'] ?? ''))
                : '';

            if ($group === '') {
                $passthrough[$id] = $experience;
                continue;
            }

            $groups[$group][(string)$id] = $experience;
        }

        if ($groups === []) {
            return $experiences;
        }

        $result = $passthrough;

        foreach ($groups as $group => $members) {
            $normalized = self::normalizeGroup((string)$group, $members);
            if ($normalized !== null) {
                $result[(string)$group] = $normalized;
            }
        }

        return $result;
    }

    /**
     * @param array<string,array<string,mixed>> $members
     * @return array<string,mixed>|null
     */
    private static function normalizeGroup(string $group, array $members): ?array
    {
        $primary = null;
        $bySurface = [];

        foreach ($members as $memberId => $member) {
            $grouping = is_array($member['grouping'] ?? null) ? $member['grouping'] : [];
            $surface = strtolower(trim((string)($grouping['surface'] ?? '')));

            if (!in_array($surface, self::SURFACES, true)) {
                return self::drop($group, "member '{$memberId}' has an invalid experience.surface");
            }

            if (($member['surfaces'][$surface] ?? null) !== 'full') {
                return self::drop(
                    $group,
                    "member '{$memberId}' declares surface '{$surface}' but is not 'full' on it"
                );
            }

            if (isset($bySurface[$surface])) {
                return self::drop(
                    $group,
                    "members '{$bySurface[$surface]['id']}' and '{$memberId}' both claim surface '{$surface}'"
                );
            }

            $bySurface[$surface] = ['id' => (string)$memberId, 'member' => $member];

            if (!empty($grouping['primary'])) {
                if ($primary !== null) {
                    return self::drop($group, 'more than one primary member');
                }
                $primary = $member;
            }
        }

        if ($primary === null) {
            return self::drop($group, 'no primary member discovered');
        }

        // The primary member's normalized entry is the card. Re-key it to the
        // canonical group id and overlay per-surface availability + launch.
        $normalized = $primary;
        $normalized['id'] = $group;

        $status = ['web' => 'unavailable', 'telnet' => 'unavailable'];
        $surfaceBackends = [];
        $memberBackends = [];

        foreach ($bySurface as $surface => $entry) {
            $member = $entry['member'];
            // A surface is 'full' for the group only when an explicit member
            // contributes it. A member's incidental surface (e.g. a NativeDoor's
            // managed browser-terminal 'web') is not the group's surface.
            $status[$surface] = 'full';

            $backend = is_array($member['backend'] ?? null) ? $member['backend'] : null;
            if ($backend !== null) {
                $surfaceBackends[$surface] = $backend;
                $memberBackends[] = $backend;
            }
        }

        $normalized['surfaces'] = $status;
        $normalized['surface_backends'] = $surfaceBackends;
        $normalized['members'] = $memberBackends;
        // 'backend' stays the primary member's backend: surface-less launch
        // resolution and single-backend consumers keep working unchanged.

        // Re-derive the launch action from the composed surfaces so it cannot
        // drift from ExperienceLaunch resolution.
        if (!is_array($normalized['actions'] ?? null)) {
            $normalized['actions'] = [];
        }
        $normalized['actions']['launch'] =
            ExperienceLaunch::canLaunch($normalized, 'web')
            || ExperienceLaunch::canLaunch($normalized, 'telnet');

        return $normalized;
    }

    /**
     * The backend (type, id) pairs whose session and activity rows belong to a
     * normalized Experience: every member of a grouped Experience (from
     * `members`), or the single `backend` of an ungrouped one.
     *
     * Read models (ExperienceState, ExperienceActivity) use this so a grouped
     * Experience's live presence and recent activity resolve across all its
     * member backend ids to the one canonical Experience -- with no change to
     * how any row is stored. For an ungrouped entry the result is a single pair
     * equal to what those read models derive today, so their behaviour is
     * unchanged.
     *
     * @param array<string,mixed> $experience a normalized GameCatalog entry
     * @return list<array{type:string,id:string}>
     */
    public static function backendMembers(array $experience): array
    {
        $raw = is_array($experience['members'] ?? null) && $experience['members'] !== []
            ? $experience['members']
            : (is_array($experience['backend'] ?? null) ? [$experience['backend']] : []);

        $pairs = [];
        foreach ($raw as $member) {
            if (!is_array($member)) {
                continue;
            }
            $id = trim((string)($member['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $pairs[] = [
                'type' => trim((string)($member['type'] ?? '')),
                'id' => $id,
            ];
        }

        if ($pairs === []) {
            // Legacy / minimal entry with no backend block and no grouping:
            // fall back to the catalog id so historical door_sessions behaviour
            // is preserved exactly.
            $id = trim((string)($experience['id'] ?? ''));
            if ($id !== '') {
                $pairs[] = [
                    'type' => trim((string)($experience['backend']['type'] ?? '')),
                    'id' => $id,
                ];
            }
        }

        return $pairs;
    }

    private static function drop(string $group, string $reason): null
    {
        if (function_exists('getServerLogger')) {
            getServerLogger()->warning(
                "ExperienceComposition: dropped Experience group '{$group}' -- {$reason}"
            );
        }

        return null;
    }
}
