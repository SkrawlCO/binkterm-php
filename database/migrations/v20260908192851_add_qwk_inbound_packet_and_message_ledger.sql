-- Migration: 20260908192851 - add qwk inbound packet and message ledger
-- Created: 2026-09-08 19:28:51 UTC
--
-- QWKnet inbound foundation: local-file import only. No FTP, polling, outbound
-- REP, scheduler, or relay/gating is introduced here.
--
-- Two tables make repeated local imports of the same packet safe and give each
-- imported echomail row enough QWK provenance for future reply handling and
-- debugging, without re-storing packet-level raw structures (the M1 parser in
-- src/QwkNet already preserves those in memory when a packet is re-read).

-- One receipt per successfully-imported QWK archive, per mailbox.
-- UNIQUE(mailbox_id, archive_sha256): re-importing a byte-identical packet is
-- recognised and short-circuits to an "already imported" result rather than
-- inserting anything.
CREATE TABLE IF NOT EXISTS qwk_inbound_packets (
    id             SERIAL       PRIMARY KEY,
    mailbox_id     INTEGER      NOT NULL REFERENCES qwk_mailboxes(id) ON DELETE CASCADE,
    archive_sha256 VARCHAR(64)  NOT NULL,
    packet_bbs_id  VARCHAR(8),
    packet_user    VARCHAR(64),
    message_count  INTEGER      NOT NULL DEFAULT 0,
    skipped_count  INTEGER      NOT NULL DEFAULT 0,
    imported_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    CONSTRAINT qwk_inbound_packets_dedupe UNIQUE (mailbox_id, archive_sha256),
    CONSTRAINT qwk_inbound_packets_sha_hex CHECK (archive_sha256 ~ '^[0-9a-f]{64}$')
);

-- One provenance sidecar row per imported echomail message.
--   * external_msgid   -- the packet-supplied MSGID, kept untruncated for
--                         reply-linking and cross-referencing.
--   * external_reply_id-- the referenced parent MSGID; retained even when the
--                         parent is not (yet) present, for the bounded backfill
--                         pass and for future outbound REP threading.
--   * dedupe_key       -- 'msgid:<id>' when the packet supplies a MSGID for the
--                         record, otherwise 'qwknum:<n>' using the remote QWK
--                         message number (a hub-stable identifier). Never derived
--                         from the packet filename or the import time.
-- UNIQUE(mailbox_id, conference_number, dedupe_key): the same message appearing
-- again -- in a later packet or a re-import -- is skipped, not duplicated.
CREATE TABLE IF NOT EXISTS qwk_inbound_messages (
    id                 SERIAL      PRIMARY KEY,
    echomail_id        INTEGER     NOT NULL REFERENCES echomail(id) ON DELETE CASCADE,
    inbound_packet_id  INTEGER     NOT NULL REFERENCES qwk_inbound_packets(id) ON DELETE CASCADE,
    mailbox_id         INTEGER     NOT NULL REFERENCES qwk_mailboxes(id) ON DELETE CASCADE,
    conference_number  INTEGER     NOT NULL,
    qwk_message_number INTEGER,
    record_offset      INTEGER,
    external_msgid     TEXT,
    external_reply_id  TEXT,
    via                TEXT,
    tz_token           VARCHAR(16),
    dedupe_key         TEXT        NOT NULL,
    created_at         TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT qwk_inbound_messages_echomail_key UNIQUE (echomail_id),
    CONSTRAINT qwk_inbound_messages_dedupe UNIQUE (mailbox_id, conference_number, dedupe_key),
    CONSTRAINT qwk_inbound_messages_conf_nonneg CHECK (conference_number >= 0)
);
