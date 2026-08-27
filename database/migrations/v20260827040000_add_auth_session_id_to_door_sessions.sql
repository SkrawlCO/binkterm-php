ALTER TABLE door_sessions
ADD COLUMN IF NOT EXISTS auth_session_id VARCHAR(128);
