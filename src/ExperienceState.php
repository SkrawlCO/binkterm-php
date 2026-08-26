<?php

/*
 * Copyright Matthew Asham and BinktermPHP Contributors
 *
 * Redistribution and use in source and binary forms, with or without modification, are permitted provided that the
 * above copyright notice, this list of conditions and the following disclaimer are met.
 */

namespace BinktermPHP;

use PDO;

/**
 * Read-side state for playable Experiences.
 *
 * ExperienceState does not own runtime state. DoorSessionManager remains
 * authoritative for active door sessions, while user_sessions remains
 * authoritative for live public presence.
 */
class ExperienceState
{
    private PDO $db;
    private GameCatalog $catalog;

    public function __construct(?PDO $db = null, ?GameCatalog $catalog = null)
    {
        $this->db = $db ?? Database::getInstance()->getPdo();
        $this->catalog = $catalog ?? new GameCatalog();
    }

    /**
     * Get the current state of all normalized Experiences available
     * to the current user and surface.
     *
     * This is the collection-level read model for Crossroads discovery.
     * DoorSessionManager remains authoritative for active sessions, while
     * user_sessions remains authoritative for live public presence.
     *
     * @param array<string,mixed>|null $user
     * @param string $surface
     * @return array<string,array<string,mixed>>
     */
    public function getExperienceStates(
        ?array $user = null,
        string $surface = 'web'
    ): array {
        $experiences = $this->catalog->getEnabledGames($user, $surface);

        if (empty($experiences)) {
            return [];
        }

        $experienceIds = array_values(array_filter(
            array_keys($experiences),
            static fn($id): bool => is_string($id) && trim($id) !== ''
        ));

        if (empty($experienceIds)) {
            return [];
        }

        $now = gmdate('Y-m-d H:i:s');
        $presenceSince = gmdate('Y-m-d H:i:s', time() - (15 * 60));

        $placeholders = implode(',', array_fill(0, count($experienceIds), '?'));

        $stmt = $this->db->prepare("
            SELECT
                ds.session_id,
                ds.user_id,
                ds.door_id,
                ds.node_number,
                ds.started_at,
                u.username
            FROM door_sessions ds
            JOIN users u
                ON u.id = ds.user_id
            WHERE ds.door_id IN ($placeholders)
              AND ds.ended_at IS NULL
              AND ds.expires_at > ?
            ORDER BY ds.door_id ASC, ds.started_at ASC
        ");

        $stmt->execute([...$experienceIds, $now]);
        $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $userIds = array_values(array_unique(array_map(
            static fn(array $session): int => (int)$session['user_id'],
            $sessions
        )));

        $presenceByUser = [];

        if (!empty($userIds)) {
            $userPlaceholders = implode(',', array_fill(0, count($userIds), '?'));

            $presenceStmt = $this->db->prepare("
                SELECT
                    user_id,
                    public_activity
                FROM user_sessions
                WHERE user_id IN ($userPlaceholders)
                  AND expires_at > ?
                  AND last_activity > ?
                  AND public_activity IS NOT NULL
                  AND public_activity <> ''
                ORDER BY user_id, last_activity DESC
            ");

            $presenceStmt->execute([
                ...$userIds,
                $now,
                $presenceSince,
            ]);

            foreach ($presenceStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $userId = (int)$row['user_id'];

                // Rows are ordered newest-first per user, so the first
                // qualifying row preserves getExperienceState() semantics.
                if (!array_key_exists($userId, $presenceByUser)) {
                    $presenceByUser[$userId] = $row['public_activity'];
                }
            }
        }

        $states = [];

        foreach ($experiences as $experienceId => $experience) {
            $states[$experienceId] = [
                'experience' => $experience,
                'active' => false,
                'session_count' => 0,
                'player_count' => 0,
                'players' => [],
            ];
        }

        $seenUsersByExperience = [];

        foreach ($sessions as $session) {
            $experienceId = (string)$session['door_id'];

            if (!isset($states[$experienceId])) {
                continue;
            }

            $userId = (int)$session['user_id'];

            $states[$experienceId]['active'] = true;
            $states[$experienceId]['session_count']++;

            $states[$experienceId]['players'][] = [
                'user_id' => $userId,
                'username' => $session['username'],
                'session_id' => $session['session_id'],
                'presence' => $presenceByUser[$userId] ?? null,
                'node' => (int)$session['node_number'],
                'started_at' => strtotime($session['started_at']),
            ];

            $seenUsersByExperience[$experienceId][$userId] = true;
        }

        foreach ($states as $experienceId => &$state) {
            $state['player_count'] = count(
                $seenUsersByExperience[$experienceId] ?? []
            );
        }
        unset($state);

        return $states;
    }

    /**
     * Get the current state of one normalized Experience.
     *
     * @param string $experienceId
     * @param array<string,mixed>|null $user
     * @param string $surface
     * @return array<string,mixed>|null
     */
    public function getExperienceState(
        string $experienceId,
        ?array $user = null,
        string $surface = 'web'
    ): ?array {
        $experienceId = trim($experienceId);

        if ($experienceId === '') {
            return null;
        }

        $experiences = $this->catalog->getEnabledGames($user, $surface);
        $experience = $experiences[$experienceId] ?? null;

        if (!is_array($experience)) {
            return null;
        }

        $now = gmdate('Y-m-d H:i:s');
        $presenceSince = gmdate('Y-m-d H:i:s', time() - (15 * 60));

        $stmt = $this->db->prepare("
            SELECT
                ds.session_id,
                ds.user_id,
                ds.door_id,
                ds.node_number,
                ds.started_at,
                u.username
            FROM door_sessions ds
            JOIN users u
                ON u.id = ds.user_id
            WHERE ds.door_id = ?
              AND ds.ended_at IS NULL
              AND ds.expires_at > ?
            ORDER BY ds.started_at ASC
        ");

        $stmt->execute([$experienceId, $now]);
        $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $players = [];
        $seenUsers = [];

        foreach ($sessions as $session) {
            $userId = (int)$session['user_id'];

            $presenceStmt = $this->db->prepare("
                SELECT public_activity
                FROM user_sessions
                WHERE user_id = ?
                  AND expires_at > ?
                  AND last_activity > ?
                  AND public_activity IS NOT NULL
                  AND public_activity <> ''
                ORDER BY last_activity DESC
                LIMIT 1
            ");

            $presenceStmt->execute([
                $userId,
                $now,
                $presenceSince,
            ]);

            $presence = $presenceStmt->fetchColumn();

            $players[] = [
                'user_id' => $userId,
                'username' => $session['username'],
                'session_id' => $session['session_id'],
                'presence' => $presence !== false ? $presence : null,
                'node' => (int)$session['node_number'],
                'started_at' => strtotime($session['started_at']),
            ];

            $seenUsers[$userId] = true;
        }

        return [
            'experience' => $experience,
            'active' => !empty($sessions),
            'session_count' => count($sessions),
            'player_count' => count($seenUsers),
            'players' => $players,
        ];
    }
}
