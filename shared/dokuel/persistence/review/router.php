<?php
require '/var/www/html/vendor/autoload.php';
// Review fixture avoids unrelated daily-credit writes for the isolated callers only.
session_start();
$f=json_decode(file_get_contents('/tmp/dk-proof-callers.json'),true);
foreach($f['callers'] as $c)$_SESSION['daily_credit_last_check_'.$c['id']]=time();
require '/var/www/html/shared/dokuel/persistence/api.php';
