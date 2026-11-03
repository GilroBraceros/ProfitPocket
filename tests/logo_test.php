<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(403);exit;}
foreach (['full','icon'] as $variant) {
    $c=curl_init('http://localhost/profitpocket/assets/branding.php?logo='.$variant);$headers=[];
    curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_HEADERFUNCTION=>static function($curl,$line)use(&$headers){$p=explode(':',$line,2);if(count($p)===2){$headers[strtolower(trim($p[0]))]=trim($p[1]);}return strlen($line);}]);
    $body=curl_exec($c);
    if (curl_getinfo($c,CURLINFO_HTTP_CODE)!==200 || ($headers['content-type']??'')!=='image/jpeg' || !getimagesizefromstring($body) || hash('sha256',$body)!==hash_file('sha256',__DIR__.'/../assets/images/logo-'.$variant.'.jpg')) {throw new RuntimeException('Original logo HTTP response or MIME invalid.');}
    curl_setopt($c,CURLOPT_HTTPHEADER,['If-None-Match: '.$headers['etag']]);curl_exec($c);
    if(curl_getinfo($c,CURLINFO_HTTP_CODE)!==304){throw new RuntimeException('Logo cache validation failed.');}
}
foreach (['../../config/database.php','unknown','icon%00'] as $invalid) {
    $c=curl_init('http://localhost/profitpocket/assets/branding.php?logo='.rawurlencode($invalid));curl_setopt($c,CURLOPT_RETURNTRANSFER,true);$body=curl_exec($c);
    if(curl_getinfo($c,CURLINFO_HTTP_CODE)!==404 || $body!==''){throw new RuntimeException('Logo endpoint accepted an arbitrary asset path.');}
}
echo "PASS: original full/icon logo bytes, HTTP 200 image/jpeg, ETag/304 and arbitrary-path rejection.\n";
