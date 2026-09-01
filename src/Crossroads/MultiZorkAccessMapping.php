<?php

declare(strict_types=1);

namespace BinktermPHP\Crossroads;

use BinktermPHP\Database;
use PDO;

/**
 * L33TEST/Crossroads-owned MultiZork Slice 1 credential mapping.
 *
 * NOT a generic BinkTermPHP external-identity/credential capability. This is
 * deliberately the smallest possible persistent mapping needed to prove one
 * thing: a BinkTerm numeric user id plus one fixed MultiZork test expedition
 * resolves to the private, opaque, server-minted MultiZork access/return
 * code that player was issued. See docs/Crossroads/MultiZorkSlice1.md.
 *
 * The access code is a bearer credential (per the MultiZork runtime proof:
 * no server-side brute-force throttling on multizorkd itself, so submission
 * of a stored value must always go through
 * {@see MultiZorkAccessRateLimit} first). Callers must never surface a
 * stored code through ordinary UI/API responses or log it.
 *
 * Deliberately NOT generalized to arbitrary providers, arbitrary worlds,
 * multiple external identities, or encryption-at-rest infrastructure — this
 * follows the same plain, narrowly-scoped-column convention already used
 * for other external-service secrets in this codebase (e.g. hub_nodes'
 * areafix_password/session_password). Promote/generalize only after a
 * second real consumer needs the same shape.
 */
final class MultiZorkAccessMapping
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getPdo();
    }

    /**
     * Return the stored access code for this user + expedition, or null if
     * none has been captured yet (first-time caller).
     */
    public function get(int $userId, string $expeditionId): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT access_code FROM multizork_expedition_credentials
             WHERE user_id = ? AND expedition_id = ?'
        );
        $stmt->execute([$userId, $expeditionId]);
        $code = $stmt->fetchColumn();

        return $code === false ? null : (string)$code;
    }

    /**
     * Store (insert or replace) the access code MultiZork issued this user
     * for this expedition.
     */
    public function save(int $userId, string $expeditionId, string $accessCode): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO multizork_expedition_credentials (user_id, expedition_id, access_code, updated_at)
             VALUES (?, ?, ?, NOW())
             ON CONFLICT (user_id, expedition_id)
             DO UPDATE SET access_code = EXCLUDED.access_code, updated_at = NOW()'
        );
        $stmt->execute([$userId, $expeditionId, $accessCode]);
    }
}
