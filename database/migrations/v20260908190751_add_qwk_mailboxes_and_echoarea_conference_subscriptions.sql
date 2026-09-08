-- Migration: 20260908190751 - add qwk mailboxes and echoarea conference subscriptions
-- Created: 2026-09-08 19:07:51 UTC
--
-- QWKnet inter-BBS foundation, schema + model only. No polling, import, export,
-- transport, or relay/gating is implemented in this slice.
--
-- Forward-ported and adapted from the upstream `qwknet` branch
-- (v20260523012838_qwk_mail_exchange_support.sql + v20260523042737 passive_mode).
-- Deviations from upstream:
--   * `passive_mode` folded into the initial table (upstream added it separately).
--   * `echo_area_qwk_subscriptions.auto_created` dropped: this slice never
--     auto-creates areas or subscriptions, so the flag carries no information.
--   * `echo_area_qwk_subscriptions.conference_tag` is nullable: an explicit
--     admin mapping does not have to know the remote conference label.
--   * The two redundant single-column indexes on the subscription table are
--     omitted: both foreign-key lookups are already served by the leftmost
--     column of an existing UNIQUE constraint.
--   * `qwk_outbound_messages`, `echo_area_gates`, echomail QWK provenance
--     columns, `relay_mode`, and network catalog rows are intentionally NOT
--     included here.

-- One inter-BBS QWK peer ("mailbox"): the remote hub we exchange packets with.
-- `password` stores a SysK-encrypted value (base64 of nonce || secretbox
-- ciphertext) produced by BinktermPHP\SysK; a plaintext password is never
-- persisted. Transport connection fields (host/port/username/ftp_remote_path/
-- passive_mode) describe an FTP peer, the only transport the upstream design
-- ships; no transport code runs yet. `poll_schedule` / `last_polled_at` /
-- `last_error` are inert status columns retained from the upstream mailbox
-- model for the future poller slice.
CREATE TABLE IF NOT EXISTS qwk_mailboxes (
    id               SERIAL       PRIMARY KEY,
    name             VARCHAR(100) NOT NULL,
    bbs_id           VARCHAR(8)   NOT NULL,
    host             VARCHAR(255) NOT NULL,
    port             INTEGER      NOT NULL DEFAULT 21,
    username         VARCHAR(100) NOT NULL,
    password         TEXT         NOT NULL,
    ftp_remote_path  VARCHAR(500) NOT NULL DEFAULT '/',
    passive_mode     BOOLEAN      NOT NULL DEFAULT TRUE,
    poll_schedule    VARCHAR(100),
    enabled          BOOLEAN      NOT NULL DEFAULT TRUE,
    last_polled_at   TIMESTAMPTZ,
    last_error       TEXT,
    created_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    CONSTRAINT qwk_mailboxes_port_range CHECK (port BETWEEN 1 AND 65535)
);

-- Explicit mapping of one local echoarea to one remote conference on one
-- mailbox. Rows are created only by an operator/admin action; nothing in this
-- slice auto-creates areas or subscriptions.
--   * UNIQUE (mailbox_id, conference_number): a conference on a peer maps to at
--     most one local area; the same conference number may exist on other peers.
--   * UNIQUE (echoarea_id, mailbox_id): an area subscribes to at most one
--     conference per peer (it may still subscribe to several peers).
CREATE TABLE IF NOT EXISTS echo_area_qwk_subscriptions (
    id                SERIAL      PRIMARY KEY,
    echoarea_id       INTEGER     NOT NULL REFERENCES echoareas(id) ON DELETE CASCADE,
    mailbox_id        INTEGER     NOT NULL REFERENCES qwk_mailboxes(id) ON DELETE CASCADE,
    conference_number INTEGER     NOT NULL,
    conference_tag    VARCHAR(50),
    created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT echo_area_qwk_subscriptions_mailbox_conf_key UNIQUE (mailbox_id, conference_number),
    CONSTRAINT echo_area_qwk_subscriptions_area_mailbox_key UNIQUE (echoarea_id, mailbox_id),
    CONSTRAINT echo_area_qwk_subscriptions_conf_nonneg CHECK (conference_number >= 0)
);
