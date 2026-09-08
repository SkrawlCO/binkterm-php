-- Migration: 20260908195112 - add qwk outbound queue and rep batches
-- Created: 2026-09-08 19:51:12 UTC
--
-- QWKnet outbound: local posts in an echo area that is explicitly subscribed to
-- a QWK mailbox are queued for export, batched into one <BBSID>.REP archive, and
-- uploaded to the peer. This slice adds the queue + batch ledger only; the
-- enqueue hook, REP builder, FTP transport, and poller are code.
--
-- Deliberately NOT included: relay/gating (FTN<->QWK), scheduler state, multi-hop
-- routing, DOVE-Net or other hub rows, network catalog changes.

-- One built .REP archive for one mailbox. States:
--   built             - archive created on disk, its message set is frozen
--   upload_attempted  - a STOR was issued but not confirmed (uncertain); the
--                       poller does NOT auto-retry these -- an operator decides
--   uploaded          - upload confirmed; the batch's queue rows are 'sent'
--   failed            - upload failed before/at connect; safe to retry (the same
--                       frozen message set rebuilds a semantically identical REP)
CREATE TABLE IF NOT EXISTS qwk_rep_batches (
    id                  SERIAL       PRIMARY KEY,
    mailbox_id          INTEGER      NOT NULL REFERENCES qwk_mailboxes(id) ON DELETE CASCADE,
    state               VARCHAR(16)  NOT NULL DEFAULT 'built',
    archive_sha256      VARCHAR(64),
    message_count       INTEGER      NOT NULL DEFAULT 0,
    created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    upload_attempted_at TIMESTAMPTZ,
    uploaded_at         TIMESTAMPTZ,
    last_error          TEXT,
    CONSTRAINT qwk_rep_batches_state_check
        CHECK (state IN ('built', 'upload_attempted', 'uploaded', 'failed'))
);

-- One local echomail message queued for one mailbox. States:
--   pending  - not yet in a batch
--   batched  - assigned to a batch (batch_id set); frozen for REP building
--   sent     - the batch it belongs to was confirmed uploaded
--   failed   - permanently skipped for this mailbox (last_error explains)
-- UNIQUE(mailbox_id, echomail_id) makes re-enqueue of the same message a no-op.
-- `msgid` is captured once at enqueue and reused on every REP (re)build so a
-- retry never produces a semantically different message.
CREATE TABLE IF NOT EXISTS qwk_outbound_messages (
    id                SERIAL      PRIMARY KEY,
    mailbox_id        INTEGER     NOT NULL REFERENCES qwk_mailboxes(id) ON DELETE CASCADE,
    echomail_id       INTEGER     NOT NULL REFERENCES echomail(id) ON DELETE CASCADE,
    conference_number INTEGER     NOT NULL,
    state             VARCHAR(16) NOT NULL DEFAULT 'pending',
    batch_id          INTEGER     REFERENCES qwk_rep_batches(id) ON DELETE SET NULL,
    msgid             TEXT,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    batched_at        TIMESTAMPTZ,
    sent_at           TIMESTAMPTZ,
    last_error        TEXT,
    CONSTRAINT qwk_outbound_messages_dedupe UNIQUE (mailbox_id, echomail_id),
    CONSTRAINT qwk_outbound_messages_state_check
        CHECK (state IN ('pending', 'batched', 'sent', 'failed')),
    CONSTRAINT qwk_outbound_messages_conf_nonneg CHECK (conference_number >= 0)
);

-- Pending-work scan per mailbox.
CREATE INDEX IF NOT EXISTS qwk_outbound_messages_pending
    ON qwk_outbound_messages (mailbox_id, state);
