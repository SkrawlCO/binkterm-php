<?php

declare(strict_types=1);

namespace BinktermPHP\Qwk\Transport;

/**
 * QWKnet FTP transport built on PHP's native `ftp://` stream wrapper.
 *
 * No `ext-ftp` is required (it is not installed in this project's runtime); the
 * `ftp` wrapper is part of core and is enabled here. Consequences:
 *
 *   - **Passive mode only.** The wrapper always uses PASV; a mailbox configured
 *     with `passive_mode = false` is rejected. Active FTP is not usable for this
 *     purpose behind NAT anyway.
 *   - **Plain FTP.** Credentials and data cross the wire unencrypted. This
 *     matches the Synchronet "QNET over FTP" convention that WeedNet and similar
 *     hubs use for the packet-exchange account. If a mailbox ever needs FTPS,
 *     add a flag and swap the scheme to `ftps://` -- the wrapper supports it.
 *
 * The password is taken from `$mailbox['password_plain']` and only ever appears
 * inside the in-memory request URL (rawurlencoded). It is never logged: the
 * optional logger only ever receives `ftp://<user>:***@<host>:<port><path>`.
 *
 * Reference: old upstream `src/Qwk/Transport/FtpTransport.php` (which used
 * `ext-ftp`). This is a functional re-implementation, not a port.
 */
class FtpStreamTransport implements QwkTransportInterface
{
    /** @var callable|null */
    private $logger;

    private int $timeoutSeconds;

    public function __construct(?callable $logger = null, int $timeoutSeconds = 45)
    {
        $this->logger = $logger;
        $this->timeoutSeconds = max(5, $timeoutSeconds);
    }

    public function downloadPacket(array $mailbox, string $destPath): bool
    {
        $this->assertUsable($mailbox);
        $base = $this->remoteBase($mailbox);
        $wantPrefix = $this->bbsId($mailbox) . '.QWK';

        // The trailing slash matters: PHP's ftp:// wrapper returns false for
        // scandir() on a path-less URL, which happens when ftp_remote_path is '/'.
        $entries = $this->withSocketTimeout(fn() => @scandir($base . '/', SCANDIR_SORT_NONE, $this->context()));
        if ($entries === false) {
            // The listing failed. A reachable control port means it is almost
            // certainly authentication or a directory-permission problem, not
            // connectivity -- probe() throws with a connectivity message if the
            // host is actually unreachable.
            $this->probe($mailbox);
            throw new \RuntimeException(
                'FTP connected but the remote directory could not be listed (check the username, password, and ftp_remote_path)'
            );
        }

        $match = null;
        foreach ($entries as $entry) {
            if (strcasecmp($entry, $wantPrefix) === 0) {
                $match = $entry;
                break;
            }
        }
        if ($match === null) {
            $this->log('no inbound packet (' . $wantPrefix . ') on the remote');
            return false;
        }

        $url = $base . '/' . $match;
        $this->log('RETR ' . $this->redact($base) . '/' . $match);
        $tmp = $destPath . '.part';

        /** @var array{bytes:int, open_error:?string} $result */
        $result = $this->withSocketTimeout(function () use ($url, $tmp): array {
            // fopen + stream copy: does not need SIZE, which some FTP servers reject.
            error_clear_last();
            $in = @fopen($url, 'rb', false, $this->context());
            if ($in === false) {
                return ['bytes' => -1, 'open_error' => (string)(error_get_last()['message'] ?? '')];
            }
            $out = @fopen($tmp, 'wb');
            if ($out === false) {
                fclose($in);
                return ['bytes' => -1, 'open_error' => null];
            }
            $copied = @stream_copy_to_stream($in, $out);
            fclose($in);
            $ok = fclose($out);

            return ['bytes' => ($copied === false || $ok === false) ? -1 : $copied, 'open_error' => null];
        });

        $bytes = $result['bytes'];
        if ($bytes < 0) {
            @unlink($tmp);
            // A RETR that is refused *because the hub built no packet this cycle*
            // is a normal empty pickup, not an error. Be specific: only a
            // "no packet / no new messages / file not found" style refusal
            // qualifies -- permission, transport, and unexpected failures stay errors.
            if ($result['open_error'] !== null && self::isNoPacketRefusal($result['open_error'])) {
                $this->log('remote reports no QWK packet this cycle (empty pickup)');
                return false;
            }
            throw new \RuntimeException(
                'FTP download of ' . $match . ' failed after the directory listing succeeded'
                . ($result['open_error'] !== null && $result['open_error'] !== ''
                    ? ': ' . $this->redact($result['open_error'])
                    : '')
            );
        }
        if ($bytes === 0) {
            @unlink($tmp);
            $this->log('downloaded packet is empty');
            return false;
        }
        if (!rename($tmp, $destPath)) {
            @unlink($tmp);
            throw new \RuntimeException('Failed to move the downloaded QWK packet to ' . $destPath);
        }
        $this->log(sprintf('RETR %s => %d bytes', $match, $bytes));
        return true;
    }

