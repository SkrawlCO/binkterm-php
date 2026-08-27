INSERT INTO chat_rooms (name, description, is_active)
VALUES
    (
        'Usurper Reborn',
        'Conversation for the Usurper Reborn Experience.',
        TRUE
    ),
    (
        'Lateania',
        'Conversation for the Lateania Experience.',
        TRUE
    ),
    (
        'Green Dragon',
        'Conversation for the Green Dragon Experience.',
        TRUE
    )
ON CONFLICT (name) DO NOTHING;
