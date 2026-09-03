-- Migration: 20260903204726 - add chessmata identity mapping
-- Created: 2026-09-03 20:47:26 UTC
--
-- L33TEST/Crossroads-owned mapping: one authenticated BinkTerm user ->
-- exactly one self-hosted Chessmata account (Crossroads Experience #4,
-- Slice 2). Used by BinktermPHP\Crossroads\ChessmataIdentity so the same
-- caller has one Chessmata identity across the graphical Web client and the
-- terminal/Telnet CLI, preserving Elo / history / leaderboard / matchmaking.
--
-- NOT a generic external-identity or credential-vault primitive. Deliberately
-- narrow: one provider, one account per user, the smallest set of columns the
-- broker needs. Every bearer secret column (*_enc) holds a
-- sodium_crypto_secretbox ciphertext (base64 "nonce||box"), NEVER plaintext --
-- see BinktermPHP\Crossroads\ChessmataSecretBox. BinkTerm stays ignorant of
-- Chessmata's Mongo schema: chessmata_user_id is just the opaque id string the
-- Chessmata HTTP API returns.

CREATE TABLE IF NOT EXISTS chessmata_identities (
    id                        SERIAL       PRIMARY KEY,

    -- immutable BinkTerm identity; one row per user, gone when the user is
    binkterm_user_id          INTEGER      NOT NULL UNIQUE
                                           REFERENCES users(id) ON DELETE CASCADE,

    -- opaque values returned by the Chessmata HTTP API
    chessmata_user_id         VARCHAR(64)  NOT NULL,
    chessmata_email           VARCHAR(255) NOT NULL,   -- bt-<id>@chessmata.invalid (RFC 2606, never deliverable)
    chessmata_display_name    VARCHAR(64)  NOT NULL,

    -- bearer secrets, each a ChessmataSecretBox ciphertext (never plaintext, never logged)
    password_enc              TEXT         NOT NULL,   -- generated password: the recovery/root credential
    api_key_enc               TEXT,                    -- durable cmk_ API key: the everyday credential (no expiry)
    refresh_token_enc         TEXT,                    -- 90d refresh token (mints access tokens; does not rotate)
    access_token_enc          TEXT,                    -- 30d access token cache

    access_token_expires_at   TIMESTAMPTZ,
    refresh_token_expires_at  TIMESTAMPTZ,

    provisioned_at            TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at                TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);
