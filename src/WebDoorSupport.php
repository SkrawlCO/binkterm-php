<?php

declare(strict_types=1);

namespace BinktermPHP;

/**
 * Shared WebDoor capability and requirement checks.
 *
 * Keeps WebDoor availability rules out of presentation routes so discovery
 * and launch use the same definition of whether an experience is runnable.
 */
final class WebDoorSupport
{
    /**
     * Return WebDoor platform features currently available on this BBS.
     *
     * @return list<string>
     */
    public static function getAvailableFeatures(): array
    {
        $features = [
            'storage',
            'leaderboard',
        ];

        $bbsConfig = BbsConfig::getConfig();
        $creditsConfig = $bbsConfig['credits'] ?? [];

        if (!empty($creditsConfig['enabled'])) {
            $features[] = 'credits';
        }

        return $features;
    }

    /**
     * Determine whether all feature requirements declared by a WebDoor
     * manifest are currently satisfied.
     */
    public static function requirementsSatisfied(array $manifest): bool
    {
        $requirements = $manifest['requirements'] ?? [];
        $requiredFeatures = $requirements['features'] ?? [];

        if (empty($requiredFeatures)) {
            return true;
        }

        $availableFeatures = self::getAvailableFeatures();

        foreach ($requiredFeatures as $required) {
            if (!in_array($required, $availableFeatures, true)) {
                return false;
            }
        }

        return true;
    }
}
