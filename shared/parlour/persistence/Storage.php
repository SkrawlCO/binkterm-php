<?php
declare(strict_types=1);

namespace Parlour;

use BinktermPHP\LeasedWebDoorStorage;
use PDO;

/**
 * Caller-bound opaque storage for Parlour's caller-scoped solo/solo-vs-bots
 * session, reusing the same generalized leased-storage mechanism as
 * Tatham/BreakLock/Ordinary Puzzles/Wordwright/Dokuel (namespace 'parlour',
 * slot 0). Caller identity must already be authenticated by the HTTP host
 * (api.php) or trusted launcher (helper.php's DOOR_USER_NUMBER contract).
 *
 * This class never interprets the envelope it stores — it is opaque JSON
 * (schemaVersion/upstreamRevision/gameId/seed/options/log/mode/humanSeat/
 * botSeats; see ../persistence/envelope.ts). Canonical replay in the shared
 * facade is what validates it, not this storage layer — exactly the same
 * division of responsibility Dokuel/Wordwright use for their own opaque
 * session snapshots.
 *
 * A shared multiplayer ROOM is explicitly out of scope for this class: it
 * only ever addresses one (caller, 'parlour', slot 0) row, so it cannot be
 * pointed at room state by construction — there is no room id parameter
 * anywhere in this type.
 */
final class Storage
{
    private LeasedWebDoorStorage $store;

    public function __construct(PDO $db, int $caller)
    {
        $this->store = new LeasedWebDoorStorage($db, $caller, 'parlour');
    }

    public function request(array $input): array
    {
        if (isset($input['user_id']) || isset($input['caller_id']) || isset($input['game_id'])) {
            throw new \InvalidArgumentException('Caller and namespace cannot be supplied in requests');
        }
        $action = $input['action'] ?? '';
        if ($action === 'read') {
            $data = $this->store->read();
            return $data === null ? ['success' => true, 'data' => null] : ['success' => true] + $data;
        }
        if ($action === 'acquire') {
            return $this->store->acquire();
        }
        $token = $input['owner_token'] ?? null;
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
            throw new \InvalidArgumentException('Owner token required');
        }
        if ($action === 'renew') {
            return $this->store->renew($token);
        }
        if ($action === 'release') {
            return $this->store->release($token);
        }
        if ($action !== 'save' || !is_int($input['revision'] ?? null) || $input['revision'] < 0 ||
            !is_string($input['attempt_id'] ?? null) || !is_array($input['data'] ?? null)) {
            throw new \InvalidArgumentException('Invalid checkpoint');
        }
        return $this->store->write($token, $input['attempt_id'], $input['revision'], $input['data']);
    }
}
