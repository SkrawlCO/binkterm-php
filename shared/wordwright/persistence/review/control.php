<?php
if (PHP_SAPI !== 'cli' || getenv('WORDWRIGHT_REVIEW') !== '1') exit(2);
require '/var/www/html/vendor/autoload.php';
$f=json_decode(file_get_contents('/tmp/ww-proof-callers.json'),true);$id=$f['callers'][0]['id'];
$db=\BinktermPHP\Database::getInstance()->getPdo();
if(($argv[1]??'')!=='expire')exit(2);
$db->prepare("UPDATE webdoor_storage SET metadata=jsonb_set(metadata,'{lease_expires_at}','0'::jsonb) WHERE user_id=? AND game_id='wordwright' AND slot=0")->execute([$id]);echo "Test caller lease expired\n";
