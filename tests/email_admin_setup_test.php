<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(403);exit;}
require __DIR__.'/../includes/auth.php';
require __DIR__.'/../includes/email_admin_setup.php';
function setup_email_expect(bool $ok,string $message): void {if (!$ok) {throw new RuntimeException($message);}}
function setup_email_reject(callable $call): void
{
    try {$call();throw new LogicException('Unsafe email setup was accepted.');}
    catch (InvalidArgumentException|AdminDenied $e) {}
}
$environment=[];$serverBefore=$_SERVER;$actor=null;$db=db();
$root=sys_get_temp_dir().'/pp-email-admin-'.bin2hex(random_bytes(8));mkdir($root,0700);$file=$root.'/email.php';
$settings=['BREVO_API_KEY','BREVO_SENDER_EMAIL','BREVO_SENDER_NAME','BREVO_REPLY_TO_EMAIL','BREVO_EMAIL_ENABLED','PROFITPOCKET_BASE_URL','PROFITPOCKET_NOTIFICATION_SECRET','PROFITPOCKET_EMAIL_CONFIG'];
foreach ($settings as $key) {$environment[$key]=getenv($key);putenv($key);}
putenv('PROFITPOCKET_EMAIL_CONFIG='.$file);
$input=['brevo_api_key'=>'fabricated-brevo-key-for-private-tests','sender_email'=>'notify@example.invalid','sender_name'=>'ProfitPocket','reply_to_email'=>'','confirm_email_setup'=>'1'];
$server=['HTTPS'=>'on','SERVER_PORT'=>'443','HTTP_HOST'=>'profitpocket.online'];$calls=0;
$verified=static function()use(&$calls):array {$calls++;return ['http'=>200,'body'=>'{"senders":[{"email":"notify@example.invalid","active":true}]}'];};
try {
    $suffix=bin2hex(random_bytes(7));
    $role=subscription_query($db,"SELECT role_id FROM roles WHERE role_code='platform_admin'")->fetchColumn();
    subscription_query($db,'INSERT INTO users(role_id,full_name,username,email,password_hash) VALUES(?,?,?,?,?)',[$role,'Private email setup fixture','email_setup_'.$suffix,'email_setup_'.$suffix.'@example.invalid',password_hash('Temporary-email-setup-'.$suffix,PASSWORD_DEFAULT)]);$actor=(int)$db->lastInsertId();
    setup_email_expect(email_setup_https($server) && !email_setup_https(['HTTP_X_FORWARDED_PROTO'=>'https','HTTP_HOST'=>'profitpocket.online']) && !email_setup_https(array_replace($server,['HTTP_HOST'=>'another.example'])),'HTTPS/origin validation failed.');
    setup_email_expect(email_setup_base_url('')==='https://profitpocket.online' && email_setup_base_url('/profitpocket')==='https://profitpocket.online/profitpocket','Canonical hosted URL is incorrect.');
    setup_email_reject(fn()=>email_setup_base_url('//another.example'));
    setup_email_reject(fn()=>email_admin_save($db,0,$input,$server,'',$verified));
    setup_email_reject(fn()=>email_admin_save($db,$actor,$input,[],'',$verified));
    setup_email_reject(fn()=>email_admin_save($db,$actor,array_replace($input,['confirm_email_setup'=>'0']),$server,'',$verified));
    setup_email_reject(fn()=>email_admin_save($db,$actor,array_replace($input,['brevo_api_key'=>"fabricated-key\nnot-valid"]),$server,'',$verified));
    setup_email_reject(fn()=>email_admin_save($db,$actor,$input,$server,'',fn()=>['http'=>401,'body'=>'{}']));
    setup_email_expect(!is_file($file),'Rejected sender/settings created a private configuration.');
    email_admin_save($db,$actor,$input,$server,'/profitpocket',$verified);
    $saved=email_config();$secret=$saved['PROFITPOCKET_NOTIFICATION_SECRET'];
    setup_email_expect($saved['BREVO_EMAIL_ENABLED']===true && $saved['BREVO_API_KEY']===$input['brevo_api_key'] && preg_match('/\A[a-f0-9]{64}\z/',$secret) && $saved['PROFITPOCKET_BASE_URL']==='https://profitpocket.online/profitpocket','Verified private settings were not saved.');
    $saved['UNRELATED_FIXTURE_SETTING']='preserve-me';file_put_contents($file,"<?php return ".var_export($saved,true).";\n");
    email_admin_save($db,$actor,array_replace($input,['brevo_api_key'=>'']),$server,'/profitpocket',$verified);
    $values=require $file;
    setup_email_expect($values['PROFITPOCKET_NOTIFICATION_SECRET']===$secret && $values['BREVO_API_KEY']===$input['brevo_api_key'] && $values['UNRELATED_FIXTURE_SETTING']==='preserve-me','Saving rotated an existing secret/key or removed unrelated settings.');
    $hash=hash_file('sha256',$file);putenv('BREVO_EMAIL_ENABLED=false');
    setup_email_reject(fn()=>email_admin_save($db,$actor,$input,$server,'/profitpocket',$verified));putenv('BREVO_EMAIL_ENABLED');
    setup_email_expect(hash_file('sha256',$file)===$hash,'Environment override failure changed private settings.');
    $user=['user_id'=>$actor];$permissions=['users.manage','settings.manage'];$emailCheck=email_diagnostic_status($db,email_config());$_SERVER=array_replace($_SERVER,$server);
    ob_start();require __DIR__.'/../includes/email_admin_setup_form.php';$html=ob_get_clean();
    setup_email_expect(!str_contains($html,$input['brevo_api_key']) && !str_contains($html,$secret) && str_contains($html,'type="password"') && str_contains($html,'Send one hosted test email'),'Setup view exposed secrets or omitted the controlled test action.');
    $public=__DIR__.'/must-not-write-email-'.$suffix.'.php';putenv('PROFITPOCKET_EMAIL_CONFIG='.$public);
    setup_email_reject(fn()=>email_admin_save($db,$actor,$input,$server,'',$verified));
    setup_email_expect(!file_exists($public),'Setup wrote credentials into the public application.');
    echo "PASS: administrator permissions, HTTPS/origin, explicit authorization, provider rejection, verified private activation, initial secret generation, existing secret/key/unknown-setting preservation, environment precedence, secret-free rendering and public-path rejection; only fabricated keys and mocked provider checks used.\n";
} finally {
    $_SERVER=$serverBefore;
    foreach ($environment as $key=>$value) {putenv($value===false?$key:$key.'='.$value);}
    if ($actor) {$db->beginTransaction();subscription_query($db,'DELETE FROM activity_logs WHERE actor_user_id=?',[$actor]);subscription_query($db,'DELETE FROM users WHERE user_id=?',[$actor]);$db->commit();}
    foreach (glob($root.'/*')?:[] as $temporary) {unlink($temporary);}rmdir($root);
}
