<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(403); exit; }
require __DIR__.'/../includes/deployment.php'; require __DIR__.'/../includes/passwords.php'; require __DIR__.'/../includes/email_diagnostics.php';
function sub_expect(bool $ok,string $message): void { if (!$ok) { throw new RuntimeException($message); } }
function sub_reject(PDO $db,callable $call,string $message): void {
    $db->exec('SAVEPOINT subscription_reject');
    try { $call(); throw new LogicException($message); }
    catch (InvalidArgumentException|AdminDenied|RuntimeException $e) { if ($e instanceof PDOException) {throw $e;} $db->exec('ROLLBACK TO SAVEPOINT subscription_reject'); }
}
function sub_counts(PDO $db): array { $r=[]; foreach (['users','businesses','owner_subscriptions','subscription_payment_methods','subscription_payments','notification_outbox','admin_action_logs','deployment_runs','auth_sessions'] as $table) { $r[$table]=$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn(); } return $r; }
$config=['BREVO_EMAIL_ENABLED'=>true,'BREVO_API_KEY'=>'fabricated-subscription-test-only','BREVO_SENDER_EMAIL'=>'notifications@example.invalid','BREVO_SENDER_NAME'=>'ProfitPocket','BREVO_REPLY_TO_EMAIL'=>'support@example.invalid','PROFITPOCKET_BASE_URL'=>'https://profitpocket.example.test','PROFITPOCKET_NOTIFICATION_SECRET'=>str_repeat('a',64),'PP_SUBSCRIPTIONS_ENABLED'=>true];
$restore=[]; foreach ($config as $key=>$value) { $restore[$key]=getenv($key); putenv($key.'='.(is_bool($value)?($value?'true':'false'):$value)); }
$db=db(); $before=sub_counts($db); $temporary=tempnam(sys_get_temp_dir(),'pp-proof-'); $failed=false;
$mock=fn()=>['http'=>201,'body'=>'{"messageId":"<subscription-test@example.invalid>"}'];
$stage='initial validation';
try {
    $zone=new DateTimeZone('Asia/Manila');
    sub_expect(subscription_month_end(new DateTimeImmutable('2026-01-31 14:45:12',$zone),31)->format('Y-m-d H:i:s')==='2026-02-28 14:45:12','January month-end overflow');
    sub_expect(subscription_month_end(new DateTimeImmutable('2028-01-31 14:45:12',$zone),31)->format('Y-m-d H:i:s')==='2028-02-29 14:45:12','Leap-year renewal');
    sub_expect(subscription_month_end(new DateTimeImmutable('2026-02-28 14:45:12',$zone),31)->format('Y-m-d')==='2026-03-31','Billing anchor was lost');
    sub_expect(brevo_sender_status($config,fn()=>['http'=>200,'body'=>'{"senders":[{"email":"notifications@example.invalid","active":true}]}'])==='active','Sender diagnostic');
    sub_expect(brevo_sender_status($config,fn()=>['http'=>401,'body'=>'{}'])==='credentials_rejected','Safe rejection diagnostic');
    file_put_contents($temporary,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Z6ZAAAAAASUVORK5CYII='));
    sub_expect(subscription_validate_proof($temporary,(int)filesize($temporary))['mime']==='image/png','Actual image proof validation');
    file_put_contents($temporary,'%PDF-1.4' . "\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n");
    sub_expect(subscription_validate_proof($temporary,(int)filesize($temporary))['mime']==='application/pdf','Actual PDF MIME admission');
    file_put_contents($temporary,'<?php echo "not an image";');
    try { subscription_validate_proof($temporary,(int)filesize($temporary)); throw new LogicException('Executable proof accepted'); } catch (InvalidArgumentException $e) {}
    echo "PASS: calendar-month clamping/anchor/leap year, MIME validation and safe sender diagnostics.\n";
    $db->beginTransaction(); $suffix=bin2hex(random_bytes(7));
    $role=(int)subscription_query($db,"SELECT role_id FROM roles WHERE role_code='platform_admin'")->fetchColumn();
    subscription_query($db,'INSERT INTO users (role_id,full_name,username,email,password_hash) VALUES (?,?,?,?,?)',[$role,'Subscription test admin','sub_admin_'.$suffix,'sub_admin_'.$suffix.'@example.invalid',password_hash('Temporary-admin-test-123!',PASSWORD_DEFAULT)]); $admin=(int)$db->lastInsertId();
    $owner=create_owner($db,['full_name'=>'Subscription test owner','username'=>'sub_owner_'.$suffix,'email'=>'sub_owner_'.$suffix.'@example.invalid','business_name'=>'Temporary subscription store','business_category_id'=>(string)$db->query('SELECT business_category_id FROM business_categories LIMIT 1')->fetchColumn()],'Temporary-owner-test-123!');
    $ownerUser=['user_id'=>$owner,'role_type'=>'owner'];
    sub_expect(subscription_state($db,$owner)['state']==='pending_payment' && !subscription_has_access($db,$ownerUser),'New owner granted paid access');
    sub_expect(home_path($ownerUser)==='user/subscription.php' && subscription_has_access($db,['user_id'=>$admin,'role_type'=>'admin']),'Role/subscription routing');
    subscription_save_method($db,$admin,0,['method_name'=>'Test wallet','recipient_name'=>'Fictional test recipient','account_identifier'=>'TEST-NOT-A-REAL-ACCOUNT-'.$suffix,'instructions'=>'Fictional payment instructions for rollback tests only.','is_enabled'=>'1']);
    $method=(int)$db->query('SELECT MAX(method_id) FROM subscription_payment_methods')->fetchColumn();
    $proof=fn(int $n)=>['name'=>hash('sha256','proof-name-'.$suffix.$n).'.bin','hash'=>hash('sha256','proof-content-'.$suffix.$n),'mime'=>'image/png','bytes'=>100];
    $input=['method_id'=>(string)$method,'reference_number'=>'TEST-'.$suffix]; $key=bin2hex(random_bytes(32));
    $first=subscription_submit($db,$owner,$input,$proof(1),$key); $id=$first['id'];
    $stage='first submission and rejection';
    sub_expect(subscription_state($db,$owner)['state']==='pending_verification' && !subscription_has_access($db,$ownerUser),'Proof submission activated access');
    sub_expect(subscription_submit($db,$owner,$input,$proof(1),$key)['id']===$id,'Submission replay duplicated');
    sub_reject($db,fn()=>subscription_submit($db,$owner,$input,$proof(2),bin2hex(random_bytes(32))),'Second pending submission accepted');
    sub_reject($db,fn()=>subscription_review($db,$owner,$id,'approved','',true),'Owner approved own payment');
    sub_reject($db,fn()=>subscription_review($db,$admin,$id,'approved','',false),'Approval without receipt verification');
    $notice=subscription_query($db,"SELECT * FROM notification_outbox WHERE subscription_payment_id=? AND notification_type='subscription_submitted'",[$id])->fetch();
    sub_expect(notification_attempt($db,$notice,$config,$mock)==='accepted','Submitted email missing');
    subscription_review($db,$admin,$id,'rejected','Please provide the matching receipt.',false);
    sub_expect(subscription_state($db,$owner)['state']==='rejected' && !subscription_has_access($db,$ownerUser),'Rejection granted access');
    $notice=subscription_query($db,"SELECT * FROM notification_outbox WHERE subscription_payment_id=? AND notification_type='subscription_rejected'",[$id])->fetch(); sub_expect(notification_attempt($db,$notice,$config,$mock)==='accepted','Rejection email missing');
    // Corrected proof may reuse the rejected payment's reference, but not its file.
    $stage='corrected proof and approval';
    $second=subscription_submit($db,$owner,$input,$proof(2),bin2hex(random_bytes(32))); $id=$second['id'];
    $now=new DateTimeImmutable('now',$zone); subscription_review($db,$admin,$id,'approved','Verified test receipt',true,$now);
    $state=subscription_state($db,$owner,$now); $firstEnd=$state['expires_at'];
    sub_expect($state['active'] && subscription_has_access($db,$ownerUser),'Approved owner remains restricted');
    sub_expect(subscription_review($db,$admin,$id,'approved','Verified test receipt',true,$now)['replayed'],'Repeated approval extended subscription twice');
    sub_expect(subscription_state($db,$owner,$now)['expires_at']===$firstEnd,'Approval replay changed period');
    sub_reject($db,fn()=>subscription_review($db,$admin,$id,'rejected','Changed decision',false),'Conflicting review accepted');
    $notice=subscription_query($db,"SELECT * FROM notification_outbox WHERE subscription_payment_id=? AND notification_type='subscription_approved'",[$id])->fetch(); sub_expect(notification_attempt($db,$notice,$config,$mock)==='accepted','Activation email missing');
    sub_reject($db,fn()=>subscription_submit($db,$owner,$input,$proof(3),bin2hex(random_bytes(32))),'Verified reference reused');
    sub_reject($db,fn()=>subscription_submit($db,$owner,['method_id'=>$method],$proof(2),bin2hex(random_bytes(32))),'Same proof reused');
    $renewal=subscription_submit($db,$owner,['method_id'=>$method,'reference_number'=>'RENEW-'.$suffix],$proof(3),bin2hex(random_bytes(32)));
    $stage='early renewal';
    subscription_review($db,$admin,$renewal['id'],'approved','Early renewal verified',true,$now->modify('+1 day'));
    $state=subscription_state($db,$owner,$now);
    sub_expect($state['expires_at']===subscription_month_end(new DateTimeImmutable($firstEnd,$zone),(int)$now->format('j'))->format('Y-m-d H:i:s'),'Early renewal discarded paid time');
    $notice=subscription_query($db,"SELECT * FROM notification_outbox WHERE subscription_payment_id=? AND notification_type='subscription_renewed'",[$renewal['id']])->fetch(); sub_expect(notification_attempt($db,$notice,$config,$mock)==='accepted','Renewal email missing');
    $pending=subscription_submit($db,$owner,['method_id'=>$method],$proof(4),bin2hex(random_bytes(32))); $preserved=$state['expires_at']; subscription_review($db,$admin,$pending['id'],'rejected','Renewal receipt did not match.',false);
    $stage='expired renewal';
    sub_expect(subscription_state($db,$owner,$now)['expires_at']===$preserved && subscription_has_access($db,$ownerUser),'Rejected renewal revoked valid paid time');
    subscription_query($db,'UPDATE owner_subscriptions SET started_at=?,expires_at=?,billing_anchor=? WHERE user_id=?',[$now->modify('-1 month')->format('Y-m-d H:i:s'),$now->modify('-1 second')->format('Y-m-d H:i:s'),(int)$now->format('j'),$owner]);
    sub_expect(!subscription_has_access($db,$ownerUser),'Expired owner retained paid access');
    $late=subscription_submit($db,$owner,['method_id'=>$method],$proof(5),bin2hex(random_bytes(32))); subscription_review($db,$admin,$late['id'],'approved','Late renewal verified',true,$now);
    sub_expect(subscription_state($db,$owner,$now)['started_at']===$now->format('Y-m-d H:i:s'),'Expired renewal did not restart on approval');
    $end=$now->modify('+3 days')->format('Y-m-d H:i:s'); subscription_query($db,'UPDATE owner_subscriptions SET expires_at=? WHERE user_id=?',[$end,$owner]);
    subscription_generate_reminders($db,$now); subscription_generate_reminders($db,$now);
    $notices=subscription_query($db,"SELECT * FROM notification_outbox WHERE user_id=? AND notification_type='subscription_expiring'",[$owner])->fetchAll(); sub_expect(count($notices)===1 && notification_attempt($db,$notices[0],$config,$mock)==='accepted','Expiring reminder duplication');
    subscription_query($db,'UPDATE owner_subscriptions SET started_at=?,expires_at=? WHERE user_id=?',[$now->modify('-1 month')->format('Y-m-d H:i:s'),$now->modify('-1 second')->format('Y-m-d H:i:s'),$owner]);
    subscription_generate_reminders($db,$now); subscription_generate_reminders($db,$now);
    $notices=subscription_query($db,"SELECT * FROM notification_outbox WHERE user_id=? AND notification_type='subscription_expired'",[$owner])->fetchAll(); sub_expect(count($notices)===1 && notification_attempt($db,$notices[0],$config,$mock)==='accepted','Expired reminder duplication');
    echo "PASS: pending access, exact ₱100, proof/reference/request deduplication, receipt-confirmed approval, rejection/resubmission, renewal/expiry, admin exemption and all six subscription email triggers.\n";
    $bootstrap=upgrade_config(); $bootstrap['PP_BOOTSTRAP_AUTHORIZED']=true; $bootstrap['PP_BOOTSTRAP_TOKEN']=str_repeat('b',64);
    foreach ([1,2] as $n) { $bootstrap['PP_BOOTSTRAP_ADMIN'.$n.'_EMAIL']='bootstrap_'.$n.'_'.$suffix.'@example.invalid'; $bootstrap['PP_BOOTSTRAP_ADMIN'.$n.'_PASSWORD']='Unique-initial-test-'.$n.'-'.$suffix; $bootstrap['PP_BOOTSTRAP_ADMIN'.$n.'_NAME']='Temporary bootstrap administrator '.$n; }
    $bad=$bootstrap; $bad['PP_BOOTSTRAP_ADMIN2_EMAIL']=$bad['PP_BOOTSTRAP_ADMIN1_EMAIL']; sub_reject($db,fn()=>deployment_bootstrap($db,$bad),'Duplicate bootstrap identities accepted');
    $bad=$bootstrap; $bad['PP_BOOTSTRAP_ADMIN2_EMAIL']='sub_owner_'.$suffix.'@example.invalid'; sub_reject($db,fn()=>deployment_bootstrap($db,$bad),'Owner promoted by bootstrap');
    sub_expect(deployment_bootstrap($db,$bootstrap)==='completed','Bootstrap did not complete');
    foreach ([1,2] as $n) {
        $u=find_login_user($db,$bootstrap['PP_BOOTSTRAP_ADMIN'.$n.'_EMAIL']); sub_expect($u['role_type']==='admin' && password_matches($u,$bootstrap['PP_BOOTSTRAP_ADMIN'.$n.'_PASSWORD']) && bootstrap_needs_password_change($db,(int)$u['user_id']),'Bootstrap account sign-in/first-password rule');
        sub_expect(home_path($u)==='auth/change_password.php','Initial password did not gate admin routing');
        create_login_session($db,$u,$bootstrap['PP_BOOTSTRAP_ADMIN'.$n.'_PASSWORD']);
    }
    $u=find_login_user($db,$bootstrap['PP_BOOTSTRAP_ADMIN1_EMAIL']); $oldHash=$u['password_hash'];
    sub_expect(deployment_bootstrap($db,$bootstrap)==='already_completed' && find_login_user($db,$bootstrap['PP_BOOTSTRAP_ADMIN1_EMAIL'])['password_hash']===$oldHash,'Bootstrap reset existing password');
    password_authenticated_change($db,(int)$u['user_id'],$bootstrap['PP_BOOTSTRAP_ADMIN1_PASSWORD'],'New-private-password-test-123!','New-private-password-test-123!',bin2hex(random_bytes(32)));
    sub_expect(!bootstrap_needs_password_change($db,(int)$u['user_id']),'First password change marker retained');
    $db->rollBack(); sub_expect(sub_counts($db)===$before,'Test rows retained');
    echo "PASS: authorized two-admin bootstrap, hashed credentials, first-login password change, real session creation, no owner promotion and idempotent reruns without password resets; all database fixtures rolled back.\n";
} catch (Throwable $e) { if ($db->inTransaction()) {$db->rollBack();} fwrite(STDERR,'FAIL ['.$stage.']: '.$e->getMessage().PHP_EOL); $failed=true; }
finally { if (is_file($temporary)) {unlink($temporary);} foreach($restore as $key=>$value){putenv($value===false?$key:$key.'='.$value);} }
if ($failed) {exit(1);}
