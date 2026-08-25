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
