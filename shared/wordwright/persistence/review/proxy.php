<?php
$r=json_decode(stream_get_contents(STDIN),true);
$headers=['Content-Type: application/json'];
foreach(['cookie','x-csrf-token'] as $key)if(isset($r['headers'][$key]))$headers[]=$key.': '.$r['headers'][$key];
$context=stream_context_create(['http'=>['method'=>'POST','header'=>implode("\r\n",$headers),'content'=>$r['body'],'ignore_errors'=>true]]);
$body=file_get_contents('http://127.0.0.1:43192/state',false,$context);
preg_match('/\s(\d{3})\s/',$http_response_header[0],$m);
echo json_encode(['status'=>(int)$m[1],'body'=>$body]);
