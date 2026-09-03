<?php

declare(strict_types=1);

namespace BinktermPHP\Crossroads;

/**
 * OpengladPersistNamespace
 *
 * L33TEST/Crossroads-owned: derives the opaque per-user browser-persistence
 * partition token for the OpenGlad WebDoor. The token is passed to the pinned
 * OpenGlad Web build as window.__opengladPersistNamespace (the seam proposed
 * upstream in openglad/openglad#281 and carried as
 * docs/Crossroads/openglad-backend/patches/0001-web-persist-namespace.patch),
 * which mounts IDBFS at /persist_<token> so each user gets an isolated store.
 *
 * IMPORTANT — this is a PERSISTENCE PARTITION IDENTIFIER, not a credential:
 *
 *   - it is NOT authentication or authorization; nothing server-side consumes it;
 *   - it is NOT a secret (it becomes an IndexedDB database name, visible in the
 *     browser) — but it is still not written to logs needlessly;
 *   - it MUST NOT depend on APP_SECRET or any rotating secret: a partition id
 *     has a different lifetime from a session/auth secret, and keying it to one
 *     would silently orphan every user's Companies on a secret rotation.
 *
 * It is derived from the immutable BinkTerm users.id alone. The "-v1" domain
 * string reserves room for a future deliberate re-partition.
 *
 * Not a generic capability. Promote/generalise only if a second real consumer
 * needs the same shape.
 */
final class OpengladPersistNamespace
{
    /** Domain separation + version for a possible future deliberate re-partition. */
    public const DOMAIN = 'openglad-persist-v1:';

    /** Token length in lowercase hex chars (<= 64; fits "/persist_<token>"). */
    public const LENGTH = 40;

    /**
     * The persistence-partition token for a BinkTerm user.
     *
     * @param int $userId The authenticated, immutable BinkTerm user id (> 0).
     * @return string 40 lowercase hex characters ([0-9a-f]).
     * @throws \InvalidArgumentException when $userId is not a positive integer.
     */
    public static function forUser(int $userId): string
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException(
                'OpengladPersistNamespace requires a positive user id'
            );
        }

        return substr(hash('sha256', self::DOMAIN . $userId), 0, self::LENGTH);
    }
}
