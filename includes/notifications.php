<?php
declare(strict_types=1);
require_once __DIR__.'/email_transport.php';
require_once __DIR__.'/email_templates.php';

function notification_schema(PDO $db): bool
{
    try { $s=$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('notification_outbox','customer_email_preferences')"); return (int)$s->fetchColumn()===2; }
    catch (PDOException $e) { return false; }
}
function notification_enqueue(PDO $db,string $type,string $event,array $refs=[],?array $config=null): ?int
{
    // Enqueue in the event's transaction; failures cannot undo the business event.
    $savepoint=false;
    try {
        $c=$config??email_config();
        if (!$c['BREVO_EMAIL_ENABLED']) { return null; } // No retrospective backlog while disabled.
        if (!in_array($type,EMAIL_TYPES,true)) { throw new InvalidArgumentException('Unsupported notification.'); }
        if ($db->inTransaction()) { $db->exec('SAVEPOINT email_enqueue'); $savepoint=true; }
        $columns=['user_id','business_id','customer_id','sale_id','payment_id','reset_id','reset_nonce','recipient_email'];
        if (isset($refs['subscription_payment_id'])) { $columns[]='subscription_payment_id'; }
        $values=[];
        foreach ($columns as $field) { $values[]=$refs[$field]??null; }
        $status=email_ready($c)?'pending':'skipped'; $code=$status==='skipped'?'configuration_missing':null;
        $s=$db->prepare('INSERT INTO notification_outbox (notification_type,event_key,'.implode(',',$columns).',status,failure_code) VALUES ('.implode(',',array_fill(0,count($columns)+4,'?')).') ON DUPLICATE KEY UPDATE event_key=event_key');
        $s->execute(array_merge([$type,hash('sha256',$event)],$values,[$status,$code]));
        $id=$db->prepare('SELECT notification_id FROM notification_outbox WHERE event_key=?'); $id->execute([hash('sha256',$event)]);
        $result=(int)$id->fetchColumn();
        if ($savepoint) { $db->exec('RELEASE SAVEPOINT email_enqueue'); }
        return $result;
    } catch (Throwable $e) {
        if ($savepoint && $db->inTransaction()) { $db->exec('ROLLBACK TO SAVEPOINT email_enqueue'); $db->exec('RELEASE SAVEPOINT email_enqueue'); }
        error_log('ProfitPocket email: queue_unavailable'); return null;
    }
}
function notification_payment_event(PDO $db,int $business,int $payment): void
{
    try {
        $c=email_config(); if (!$c['BREVO_EMAIL_ENABLED']) { return; }
        require_once __DIR__.'/payments.php';
        $r=payment_read($db,$business,$payment); $sale=$r['sale']; $p=$r['payment'];
        if ($sale['sale_status']!=='posted' || $p['payment_kind']!=='collection') { return; }
        $type=match($p['payment_type']) { 'downpayment','partial'=>'partial_payment','final'=>'balance_settled',default=>'payment_recorded' };
        notification_enqueue($db,$type,'payment:'.$business.':'.$payment,['business_id'=>$business,'sale_id'=>$sale['sale_id'],'payment_id'=>$payment,'customer_id'=>$sale['customer_id']],$c);
    } catch (Throwable $e) { error_log('ProfitPocket email: event_queue_unavailable'); }
}
function notification_preferences(PDO $db,int $business,int $customer): array
{
    try { $s=$db->prepare('SELECT * FROM customer_email_preferences WHERE business_id=? AND customer_id=?'); $s->execute([$business,$customer]); return $s->fetch()?:[]; }
    catch (PDOException $e) { return []; }
}
function notification_save_preferences(PDO $db,array $user,int $customer,array $input,?array $old): void
{
    if (!$db->inTransaction()) { throw new LogicException('Email preference changes require a transaction.'); }
    $scope=$db->prepare('SELECT c.customer_id FROM customers c JOIN businesses b ON b.business_id=c.business_id WHERE c.customer_id=? AND c.business_id=? AND b.owner_user_id=? FOR UPDATE'); $scope->execute([$customer,$user['business_id'],$user['user_id']]);
    if (!$scope->fetchColumn()) { throw new InvalidArgumentException('Customer email preferences are not available.'); }
    if (!notification_schema($db)) {
        if (!empty($input['email_receipts']) || !empty($input['email_reminders'])) { throw new InvalidArgumentException('Email preferences require the approved notification migration.'); }
        return;
    }
    $previous=notification_preferences($db,(int)$user['business_id'],$customer);
    $email=trim((string)($input['email']??'')); $emailHash=hash('sha256',mb_strtolower($email));
    if (!isset($input['email_preferences_present'])) {
        // Legacy edits changing the address revoke consent, rather than transferring it.
        if ($previous && !hash_equals((string)$previous['email_hash'],$emailHash)) { $db->prepare('UPDATE customer_email_preferences SET payment_receipts=0,reminders=0,updated_at=CURRENT_TIMESTAMP WHERE customer_id=? AND business_id=?')->execute([$customer,$user['business_id']]); }
        return;
    }
    $receipts=($input['email_receipts']??'')==='1'; $reminders=($input['email_reminders']??'')==='1';
    $note=is_string($input['email_consent_note']??null)?trim($input['email_consent_note']):'';
    if (mb_strlen($note)>255 || !mb_check_encoding($note,'UTF-8')) { throw new InvalidArgumentException('Keep the customer email consent note within 255 characters.'); }
    $changed=($receipts && empty($previous['payment_receipts'])) || ($reminders && empty($previous['reminders'])) || !hash_equals((string)($previous['email_hash']??''),$emailHash);
    if (($receipts || $reminders) && (!filter_var($email,FILTER_VALIDATE_EMAIL) || ($changed && (($input['email_consent_confirm']??'')!=='1' || $note==='')))) { throw new InvalidArgumentException('Confirm the customer agreed at this email address and provide a short consent note before enabling notifications.'); }
    $db->prepare('INSERT INTO customer_email_preferences (customer_id,business_id,email_hash,payment_receipts,reminders,consent_note,updated_by) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE email_hash=VALUES(email_hash),payment_receipts=VALUES(payment_receipts),reminders=VALUES(reminders),consent_note=VALUES(consent_note),updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP')->execute([$customer,$user['business_id'],$emailHash,(int)$receipts,(int)$reminders,$note!==''?$note:($previous['consent_note']??null),$user['user_id']]);
    if (function_exists('sale_audit')) { sale_audit($db,$user,$customer,'CUSTOMER_EMAIL_PREFERENCES_UPDATED',[],['payment_receipts'=>$receipts,'reminders'=>$reminders,'email_hash'=>$emailHash],'customer'); }
}
function notification_reset_token(string $nonce,array $config): string
{
    if (!preg_match('/\A[a-f0-9]{64}\z/',(string)$config['PROFITPOCKET_NOTIFICATION_SECRET']) || !preg_match('/\A[a-f0-9]{64}\z/',$nonce)) { throw new RuntimeException('Reset secret unavailable.'); }
    return hash_hmac('sha256','profitpocket-reset:'.$nonce,$config['PROFITPOCKET_NOTIFICATION_SECRET']);
}
function notification_resolve(PDO $db,array $job,array $config): array
{
    if (in_array($job['notification_type'],['registration_payment_submitted','subscription_admin_review'],true)) { require_once __DIR__.'/registration.php'; return registration_notification($db,$job); }
    if (str_starts_with($job['notification_type'],'subscription_')) { require_once __DIR__.'/subscriptions.php'; return subscription_notification($db,$job); }
    if (in_array($job['notification_type'],['welcome','password_reset','password_changed'],true)) {
        $s=$db->prepare('SELECT full_name,email,account_status FROM users WHERE user_id=?'); $s->execute([$job['user_id']]); $u=$s->fetch();
        if (!$u) { throw new DomainException('account_missing'); }
        $data=['name'=>$u['full_name'],'changed_at'=>$job['created_at']]; $email=$job['recipient_email']??$u['email'];
        if ($job['notification_type']==='welcome') { require_once __DIR__.'/subscriptions.php'; $data['subscription_required']=subscription_enabled(); }
        if ($job['notification_type']==='password_reset') {
            $s=$db->prepare('SELECT token_hash FROM password_reset_tokens WHERE reset_id=? AND user_id=? AND used_at IS NULL AND expires_at>CURRENT_TIMESTAMP'); $s->execute([$job['reset_id'],$job['user_id']]); $hash=$s->fetchColumn();
            $token=notification_reset_token((string)$job['reset_nonce'],$config);
            if (!$hash || $u['account_status']!=='active' || mb_strtolower($email)!==mb_strtolower($u['email']) || !hash_equals($hash,hash('sha256',$token))) { throw new DomainException('reset_expired'); }
            $data['reset_token']=$token;
        }
        return ['data'=>$data,'email'=>$email,'name'=>$u['full_name']];
    }
    require_once __DIR__.'/payments.php';
    $s=$db->prepare("SELECT c.customer_name,c.email,c.is_active,b.business_name FROM customers c JOIN businesses b ON b.business_id=c.business_id JOIN users u ON u.user_id=b.owner_user_id WHERE c.customer_id=? AND c.business_id=? AND u.account_status='active'"); $s->execute([$job['customer_id'],$job['business_id']]); $customer=$s->fetch();
    if (!$customer || !$customer['is_active']) { throw new DomainException('customer_ineligible'); }
    require_once __DIR__.'/subscriptions.php';
    $owner=subscription_query($db,"SELECT u.user_id,r.role_type FROM businesses b JOIN users u ON u.user_id=b.owner_user_id JOIN roles r ON r.role_id=u.role_id WHERE b.business_id=?",[$job['business_id']])->fetch();
    if (!$owner || !subscription_has_access($db,$owner)) { throw new DomainException('subscription_inactive'); }
    if (!filter_var($customer['email'],FILTER_VALIDATE_EMAIL)) { throw new DomainException('invalid_recipient'); }
    $preference=notification_preferences($db,(int)$job['business_id'],(int)$job['customer_id']);
    $isReminder=in_array($job['notification_type'],['upcoming_due','overdue'],true);
    if (empty($preference[$isReminder?'reminders':'payment_receipts']) || !hash_equals((string)$preference['email_hash'],hash('sha256',mb_strtolower($customer['email'])))) { throw new DomainException('consent_missing'); }
    if ($job['recipient_email'] && mb_strtolower($job['recipient_email'])!==mb_strtolower($customer['email'])) { throw new DomainException('recipient_changed'); }
    $sale=payment_sale_read($db,(int)$job['business_id'],(int)$job['sale_id']);
    if ((int)$sale['customer_id']!==(int)$job['customer_id']) { throw new DomainException('customer_mismatch'); }
    $data=['customer_name'=>$customer['customer_name'],'business_name'=>$customer['business_name'],'remaining'=>$sale['balance_due'],'due_date'=>$sale['due_date']];
    if ($isReminder) {
        $expected='reminder:'.$job['sale_id'].':'.$sale['due_date'].':'.$job['notification_type'];
        $today=date('Y-m-d');
        if (!$sale['due_date'] || bccomp($sale['balance_due'],'0',2)<=0 || !hash_equals($job['event_key'],hash('sha256',$expected)) || ($job['notification_type']==='upcoming_due' && $sale['due_date']<$today) || ($job['notification_type']==='overdue' && $sale['due_date']>=$today)) { throw new DomainException('reminder_obsolete'); }
    } else {
        $p=null; $before='0.00'; $ordered=$sale['payments']; usort($ordered,fn($a,$b)=>(int)$a['payment_id']<=>(int)$b['payment_id']);
        foreach ($ordered as $item) { if ((int)$item['payment_id']===(int)$job['payment_id']) { $p=$item; break; } $before=$item['payment_kind']==='collection'?bcadd($before,$item['amount'],2):bcsub($before,$item['amount'],2); }
        if (!$p || $p['payment_kind']!=='collection') { throw new DomainException('payment_missing'); }
        $classification=payment_classify($sale)[$p['payment_id']]??null;
        $expectedType=match($classification) { 'downpayment','partial'=>'partial_payment','final'=>'balance_settled',default=>'payment_recorded' };
        if ($job['notification_type']!==$expectedType) { throw new DomainException('payment_type_mismatch'); }
        foreach ($ordered as $item) { if ($item['payment_kind']==='refund' && (int)$item['reverses_payment_id']===(int)$p['payment_id']) { throw new DomainException('payment_reversed'); } }
        $previous=bcsub($sale['net_sale'],$before,2); $remaining=bcsub($previous,$p['amount'],2);
        if ($job['notification_type']==='partial_payment' && bccomp($sale['balance_due'],'0',2)===0) { throw new DomainException('partial_superseded'); }
        if ($job['notification_type']==='balance_settled' && bccomp($sale['balance_due'],'0',2)!==0) { throw new DomainException('settlement_reopened'); }
        $data+=['amount'=>$p['amount'],'previous'=>$previous,'paid_at'=>$p['paid_at'],'reference_number'=>$p['reference_number'],'total_paid'=>bcadd($before,$p['amount'],2)]; $data['remaining']=$remaining;
    }
    return ['data'=>$data,'email'=>$customer['email'],'name'=>$customer['customer_name']];
}
function notification_attempt(PDO $db,array $job,array $config,?callable $transport=null): string
{
    if ($db->inTransaction() && !$transport) { throw new LogicException('Real email cannot be sent before commit.'); }
    $fresh=$db->prepare("SELECT * FROM notification_outbox WHERE notification_id=? AND status='pending'"); $fresh->execute([$job['notification_id']]); $job=$fresh->fetch();
    if (!$job) { return 'skipped'; }
    if ((int)$job['attempts']>=3 || ($job['first_attempt_at'] && strtotime($job['first_attempt_at'])<time()-600)) {
        $db->prepare("UPDATE notification_outbox SET status='uncertain',failure_code='retry_window_expired',reset_nonce=NULL WHERE notification_id=?")->execute([$job['notification_id']]); return 'uncertain';
    }
    try {
        $record=notification_resolve($db,$job,$config);
        $rendered=email_render($job['notification_type'],$record['data'],$config)+['to_email'=>$record['email'],'to_name'=>$record['name']];
    } catch (Throwable $e) {
        $code=$e instanceof DomainException?$e->getMessage():'record_unavailable';
        $db->prepare("UPDATE notification_outbox SET status='skipped',failure_code=?,reset_nonce=NULL WHERE notification_id=?")->execute([$code,$job['notification_id']]); return 'skipped';
    }
    $db->prepare("UPDATE notification_outbox SET status='processing',attempts=attempts+1,attempted_at=CURRENT_TIMESTAMP,first_attempt_at=COALESCE(first_attempt_at,CURRENT_TIMESTAMP),recipient_email=? WHERE notification_id=?")->execute([$record['email'],$job['notification_id']]);
    $result=brevo_send($config,$rendered,$job['event_key'],$transport); $status=$result['status'];
    $delay=max(30,min(600,(int)($result['retry']??60))); $retry=$status==='retry' && (int)$job['attempts']<2;
    $stored=$retry?'pending':($status==='retry'?'failed':$status);
    $db->prepare('UPDATE notification_outbox SET status=?,failure_code=?,message_id=?,accepted_at=CASE WHEN ?=\'accepted\' THEN CURRENT_TIMESTAMP ELSE NULL END,available_at=DATE_ADD(CURRENT_TIMESTAMP,INTERVAL ? SECOND),reset_nonce=CASE WHEN ?=\'pending\' THEN reset_nonce ELSE NULL END WHERE notification_id=?')->execute([$stored,$result['code']??null,$result['message_id']??null,$stored,$delay,$stored,$job['notification_id']]);
    return $retry?'retry':$stored;
}
function notification_process(PDO $db,?array $config=null,?callable $transport=null,int $limit=30): array
{
    $config??=email_config(); $counts=['accepted'=>0,'failed'=>0,'skipped'=>0,'uncertain'=>0,'retry'=>0];
    if ($db->inTransaction()) { throw new LogicException('Email workers must run outside financial transactions.'); }
    if (!email_ready($config)) { return $counts+['disabled'=>true]; }
    $lock='pp-email:'.substr(hash('sha256',$db->query('SELECT DATABASE()')->fetchColumn()),0,32);
    $s=$db->prepare('SELECT GET_LOCK(?,0)'); $s->execute([$lock]); if (!(int)$s->fetchColumn()) { return $counts+['busy'=>true]; }
    try {
        // A crashed in-flight request may already have reached Brevo. Never blindly resend it.
        $db->exec("UPDATE notification_outbox SET status='uncertain',failure_code='interrupted_attempt',reset_nonce=NULL WHERE status='processing'");
        $jobs=$db->query("SELECT * FROM notification_outbox WHERE status='pending' AND available_at<=CURRENT_TIMESTAMP ORDER BY notification_id LIMIT ".max(1,min(100,$limit)))->fetchAll();
        foreach ($jobs as $job) { $counts[notification_attempt($db,$job,$config,$transport)]++; }
    } finally { $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]); }
    return $counts;
}
function notification_generate_reminders(PDO $db,?array $config=null,?DateTimeImmutable $today=null): int
{
    $config??=email_config(); if (!email_ready($config)) { return 0; }
    require_once __DIR__.'/payments.php';
    $today=($today??new DateTimeImmutable('today',new DateTimeZone('Asia/Manila')))->setTimezone(new DateTimeZone('Asia/Manila'));
    $dates=['upcoming_due'=>$today->modify('+3 days')->format('Y-m-d'),'overdue'=>$today->modify('-1 day')->format('Y-m-d')]; $count=0;
    foreach ($dates as $type=>$due) {
        $s=$db->prepare("SELECT f.sale_id,f.customer_id,f.business_id FROM v_sale_financials f JOIN customer_credits cr ON cr.sale_id=f.sale_id JOIN customer_email_preferences p ON p.customer_id=f.customer_id AND p.business_id=f.business_id JOIN customers c ON c.customer_id=f.customer_id AND c.business_id=f.business_id JOIN businesses b ON b.business_id=f.business_id JOIN users u ON u.user_id=b.owner_user_id WHERE f.sale_status='posted' AND f.balance_due>0 AND cr.due_date=? AND p.reminders=1 AND c.is_active=1 AND u.account_status='active'"); $s->execute([$due]);
        foreach ($s->fetchAll() as $row) { $id=notification_enqueue($db,$type,'reminder:'.$row['sale_id'].':'.$due.':'.$type,['business_id'=>$row['business_id'],'customer_id'=>$row['customer_id'],'sale_id'=>$row['sale_id']],$config); if ($id) { $count++; } }
    }
    return $count;
}
