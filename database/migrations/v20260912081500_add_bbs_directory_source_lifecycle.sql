-- Migration: 20260912081500 - add bbs_directory source lifecycle fields
-- Created: 2026-09-12 08:15:00 UTC
--
-- Minimal lifecycle/provenance fields needed for the IBBS (Telnet BBS Guide)
-- importer's apply mode. source='ibbs' (existing column) carries provenance;
-- these three columns let monthly reconciliation know which edition last
-- touched a row and whether it has dropped out of a recent edition without
-- being deleted.

ALTER TABLE bbs_directory
    ADD COLUMN IF NOT EXISTS source_edition VARCHAR(50),
    ADD COLUMN IF NOT EXISTS updated_from_source_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS missing_since TIMESTAMPTZ;

CREATE INDEX IF NOT EXISTS idx_bbs_directory_missing_since ON bbs_directory(missing_since);
