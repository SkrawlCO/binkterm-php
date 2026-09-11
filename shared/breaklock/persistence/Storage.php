<?php
declare(strict_types=1);

namespace BreakLock;

use BinktermPHP\LeasedWebDoorStorage;
use PDO;

/** Caller identity must already be authenticated by the HTTP host or trusted launcher. */
final class Storage
{
    private LeasedWebDoorStorage $store;
    public function __construct(PDO $db, int $caller)
    {
        $this->store = new LeasedWebDoorStorage($db, $caller, 'breaklock');
    }
    public function request(array $input): array
    {
        if (isset($input['user_id']) || isset($input['caller_id']) || isset($input['game_id'])) {
            throw new \InvalidArgumentException('Caller and namespace cannot be supplied in requests');
        }
        $action = $input['action'] ?? '';
        if ($action === 'acquire') return $this->store->acquire();
        $token = $input['owner_token'] ?? null;
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
            throw new \InvalidArgumentException('Owner token required');
        }
        if ($action === 'renew') return $this->store->renew($token);
        if ($action === 'release') return $this->store->release($token);
        if ($action !== 'save' || !is_int($input['revision'] ?? null) || $input['revision'] < 0 ||
            !is_string($input['attempt_id'] ?? null) || !is_array($input['data'] ?? null)) {
            throw new \InvalidArgumentException('Invalid checkpoint');
        }
        // Opaque caller-owned snapshot, not a score/anti-cheat authority. Round.restore
        // validates canonical state at each surface; database limits bound payload size.
        return $this->store->write($token, $input['attempt_id'], $input['revision'], $input['data']);
    }
}
