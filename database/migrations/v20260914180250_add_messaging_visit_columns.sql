-- Migration: 20260914180250 - add_messaging_visit_columns
-- Created: 2026-09-14 18:02:50 UTC

-- Messaging Evolution Slice 1: the logical "visit family" boundary used for
-- future Since-Your-Last-Call semantics. Distinct from user_sessions (per
-- connection) and from last_caller_visit_at (public arrival ticker, coalesced
-- to 30 minutes). A visit family stays open across concurrent Web/Telnet/SSH
-- sessions as long as any of them renews within GRACE_SECONDS of the last
-- renewal; see src/Messaging/VisitTracker.php.
ALTER TABLE users ADD COLUMN IF NOT EXISTS messaging_visit_boundary_at TIMESTAMPTZ;
ALTER TABLE users ADD COLUMN IF NOT EXISTS messaging_visit_renewed_at TIMESTAMPTZ;
