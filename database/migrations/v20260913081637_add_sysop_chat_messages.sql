-- Migration: 20260913081637 - add sysop chat messages
-- Created: 2026-09-13 08:16:37 UTC
--
-- SysOp Chat M1B: minimal, ephemeral private message transport for one
-- ACCEPTED sysop_pages row. Deliberately NOT BinktermPHP\Chat\ChatMessageService
-- (see docs/SysopChat/M1A.md "Message transport recon") -- that service
-- permanently persists chat_messages and fans out to Matterbridge/PacketBBS/
-- ActivityTracker, all wrong for a private, short-lived SysOp conversation.
--
-- Every message belongs to exactly one sysop_pages row (cascade-deleted with
-- it) and has no independent lifecycle of its own: BinktermPHP\SysopChatService
-- ::completePage() purges a page's messages as soon as the chat ends, so
-- nothing here is a permanent transcript. A brief caller disconnect/reconnect
-- during an active (still ACCEPTED) chat is naturally tolerated -- messages
-- are not purged until completion, not on every gap in activity.

CREATE TABLE IF NOT EXISTS sysop_chat_messages (
    id              SERIAL       PRIMARY KEY,
    page_id         INTEGER      NOT NULL REFERENCES sysop_pages(id) ON DELETE CASCADE,
    sender_user_id  INTEGER      NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    body            VARCHAR(2000) NOT NULL CHECK (char_length(body) > 0),
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

-- Ordered transcript lookup for one page (the only query shape this table serves).
CREATE INDEX IF NOT EXISTS idx_sysop_chat_messages_page_id
    ON sysop_chat_messages (page_id, id);
