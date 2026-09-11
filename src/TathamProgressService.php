<?php

declare(strict_types=1);

namespace BinktermPHP;

/** Light Up's caller-bound boundary. Canonical engine alone validates saves/status. */
final class TathamProgressService
{
    public const PUZZLE_ID = '7x7:cBd0c1hBe2h1c0d0c';
    public const ENGINE = '428913c6a58b60802fe5d734d9a44c34159f4e0f';

    public function __construct(private LeasedWebDoorStorage $storage)
    {
    }

    public function acquire(): array
    {
        return $this->storage->acquire();
    }

    public function renew(string $owner): array
    {
        return $this->storage->renew($owner);
    }

    public function release(string $owner): array
    {
        return $this->storage->release($owner);
    }

    /** Validate without transforming the supplied canonical bytes. */
    public function save(string $owner, string $attempt, int $revision, string $payload): array
    {
        if ($payload === '' || strlen($payload) > 90000) {
            throw new \InvalidArgumentException('Invalid payload size');
        }
        $binary = dirname(__DIR__) . '/docs/Crossroads/tatham-backend/generated/validate';
        if (!is_executable($binary)) {
            throw new \RuntimeException('Canonical validator unavailable');
        }
        $process = proc_open([$binary], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Canonical validator unavailable');
        }
        $offset = 0;
        while ($offset < strlen($payload)) {
            $written = fwrite($pipes[0], substr($payload, $offset));
            if (!$written) {
                break;
            }
            $offset += $written;
        }
        fclose($pipes[0]);
        $status = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0 || $offset !== strlen($payload) || !in_array($status, ["-1\n", "0\n", "1\n"], true)) {
            throw new \InvalidArgumentException('Canonical validation rejected save');
        }
        $previous = $this->storage->read();
        $old = $previous['data'] ?? [];
        $now = gmdate('Y-m-d\TH:i:s\Z');
        return $this->storage->write($owner, $attempt, $revision, [
            'backend' => 'lightup', 'puzzle_id' => self::PUZZLE_ID,
            'engine_revision' => self::ENGINE, 'payload' => $payload,
            'started_at' => $old['started_at'] ?? $now, 'updated_at' => $now,
            'completed' => $status === "1\n",
            'completed_at' => $old['completed_at'] ?? ($status === "1\n" ? $now : null),
        ]);
    }
}
