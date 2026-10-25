<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/subscriptions.php';
$user=current_user(); if (!$user) { redirect_to('auth/login.php'); }
$user=require_role($user['role_type']==='admin'?'admin':'owner');
try {
    $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT); if (!$id || $id<1) { throw new InvalidArgumentException('Proof not found.'); }
    $file=subscription_proof(db(),$user,(int)$id); $extension=['image/jpeg'=>'jpg','image/png'=>'png','application/pdf'=>'pdf'][$file['proof_mime']];
    header('Content-Type: '.$file['proof_mime']); header('Content-Length: '.filesize($file['path']));
    header('Content-Disposition: attachment; filename="subscription-proof-'.$id.'.'.$extension.'"');
    header("Content-Security-Policy: default-src 'none'; sandbox; frame-ancestors 'none'"); header('Referrer-Policy: no-referrer');
    session_write_close(); readfile($file['path']);
} catch (InvalidArgumentException $e) { http_response_code(404); header('Content-Type: text/plain; charset=UTF-8'); echo 'Payment proof not found.'; }
catch (Throwable $e) { http_response_code($e instanceof AdminDenied?403:503); header('Content-Type: text/plain; charset=UTF-8'); echo 'Payment proof is unavailable.'; }
