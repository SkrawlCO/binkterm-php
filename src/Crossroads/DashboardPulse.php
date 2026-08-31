<?php

declare(strict_types=1);

namespace BinktermPHP\Crossroads;

use BinktermPHP\ExperienceParticipation;

/**
 * View model for the dashboard "Crossroads pulse" card — a small, truthful
 * glimpse on the authenticated web arrival that Crossroads exists and has
 * continuity. It is deliberately NOT a game catalogue.
 *
 * This is a pure reducer over already-fetched, already-authorized reads:
 * an {@see \BinktermPHP\ExperienceState::getExperienceStates()} snapshot and an
 * {@see \BinktermPHP\ExperienceActivity::recentAcrossCatalog()} result. It
 * performs no queries, no discovery, and no authorization of its own.
 *
 * Priority (first match wins):
 *   1. participating — the viewer has an active session somewhere
 *   2. others        — distinct other people are participating right now
 *   3. recent_self   — the viewer's own newest authorized play footprint
 *                      (historical personal relationship: "You played …")
 *   4. recent        — the community's newest authorized play footprint
 *   5. quiet         — nothing to show
 *
 * `recent_self` is historical relationship ONLY. It must never be presented as
 * current participation, resumability / Return, saved progress, current
 * presence, duration, or completion — the composed view model carries no such
 * fields, and the partial renders a bare "You played {Experience} {when}".
 */
final class DashboardPulse
{
    /** Maximum "{username} is playing {Experience}" rows in the `others` state. */
    public const MAX_OTHER_ROWS = 3;

    /**
     * Whether the `/` route should spend an ExperienceState read composing the
     * pulse: only when the card is available AND the viewer has not hidden it.
     *
     * @param array{hidden?:array<int,string>} $dashboardLayout
     */
    public static function shouldCompose(bool $available, array $dashboardLayout): bool
    {
        return $available
            && !in_array('crossroads', $dashboardLayout['hidden'] ?? [], true);
    }

    /**
     * @param array<string,array<string,mixed>> $experienceStates
     *     ExperienceState::getExperienceStates() result, keyed by experience id.
     * @param int $viewerId Numeric id of the authenticated viewer.
     * @param array<int,array<string,mixed>> $recentFootprints
     *     ExperienceActivity::recentAcrossCatalog() result, newest first.
     * @param array<int,array<string,mixed>> $viewerRecentFootprints
     *     ExperienceActivity::recentForUser() result for this viewer, newest
     *     first. Already authorization-scoped to the viewer's web catalog.
     * @return array{
     *     state: 'participating'|'others'|'recent_self'|'recent'|'quiet',
     *     viewer?: array{experience_id:string,experience_name:string},
     *     others?: array<int,array{username:string,experience_id:string,experience_name:string}>,
     *     recent_self?: array{experience_id:string,experience_name:string,occurred_at:string},
     *     recent?: array{username:string,experience_id:string,experience_name:string,first_play:bool}
     * }
     */
    public static function compose(
        array $experienceStates,
        int $viewerId,
        array $recentFootprints,
        array $viewerRecentFootprints = []
    ): array {
        // 1. Is the viewer participating anywhere? Priority over everything.
        if ($viewerId > 0) {
            foreach ($experienceStates as $id => $state) {
                if (!is_array($state) || !is_array($state['players'] ?? null)) {
                    continue;
                }

                if (ExperienceParticipation::findViewerPlayer($state, $viewerId) !== null) {
                    $experience = is_array($state['experience'] ?? null)
                        ? $state['experience']
                        : [];

                    return [
                        'state' => 'participating',
                        'viewer' => [
                            'experience_id' => self::experienceId($experience, (string)$id),
                            'experience_name' => self::experienceName($experience, (string)$id),
                        ],
                    ];
                }
            }
        }

        // 2. Are distinct OTHER people participating right now?
        $others = [];
        $seenPairs = [];

        foreach ($experienceStates as $id => $state) {
            if (!is_array($state) || !is_array($state['players'] ?? null)) {
                continue;
            }

            if (!ExperienceParticipation::hasDistinctOtherPlayer($state, $viewerId)) {
                continue;
            }

            $experience = is_array($state['experience'] ?? null) ? $state['experience'] : [];
            $experienceId = self::experienceId($experience, (string)$id);
            $experienceName = self::experienceName($experience, (string)$id);

            foreach ($state['players'] ?? [] as $player) {
                if (!is_array($player)) {
                    continue;
                }

                $playerId = (int)($player['user_id'] ?? 0);
                $username = trim((string)($player['username'] ?? ''));

                if ($playerId <= 0 || $playerId === $viewerId || $username === '') {
                    continue;
                }

                // One row per (person, Experience) — a person on two nodes of
                // the same Experience is one statement, not two.
                $pairKey = $playerId . "\0" . $experienceId;
                if (isset($seenPairs[$pairKey])) {
                    continue;
                }
                $seenPairs[$pairKey] = true;

                $others[] = [
                    'username' => $username,
                    'experience_id' => $experienceId,
                    'experience_name' => $experienceName,
                ];
            }
        }

        if ($others !== []) {
            return [
                'state' => 'others',
                'others' => array_slice($others, 0, self::MAX_OTHER_ROWS),
            ];
        }

        // 3. The viewer's own most-recent authorized play footprint — shown
        //    when nobody more immediate is around. Historical relationship
        //    only ("You played …"): no presence, Return, progress, or duration.
        if ($viewerId > 0) {
            foreach ($viewerRecentFootprints as $footprint) {
                if (!is_array($footprint)) {
                    continue;
                }

                $experienceId = trim((string)($footprint['experience_id'] ?? ''));
                $occurredAt = trim((string)($footprint['occurred_at'] ?? ''));

                if ($experienceId === '' || $occurredAt === '') {
                    continue;
                }

                $experienceName = trim((string)($footprint['experience_name'] ?? ''));

                return [
                    'state' => 'recent_self',
                    'recent_self' => [
                        'experience_id' => $experienceId,
                        'experience_name' => $experienceName !== '' ? $experienceName : $experienceId,
                        'occurred_at' => $occurredAt,
                    ],
                ];
            }
        }

        // 4. The community's newest authorized recent play footprint.
        foreach ($recentFootprints as $footprint) {
            if (!is_array($footprint)) {
                continue;
            }

            $experienceId = trim((string)($footprint['experience_id'] ?? ''));
            $username = trim((string)($footprint['username'] ?? ''));

            if ($experienceId === '' || $username === '') {
                continue;
            }

            $experienceName = trim((string)($footprint['experience_name'] ?? ''));

            return [
                'state' => 'recent',
                'recent' => [
                    'username' => $username,
                    'experience_id' => $experienceId,
                    'experience_name' => $experienceName !== '' ? $experienceName : $experienceId,
                    'first_play' => ($footprint['type'] ?? '') === 'first_play',
                ],
            ];
        }

        // 5. Nothing to show.
        return ['state' => 'quiet'];
    }

    /**
     * @param array<string,mixed> $experience
     */
    private static function experienceId(array $experience, string $fallbackId): string
    {
        $id = trim((string)($experience['id'] ?? ''));

        return $id !== '' ? $id : $fallbackId;
    }

    /**
     * @param array<string,mixed> $experience
     */
    private static function experienceName(array $experience, string $fallbackId): string
    {
        $name = trim((string)($experience['name'] ?? ''));

        return $name !== '' ? $name : $fallbackId;
    }
}
