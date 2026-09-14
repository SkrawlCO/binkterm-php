<?php

namespace BinktermPHP\Messaging;

use BinktermPHP\Database;
use PDO;

/**
 * Presentation-only hydration for the "Since Your Last Call" dashboard card.
 *
 * {@see ActivityService} deliberately returns ids/counts only. This class
 * resolves a small, already-visibility-filtered set of ids (at most the
 * handful actually about to be displayed — never the full capped list) into
 * the minimal display fields a card row needs. It performs no visibility
 * filtering of its own: the ids it is given have already passed
 * ActivityService's moderation/ignore/sysop-only/netmail-ownership checks, so
 * re-filtering here would be redundant, not protective.
 *
 * Deliberately separate from {@see SylcPulse}, which stays a pure, query-free
 * reducer (mirroring {@see \BinktermPHP\Crossroads\DashboardPulse}'s own
 * discipline) — this class is the thing that actually touches the database.
 */
class SylcHydrator
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getPdo();
    }

    /**
     * @param int[] $ids
     * @return list<array{id:int,from_name:string,subject:?string,date_received:string}>
     */
    public function hydrateNetmail(array $ids): array
    {
        return $this->hydrate('netmail', $ids);
    }

    /**
     * @param int[] $ids
     * @return list<array{id:int,from_name:string,subject:?string,date_received:string}>
     */
    public function hydrateEchomailReplies(array $ids): array
    {
        return $this->hydrate('echomail', $ids);
    }

    /** @param int[] $ids */
    private function hydrate(string $table, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("
            SELECT id, from_name, subject, date_received
            FROM {$table}
            WHERE id IN ({$placeholders})
        ");
        $stmt->execute($ids);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'id'            => (int) $r['id'],
                'from_name'     => (string) $r['from_name'],
                'subject'       => $r['subject'] !== null ? (string) $r['subject'] : null,
                'date_received' => (string) $r['date_received'],
            ];
        }

        return $out;
    }
}
