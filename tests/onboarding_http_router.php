<?php
// Dedicated local HTTP test server. Email sending is disabled in its process.
if(PHP_SAPI!=='cli-server' || !in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true)){http_response_code(403);exit;}
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$prefix='/profitpocket/';
if(!str_starts_with($path,$prefix)){http_response_code(404);exit;}
$relative=substr($path,strlen($prefix));$relative=$relative===''?'index.php':$relative;$root=realpath(__DIR__.'/..');$file=realpath($root.'/'.$relative);
if(!$file || !str_starts_with($file,$root.DIRECTORY_SEPARATOR) || !is_file($file)){http_response_code(404);exit;}
if(preg_match('~\A(?:auth|user|admin)/[a-z_]+\.php\z~',$relative) || $relative==='index.php'){
    $_SERVER['SCRIPT_NAME']=$prefix.$relative;$_SERVER['PHP_SELF']=$_SERVER['SCRIPT_NAME'];$_SERVER['SCRIPT_FILENAME']=$file;require $file;exit;
}
if(str_starts_with($relative,'assets/') && $_SERVER['REQUEST_METHOD']==='GET'){
    $types=['css'=>'text/css','js'=>'application/javascript','jpg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','svg'=>'image/svg+xml'];$extension=pathinfo($file,PATHINFO_EXTENSION);
    if(isset($types[$extension])){header('Content-Type: '.$types[$extension]);readfile($file);exit;}
}
http_response_code(403);