    public function uploadPacket(array $mailbox, string $localPath): bool
    {
        $this->assertUsable($mailbox);
        if (!is_file($localPath) || filesize($localPath) === 0) {
            throw new \RuntimeException('REP archive is missing or empty: ' . $localPath);
        }

        $url = $this->remoteBase($mailbox) . '/' . $this->bbsId($mailbox) . '.REP';
        $bytes = (int)filesize($localPath);
        $this->log(sprintf('STOR %s (%d bytes)', $this->redact($url), $bytes));

        $written = $this->withSocketTimeout(function () use ($url, $localPath): int {
            $out = @fopen($url, 'wb', false, $this->context());
            if ($out === false) {
                return -1;
            }
            $in = @fopen($localPath, 'rb');
            if ($in === false) {
                fclose($out);
                return -1;
            }
            $copied = @stream_copy_to_stream($in, $out);
            fclose($in);
            // fclose() flushes the data connection; the wrapper only reports
            // success once the server's transfer-complete reply is seen.
            $ok = fclose($out);

            return ($copied === false || $ok === false) ? -1 : $copied;
        });

        if ($written < 0 || $written !== $bytes) {
            throw new \RuntimeException(
                'FTP upload did not complete (' . var_export($written, true) . ' of ' . $bytes . ' bytes)'
            );
        }
        $this->log('STOR complete');
        return true;
    }

    // --- internals ---------------------------------------------------------

    /** @param array<string,mixed> $mailbox */
    private function assertUsable(array $mailbox): void
    {
        if (!in_array('ftp', stream_get_wrappers(), true)) {
            throw new \RuntimeException('The PHP ftp:// stream wrapper is not available');
        }
        $passive = !array_key_exists('passive_mode', $mailbox)
            || filter_var($mailbox['passive_mode'], FILTER_VALIDATE_BOOLEAN);
        if (!$passive) {
            throw new \RuntimeException('This FTP transport only supports passive mode; enable passive_mode on the mailbox');
        }
        if (trim((string)($mailbox['host'] ?? '')) === '' || trim((string)($mailbox['username'] ?? '')) === '') {
            throw new \RuntimeException('QWK mailbox is missing host or username');
        }
    }

    /** @param array<string,mixed> $mailbox */
    private function remoteBase(array $mailbox): string
    {
        $user = rawurlencode(trim((string)$mailbox['username']));
        $pass = rawurlencode((string)($mailbox['password_plain'] ?? ''));
        $host = trim((string)$mailbox['host']);
        $port = ((int)($mailbox['port'] ?? 21)) ?: 21;
        $path = rtrim('/' . trim(str_replace('\\', '/', (string)($mailbox['ftp_remote_path'] ?? '/')), '/'), '/');

        return sprintf('ftp://%s:%s@%s:%d%s', $user, $pass, $host, $port, $path);
    }

    /** @param array<string,mixed> $mailbox */
    private function bbsId(array $mailbox): string
    {
        return strtoupper(substr((string)preg_replace('/[^A-Za-z0-9]/', '', (string)($mailbox['bbs_id'] ?? '')), 0, 8));
    }

    /** @param array<string,mixed> $mailbox */
    private function probe(array $mailbox): void
    {
        $host = trim((string)$mailbox['host']);
        $port = ((int)($mailbox['port'] ?? 21)) ?: 21;
        $errno = 0;
        $errstr = '';
        $sock = @fsockopen($host, $port, $errno, $errstr, (float)min(15, $this->timeoutSeconds));
        if ($sock === false) {
            throw new \RuntimeException(sprintf('Cannot reach FTP host %s:%d (%s)', $host, $port, $errstr ?: "errno $errno"));
        }
        fclose($sock);
    }

    /** @return resource */
    private function context()
    {
        return stream_context_create(['ftp' => ['overwrite' => true]]);
    }

    /**
     * @template T
     * @param callable():T $fn
     * @return T
     */
    private function withSocketTimeout(callable $fn)
    {
        $previous = ini_set('default_socket_timeout', (string)$this->timeoutSeconds);
        try {
            return $fn();
        } finally {
            if ($previous !== false) {
                ini_set('default_socket_timeout', $previous);
            }
        }
    }

    /**
     * True only when a RETR was refused because the hub has no packet to send
     * this cycle (a normal empty pickup), not because of a permission, transport,
     * or unexpected failure.
     */
    private static function isNoPacketRefusal(string $ftpError): bool
    {
        // Must be a "file/resource not available" FTP reply...
        if (!preg_match('/\b(?:450|550)\b/', $ftpError)) {
            return false;
        }
        // ...for a reason that is specifically "nothing to download".
        return (bool)preg_match(
            '/no\s+(?:new\s+message|qwk\s+packet|packet|mail|file)|'
            . 'no\s+such\s+file|file\s+not\s+found|not\s+found|does\s+not\s+exist|'
            . 'nothing\s+to\s+(?:send|download)/i',
            $ftpError
        );
    }

    private function redact(string $url): string
    {
        return (string)preg_replace('#(ftps?://[^:/@\s]+):[^@\s]*@#', '$1:***@', $url);
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)('[QWK-FTP] ' . $message);
        }
    }
}
