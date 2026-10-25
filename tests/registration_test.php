<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require __DIR__.'/../includes/auth.php';require __DIR__.'/../includes/registration.php';require __DIR__.'/../includes/admin.php';require __DIR__.'/../includes/notifications.php';
function reg_expect(bool $ok,string $message):void{if(!$ok){throw new RuntimeException($message);}}
function reg_reject(PDO $db,callable $fn,string $message):void{$db->exec('SAVEPOINT rejected_registration');try{$fn();throw new LogicException($message);}catch(InvalidArgumentException|AdminDenied $e){$db->exec('ROLLBACK TO SAVEPOINT rejected_registration');}}
$config=['BREVO_EMAIL_ENABLED'=>true,'BREVO_API_KEY'=>'fabricated-registration-test-only','BREVO_SENDER_EMAIL'=>'notify@example.invalid','BREVO_SENDER_NAME'=>'ProfitPocket','BREVO_REPLY_TO_EMAIL'=>'support@example.invalid','PROFITPOCKET_BASE_URL'=>'https://profitpocket.example.test','PROFITPOCKET_NOTIFICATION_SECRET'=>str_repeat('a',64),'PP_SUBSCRIPTIONS_ENABLED'=>true];
$restore=[];foreach($config as$key=>$value){$restore[$key]=getenv($key);putenv($key.'='.(is_bool($value)?($value?'true':'false'):$value));}
$root=sys_get_temp_dir().'/pp-registration-'.bin2hex(random_bytes(8));mkdir($root,0700);$restore['PP_SUBSCRIPTION_STORAGE']=getenv('PP_SUBSCRIPTION_STORAGE');putenv('PP_SUBSCRIPTION_STORAGE='.$root);$db=db();$baseline=(int)$db->query('SELECT COUNT(*) FROM registration_onboarding')->fetchColumn();$failed=false;
try{
    reg_expect(registration_payment_schema($db),'Approved registration migration missing');$db->beginTransaction();$suffix=bin2hex(random_bytes(7));$roles=array_column($db->query('SELECT role_id,role_code FROM roles')->fetchAll(),'role_id','role_code');$admins=[];
    foreach(['admin'=>'platform_admin','revoked'=>'platform_admin','support'=>'support_admin']as$name=>$role){subscription_query($db,'INSERT INTO users (role_id,full_name,username,email,password_hash) VALUES (?,?,?,?,?)',[$roles[$role],'Registration test '.$name,'reg_'.$name.'_'.$suffix,'reg_'.$name.'_'.$suffix.'@example.invalid',password_hash('Temporary-registration-test-123!',PASSWORD_DEFAULT)]);$admins[$name]=(int)$db->lastInsertId();}
    $token=bin2hex(random_bytes(32));$request=bin2hex(random_bytes(32));$values=['full_name'=>'Registration owner <test>','username'=>'reg_owner_'.$suffix,'email'=>'reg_owner_'.$suffix.'@example.invalid','business_name'=>'Registration test shop','business_category_id'=>(string)$db->query('SELECT business_category_id FROM business_categories LIMIT 1')->fetchColumn()];
    $owner=registration_create($db,$values,'Temporary-registration-owner-123!',$request,$token);reg_expect(registration_create($db,$values,'Temporary-registration-owner-123!',$request,$token)===$owner,'Registration replay duplicated account');
    reg_expect((int)subscription_query($db,"SELECT COUNT(*) FROM notification_outbox WHERE user_id=? AND notification_type='welcome'",[$owner])->fetchColumn()===0,'Welcome sent before initial payment');
    $_SESSION['onboarding_token']=$token;$current=registration_current($db);reg_expect((int)$current['user_id']===$owner && $current['token_hash']===hash('sha256',$token) && !isset($_SESSION['auth_token']),'Onboarding token storage or authentication boundary');
    reg_expect(!subscription_has_access($db,['user_id'=>$owner,'role_type'=>'owner']),'Unpaid registration granted paid access');
    $image=$root.'/sample';file_put_contents($image,base64_decode('UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEADsD+JaQAA3AAAAAA'));$qr=subscription_validate_qr($image,(int)filesize($image));reg_expect($qr['mime']==='image/webp','WebP QR validation');copy($image,subscription_qr_directory(true).'/'.$qr['name']);
    file_put_contents($image,'<?php echo "not an image";');clearstatcache(true);
    reg_reject($db,fn()=>subscription_validate_qr($image,(int)filesize($image)),'Executable QR upload accepted');
    $methodInput=['method_category'=>'gcash','recipient_name'=>'Fictional receiver','account_identifier'=>'TEST-'.$suffix,'instructions'=>'Test-only instructions','is_enabled'=>'1'];$method=subscription_save_method($db,$admins['admin'],0,$methodInput,$qr)['id'];
    reg_reject($db,fn()=>subscription_save_method($db,$admins['admin'],0,$methodInput),'Duplicate receiving account allowed');
    reg_reject($db,fn()=>subscription_save_method($db,$admins['admin'],0,array_replace($methodInput,['method_category'=>'other','custom_method_name'=>'GCash'])),'Duplicate account disguised as Other allowed');
    reg_reject($db,fn()=>subscription_save_method($db,$admins['admin'],0,array_replace($methodInput,['method_category'=>'bank'])),'Bank without name allowed');
    reg_reject($db,fn()=>subscription_save_method($db,$admins['admin'],0,array_replace($methodInput,['method_category'=>'other','custom_method_name'=>'Maya'])),'Excluded provider allowed');
    reg_reject($db,fn()=>subscription_save_method($db,$owner,0,$methodInput),'Owner modified receiving accounts');
    $proof=['name'=>hash('sha256','registration-proof'.$suffix).'.bin','hash'=>hash('sha256','registration-content'.$suffix),'mime'=>'image/png','bytes'=>100];$key=bin2hex(random_bytes(32));
    reg_reject($db,fn()=>registration_submit($db,str_repeat('f',64),['method_id'=>$method],$proof,$key),'Unbound registration token accepted');
    $saved=registration_submit($db,$token,['method_id'=>$method,'user_id'=>$admins['admin'],'amount'=>'0.01'],$proof,$key);$payment=$saved['id'];
    reg_expect(registration_submit($db,$token,['method_id'=>$method],$proof,$key)['replayed'],'Initial proof replay duplicated');
    $row=subscription_query($db,'SELECT user_id,amount,method_snapshot FROM subscription_payments WHERE submission_id=?',[$payment])->fetch();reg_expect((int)$row['user_id']===$owner && $row['amount']==='100.00' && str_contains($row['method_snapshot'],$qr['name']),'Payment binding, amount or QR snapshot changed');
    reg_expect((int)subscription_query($db,"SELECT COUNT(*) FROM activity_logs WHERE actor_user_id=? AND action_code='SUBSCRIPTION_PROOF_SUBMITTED'",[$owner])->fetchColumn()===1,'Proof submission audit missing or duplicated');
    $notice=subscription_query($db,"SELECT * FROM notification_outbox WHERE subscription_payment_id=? AND notification_type='registration_payment_submitted'",[$payment])->fetch();
    reg_expect((bool)$notice && (int)subscription_query($db,"SELECT COUNT(*) FROM notification_outbox WHERE subscription_payment_id=? AND notification_type IN ('welcome','subscription_submitted')",[$payment])->fetchColumn()===0,'Duplicate initial owner notifications');
    $mock=fn()=>['http'=>201,'body'=>'{"messageId":"<registration-test@example.invalid>"}'];reg_expect(notification_attempt($db,$notice,$config,$mock)==='accepted','Combined welcome dispatch');
    $adminNotice=subscription_query($db,"SELECT * FROM notification_outbox WHERE subscription_payment_id=? AND user_id=? AND notification_type='subscription_admin_review'",[$payment,$admins['admin']])->fetch();reg_expect(notification_attempt($db,$adminNotice,$config,$mock)==='accepted','Authorized admin review email');
    reg_expect((int)subscription_query($db,"SELECT COUNT(*) FROM notification_outbox WHERE subscription_payment_id=? AND user_id=?",[$payment,$admins['support']])->fetchColumn()===0,'Support-only admin received financial notice');
    $revoked=subscription_query($db,"SELECT * FROM notification_outbox WHERE subscription_payment_id=? AND user_id=?",[$payment,$admins['revoked']])->fetch();subscription_query($db,'UPDATE users SET role_id=? WHERE user_id=?',[$roles['support_admin'],$admins['revoked']]);reg_expect(notification_attempt($db,$revoked,$config,$mock)==='skipped','Revoked review permission still emailed');
    subscription_review($db,$admins['admin'],$payment,'rejected','Provide corrected proof.',false);
    $corrected=$proof;$corrected['name']=hash('sha256','corrected-name-'.$suffix).'.bin';$corrected['hash']=hash('sha256','corrected-proof-'.$suffix);
    $payment=subscription_submit($db,$owner,['method_id'=>$method],$corrected,bin2hex(random_bytes(32)))['id'];
    reg_expect((int)subscription_query($db,"SELECT COUNT(*) FROM notification_outbox WHERE user_id=? AND notification_type='registration_payment_submitted'",[$owner])->fetchColumn()===1,'Corrected proof repeated welcome');
    reg_expect((bool)subscription_query($db,"SELECT 1 FROM notification_outbox WHERE subscription_payment_id=? AND user_id=? AND notification_type='subscription_admin_review'",[$payment,$admins['admin']])->fetchColumn(),'Corrected initial proof did not notify reviewer');
    subscription_save_method($db,$admins['admin'],$method,$methodInput+['remove_qr'=>'1']);subscription_cleanup_qr($db,$qr['name']);reg_expect(is_file(subscription_qr_directory().'/'.$qr['name']),'Historical QR snapshot file deleted');
    reg_reject($db,fn()=>subscription_method_action($db,$admins['admin'],$method,'remove'),'Used method deleted');subscription_method_action($db,$admins['admin'],$method,'deactivate');reg_expect(!in_array($method,array_column(subscription_methods($db),'method_id')),'Deactivated method offered');
    subscription_review($db,$admins['admin'],$payment,'approved','Verified fictional test',true);reg_expect(subscription_state($db,$owner)['active'],'Approval failed');
    $db->rollBack();reg_expect((int)$db->query('SELECT COUNT(*) FROM registration_onboarding')->fetchColumn()===$baseline,'Registration fixtures retained');
    echo "PASS: hashed session-only onboarding, registration/proof replay, scoped ₱100 payment, deferred welcome, authorized admin notices, permission revocation, QR validation/history and method lifecycle; all database fixtures rolled back.\n";
}catch(Throwable $e){if($db->inTransaction()){$db->rollBack();}fwrite(STDERR,'FAIL: '.$e->getMessage().PHP_EOL);$failed=true;}
finally{foreach(glob($root.'/payment-qr/*.bin')?:[]as$file){unlink($file);}if(is_dir($root.'/payment-qr')){rmdir($root.'/payment-qr');}if(is_file($root.'/sample')){unlink($root.'/sample');}rmdir($root);foreach($restore as$key=>$value){putenv($value===false?$key:$key.'='.$value);}}
if($failed){exit(1);}
