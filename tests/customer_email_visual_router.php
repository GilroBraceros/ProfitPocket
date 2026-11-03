<?php
// Loopback test snapshots only. Never invokes the email transport or changes DB.
if (PHP_SAPI!=='cli-server' || !in_array($_SERVER['REMOTE_ADDR'],['127.0.0.1','::1'],true)) {http_response_code(403);exit;}
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$results=__DIR__.'/http-results/browser-customer-email';
if (!str_starts_with($path,'/email/')) {header("Content-Security-Policy: default-src 'self'; style-src 'self' https://cdn.jsdelivr.net; script-src 'self' https://cdn.jsdelivr.net; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");}
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if ($path!=='/profitpocket/user/sale_email.php' || ($_POST['intent']??'')!=='preview') {usleep(500000);http_response_code(403);header('Content-Type: application/json');echo json_encode(['success'=>false,'message'=>'Read-only visual test: no email sent.']);exit;}
    require __DIR__.'/../includes/email_templates.php';$data=json_decode(file_get_contents($results.'/preview-data.json'),true);$data['owner_message']=is_string($_POST['owner_message']??null)?substr($_POST['owner_message'],0,500):'';
    $config=['PROFITPOCKET_BASE_URL'=>'https://profitpocket.example.test','BREVO_REPLY_TO_EMAIL'=>'support@example.invalid','BREVO_SENDER_EMAIL'=>'notify@example.invalid'];
    $html=email_browser_preview(email_render('manual_reminder',$data,$config)['html'],'/profitpocket');
    header('Content-Type: application/json');echo json_encode(['success'=>true,'html'=>$html,'message'=>'Preview updated.']);exit;
}
if ($path==='/reminder') {$file=$results.'/reminder-page.html';}
elseif (preg_match('~^/email/(purchase_paid|purchase_unpaid|purchase_installment|manual_reminder)$~',$path,$match)) {$file=$results.'/'.$match[1].'.html';}
if (isset($file) && is_file($file)) {header('Content-Type: text/html; charset=UTF-8');echo str_replace('https://profitpocket.example.test/assets/branding.php','/profitpocket/assets/branding.php',file_get_contents($file));exit;}
if ($path==='/profitpocket/assets/branding.php') {require __DIR__.'/../assets/branding.php';exit;}
if (str_starts_with($path,'/profitpocket/assets/')) {
    $root=realpath(__DIR__.'/../assets');$file=realpath(__DIR__.'/..'.substr($path,strlen('/profitpocket')));$types=['css'=>'text/css','js'=>'application/javascript'];
    if ($file && str_starts_with($file,$root.DIRECTORY_SEPARATOR) && is_file($file) && isset($types[pathinfo($file,PATHINFO_EXTENSION)])) {header('Content-Type: '.$types[pathinfo($file,PATHINFO_EXTENSION)]);readfile($file);exit;}
}
http_response_code(404);
