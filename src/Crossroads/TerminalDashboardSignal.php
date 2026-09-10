<?php

namespace BinktermPHP\Crossroads;

use BinktermPHP\DashboardCardRegistry;
use BinktermPHP\ExperienceActivity;
use BinktermPHP\ExperienceState;

/**
 * The terminal main-menu "Crossroads" signal.
 *
 * Reuses the same {@see DashboardPulse} reducer the authenticated web dashboard's
 * Crossroads pulse card uses, scoped to the terminal's authorized catalog, and
 * stripped to the minimum presentation-neutral fields the terminal main menu
 * needs — no usernames, no per-session rows, no experience ids. Only `others`
 * (aggregate headcount) and `recent_self` (historical continuity) earn a line;
 * every other pulse state yields null so the caller renders nothing.
 *
 * Both `GET /api/dashboard/stats` and the telnet/SSH daemons call this so the
 * two paths stay identical. Any failure is contained here and yields null — it
 * must never break the rest of the dashboard stats payload.
 */
class TerminalDashboardSignal
{
    /**
     * @param array $user Authenticated user row (accepts `user_id` or `id`).
     * @return array{state:string,count?:int,experience_name?:string}|null
     */
    public static function compose(array $user): ?array
    {
        try {
            $conditions = DashboardCardRegistry::resolveConditions();
            if (empty($conditions['crossroads_available'])) {
                return null;
            }

            $userId = (int)($user['user_id'] ?? $user['id'] ?? 0);
            $experienceStates = (new ExperienceState())->getExperienceStates($user, 'terminal');
            $authorizedCatalog = array_column($experienceStates, 'experience');
            $experienceActivity = new ExperienceActivity();
            $recentFootprints = $experienceActivity->recentAcrossCatalog($authorizedCatalog, 1);
            $viewerRecentFootprints = $experienceActivity->recentForUser($authorizedCatalog, $userId, 1);
            $pulse = DashboardPulse::compose(
                $experienceStates,
                $userId,
                $recentFootprints,
                $viewerRecentFootprints
            );

            if (($pulse['state'] ?? null) === 'others') {
                return [
                    'state' => 'others',
                    'count' => count($pulse['others'] ?? []),
                ];
            }

            if (($pulse['state'] ?? null) === 'recent_self') {
                $experienceName = trim((string)($pulse['recent_self']['experience_name'] ?? ''));
                if ($experienceName !== '') {
                    return [
                        'state' => 'recent_self',
                        'experience_name' => $experienceName,
                    ];
                }
            }

            return null;
        } catch (\Throwable $e) {
            if (function_exists('getServerLogger')) {
                getServerLogger()->warning('Terminal dashboard Crossroads signal failed: ' . $e->getMessage());
            }
            return null;
        }
    }
}
