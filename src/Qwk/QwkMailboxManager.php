<?php

declare(strict_types=1);

namespace BinktermPHP\Qwk;

use BinktermPHP\Database;
use BinktermPHP\SysK;
use PDO;

/**
 * CRUD for inter-BBS QWK mailboxes (`qwk_mailboxes`).
 *
 * A mailbox is one remote QWK networking peer: its BBS/packet ID, the FTP
 * connection details used to exchange packets, and an encrypted login password.
 * This class is storage only — it performs no polling, transport, import, or
 * export.
 *
 * The password column is written through {@see \BinktermPHP\SysK} and is never
 * stored or returned in plaintext. Read methods omit the password entirely
 * unless a caller explicitly opts in with `$includeSecret = true`, in which case
 * the decrypted value is returned as `password_plain` for trusted internal use
 * (e.g. a future transport layer). Callers must never log or echo it.
 *
 * Forward-ported and adapted from the upstream `qwknet` branch
 * (`src/Qwk/QwkMailboxManager.php`). The upstream `markPollResult()` helper is
 * intentionally omitted until the polling slice exists.
 */
class QwkMailboxManager
{
    /** Columns that are safe to expose to ordinary callers. */
    private const PUBLIC_COLUMNS =
        'id, name, bbs_id, host, port, username, ftp_remote_path, passive_mode, ' .
        'poll_schedule, enabled, last_polled_at, last_error, created_at, updated_at';

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getPdo();
    }

    /**
     * @return array<int, array<string, mixed>> mailboxes without secrets
     */
    public function getAll(): array
    {
        $stmt = $this->db->query(
            'SELECT ' . self::PUBLIC_COLUMNS . ' FROM qwk_mailboxes ORDER BY LOWER(name), id'
        );

        return array_map([$this, 'normalizeRow'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getById(int $id, bool $includeSecret = false): ?array
    {
        $columns = self::PUBLIC_COLUMNS . ($includeSecret ? ', password' : '');
        $stmt = $this->db->prepare("SELECT $columns FROM qwk_mailboxes WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $row = $this->normalizeRow($row);
        if ($includeSecret) {
            $row['password_plain'] = SysK::decrypt((string)($row['password'] ?? ''));
            unset($row['password']);
        }

        return $row;
    }

    /**
     * Create (when `$id` is null) or update a mailbox.
     *
     * On update, a blank `password` keeps the stored one; a non-blank value
     * replaces it. Returns the mailbox id.
     *
     * @param array<string, mixed> $data
     * @throws \InvalidArgumentException on invalid input or unknown id
     */
    public function save(array $data, ?int $id = null): int
    {
        $name = trim((string)($data['name'] ?? ''));
        $bbsId = strtoupper(substr((string)preg_replace('/[^A-Za-z0-9]/', '', (string)($data['bbs_id'] ?? '')), 0, 8));
        $host = trim((string)($data['host'] ?? ''));
        $port = (int)($data['port'] ?? 21);
        $username = trim((string)($data['username'] ?? ''));
        $password = (string)($data['password'] ?? '');
        $remotePath = trim((string)($data['ftp_remote_path'] ?? '/'));
        $remotePath = $remotePath !== '' ? $remotePath : '/';
        $passiveMode = array_key_exists('passive_mode', $data)
            ? filter_var($data['passive_mode'], FILTER_VALIDATE_BOOLEAN)
            : true;
        $pollSchedule = trim((string)($data['poll_schedule'] ?? ''));
        $enabled = array_key_exists('enabled', $data)
            ? filter_var($data['enabled'], FILTER_VALIDATE_BOOLEAN)
            : true;

        if ($name === '' || $bbsId === '' || $host === '' || $username === '') {
            throw new \InvalidArgumentException('QWK mailbox requires name, bbs_id, host and username');
        }
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('QWK mailbox port must be between 1 and 65535');
        }

        if ($id === null) {
            if ($password === '') {
                throw new \InvalidArgumentException('A password is required for a new QWK mailbox');
            }
            $stmt = $this->db->prepare('
                INSERT INTO qwk_mailboxes
                    (name, bbs_id, host, port, username, password, ftp_remote_path,
                     passive_mode, poll_schedule, enabled, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                RETURNING id
            ');
            $stmt->execute([
                $name,
                $bbsId,
                $host,
                $port,
                $username,
                SysK::encrypt($password),
                $remotePath,
                $passiveMode ? 'true' : 'false',
                $pollSchedule !== '' ? $pollSchedule : null,
                $enabled ? 'true' : 'false',
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return $row ? (int)$row['id'] : 0;
        }

        $existing = $this->getById($id, true);
        if ($existing === null) {
            throw new \InvalidArgumentException('QWK mailbox not found');
        }
        $encryptedPassword = $password !== ''
            ? SysK::encrypt($password)
            : SysK::encrypt((string)($existing['password_plain'] ?? ''));

        $stmt = $this->db->prepare('
            UPDATE qwk_mailboxes SET
                name = ?, bbs_id = ?, host = ?, port = ?, username = ?, password = ?,
                ftp_remote_path = ?, passive_mode = ?, poll_schedule = ?, enabled = ?, updated_at = NOW()
            WHERE id = ?
        ');
        $stmt->execute([
            $name,
            $bbsId,
            $host,
            $port,
            $username,
            $encryptedPassword,
            $remotePath,
            $passiveMode ? 'true' : 'false',
            $pollSchedule !== '' ? $pollSchedule : null,
            $enabled ? 'true' : 'false',
            $id,
        ]);

        return $id;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM qwk_mailboxes WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Decrypt an encrypted password column value. Trusted internal callers only.
     */
    public function decryptPassword(string $encrypted): string
    {
        return SysK::decrypt($encrypted);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        if (array_key_exists('id', $row)) {
            $row['id'] = (int)$row['id'];
        }
        if (array_key_exists('port', $row)) {
            $row['port'] = (int)$row['port'];
        }
        foreach (['passive_mode', 'enabled'] as $flag) {
            if (array_key_exists($flag, $row)) {
                $row[$flag] = filter_var($row[$flag], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $row;
    }
}
