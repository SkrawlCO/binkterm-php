<?php
if (PHP_SAPI !== 'cli' || getenv('DOKUEL_REVIEW') !== '1') exit(2);
require '/var/www/html/vendor/autoload.php';
$db=\BinktermPHP\Database::getInstance()->getPdo();
$q=$db->query("SELECT id, username, is_active FROM users WHERE username ~ '^dkproof[0-9a-f]{10}$' AND real_name = 'Dokuel isolated proof ' || username ORDER BY id");
$rows=$q->fetchAll(PDO::FETCH_ASSOC);
if (($argv[1] ?? '') === 'cleanup') {
    $db->beginTransaction();
    foreach ($rows as $row) {
        if (!$row['is_active']) continue;
        $db->prepare('DELETE FROM user_sessions WHERE user_id=?')->execute([$row['id']]);
        $db->prepare("DELETE FROM users_meta WHERE user_id=? AND keyname='csrf_token'")->execute([$row['id']]);
        $db->prepare("DELETE FROM webdoor_storage WHERE user_id=? AND game_id='dokuel'")->execute([$row['id']]);
        $db->prepare('UPDATE users SET is_active=FALSE,password_hash=? WHERE id=?')->execute([password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT),$row['id']]);
    }
    $db->commit(); echo "Isolated Dokuel fixture accounts cleaned up\n";
} else echo json_encode($rows), "\n";
