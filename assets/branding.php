<?php
declare(strict_types=1);
// Public original logos only. No application configuration or user paths load here.
$variant=$_GET['logo']??'icon';
if (!is_string($variant) || !in_array($variant,['full','icon'],true)) {http_response_code(404);exit;}
$name='logo-'.$variant.'.jpg';$root=dirname(__DIR__);
foreach ([__DIR__.'/images/'.$name,$root.'/profitpocket/assets/images/'.$name] as $candidate) {
    if (is_link($candidate) || !is_file($candidate) || !is_readable($candidate)) {continue;}
    $resolved=realpath($candidate);$directory=realpath(dirname($candidate));
    if (!$resolved || !$directory || dirname($resolved)!==$directory) {continue;}
    if (strtolower(str_replace('\\','/',$resolved))!==strtolower(str_replace('\\','/',$candidate))) {continue;}
    $size=@getimagesize($resolved);
    if (!$size || $size[2]!==IMAGETYPE_JPEG) {continue;}
    $etag='"'.hash_file('sha256',$resolved).'"';
    header('Content-Type: image/jpeg');header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=86400');header('ETag: '.$etag);
    if (($_SERVER['HTTP_IF_NONE_MATCH']??'')===$etag) {http_response_code(304);exit;}
    header('Content-Length: '.filesize($resolved));readfile($resolved);exit;
}
http_response_code(404);
