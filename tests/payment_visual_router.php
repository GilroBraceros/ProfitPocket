<?php
// Test-only loopback server for templates rendered from rolled-back DB fixtures.
// It never connects to the database and cannot accept application mutations.
if (PHP_SAPI!=='cli-server' || $_SERVER['REQUEST_METHOD']!=='GET') { http_response_code(403); exit; }
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$fixtures=['/history'=>'payments-history-fixture.html','/details'=>'payments-details-fixture.html','/conversation'=>'payments-history-modal-fixture.html'];
if (isset($fixtures[$path])) {
    header('Content-Type: text/html; charset=UTF-8'); header('Cache-Control: no-store');
    readfile(__DIR__.'/http-results/'.$fixtures[$path]); exit;
}
if (str_starts_with($path,'/profitpocket/assets/')) {
    $base=realpath(__DIR__.'/../assets'); $file=realpath(__DIR__.'/..'.substr($path,strlen('/profitpocket')));
    $types=['css'=>'text/css','js'=>'application/javascript','jpg'=>'image/jpeg','png'=>'image/png','svg'=>'image/svg+xml'];
    if ($file && str_starts_with($file,$base.DIRECTORY_SEPARATOR) && is_file($file) && isset($types[pathinfo($file,PATHINFO_EXTENSION)])) { header('Content-Type: '.$types[pathinfo($file,PATHINFO_EXTENSION)]); readfile($file); exit; }
}
http_response_code(404);
