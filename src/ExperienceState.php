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

        $doorExperienceIds = [];
        $webExperienceIds = [];
        // Session rows key on the real backend id. A grouped Experience
        // (ExperienceComposition) has more than one member backend; map every
        // member id back to its canonical Experience so presence from any member
        // is attributed to the one card. For an ungrouped entry backendMembers()
        // yields a single pair whose id equals the catalog id, so these maps are
        // identity and behaviour is unchanged.
        $doorCanonicalByBackendId = [];
        $webCanonicalByBackendId = [];

        foreach ($experiences as $experienceId => $experience) {
            foreach (ExperienceComposition::backendMembers($experience) as $member) {
                if ($member['type'] === 'web') {
                    $webExperienceIds[] = $member['id'];
                    $webCanonicalByBackendId[$member['id']] = (string)$experienceId;
                } else {
                    // Native, DOS, and JS-DOS all use door_sessions today.
                    $doorExperienceIds[] = $member['id'];
                    $doorCanonicalByBackendId[$member['id']] = (string)$experienceId;
                }
            }
        }

        $sessions = [];

        if (!empty($doorExperienceIds)) {
            $placeholders = implode(
                ',',
                array_fill(0, count($doorExperienceIds), '?')
            );

            $stmt = $this->db->prepare("
                SELECT
                    ds.session_id,
                    ds.user_id,
                    ds.door_id AS experience_id,
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

            $stmt->execute([...$doorExperienceIds, $now]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $row['experience_id'] =
                    $doorCanonicalByBackendId[(string)$row['experience_id']]
                    ?? (string)$row['experience_id'];
                $sessions[] = $row;
            }
        }

        if (!empty($webExperienceIds)) {
            $placeholders = implode(
                ',',
                array_fill(0, count($webExperienceIds), '?')
            );

            $stmt = $this->db->prepare("
                SELECT
                    ws.session_id,
                    ws.user_id,
                    ws.game_id AS experience_id,
                    NULL AS node_number,
                    ws.created_at AS started_at,
                    u.username
                FROM webdoor_sessions ws
                JOIN users u
                    ON u.id = ws.user_id
                WHERE ws.game_id IN ($placeholders)
                  AND ws.ended_at IS NULL
                  AND ws.expires_at > ?
                ORDER BY ws.game_id ASC, ws.created_at ASC
            ");

            $stmt->execute([...$webExperienceIds, $now]);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $row['experience_id'] =
                    $webCanonicalByBackendId[(string)$row['experience_id']]
                    ?? (string)$row['experience_id'];
                $sessions[] = $row;
            }
        }

        usort(
            $sessions,
            static function (array $a, array $b): int {
                $experienceCompare = strcmp(
                    (string)$a['experience_id'],
                    (string)$b['experience_id']
                );

                if ($experienceCompare !== 0) {
                    return $experienceCompare;
                }

                return strcmp(
                    (string)$a['started_at'],
                    (string)$b['started_at']
                );
            }
        );

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
            $experienceId = (string)$session['experience_id'];

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
                'presence_state' => 'playing',
                'node' => $session['node_number'] !== null
                    ? (int)$session['node_number']
                    : null,
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

        // A grouped Experience (ExperienceComposition) has more than one member
        // backend, possibly across both session tables. Query each member and
        // attribute every row to this one requested Experience. For an ungrouped
        // entry this is a single query keyed by the catalog id -- unchanged.
        $doorMemberIds = [];
        $webMemberIds = [];
        foreach (ExperienceComposition::backendMembers($experience) as $member) {
            if ($member['type'] === 'web') {
                $webMemberIds[] = $member['id'];
            } else {
                $doorMemberIds[] = $member['id'];
            }
        }

        $sessions = [];

        if ($doorMemberIds !== []) {
            $placeholders = implode(',', array_fill(0, count($doorMemberIds), '?'));
            $stmt = $this->db->prepare("
                SELECT
                    ds.session_id,
                    ds.user_id,
                    ds.door_id AS experience_id,
                    ds.node_number,
                    ds.started_at,
                    u.username
                FROM door_sessions ds
                JOIN users u
                    ON u.id = ds.user_id
                WHERE ds.door_id IN ($placeholders)
                  AND ds.ended_at IS NULL
                  AND ds.expires_at > ?
                ORDER BY ds.started_at ASC
            ");
            $stmt->execute([...$doorMemberIds, $now]);
            $sessions = array_merge($sessions, $stmt->fetchAll(PDO::FETCH_ASSOC));
        }

        if ($webMemberIds !== []) {
            $placeholders = implode(',', array_fill(0, count($webMemberIds), '?'));
            $stmt = $this->db->prepare("
                SELECT
                    ws.session_id,
                    ws.user_id,
                    ws.game_id AS experience_id,
                    NULL AS node_number,
                    ws.created_at AS started_at,
                    u.username
                FROM webdoor_sessions ws
                JOIN users u
                    ON u.id = ws.user_id
                WHERE ws.game_id IN ($placeholders)
                  AND ws.ended_at IS NULL
                  AND ws.expires_at > ?
                ORDER BY ws.created_at ASC
            ");
            $stmt->execute([...$webMemberIds, $now]);
            $sessions = array_merge($sessions, $stmt->fetchAll(PDO::FETCH_ASSOC));
        }

        // Keep the single-Experience "oldest session first" order when a grouped
        // Experience merged rows from both session tables. A single-table result
        // is already ordered, so this is a no-op for ungrouped entries.
        usort(
            $sessions,
            static fn(array $a, array $b): int => strcmp(
                (string)$a['started_at'],
                (string)$b['started_at']
            )
        );

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
                'presence_state' => 'playing',
                'node' => $session['node_number'] !== null
                    ? (int)$session['node_number']
                    : null,
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

    /**
     * Anonymous-safe aggregate occupancy for every web-discoverable Experience.
     *
     * This is the state read for logged-out discovery. It deliberately does not
     * build a roster: no users join, so the result carries no username, user id,
     * session id, node, timestamp, or presence string. Only per-Experience
     * aggregate counts are returned.
     *
     * Active-session semantics match getExperienceState(): rows in
     * door_sessions / webdoor_sessions with ended_at IS NULL and a live
     * expires_at. session_count is the active row count; player_count is the
     * distinct user count (a user in the same Experience on two nodes counts
     * once).
     *
     * @return array<string,array{active:bool,session_count:int,player_count:int}>
     */
    public function getPublicExperienceAggregates(string $surface = 'web'): array
    {
        $experiences = $this->catalog->getEnabledGames(null, $surface);

        if (empty($experiences)) {
            return [];
        }

        $doorIds = [];
        $webIds = [];
        // Grouped Experiences: map each member backend id to its canonical id so
        // aggregate occupancy is counted once per card. Identity for ungrouped.
        $doorCanonicalByBackendId = [];
        $webCanonicalByBackendId = [];

        foreach ($experiences as $experienceId => $experience) {
            $experienceId = (string)$experienceId;

            if (trim($experienceId) === '') {
                continue;
            }

            foreach (ExperienceComposition::backendMembers($experience) as $member) {
                if ($member['type'] === 'web') {
                    $webIds[] = $member['id'];
                    $webCanonicalByBackendId[$member['id']] = $experienceId;
                } else {
                    $doorIds[] = $member['id'];
                    $doorCanonicalByBackendId[$member['id']] = $experienceId;
                }
            }
        }

        $now = gmdate('Y-m-d H:i:s');

        $sessionRowCounts = [];
        $distinctUsers = [];

        if (!empty($doorIds)) {
            $placeholders = implode(',', array_fill(0, count($doorIds), '?'));

            $stmt = $this->db->prepare("
                SELECT ds.door_id AS experience_id, ds.user_id
                FROM door_sessions ds
                WHERE ds.door_id IN ($placeholders)
                  AND ds.ended_at IS NULL
                  AND ds.expires_at > ?
            ");
            $stmt->execute([...$doorIds, $now]);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $experienceId = $doorCanonicalByBackendId[(string)$row['experience_id']]
                    ?? (string)$row['experience_id'];
                $sessionRowCounts[$experienceId] =
                    ($sessionRowCounts[$experienceId] ?? 0) + 1;
                $distinctUsers[$experienceId][(int)$row['user_id']] = true;
            }
        }

        if (!empty($webIds)) {
            $placeholders = implode(',', array_fill(0, count($webIds), '?'));

            $stmt = $this->db->prepare("
                SELECT ws.game_id AS experience_id, ws.user_id
                FROM webdoor_sessions ws
                WHERE ws.game_id IN ($placeholders)
                  AND ws.ended_at IS NULL
                  AND ws.expires_at > ?
            ");
            $stmt->execute([...$webIds, $now]);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $experienceId = $webCanonicalByBackendId[(string)$row['experience_id']]
                    ?? (string)$row['experience_id'];
                $sessionRowCounts[$experienceId] =
                    ($sessionRowCounts[$experienceId] ?? 0) + 1;
                $distinctUsers[$experienceId][(int)$row['user_id']] = true;
            }
        }

        $aggregates = [];

        foreach ($experiences as $experienceId => $experience) {
            $experienceId = (string)$experienceId;
            $sessionCount = $sessionRowCounts[$experienceId] ?? 0;

            $aggregates[$experienceId] = [
                'active' => $sessionCount > 0,
                'session_count' => $sessionCount,
                'player_count' => count($distinctUsers[$experienceId] ?? []),
            ];
        }

        return $aggregates;
    }

    /**
     * Site-wide distinct count of people currently active in any
     * web-discoverable Experience.
     *
     * Name-free companion to getPublicExperienceAggregates() for the
     * "around the Crossroads" line. Returns a single integer; no identity
     * ever leaves this method.
     */
    public function getPublicActivePeopleCount(string $surface = 'web'): int
    {
        $experiences = $this->catalog->getEnabledGames(null, $surface);

        if (empty($experiences)) {
            return 0;
        }

        $doorIds = [];
        $webIds = [];

        // Site-wide distinct people: query every member backend of every
        // Experience. No per-Experience attribution is needed (the result is one
        // global distinct-user set), so no canonical map. Identity for ungrouped.
        foreach ($experiences as $experienceId => $experience) {
            if (trim((string)$experienceId) === '') {
                continue;
            }

            foreach (ExperienceComposition::backendMembers($experience) as $member) {
                if ($member['type'] === 'web') {
                    $webIds[] = $member['id'];
                } else {
                    $doorIds[] = $member['id'];
                }
            }
        }

        $now = gmdate('Y-m-d H:i:s');
        $activeUserIds = [];

        if (!empty($doorIds)) {
            $placeholders = implode(',', array_fill(0, count($doorIds), '?'));

            $stmt = $this->db->prepare("
                SELECT DISTINCT ds.user_id
                FROM door_sessions ds
                WHERE ds.door_id IN ($placeholders)
                  AND ds.ended_at IS NULL
                  AND ds.expires_at > ?
            ");
            $stmt->execute([...$doorIds, $now]);

            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $userId) {
                $activeUserIds[(int)$userId] = true;
            }
        }

        if (!empty($webIds)) {
            $placeholders = implode(',', array_fill(0, count($webIds), '?'));

            $stmt = $this->db->prepare("
                SELECT DISTINCT ws.user_id
                FROM webdoor_sessions ws
                WHERE ws.game_id IN ($placeholders)
                  AND ws.ended_at IS NULL
                  AND ws.expires_at > ?
            ");
            $stmt->execute([...$webIds, $now]);

            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $userId) {
                $activeUserIds[(int)$userId] = true;
            }
        }

        return count($activeUserIds);
    }
}
