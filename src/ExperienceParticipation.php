<?php

namespace BinktermPHP;

use PDO;

/**
 * Normalized viewer participation operations for Experiences.
 *
 * ExperienceState remains the read-side authority for determining whether a
 * viewer is currently participating. This service owns normalized viewer
 * actions and explicit participation termination while keeping backend
 * session details out of the Experience lobby.
 */
final class ExperienceParticipation
{
    private ?PDO $db;
    private ?DoorSessionManager $doorSessions;

    public function __construct(
        ?PDO $db = null,
        ?DoorSessionManager $doorSessions = null
    ) {
        $this->db = $db;
        $this->doorSessions = $doorSessions;
    }

    /**
     * Find the authenticated viewer's active player record.
     *
     * @param array<string,mixed> $state
     * @return array<string,mixed>|null
     */
    public static function findViewerPlayer(
        array $state,
        int $userId
    ): ?array {
        if ($userId <= 0) {
            return null;
        }

        foreach ($state['players'] ?? [] as $player) {
            if (
                is_array($player)
                && (int)($player['user_id'] ?? 0) === $userId
            ) {
                return $player;
            }
        }

        return null;
    }

    /**
     * Whether an Experience state snapshot contains at least one active user
     * other than the given viewer.
     *
     * This is the canonical "is anyone else here?" predicate. It powers the
     * web Crossroads "Live Now" section, which asks *where are other people*,
     * as distinct from participation membership (see {@see findViewerPlayer()}).
     * The two questions overlap intentionally: an Experience the viewer shares
     * with another caller satisfies both.
     *
     * Multiple concurrent sessions held by one account collapse to one person,
     * so a roster containing only the viewer's own sessions is not "live". A
     * null, empty, or malformed roster is treated as nobody present, and player
     * rows without a positive integer user id are ignored.
     *
     * @param array<string,mixed>|null $state An ExperienceState snapshot, i.e.
     *     the shape produced by {@see \BinktermPHP\ExperienceState}.
     * @param int $viewerId Numeric id of the authenticated viewer, or 0 when
     *     there is no viewer (any real player then counts as "other").
     */
    public static function hasDistinctOtherPlayer(
        ?array $state,
        int $viewerId
    ): bool {
        if ($state === null) {
            return false;
        }

        $players = $state['players'] ?? null;

        if (!is_array($players)) {
            return false;
        }

        foreach ($players as $player) {
            if (!is_array($player)) {
                continue;
            }

            $playerId = (int)($player['user_id'] ?? 0);

            if ($playerId > 0 && $playerId !== $viewerId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalized actions available to the current viewer.
     *
     * @param array<string,mixed> $experience
     * @param array<string,mixed>|null $viewerPlayer
     * @return array{play:bool,return:bool,end:bool}
     */
    public static function viewerActions(
        array $experience,
        ?array $viewerPlayer,
        string $surface = 'web'
    ): array {
        $participating = $viewerPlayer !== null;
        $launchable = ExperienceLaunch::canLaunch($experience, $surface);

        return [
            'play' => !$participating && $launchable,
            'return' => $participating && $launchable,
            'end' => $participating && self::canEnd($experience),
        ];
    }

    /**
     * Whether this backend supports explicit Experience termination.
     *
     * @param array<string,mixed> $experience
     */
    public static function canEnd(array $experience): bool
    {
        $backend = $experience['backend'] ?? null;

        if (!is_array($backend)) {
            return false;
        }

        $type = trim((string)($backend['type'] ?? ''));
        $id = trim((string)($backend['id'] ?? ''));

        return $id !== ''
            && in_array($type, ['native', 'dos', 'jsdos', 'web'], true);
    }

    /**
     * Explicitly end the authenticated viewer's current participation.
     *
     * This method always scopes termination to the viewer session, user, and
     * Experience backend. Browser detach/navigation is intentionally not an
     * implicit termination path.
     *
     * @param array<string,mixed> $experience
     * @param array<string,mixed> $user
     * @param array<string,mixed> $viewerPlayer
     */
    public function end(
        array $experience,
        array $user,
        array $viewerPlayer
    ): bool {
        $backend = $experience['backend'] ?? null;

        if (!is_array($backend)) {
            return false;
        }

        $type = trim((string)($backend['type'] ?? ''));
        $backendId = trim((string)($backend['id'] ?? ''));
        $sessionId = trim((string)($viewerPlayer['session_id'] ?? ''));
        $userId = (int)($user['user_id'] ?? $user['id'] ?? 0);

        if (
            $backendId === ''
            || $sessionId === ''
            || $userId <= 0
            || (int)($viewerPlayer['user_id'] ?? 0) !== $userId
        ) {
            return false;
        }

        return match ($type) {
            'native', 'dos' => $this->endManagedDoor(
                $sessionId,
                $userId,
                $backendId
            ),
            'jsdos' => $this->endJsdos(
                $sessionId,
                $userId,
                $backendId
            ),
            'web' => $this->endWebDoor(
                $sessionId,
                $userId,
                $backendId
            ),
            default => false,
        };
    }

    private function endManagedDoor(
        string $sessionId,
        int $userId,
        string $doorId
    ): bool {
        $manager = $this->doorSessions
            ?? new DoorSessionManager(null, true);

        $session = $manager->getSession($sessionId);

        if (
            !$session
            || (int)($session['user_id'] ?? 0) !== $userId
            || (string)($session['door_id'] ?? '') !== $doorId
        ) {
            return false;
        }

        return $manager->endSession($sessionId);
    }

    private function endJsdos(
        string $sessionId,
        int $userId,
        string $doorId
    ): bool {
        $stmt = $this->db()->prepare("
            UPDATE door_sessions
               SET ended_at = ?
             WHERE session_id = ?
               AND user_id = ?
               AND door_id = ?
               AND door_type = 'jsdos'
               AND ended_at IS NULL
        ");

        $stmt->execute([
            gmdate('Y-m-d H:i:s'),
            $sessionId,
            $userId,
            $doorId,
        ]);

        return $stmt->rowCount() > 0;
    }

    private function endWebDoor(
        string $sessionId,
        int $userId,
        string $gameId
    ): bool {
        $stmt = $this->db()->prepare("
            UPDATE webdoor_sessions
               SET ended_at = ?
             WHERE session_id = ?
               AND user_id = ?
               AND game_id = ?
               AND ended_at IS NULL
        ");

        $stmt->execute([
            gmdate('Y-m-d H:i:s'),
            $sessionId,
            $userId,
            $gameId,
        ]);

        return $stmt->rowCount() > 0;
    }

    private function db(): PDO
    {
        if ($this->db === null) {
            $this->db = Database::getInstance()->getPdo();
        }

        return $this->db;
    }
}
