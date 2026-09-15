-- Migration: 20260915035904 - add echomail root id and reply_to_id index
-- Created: 2026-09-15 03:59:04 UTC
--
-- Messaging Evolution Phase 1 (personal relevance): materializes a
-- conversation-root primitive on echomail so "did user U participate
-- anywhere in this conversation" is an O(1) lookup instead of a recursive
-- ancestry walk per request. See:
--   /root/L33TEST_Messaging_Phase1_Personal_Relevance_Design_2026-09-14.md
-- (Track B / Track K) for the full design rationale.
--
-- root_id is nullable and self-referencing: a message with no known parent
-- chain yet (out-of-order FTN/QWK arrival, or an ancestor still missing)
-- simply has root_id = NULL until the application-level resolve/propagate
-- logic (MessageHandler::resolveEchomailRootId()/propagateEchomailRootId(),
-- called from BinkdProcessor, Qwk\QwkInbound, and
-- MessageHandler::postEchomail()) fills it in — mirroring exactly how
-- reply_to_id itself is already left NULL and backfilled later for the
-- same reason. This migration only adds structure and backfills existing
-- rows; it does not change reply_to_id, message content, or any other
-- column.

ALTER TABLE echomail ADD COLUMN IF NOT EXISTS root_id INTEGER REFERENCES echomail(id);

-- Missing pre-existing index: reply_to_id has carried a FK constraint since
-- introduction, but Postgres does not implicitly index FK columns (unlike
-- primary keys), and no migration ever added one. This has made every
-- "find replies to message(s) X" query (including the already-shipped
-- Messaging Evolution Slice 2 ActivityService::repliesToCallerSinceIds())
-- a sequential scan. Bundled here because it hardens the exact query family
-- Phase 1 extends, per the approved design's own recommendation.
CREATE INDEX IF NOT EXISTS idx_echomail_reply_to_id ON echomail(reply_to_id);

-- Backs both "which conversation is this root's own" identity lookups and
-- ActivityService's new "did user U participate in root R" existence check.
CREATE INDEX IF NOT EXISTS idx_echomail_root_id_user_id ON echomail(root_id, user_id);

-- Backfill existing rows. Bounded recursive walk (depth-limited, matching
-- this codebase's existing bounded-recursion precedent — e.g.
-- Qwk\QwkInbound::CYCLE_WALK_LIMIT, MessageHandler's thread-loader
-- maxDepth = 50) so a corrupt/cyclic reply_to_id chain (which should not
-- be reachable through normal insert paths, but is not structurally
-- impossible — see the Phase 1 design's Track A note on the existing
-- ancestry CTE having no cycle guard of its own) cannot hang or crash this
-- migration: any row not reached within the depth cap is simply left with
-- root_id = NULL, exactly like a genuine unresolved out-of-order orphan,
-- rather than being guessed at. It can be repaired later the same way any
-- other unresolved chain is repaired — by the ordinary
-- resolve/propagate logic firing again once/if the chain is corrected.
WITH RECURSIVE roots AS (
    SELECT id, id AS root_id, 0 AS depth
    FROM echomail
    WHERE reply_to_id IS NULL

    UNION ALL

    SELECT em.id, r.root_id, r.depth + 1
    FROM echomail em
    JOIN roots r ON em.reply_to_id = r.id
    WHERE r.depth < 100
)
UPDATE echomail e
SET root_id = r.root_id
FROM roots r
WHERE e.id = r.id
  AND e.root_id IS DISTINCT FROM r.root_id;
