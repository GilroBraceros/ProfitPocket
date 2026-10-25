<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(403); exit; }
require __DIR__.'/../includes/passwords.php'; require __DIR__.'/../includes/payments.php';
function email_expect(bool $value,string $message): void { if (!$value) { throw new RuntimeException($message); } }
function email_job(PDO $db,int $id): array { $s=$db->prepare('SELECT * FROM notification_outbox WHERE notification_id=?'); $s->execute([$id]); return $s->fetch(); }
$config=['BREVO_EMAIL_ENABLED'=>true,'BREVO_API_KEY'=>'fabricated-test-credential-only','BREVO_SENDER_EMAIL'=>'notify@example.invalid','BREVO_SENDER_NAME'=>'ProfitPocket','BREVO_REPLY_TO_EMAIL'=>'support@example.invalid','PROFITPOCKET_BASE_URL'=>'https://profitpocket.example.test','PROFITPOCKET_NOTIFICATION_SECRET'=>str_repeat('a',64)];
$restore=[]; foreach ($config as $key=>$value) { $restore[$key]=getenv($key); putenv($key.'='.(is_bool($value)?($value?'true':'false'):$value)); }
$restore['PP_SUBSCRIPTIONS_ENABLED']=getenv('PP_SUBSCRIPTIONS_ENABLED'); putenv('PP_SUBSCRIPTIONS_ENABLED=false');
$db=db(); $baseline=(int)$db->query('SELECT COUNT(*) FROM notification_outbox')->fetchColumn();
$mock=static function(array $payload): array { email_expect(!isset($payload['templateId']) && !isset($payload['api-key']),'Forbidden template/credential in payload'); email_expect(isset($payload['htmlContent'],$payload['textContent'],$payload['headers']['idempotencyKey']),'Incomplete API payload'); return ['http'=>201,'body'=>'{"messageId":"<fabricated-acceptance@example.invalid>"}']; };
$cleanupUser=null; $failed=false; $_SERVER['REMOTE_ADDR']='email-test-'.bin2hex(random_bytes(12)); $requestIpHash=auth_ip_key();
try {
    email_expect(!email_recipient_valid('person@example.invalid') && email_recipient_valid('person@example.invalid',true),'Development recipient guard');
    $sample=['name'=>'<script>','customer_name'=>'<unsafe>','business_name'=>'Sample Store','amount'=>'1250.00','previous'=>'2000.00','remaining'=>'750.00','total_paid'=>'2000.00','paid_at'=>'2026-10-25 09:30:00','changed_at'=>'2026-10-25 09:30:00','due_date'=>'2026-10-28','reference_number'=>null,'reset_token'=>str_repeat('b',64),'submitted_at'=>'2026-10-25 09:30:00','period_start'=>'2026-10-25 09:30:00','period_end'=>'2026-11-25 09:30:00','reason'=>'Sample rejection','owner_name'=>'Sample Owner','submission_id'=>1];
    foreach (EMAIL_TYPES as $type) { $r=email_render($type,$sample,$config); email_expect(!str_contains($r['html'],'<script>') && str_contains($r['html'],'ProfitPocket') && str_contains($r['html'],'All rights reserved.'),'Unsafe/unbranded template '.$type); email_expect(!preg_match('/href="http:/',$r['html']),'Non-HTTPS CTA'); }
    email_expect(email_money('1250.00')==='₱1,250.00' && str_contains(email_date('2026-10-25 09:30:00'),'9:30 am PHT'),'Peso/time formatting');
    $r=email_render('welcome',$sample,$config)+['to_email'=>'user@example.invalid','to_name'=>'Sample'];
    email_expect(brevo_send(array_replace($config,['BREVO_API_KEY'=>'']),$r,'missing',$mock)['status']==='skipped','Missing credentials');
    email_expect(brevo_send($config,$r,'ok',$mock)['status']==='accepted','API acceptance');
    foreach ([400=>'failed',401=>'failed',403=>'failed',429=>'retry',503=>'retry'] as $status=>$expected) { email_expect(brevo_send($config,$r,'http-'.$status,fn()=>['http'=>$status,'body'=>'{"message":"secret response must not be logged"}'])['status']===$expected,'API status '.$status); }
    email_expect(brevo_send($config,$r,'timeout',fn()=>['error'=>28])['status']==='uncertain','Timeout duplicates not prevented');
    email_expect(brevo_send($config,$r,'malformed-acceptance',fn()=>['http'=>201,'body'=>'{}'])['status']==='uncertain','Unconfirmed acceptance must require review');
    echo 'PASS: all '.count(EMAIL_TYPES)." templates, escaped branding, HTTPS, peso/Manila formatting, reserved-address guard and API response categories (mock transport only).\n";
    $db->beginTransaction(); $suffix=bin2hex(random_bytes(8));
    $uid=create_owner($db,['full_name'=>'Email rollback owner','username'=>'email_test_'.$suffix,'email'=>'owner_'.$suffix.'@example.invalid','business_name'=>'Email rollback store','business_category_id'=>(string)$db->query('SELECT business_category_id FROM business_categories LIMIT 1')->fetchColumn()],'Email-test-password-123!');
    $business=(int)customer_query($db,'SELECT business_id FROM businesses WHERE owner_user_id=?',[$uid])->fetchColumn(); $user=['user_id'=>$uid,'business_id'=>$business];
    $welcome=customer_query($db,"SELECT * FROM notification_outbox WHERE user_id=? AND notification_type='welcome'",[$uid])->fetch();
    email_expect((bool)$welcome,'Registration hook missing'); email_expect(notification_attempt($db,$welcome,$config,$mock)==='accepted','Welcome acceptance log');
    email_expect(notification_attempt($db,$welcome,$config,$mock)==='skipped' && email_job($db,(int)$welcome['notification_id'])['attempts']===1,'Repeated dispatch not blocked');
    $customer=customer_save($db,$user,0,['customer_name'=>'Email customer','email'=>'customer_'.$suffix.'@example.invalid'],bin2hex(random_bytes(32)));
    $permission=['email'=>'customer_'.$suffix.'@example.invalid','email_preferences_present'=>'1','email_receipts'=>'1','email_reminders'=>'1','email_consent_note'=>'Agreed during test','email_consent_confirm'=>'1']; notification_save_preferences($db,$user,$customer,$permission,null);
    $product=sale_product_create($db,$user,['product_name'=>'Email test product','default_selling_price'=>'2000','default_unit_cost'=>'100'],bin2hex(random_bytes(32)));
    $base=['customer_id'=>(string)$customer,'sold_at'=>date('Y-m-d\TH:i',strtotime('-4 days')),'save_mode'=>'posted','payment_plan'=>'partial','initial_payment'=>'500','payment_category'=>'cash','discount_type'=>'none','discount_value'=>'0','items'=>[['product_id'=>(string)$product,'quantity'=>'1','unit_price'=>'2000']],'due_date'=>date('Y-m-d',strtotime('+3 days'))];
    $sale=sale_create($db,$user,$base,bin2hex(random_bytes(32)));
    $partial=customer_query($db,"SELECT * FROM notification_outbox WHERE business_id=? AND sale_id=? AND notification_type='partial_payment'",[$business,$sale])->fetch();
    email_expect((bool)$partial && notification_attempt($db,$partial,$config,$mock)==='accepted','Downpayment/partial hook');
    $partialDetails=notification_resolve($db,$partial,$config)['data']; email_expect($partialDetails['previous']==='2000.00' && $partialDetails['remaining']==='1500.00','Payment balances');
    notification_generate_reminders($db,$config); notification_generate_reminders($db,$config);
    $upcoming=customer_query($db,"SELECT * FROM notification_outbox WHERE business_id=? AND sale_id=? AND notification_type='upcoming_due'",[$business,$sale])->fetch();
    email_expect((bool)$upcoming && (int)customer_query($db,"SELECT COUNT(*) FROM notification_outbox WHERE sale_id=? AND notification_type='upcoming_due'",[$sale])->fetchColumn()===1 && notification_attempt($db,$upcoming,$config,$mock)==='accepted','Upcoming reminder/repeated cron');
    $current=payment_sale_read($db,$business,$sale); utang_edit_due($db,$user,$sale,['due_date'=>date('Y-m-d',strtotime('-1 day')),'revision'=>utang_due_revision($current['due_date'])],bin2hex(random_bytes(32)));
    notification_generate_reminders($db,$config); $overdue=customer_query($db,"SELECT * FROM notification_outbox WHERE sale_id=? AND notification_type='overdue'",[$sale])->fetch(); email_expect(notification_attempt($db,$overdue,$config,$mock)==='accepted','Overdue reminder');
    $payment=['amount'=>'1500','payment_category'=>'cash','paid_at'=>date('Y-m-d\TH:i')]; $key=bin2hex(random_bytes(32)); payment_collect($db,$user,$sale,$payment,$key); payment_collect($db,$user,$sale,$payment,$key);
    $settled=customer_query($db,"SELECT * FROM notification_outbox WHERE sale_id=? AND notification_type='balance_settled'",[$sale])->fetch(); email_expect((bool)$settled && notification_attempt($db,$settled,$config,$mock)==='accepted','Settlement event');
    email_expect((int)customer_query($db,'SELECT COUNT(*) FROM notification_outbox WHERE payment_id=?',[$settled['payment_id']])->fetchColumn()===1,'Duplicate confirmation');
    $full=sale_create($db,$user,array_replace($base,['payment_plan'=>'full','initial_payment'=>'2000']),bin2hex(random_bytes(32)));
    $recorded=customer_query($db,"SELECT * FROM notification_outbox WHERE sale_id=? AND notification_type='payment_recorded'",[$full])->fetch(); email_expect((bool)$recorded && notification_attempt($db,$recorded,$config,$mock)==='accepted','Full payment misclassified as settlement');
    $savedSale=payment_sale_read($db,$business,$full);
    $originalMode=(string)$db->query('SELECT @@SESSION.sql_mode')->fetchColumn();
    $db->prepare('SET SESSION sql_mode=?')->execute([$originalMode.',STRICT_ALL_TABLES']);
    try { email_expect(notification_enqueue($db,'payment_recorded','forced-queue-failure:'.$suffix,['sale_id'=>$full,'recipient_email'=>str_repeat('x',300)],$config)===null,'Queue failure was not isolated'); }
    finally { $db->prepare('SET SESSION sql_mode=?')->execute([$originalMode]); }
    email_expect($db->inTransaction() && payment_sale_read($db,$business,$full)['net_sale']===$savedSale['net_sale'],'Email log failure damaged a valid financial record');
    $invalid=customer_save($db,$user,0,['customer_name'=>'No email customer'],bin2hex(random_bytes(32))); $noEmail=sale_create($db,$user,array_replace($base,['customer_id'=>(string)$invalid]),bin2hex(random_bytes(32)));
    $skip=customer_query($db,'SELECT * FROM notification_outbox WHERE sale_id=?',[$noEmail])->fetch(); email_expect(notification_attempt($db,$skip,$config,$mock)==='skipped' && email_job($db,(int)$skip['notification_id'])['failure_code']==='invalid_recipient','Missing recipient');
    $retryId=notification_enqueue($db,'password_changed','retry:'.$suffix,['user_id'=>$uid,'recipient_email'=>'owner_'.$suffix.'@example.invalid'],$config); $retryJob=email_job($db,$retryId);
    for ($i=0;$i<3;$i++) { notification_attempt($db,$retryJob,$config,fn()=>['http'=>429,'body'=>'{}','retry'=>30]); }
    email_expect(email_job($db,$retryId)['status']==='failed' && email_job($db,$retryId)['attempts']===3,'Unbounded retries');
    notification_save_preferences($db,$user,$customer,array_replace($permission,['email_receipts'=>'0','email_reminders'=>'0']),null);
    $denied=sale_create($db,$user,$base,bin2hex(random_bytes(32))); $deniedJob=customer_query($db,'SELECT * FROM notification_outbox WHERE sale_id=?',[$denied])->fetch(); email_expect(notification_attempt($db,$deniedJob,$config,$mock)==='skipped' && email_job($db,(int)$deniedJob['notification_id'])['failure_code']==='consent_missing','Missing permission');
    try { notification_attempt($db,$retryJob,$config); throw new RuntimeException('Precommit send allowed'); } catch (LogicException $e) {}
    $db->rollBack(); email_expect((int)$db->query('SELECT COUNT(*) FROM notification_outbox')->fetchColumn()===$baseline,'Rollback retained financial emails');
    echo "PASS: transactional registration/payment hooks, mutually exclusive confirmations, exact balances, consent, missing email, both reminder stages, duplicate jobs, bounded retries and precommit-send guard; financial fixtures rolled back.\n";
    // Password reset exercises real commits on a disposable NONFINANCIAL account.
    putenv('BREVO_EMAIL_ENABLED=false'); $db->beginTransaction();
    $cleanupUser=create_owner($db,['full_name'=>'Reset test owner','username'=>'email_reset_'.$suffix,'email'=>'reset_'.$suffix.'@example.invalid','business_name'=>'Reset test store','business_category_id'=>$baseCategory=(string)$db->query('SELECT business_category_id FROM business_categories LIMIT 1')->fetchColumn()],'Old-password-test-123!'); $db->commit(); putenv('BREVO_EMAIL_ENABLED=true');
    password_request_reset($db,'reset_'.$suffix.'@example.invalid',$config);
    $reset=customer_query($db,"SELECT * FROM notification_outbox WHERE user_id=? AND notification_type='password_reset' ORDER BY notification_id DESC LIMIT 1",[$cleanupUser])->fetch(); email_expect((bool)$reset,'Reset request not queued');
    $token=notification_reset_token($reset['reset_nonce'],$config); $stored=customer_query($db,'SELECT token_hash FROM password_reset_tokens WHERE reset_id=?',[$reset['reset_id']])->fetchColumn(); email_expect($stored===hash('sha256',$token) && !str_contains(json_encode($reset),$token),'Raw reset token persisted');
    notification_attempt($db,$reset,$config,$mock); email_expect(email_job($db,(int)$reset['notification_id'])['reset_nonce']===null,'Reset nonce retained after acceptance');
    $db->beginTransaction(); password_complete_reset($db,$token,'New-password-test-123!','New-password-test-123!',$config); $db->commit();
    email_expect(password_verify('New-password-test-123!',customer_query($db,'SELECT password_hash FROM users WHERE user_id=?',[$cleanupUser])->fetchColumn()),'Password not reset');
    try { $db->beginTransaction(); password_complete_reset($db,$token,'Other-password-123!','Other-password-123!',$config); throw new RuntimeException('Token reused'); } catch (InvalidArgumentException $e) { $db->rollBack(); }
    $db->beginTransaction(); password_authenticated_change($db,$cleanupUser,'New-password-test-123!','Third-password-test-123!','Third-password-test-123!','test-auth-change'); $db->commit();
    email_expect((int)customer_query($db,"SELECT COUNT(*) FROM notification_outbox WHERE user_id=? AND notification_type='password_changed'",[$cleanupUser])->fetchColumn()===2,'Password change events missing');
    for ($i=0;$i<5;$i++) { password_request_reset($db,'reset_'.$suffix.'@example.invalid',$config); }
    email_expect((int)customer_query($db,'SELECT COUNT(*) FROM password_reset_tokens WHERE user_id=?',[$cleanupUser])->fetchColumn()===3,'Reset request limit');
    $latest=customer_query($db,'SELECT * FROM password_reset_tokens WHERE user_id=? ORDER BY reset_id DESC LIMIT 1',[$cleanupUser])->fetch();
    $latestJob=customer_query($db,'SELECT reset_nonce FROM notification_outbox WHERE reset_id=?',[$latest['reset_id']])->fetch();
    $expiredToken=notification_reset_token($latestJob['reset_nonce'],$config);
    $db->beginTransaction(); $db->prepare('UPDATE password_reset_tokens SET created_at=DATE_SUB(CURRENT_TIMESTAMP,INTERVAL 31 MINUTE),expires_at=DATE_SUB(CURRENT_TIMESTAMP,INTERVAL 1 SECOND) WHERE reset_id=?')->execute([$latest['reset_id']]);
    try { password_complete_reset($db,$expiredToken,'Expired-password-123!','Expired-password-123!',$config); throw new RuntimeException('Expired token accepted'); } catch (InvalidArgumentException $e) { $db->rollBack(); }
    echo "PASS: reset requests, hashed tokens, secret-bound nonce, single-use reset, authenticated password changes, security notifications and rate limiting (no real API calls).\n";
} catch (Throwable $e) { if ($db->inTransaction()) { $db->rollBack(); } fwrite(STDERR,'FAIL: '.$e->getMessage().PHP_EOL); $failed=true; }
finally {
    if ($cleanupUser) { $db->beginTransaction(); foreach (['notification_outbox','password_reset_tokens','auth_sessions'] as $table) { $db->prepare('DELETE FROM '.$table.' WHERE user_id=?')->execute([$cleanupUser]); } $db->prepare('DELETE FROM activity_logs WHERE actor_user_id=?')->execute([$cleanupUser]); $db->prepare('DELETE FROM businesses WHERE owner_user_id=?')->execute([$cleanupUser]); $db->prepare('DELETE FROM users WHERE user_id=?')->execute([$cleanupUser]); $db->commit(); }
    $db->prepare("DELETE FROM activity_logs WHERE action_code='AUTH_RESET_REQUESTED' AND details=?")->execute([$requestIpHash]);
    foreach ($restore as $key=>$value) { putenv($value===false?$key:$key.'='.$value); }
}
if ($failed) { exit(1); }
