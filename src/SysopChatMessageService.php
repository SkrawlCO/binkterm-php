<?php

namespace BinktermPHP;

use BinktermPHP\Realtime\BinkStream;
use PDO;

/**
 * SysOp Chat — M1B minimal ephemeral message transport.
 *
 * Owns `sysop_chat_messages`: the private message body/list for exactly one
 * ACCEPTED {@see SysopChatService} page. This is deliberately not
 * {@see \BinktermPHP\Chat\ChatMessageService} — see docs/SysopChat/M1A.md
 * "Message transport recon" for why that service (permanent `chat_messages`,
 * Matterbridge/PacketBBS/ActivityTracker side effects) is the wrong fit.
 *
 * `page_id` alone never authorizes access — every read/write here re-checks
 * that the acting user is one of the page's two participants (the caller or
 * the accepting admin), read fresh from `sysop_pages` on every call rather
 * than trusted from a prior lookup.
 */
class SysopChatMessageService
{
    /** Realtime event type for one delivered message. */
    public const EVENT_MESSAGE = 'sysop_chat.message';

    private const MAX_BODY_LENGTH = 2000;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getPdo();
    }

    /**
     * Send one message on an ACCEPTED page. `$senderUserId` must be the
     * page's caller or its accepting admin; anyone else — including a valid
     * user id that simply isn't a participant on this page — is refused.
     *
     * @return array{id:int,body:string,sender_user_id:int,created_at:string}|null
     *   Null on any refusal: page missing, not ACCEPTED, sender not a
     *   participant, or an empty/oversized body.
     */
    public function sendMessage(int $pageId, int $senderUserId, string $body): ?array
    {
        $body = trim($body);
        if ($pageId <= 0 || $senderUserId <= 0 || $body === '' || mb_strlen($body) > self::MAX_BODY_LENGTH) {
            return null;
        }

        $participants = $this->activeParticipants($pageId);
        if ($participants === null) {
            return null; // page missing or not ACCEPTED
        }
        [$callerUserId, $adminUserId] = $participants;

        if ($senderUserId !== $callerUserId && $senderUserId !== $adminUserId) {
            return null; // not a participant on this page
        }

        $stmt = $this->db->prepare("
            INSERT INTO sysop_chat_messages (page_id, sender_user_id, body)
            VALUES (?, ?, ?)
            RETURNING id, body, sender_user_id, created_at::text AS created_at
        ");
        $stmt->execute([$pageId, $senderUserId, $body]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $message = [
            'id'             => (int) $row['id'],
            'body'           => (string) $row['body'],
            'sender_user_id' => (int) $row['sender_user_id'],
            'created_at'     => (string) $row['created_at'],
        ];

        $recipientUserId = $senderUserId === $callerUserId ? $adminUserId : $callerUserId;

        // Targeted at the exact other participant only (never an admin-wide
        // broadcast), so the message body is safe to carry in the payload —
        // sse_events delivery is scoped to this one user_id.
        BinkStream::emit(
            $this->db,
            self::EVENT_MESSAGE,
            [
                'page_id'        => $pageId,
                'message_id'     => $message['id'],
                'sender_user_id' => $senderUserId,
                'created_at'     => $message['created_at'],
                'body'           => $message['body'],
            ],
            $recipientUserId
        );

        return $message;
    }

    /**
     * List a page's messages, oldest first. `$requestingUserId` must be the
     * page's caller or accepting admin (past or present — this also serves a
     * just-completed page gracefully as an empty list once purged, rather
     * than an authorization error).
     *
     * @return list<array{id:int,body:string,sender_user_id:int,created_at:string}>|null
     *   Null when the requester is not a participant; an empty array is a
     *   valid, authorized "no messages" result.
     */
    public function listMessages(int $pageId, int $requestingUserId): ?array
    {
        if ($pageId <= 0 || $requestingUserId <= 0) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT caller_user_id, accepted_by_user_id
            FROM sysop_pages
            WHERE id = ?
        ");
        $stmt->execute([$pageId]);
        $page = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$page) {
            return null;
        }

        $callerUserId = (int) $page['caller_user_id'];
        $adminUserId = $page['accepted_by_user_id'] !== null ? (int) $page['accepted_by_user_id'] : null;

        if ($requestingUserId !== $callerUserId && $requestingUserId !== $adminUserId) {
            return null; // never a participant on this page
        }

        $listStmt = $this->db->prepare("
            SELECT id, body, sender_user_id, created_at::text AS created_at
            FROM sysop_chat_messages
            WHERE page_id = ?
            ORDER BY id ASC
        ");
        $listStmt->execute([$pageId]);

        $out = [];
        foreach ($listStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = [
                'id'             => (int) $row['id'],
                'body'           => (string) $row['body'],
                'sender_user_id' => (int) $row['sender_user_id'],
                'created_at'     => (string) $row['created_at'],
            ];
        }
        return $out;
    }

    /**
     * Delete every message for one page. Called by
     * {@see SysopChatService::completePage()} once a chat ends — internal
     * lifecycle cleanup, not a caller-facing operation, so it takes no acting
     * user and performs no participant check.
     */
    public function purgeForPage(int $pageId): int
    {
        if ($pageId <= 0) {
            return 0;
        }

        $stmt = $this->db->prepare('DELETE FROM sysop_chat_messages WHERE page_id = ?');
        $stmt->execute([$pageId]);
        return $stmt->rowCount();
    }

    /**
     * @return array{0:int,1:int}|null [caller_user_id, accepted_by_user_id]
     *   for an ACCEPTED page, or null if the page is missing or not
     *   currently ACCEPTED (accepted_by_user_id is guaranteed non-null here).
     */
    private function activeParticipants(int $pageId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT caller_user_id, accepted_by_user_id
            FROM sysop_pages
            WHERE id = ? AND status = ?
        ");
        $stmt->execute([$pageId, SysopChatService::STATUS_ACCEPTED]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['accepted_by_user_id'] === null) {
            return null;
        }

        return [(int) $row['caller_user_id'], (int) $row['accepted_by_user_id']];
    }
}
