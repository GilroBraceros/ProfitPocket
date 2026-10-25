<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/registration.php';
try{
    $db=db();$user=current_user();$onboarding=$user?null:registration_current($db);
    if(!$user && !$onboarding){http_response_code(403);exit;}
    $admin=($user['role_type']??'')==='admin';
    if($admin){$user=require_role('admin');require_once __DIR__.'/../includes/admin.php';admin_authorize($db,(int)$user['user_id'],'users.manage');}
    $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT);$method=subscription_query($db,'SELECT * FROM subscription_payment_methods WHERE method_id=?',[$id?:0])->fetch();
    if(!$method || empty($method['qr_filename']) || (!$admin && (!$method['is_enabled'] || !subscription_method_supported($method))) || !preg_match('/\A[a-f0-9]{64}\.bin\z/',$method['qr_filename'])){throw new InvalidArgumentException();}
    $root=subscription_qr_directory();$file=realpath($root.'/'.$method['qr_filename']);
    if(!$file || dirname($file)!==$root || !is_file($file)){throw new InvalidArgumentException();}
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file);if(!in_array($mime,['image/jpeg','image/png','image/webp'],true)){throw new InvalidArgumentException();}
    header('Content-Type: '.$mime);header('Content-Length: '.filesize($file));header("Content-Security-Policy: default-src 'none'; sandbox; frame-ancestors 'none'");session_write_close();readfile($file);
}catch(Throwable $e){http_response_code(404);header('Content-Type: text/plain; charset=UTF-8');echo 'Payment QR image unavailable.';}
