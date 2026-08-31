<?php

/**
 * Door Capacity Exception
 *
 * @package BinktermPHP
 */

namespace BinktermPHP;

use Exception;

/**
 * Thrown by {@see DoorSessionManager::startSession()} when a door has already
 * reached its configured per-door concurrency limit (manifest `max_nodes`, or
 * `config.max_sessions` when no `max_nodes` is set).
 *
 * The decision is made inside the serialized admission transaction, so it is
 * authoritative even when two launch requests overlap. HTTP callers should
 * translate this into the "door at capacity" response (HTTP 503, error code
 * `errors.door.capacity_reached_detail`) rather than a generic launch failure.
 */
class DoorCapacityException extends Exception
{
    /**
     * @param string $doorId         Door that is at capacity
     * @param int    $maxNodes       Configured per-door concurrency limit
     * @param int    $activeSessions Active session count observed under the lock
     */
    public function __construct(
        public readonly string $doorId,
        public readonly int $maxNodes,
        public readonly int $activeSessions
    ) {
        parent::__construct(sprintf(
            'Door "%s" is at capacity (%d/%d active sessions)',
            $doorId,
            $activeSessions,
            $maxNodes
        ));
    }
}
