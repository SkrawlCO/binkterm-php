-- Add intentional public-facing presence separate from internal page/activity tracking.

ALTER TABLE user_sessions
    ADD COLUMN IF NOT EXISTS public_activity VARCHAR(255);

COMMENT ON COLUMN user_sessions.public_activity IS
    'Intentional public-facing presence text, such as the Experience currently being played';
