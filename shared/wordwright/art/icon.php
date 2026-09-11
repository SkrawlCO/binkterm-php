<?php
// Original Wordwright tile/pen composition, rendered with system DejaVu Sans.
$im=imagecreatetruecolor(1024,1024);imageantialias($im,true);
for($y=0;$y<1024;$y++){ $c=imagecolorallocate($im,18+(int)($y/90),22+(int)($y/150),48+(int)($y/70));imageline($im,0,$y,1023,$y,$c); }
$cyan=imagecolorallocate($im,79,213,220);$white=imagecolorallocate($im,244,239,226);$gold=imagecolorallocate($im,247,180,71);$ink=imagecolorallocate($im,23,31,64);$shadow=imagecolorallocate($im,8,13,31);
imagefilledrectangle($im,158,253,576,675,$shadow);imagefilledrectangle($im,139,230,557,652,$white);
imagefilledrectangle($im,533,378,871,716,$shadow);imagefilledrectangle($im,514,355,852,693,$cyan);
$font='/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';imagettftext($im,245,0,184,552,$ink,$font,'W');imagettftext($im,172,0,601,605,$ink,$font,'R');
imagesetthickness($im,24);imageline($im,186,786,747,786,$gold);
imagefilledpolygon($im,[724,250,796,178,851,233,779,305],$gold);imagefilledpolygon($im,[715,261,766,312,700,327],$white);
$out=imagescale($im,512,512,IMG_BICUBIC_FIXED);imagepng($out,dirname(__DIR__,3).'/public_html/webdoors/wordwright/icon.png');imagepng(imagescale($out,48,48,IMG_BICUBIC_FIXED),'/tmp/wordwright-icon48.png');
