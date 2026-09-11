<?php
// Original, reproducible Sudoku tile composition. No third-party artwork.
$im=imagecreatetruecolor(1024,1024);imageantialias($im,true);
for($y=0;$y<1024;$y++){ $c=imagecolorallocate($im,13+(int)($y/100),29+(int)($y/110),49+(int)($y/90));imageline($im,0,$y,1023,$y,$c); }
$paper=imagecolorallocate($im,244,241,224);$ink=imagecolorallocate($im,27,54,67);$mint=imagecolorallocate($im,69,211,168);$gold=imagecolorallocate($im,255,196,90);$line=imagecolorallocate($im,167,182,174);
imagefilledrectangle($im,152,160,904,912,imagecolorallocate($im,7,18,29));imagefilledrectangle($im,128,128,884,884,$paper);
$cell=84;imagefilledrectangle($im,128+4*$cell,128+3*$cell,128+5*$cell,128+4*$cell,$mint);imagefilledrectangle($im,128+$cell,128+7*$cell,128+2*$cell,128+8*$cell,$gold);
for($i=0;$i<=9;$i++){imagesetthickness($im,$i%3===0?12:3);imageline($im,128+$cell*$i,128,128+$cell*$i,884,$i%3===0?$ink:$line);imageline($im,128,128+$cell*$i,884,128+$cell*$i,$i%3===0?$ink:$line);}
$font='/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
foreach([[0,0,'5'],[1,3,'7'],[2,7,'2'],[3,4,'9'],[4,1,'4'],[5,8,'6'],[6,5,'3'],[8,2,'8']] as [$r,$c,$n])imagettftext($im,43,0,151+$c*$cell,190+$r*$cell,$ink,$font,$n);
foreach([[0,0,'1'],[0,1,'3'],[1,0,'7']] as [$r,$c,$n])imagettftext($im,20,0,221+$c*34,744+$r*36,$ink,$font,$n);
$out=imagescale($im,512,512,IMG_BICUBIC_FIXED);imagepng($out,dirname(__DIR__,3).'/public_html/webdoors/dokuel/icon.png');imagepng(imagescale($out,48,48,IMG_BICUBIC_FIXED),'/tmp/dokuel-icon48.png');
