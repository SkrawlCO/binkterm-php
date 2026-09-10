-- Durable genuine caller arrival; intentionally no historical backfill.
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_caller_visit_at TIMESTAMPTZ;
