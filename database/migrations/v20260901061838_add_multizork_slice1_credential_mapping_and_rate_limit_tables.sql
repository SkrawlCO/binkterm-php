-- Migration: 20260901061838 - add multizork slice1 credential mapping and rate limit tables
-- Created: 2026-09-01 06:18:38 UTC
--
-- L33TEST/Crossroads-owned tables for Crossroads Experience #1 (MultiZork)
-- Slice 1. NOT a generic BinkTermPHP external-identity or rate-limit
-- primitive — see docs/Crossroads/MultiZorkSlice1.md. Deliberately narrow:
-- one BinkTerm user + one fixed test expedition -> one opaque MultiZork
-- access code. Not designed for arbitrary providers/worlds/credential
-- types; promote/generalize later only if a second real consumer needs
-- the same shape.

-- Opaque, server-side-only mapping from a BinkTerm user to the private
-- MultiZork access/return code for one fixed test expedition. Never
-- surfaced through ordinary UI/API responses or logging — see
-- BinktermPHP\Crossroads\MultiZorkAccessMapping.
CREATE TABLE IF NOT EXISTS multizork_expedition_credentials (
    id            SERIAL       PRIMARY KEY,
    user_id       INTEGER      NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    expedition_id VARCHAR(64)  NOT NULL,
    access_code   VARCHAR(16)  NOT NULL,
    created_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    UNIQUE (user_id, expedition_id)
);

-- Rate-limit tracking for MultiZork access-code submission, mirroring the
-- existing PacketBbsLoginRateLimit table shape
-- (packet_bbs_login_attempts): a rolling window of attempts, keyed by the
-- numeric BinkTerm user id rather than an FTN node id. The access code
-- itself is never written to this table.
CREATE TABLE IF NOT EXISTS multizork_access_attempts (
    id            SERIAL       PRIMARY KEY,
    user_id       INTEGER      NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    expedition_id VARCHAR(64)  NOT NULL,
    success       BOOLEAN      NOT NULL DEFAULT FALSE,
    attempted_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS multizork_access_attempts_user_time_idx
    ON multizork_access_attempts (user_id, expedition_id, attempted_at);
