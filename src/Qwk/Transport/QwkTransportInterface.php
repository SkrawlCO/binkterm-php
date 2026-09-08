<?php

declare(strict_types=1);

namespace BinktermPHP\Qwk\Transport;

/**
 * Moves QWKnet packets between this system and a remote mailbox.
 *
 * Implementations receive the mailbox row (including the *decrypted* password in
 * `password_plain`) and must never log, print, or otherwise persist it.
 */
interface QwkTransportInterface
{
    /**
     * Download the mailbox's inbound `<BBSID>.QWK` to `$destPath`.
     *
     * @param array<string,mixed> $mailbox
     * @return bool true if a packet was written to `$destPath`; false if the
     *              remote has no packet available (not an error)
     * @throws \RuntimeException on a connection/authentication failure
     */
    public function downloadPacket(array $mailbox, string $destPath): bool;

    /**
     * Upload a locally-built `<BBSID>.REP`.
     *
     * @param array<string,mixed> $mailbox
     * @return bool true only when the transfer is confirmed complete
     * @throws \RuntimeException on a connection/authentication failure
     */
    public function uploadPacket(array $mailbox, string $localPath): bool;
}
