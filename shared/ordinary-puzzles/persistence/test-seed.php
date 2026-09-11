<?php
require __DIR__ . '/test-bootstrap.php';
foreach (['username TEXT','real_name TEXT','email TEXT','is_admin BOOLEAN DEFAULT FALSE','manage_hub_point BOOLEAN DEFAULT FALSE','password_hash TEXT','created_at TIMESTAMP','last_login TIMESTAMP','location TEXT','about_me TEXT','fidonet_address TEXT','is_active BOOLEAN DEFAULT TRUE'] as $column) $db->exec('ALTER TABLE users ADD COLUMN IF NOT EXISTS ' . $column);
$db->exec("CREATE TABLE IF NOT EXISTS user_sessions (session_id TEXT PRIMARY KEY, user_id INTEGER, expires_at TIMESTAMP, last_activity TIMESTAMP, ip_address TEXT)");
$db->exec("CREATE TABLE IF NOT EXISTS users_meta (user_id INTEGER, keyname TEXT, valname TEXT, updated_at TIMESTAMP, UNIQUE(user_id,keyname))");
$db->exec("CREATE TABLE IF NOT EXISTS user_settings (user_id INTEGER PRIMARY KEY, locale TEXT)");
$session = bin2hex(random_bytes(32)); $csrf = bin2hex(random_bytes(32));
$db->prepare("INSERT INTO user_sessions VALUES (?,1,NOW()+INTERVAL '1 hour',NOW(),'127.0.0.1')")->execute([$session]);
(new \BinktermPHP\UserMeta())->setValue(1, 'csrf_token', $csrf);
$db->exec("DELETE FROM webdoor_storage WHERE game_id='ordinary-puzzles'");
echo json_encode(['session' => $session, 'csrf' => $csrf]);
