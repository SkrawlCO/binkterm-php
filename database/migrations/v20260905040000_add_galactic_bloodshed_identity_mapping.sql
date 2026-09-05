-- Migration: 20260905040000 - add galactic bloodshed identity mapping
-- Created: 2026-09-05 04:00:00 UTC
--
-- L33TEST/Crossroads-owned mapping: one authenticated BinkTerm user -> exactly
-- one Galactic Bloodshed race (Crossroads Experience #6 candidate). Used by
-- BinktermPHP\Crossroads\GalacticBloodshedIdentity so the same caller resolves
-- to one persistent-universe race across Telnet and Web.
--
-- Unlike chessmata_identities (Slice 2's HTTP-API broker, provisioned inside a
-- single fast DB transaction), Galactic Bloodshed provisioning shells out to
-- the real `enrol` binary against GB's own SQLite universe -- an operation
-- that can block on a live human answering real race-design prompts (racial
-- type, home planet, sector preference), and that BinkTermPHP's Postgres
-- cannot transactionally coordinate with (no two-phase commit between two
-- independent databases). `status` + `attempt_token` exist specifically to
-- make that gap safe: candidate credentials are persisted in 'provisioning'
-- BEFORE `enrol` is ever invoked, so a crash between GB accepting the
-- enrollment and BinkTerm recording it leaves enough information (the exact
-- credential that was attempted) to reconcile via a login probe rather than
-- silently orphaning a race or double-provisioning one. See
-- GalacticBloodshedIdentity::resolve()/reconcile().
--
-- Every bearer secret column (*_enc) holds a GalacticBloodshedSecretBox
-- ciphertext (sodium_crypto_secretbox, base64 "nonce||box"), NEVER plaintext.
-- Deliberately narrow: one provider, one race per user.

CREATE TABLE IF NOT EXISTS galactic_bloodshed_identities (
    id                     SERIAL       PRIMARY KEY,

    -- immutable BinkTerm identity; one row per user, gone when the user is
    binkterm_user_id       INTEGER      NOT NULL UNIQUE
                                        REFERENCES users(id) ON DELETE CASCADE,

    -- 'pending'      -- no attempt yet (or a prior attempt was reset)
    -- 'provisioning' -- enrol claimed/in flight; race_password_enc /
    --                   governor_password_enc are the candidate credentials
    --                   that either are being attempted right now, or were
    --                   attempted by a process that never reported back
    -- 'provisioned'  -- enrol confirmed success (or reconciled via a login
    --                   probe); gb_playernum is authoritative
    -- 'failed'       -- last attempt definitively failed before GB accepted
    --                   it (safe to retry from scratch with fresh credentials)
    status                 VARCHAR(16)  NOT NULL DEFAULT 'pending',

    -- GalacticBloodshedSecretBox ciphertexts. Populated when a provisioning
    -- attempt is claimed (before `enrol` runs), not only after success --
    -- see the reconciliation note above.
    race_password_enc      TEXT,
    governor_password_enc  TEXT,

    -- recorded once GB has confirmed the race exists (enrol's own success
    -- line, or a reconciling login probe's response)
    gb_playernum           INTEGER,

    -- claims a single in-flight provisioning attempt; NULL when none is
    -- outstanding. A launcher must hold the matching token to transition
    -- 'provisioning' -> 'provisioned'/'failed'; a stale attempt_started_at
    -- (older than the launcher's own liveness expectation) is what makes a
    -- row eligible for reconciliation instead of an indefinite lock.
    attempt_token          UUID,
    attempt_started_at     TIMESTAMPTZ,

    provisioned_at         TIMESTAMPTZ,
    updated_at             TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_galactic_bloodshed_identities_status
    ON galactic_bloodshed_identities (status);
