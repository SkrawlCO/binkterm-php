<?php

declare(strict_types=1);

namespace BinktermPHP;

use PDO;

/**
 * Caller-bound opaque storage for the reserved, namespaced WebDoor slot.
 * The caller ID must come from authenticated application/launcher context.
 * This class deliberately owns its transaction; do not call inside another one.
 * Owner secrets are returned only by acquire; only their hashes are persisted.
 */
final class LeasedWebDoorStorage
{
    public const GAME_ID = 'tatham';
    public const SLOT = 0;
    public const LEASE_SECONDS = 30;
    public const RENEW_SECONDS = 10;
    public const MAX_BYTES = 102400;

    public function __construct(private PDO $db, private int $userId, private string $gameId = self::GAME_ID)
    {
        if (!self::isReserved($gameId)) {
            throw new \InvalidArgumentException('Unknown leased namespace');
        }
        if ($userId <= 0) {
            throw new \InvalidArgumentException('An authenticated caller ID is required');
        }
    }

    public static function isReserved(string $gameId): bool
    {
        return in_array($gameId, [self::GAME_ID, 'breaklock', 'ordinary-puzzles', 'wordwright', 'dokuel', 'parlour'], true);
    }

    /** Read only this caller's data; never return lease credentials. */
    public function read(): ?array
    {
        $row = $this->row(false);
        return $row === null ? null : $this->snapshot($row);
    }

    /** Atomically create the slot if absent and acquire an available lease. */
    public function acquire(): array
    {
        return $this->transaction(function (): array {
            $metadata = ['attempt_id' => bin2hex(random_bytes(16)), 'revision' => 0];
            $insert = $this->db->prepare('INSERT INTO webdoor_storage (user_id, game_id, slot, data, metadata)
                VALUES (?, ?, 0, \'{}\'::jsonb, ?::jsonb) ON CONFLICT (user_id, game_id, slot) DO NOTHING');
            $insert->execute([$this->userId, $this->gameId, $this->encode($metadata)]);
            $row = $this->row(true);
            $now = $this->now();
            if (($row['metadata']['lease_expires_at'] ?? 0) > $now) {
                return $this->conflict();
            }
            $token = bin2hex(random_bytes(32));
            $row['metadata']['lease_owner_hash'] = hash('sha256', $token);
            $row['metadata']['lease_expires_at'] = $now + self::LEASE_SECONDS;
            $this->persist($row);
            return ['success' => true, 'owner_token' => $token] + $this->snapshot($row);
        });
    }

    public function renew(string $ownerToken): array
    {
        return $this->owned($ownerToken, function (array $row, float $now): array {
            $row['metadata']['lease_expires_at'] = $now + self::LEASE_SECONDS;
            return $row;
        });
    }

    /** Preserve opaque JSON data, including save text, without interpreting it. */
    public function write(string $ownerToken, string $attemptId, int $expectedRevision, array $data): array
    {
        if (strlen($this->encode($data)) > self::MAX_BYTES) {
            throw new \LengthException('Save data exceeds maximum size');
        }
        return $this->owned($ownerToken, function (array $row, float $now) use ($attemptId, $expectedRevision, $data): ?array {
            if ($row['metadata']['attempt_id'] !== $attemptId || $row['metadata']['revision'] !== $expectedRevision) {
                return null;
            }
            if ($expectedRevision === PHP_INT_MAX) {
                throw new \OverflowException('Save revision exhausted');
            }
            $row['data'] = $data;
            $row['metadata']['revision']++;
            $row['metadata']['lease_expires_at'] = $now + self::LEASE_SECONDS;
            return $row;
        });
    }

    public function release(string $ownerToken): array
    {
        return $this->owned($ownerToken, function (array $row): array {
            unset($row['metadata']['lease_owner_hash'], $row['metadata']['lease_expires_at']);
            return $row;
        });
    }

    private function owned(string $token, callable $change): array
    {
        return $this->transaction(function () use ($token, $change): array {
            $row = $this->row(true);
            $now = $this->now();
            if ($row === null || ($row['metadata']['lease_expires_at'] ?? 0) <= $now ||
                !hash_equals($row['metadata']['lease_owner_hash'] ?? '', hash('sha256', $token))) {
                return $this->conflict();
            }
            $row = $change($row, $now);
            if ($row === null) {
                return $this->conflict();
            }
            $this->persist($row);
            return ['success' => true] + $this->snapshot($row);
        });
    }

    private function row(bool $lock): ?array
    {
        $query = $this->db->prepare('SELECT data, metadata FROM webdoor_storage
            WHERE user_id = ? AND game_id = ? AND slot = 0' . ($lock ? ' FOR UPDATE' : ''));
        $query->execute([$this->userId, $this->gameId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return ['data' => json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR),
            'metadata' => json_decode($row['metadata'], true, 512, JSON_THROW_ON_ERROR)];
    }

    private function snapshot(array $row): array
    {
        return ['data' => $row['data'], 'attempt_id' => $row['metadata']['attempt_id'],
            'revision' => $row['metadata']['revision'],
            'lease_expires_at' => $row['metadata']['lease_expires_at'] ?? null];
    }

    private function persist(array $row): void
    {
        $query = $this->db->prepare('UPDATE webdoor_storage SET data = ?::jsonb, metadata = ?::jsonb,
            saved_at = clock_timestamp() WHERE user_id = ? AND game_id = ? AND slot = 0');
        $query->execute([$this->encode($row['data']), $this->encode($row['metadata']), $this->userId, $this->gameId]);
    }

    /** Read database time after acquiring the row lock, not transaction-start time. */
    private function now(): float
    {
        return (float)$this->db->query('SELECT EXTRACT(EPOCH FROM clock_timestamp())')->fetchColumn();
    }

    private function transaction(callable $operation): array
    {
        if ($this->db->inTransaction()) {
            throw new \LogicException('Leased storage requires its own transaction');
        }
        $this->db->beginTransaction();
        try {
            $result = $operation();
            $this->db->commit();
            return $result;
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }

    private function conflict(): array
    {
        return ['success' => false, 'reason' => 'conflict'];
    }

    private function encode(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }
}
