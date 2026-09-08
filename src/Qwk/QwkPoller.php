<?php

declare(strict_types=1);

namespace BinktermPHP\Qwk;

use BinktermPHP\Database;
use BinktermPHP\Qwk\Transport\FtpStreamTransport;
use BinktermPHP\Qwk\Transport\QwkTransportInterface;
use PDO;

/**
 * Runs one full exchange for a single QWK mailbox: download + inbound import,
 * then build + upload the outbound REP for anything queued.
 *
 * No scheduler wiring. The decrypted password lives only in the mailbox array
 * passed to the transport and is never logged or returned.
 */
class QwkPoller
{
    private PDO $db;
    private QwkTransportInterface $transport;
    private QwkInbound $inbound;
    private QwkOutbound $outbound;
    /** @var callable|null */
    private $logger;

    public function __construct(
        ?PDO $db = null,
        ?QwkTransportInterface $transport = null,
        ?QwkInbound $inbound = null,
        ?QwkOutbound $outbound = null,
        ?callable $logger = null
    ) {
        $this->db = $db ?? Database::getInstance()->getPdo();
        $this->logger = $logger;
        $this->transport = $transport ?? new FtpStreamTransport($logger);
        $this->inbound = $inbound ?? new QwkInbound($this->db);
        $this->outbound = $outbound ?? new QwkOutbound($this->db);
    }

    /**
     * @param array{download?:bool,upload?:bool} $options
     * @return array<string,mixed>
     */
    public function poll(int $mailboxId, array $options = []): array
    {
        $doDownload = $options['download'] ?? true;
        $doUpload = $options['upload'] ?? true;

        $mailbox = $this->loadMailboxWithSecret($mailboxId);
        $bbsId = strtoupper((string)$mailbox['bbs_id']);

        $result = [
            'mailbox_id' => $mailboxId,
            'bbs_id' => $bbsId,
            'download' => ['performed' => false],
            'inbound' => null,
            'outbound' => ['pending_before' => $this->outbound->pendingCount($mailboxId), 'batch' => null],
            'errors' => [],
        ];

        $tmpDir = sys_get_temp_dir() . '/qwknet-poll-' . bin2hex(random_bytes(6));
        @mkdir($tmpDir, 0700, true);
        $downloadPath = $tmpDir . '/' . $bbsId . '.QWK';
        $repPath = null;

        try {
            // --- inbound ---
            if ($doDownload) {
                $result['download']['performed'] = true;
                $got = $this->transport->downloadPacket($mailbox, $downloadPath);
                $result['download']['packet_received'] = $got;
                if ($got) {
                    $result['inbound'] = $this->inbound->importLocalPacket($mailboxId, $downloadPath);
                }
            }

            // --- outbound ---
            if ($doUpload) {
                $batch = $this->outbound->prepareBatch($mailboxId);
                if ($batch !== null) {
                    $repPath = $batch['path'];
                    $summary = [
                        'batch_id' => $batch['batch_id'],
                        'message_count' => $batch['message_count'],
                        'archive_sha256' => $batch['archive_sha256'],
                        'rebuilt' => $batch['rebuilt'],
                        'uploaded' => false,
                    ];
                    try {
                        $this->outbound->markUploadAttempted($batch['batch_id']);
                        if ($this->transport->uploadPacket($mailbox, $repPath)) {
                            $this->outbound->markUploaded($batch['batch_id']);
                            $summary['uploaded'] = true;
                        } else {
                            $this->outbound->markFailed($batch['batch_id'], 'transport reported an incomplete upload');
                        }
                    } catch (\Throwable $e) {
                        // Connection failed before/at STOR: retryable, not uncertain.
                        $this->outbound->markFailed($batch['batch_id'], $e->getMessage());
                        $summary['error'] = $e->getMessage();
                        $result['errors'][] = 'upload: ' . $e->getMessage();
                    }
                    $result['outbound']['batch'] = $summary;
                }
            }

            $this->markPollResult($mailboxId, null);
        } catch (\Throwable $e) {
            $result['errors'][] = $e->getMessage();
            $this->markPollResult($mailboxId, $e->getMessage());
        } finally {
            @unlink($downloadPath);
            if ($repPath !== null) {
                @unlink($repPath);
            }
            @rmdir($tmpDir);
        }

        $result['outbound']['pending_after'] = $this->outbound->pendingCount($mailboxId);
        $result['status'] = $result['errors'] === [] ? 'ok' : 'error';

        return $result;
    }

    /** @return array<string,mixed> */
    private function loadMailboxWithSecret(int $mailboxId): array
    {
        $stmt = $this->db->prepare('
            SELECT id, name, bbs_id, host, port, username, password, ftp_remote_path, passive_mode, enabled
            FROM qwk_mailboxes WHERE id = ?
        ');
        $stmt->execute([$mailboxId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new \InvalidArgumentException('QWK mailbox ' . $mailboxId . ' not found');
        }
        $row['passive_mode'] = filter_var($row['passive_mode'], FILTER_VALIDATE_BOOLEAN);
        $row['password_plain'] = \BinktermPHP\SysK::decrypt((string)($row['password'] ?? ''));
        unset($row['password']);

        return $row;
    }

    private function markPollResult(int $mailboxId, ?string $error): void
    {
        $this->db->prepare('
            UPDATE qwk_mailboxes
            SET last_polled_at = NOW(), last_error = ?, updated_at = NOW()
            WHERE id = ?
        ')->execute([$error !== null ? mb_substr($error, 0, 2000) : null, $mailboxId]);
    }
}
