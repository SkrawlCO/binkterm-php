<?php

namespace BinktermPHP\TelnetServer;

use BinktermPHP\ActivityTracker;
use BinktermPHP\FileAreaManager;
use BinktermPHP\MessageHandler;

/**
 * Network-free equivalents of the terminal message-detail HTTP fetches.
 *
 * The telnet/SSH daemons proxied every message a caller opened through
 * `GET /api/messages/{type}/{id}` — a full round trip out to the site's public
 * URL (Cloudflare), a fresh TLS handshake, and the whole framework request
 * lifecycle, every time. These methods call the same canonical
 * {@see MessageHandler::getMessage()} the routes delegate to, and reproduce the
 * routes' response envelope and enrichment (REPLYTO parsing, activity tracking,
 * netmail attachments) exactly, so the viewers behave identically apart from
 * latency.
 *
 * The return shape matches {@see TelnetUtils::apiRequest()}:
 * `['status' => int, 'data' => array, 'error' => ?string]`, so existing
 * `$detail['data'][...]` / `$detail['status']` call sites are untouched.
 *
 * Not `final` only so the activity-log write can be stubbed in tests via the
 * {@see trackNetmailRead()} seam.
 */
class TerminalMessageService
{
    private ?MessageHandler $messages;

    public function __construct(?MessageHandler $messages = null)
    {
        $this->messages = $messages;
    }

    private function messages(): MessageHandler
    {
        return $this->messages ??= new MessageHandler();
    }

    /**
     * Mirror of `GET /api/messages/echomail/{echoarea}/{id}`.
     *
     * @return array{status:int,data:array<string,mixed>,error:?string}
     */
    public function echomailDetail(string $area, int $id, ?int $userId): array
    {
        [$tag, $domain] = array_pad(explode('@', $area, 2), 2, '');

        $message = $this->messages()->getMessage($id, 'echomail', $userId);
        if (!$message) {
            return ['status' => 404, 'data' => [], 'error' => 'not_found'];
        }

        // The route 404s when the id does not belong to the addressed area.
        if (strcasecmp((string) ($message['echoarea'] ?? ''), $tag) !== 0
            || strcasecmp((string) ($message['domain'] ?? ''), $domain) !== 0) {
            return ['status' => 404, 'data' => [], 'error' => 'not_found'];
        }

        // The route resolves `allow_media` from this column and then drops it.
        // The terminal viewers / compose / forward never read `allow_media`, so
        // only the internal-column cleanup is needed here.
        unset($message['area_allow_media']);

        // The route does NOT record an activity row for echomail detail (only
        // the area-view list route does) — so neither do we.

        return ['status' => 200, 'data' => self::applyReplyTo($message), 'error' => null];
    }

    /**
     * Mirror of `GET /api/messages/netmail/{id}`.
     *
     * @return array{status:int,data:array<string,mixed>,error:?string}
     */
    public function netmailDetail(int $id, ?int $userId): array
    {
        $message = $this->messages()->getMessage($id, 'netmail', $userId);
        if (!$message) {
            return ['status' => 404, 'data' => [], 'error' => 'not_found'];
        }

        $this->trackNetmailRead($userId, $id);

        $message = self::applyReplyTo($message);

        $message['attachments'] = [];
        if (FileAreaManager::isFeatureEnabled()) {
            try {
                $message['attachments'] = (new FileAreaManager())
                    ->getMessageAttachments($id, 'netmail', $userId ?: null);
            } catch (\Throwable $e) {
                $message['attachments'] = [];
            }
        }

        // The route also sets `can_edit`; the terminal netmail viewer / compose
        // never read it, so it is intentionally omitted.

        return ['status' => 200, 'data' => $message, 'error' => null];
    }

    /**
     * Record the netmail-read activity row the `GET /api/messages/netmail/{id}`
     * route writes. Isolated so tests can exercise the read path without a write.
     */
    protected function trackNetmailRead(?int $userId, int $id): void
    {
        ActivityTracker::track($userId, ActivityTracker::TYPE_NETMAIL_READ, $id);
    }

    /**
     * REPLYTO enrichment identical to the message-detail routes: take the first
     * valid `\x01REPLYTO` kludge in `message_text`, then let one in
     * `kludge_lines` override it. Parsing is the canonical
     * {@see MessageHandler::parseReplyToKludgeText()} — the same code the
     * `parseReplyToKludge()` global (and therefore the routes) now runs.
     *
     * @param array<string,mixed> $message
     * @return array<string,mixed>
     */
    private static function applyReplyTo(array $message): array
    {
        $fromText = MessageHandler::parseReplyToKludgeText((string) ($message['message_text'] ?? ''));
        if ($fromText) {
            $message['replyto_address'] = $fromText['address'];
            $message['replyto_name']    = $fromText['name'];
        }

        if (isset($message['kludge_lines'])) {
            $fromKludge = MessageHandler::parseReplyToKludgeText((string) $message['kludge_lines']);
            if ($fromKludge) {
                $message['replyto_address'] = $fromKludge['address'];
                $message['replyto_name']    = $fromKludge['name'];
            }
        }

        return $message;
    }
}
