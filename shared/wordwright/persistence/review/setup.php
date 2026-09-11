<?php
if (PHP_SAPI !== 'cli' || getenv('WORDWRIGHT_REVIEW') !== '1') exit(2);
require '/var/www/html/vendor/autoload.php';
$db=\BinktermPHP\Database::getInstance()->getPdo();
$legacy=function()use($db){return $db->query("SELECT md5(COALESCE(jsonb_agg(to_jsonb(s) ORDER BY id)::text,'')) FROM webdoor_storage s WHERE game_id='wordle'")->fetchColumn().':'.$db->query("SELECT md5(COALESCE(jsonb_agg(to_jsonb(s) ORDER BY id)::text,'')) FROM webdoor_leaderboards s")->fetchColumn();};
if(($argv[1]??'')==='cleanup'){
 $f=json_decode(file_get_contents('/tmp/ww-proof-callers.json'),true);
 if($legacy()!==$f['legacy'])throw new Exception('Legacy data changed during proof');
 foreach($f['callers'] as $c){
  $db->prepare('DELETE FROM user_sessions WHERE user_id=?')->execute([$c['id']]);
  $db->prepare("DELETE FROM users_meta WHERE user_id=? AND keyname='csrf_token'")->execute([$c['id']]);
  $db->prepare("DELETE FROM webdoor_storage WHERE user_id=? AND game_id='wordwright'")->execute([$c['id']]);
  $db->prepare('UPDATE users SET is_active=FALSE,password_hash=? WHERE id=?')->execute([password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT),$c['id']]);
 }
 unlink('/tmp/ww-proof-callers.json');echo "Legacy save/leaderboard fingerprints unchanged; callers deactivated, sessions/tokens/state removed\n";exit;
}
$f=['legacy'=>$legacy(),'callers'=>[]];
$db->beginTransaction();
for($i=0;$i<2;$i++){
 $name='wwproof'.bin2hex(random_bytes(5));
 $q=$db->prepare('INSERT INTO users (username,password_hash,real_name,is_admin,is_active) VALUES (?,?,?,false,true) RETURNING id');
 $q->execute([$name,password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT),'Wordwright isolated proof '.$name]);$id=(int)$q->fetchColumn();
 $db->prepare("INSERT INTO user_settings (user_id,timezone,messages_per_page,theme) VALUES (?,'UTC',25,'dark')")->execute([$id]);
 $session=(new \BinktermPHP\Auth())->createSession($id);$csrf=bin2hex(random_bytes(32));(new \BinktermPHP\UserMeta())->setValue($id,'csrf_token',$csrf);
 $f['callers'][]=['id'=>$id,'session'=>$session,'csrf'=>$csrf];
}
$db->commit();
file_put_contents('/tmp/ww-proof-callers.json',json_encode($f));chmod('/tmp/ww-proof-callers.json',0600);echo "Two isolated callers created; credentials withheld\n";
