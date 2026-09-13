-- Migration: 20260913074753 - add sysop pages
-- Created: 2026-09-13 07:47:53 UTC
--
-- SysOp Chat M1A: the authoritative backend lifecycle record for a caller's
-- "Page SysOp" request. This table owns state transitions only; it is
-- deliberately NOT a chat-transcript store (see BinktermPHP\Chat\SysopChatService
-- doc block and docs/SysopChat/M1A.md -- ChatMessageService's direct-message
-- persistence is durable/cross-network-fanned-out and unsuitable for this
-- feature's ephemeral-chat intent, so message transport is left to a later
-- slice rather than reused here).
--
-- caller_session_id is nullable: a Telnet/SSH/Web-terminal caller pages from a
-- live BbsSession bound to one user_sessions.session_id, but an ordinary Web UI
-- caller has no equivalent terminal session concept and pages from plain
-- authenticated user identity alone (see docs/SysopChat/M1A.md "Session model").
--
-- Two partial-unique indexes enforce the M1 concurrency invariants directly at
-- the data layer rather than relying on application-level locking:
--   * at most one WAITING page per caller
--   * at most one ACCEPTED page globally (the M1 singleton-Matt-chat rule)

CREATE TABLE IF NOT EXISTS sysop_pages (
    id                   SERIAL       PRIMARY KEY,
    caller_user_id       INTEGER      NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    caller_session_id    VARCHAR(255),
    surface              VARCHAR(20)  NOT NULL
        CHECK (surface IN ('telnet', 'ssh', 'web_terminal', 'web_ui')),
    status               VARCHAR(20)  NOT NULL DEFAULT 'waiting'
        CHECK (status IN ('waiting', 'accepted', 'declined', 'expired', 'completed', 'cancelled')),
    accepted_by_user_id  INTEGER      REFERENCES users(id) ON DELETE SET NULL,
    created_at           TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at           TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    accepted_at          TIMESTAMPTZ,
    completed_at         TIMESTAMPTZ,
    expires_at           TIMESTAMPTZ  NOT NULL
);

-- At most one WAITING page per caller.
CREATE UNIQUE INDEX IF NOT EXISTS idx_sysop_pages_one_waiting_per_caller
    ON sysop_pages (caller_user_id)
    WHERE status = 'waiting';

-- M1 singleton: at most one ACCEPTED (chatting) page globally.
CREATE UNIQUE INDEX IF NOT EXISTS idx_sysop_pages_one_accepted_globally
    ON sysop_pages ((1))
    WHERE status = 'accepted';

-- Admin "waiting pages" listing and stale-row expiry both scan by status.
CREATE INDEX IF NOT EXISTS idx_sysop_pages_status
    ON sysop_pages (status);

-- Expiry sweep scans waiting rows past their deadline.
CREATE INDEX IF NOT EXISTS idx_sysop_pages_expires_at
    ON sysop_pages (expires_at)
    WHERE status = 'waiting';
