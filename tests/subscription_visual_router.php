<?php
// Read-only loopback preview of actual HTTP test snapshots; never touches the DB.
if (PHP_SAPI!=='cli-server' || $_SERVER['REQUEST_METHOD']!=='GET') {http_response_code(403);exit;}
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if (preg_match('~^/(homepage|pending-payment|pending-verification|rejected|active|expired|renewed|admin-review|admin-overview)$~',$path,$match)) {
    $file=__DIR__.'/http-results/subscription-'.$match[1].'.html';
    if(is_file($file)){header('Content-Type: text/html; charset=UTF-8');header('Cache-Control: no-store');readfile($file);exit;}
}
if (preg_match('~^/email/(subscription_(?:submitted|approved|rejected|expiring|expired|renewed))$~',$path,$match)) {
    $file=__DIR__.'/http-results/email-previews/'.$match[1].'.html';
    if(is_file($file)){header('Content-Type: text/html; charset=UTF-8');readfile($file);exit;}
}
if(str_starts_with($path,'/profitpocket/assets/')){
    $root=realpath(__DIR__.'/../assets');$file=realpath(__DIR__.'/..'.substr($path,strlen('/profitpocket')));
    $types=['css'=>'text/css','js'=>'application/javascript','jpg'=>'image/jpeg','png'=>'image/png','svg'=>'image/svg+xml'];
    if($file && str_starts_with($file,$root.DIRECTORY_SEPARATOR) && is_file($file) && isset($types[pathinfo($file,PATHINFO_EXTENSION)])){header('Content-Type: '.$types[pathinfo($file,PATHINFO_EXTENSION)]);readfile($file);exit;}
}
http_response_code(404);
