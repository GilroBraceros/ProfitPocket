<?php
declare(strict_types=1);
if (PHP_SAPI==='cli') { require_once __DIR__.'/../config/database.php'; date_default_timezone_set('Asia/Manila'); }
else { require_once __DIR__.'/auth.php'; }
require_once __DIR__.'/../config/upgrade.php';
const SUBSCRIPTION_PRICE='100.00';
const SUBSCRIPTION_VERSION='20261025_subscriptions';

function subscription_query(PDO $db,string $sql,array $values=[]): PDOStatement
{
    $s=$db->prepare($sql); $s->execute($values); return $s;
}
function subscription_schema(PDO $db): bool
{
    try { return (bool)subscription_query($db,'SELECT 1 FROM deployment_runs WHERE run_key=?',['migration:'.SUBSCRIPTION_VERSION])->fetchColumn(); }
    catch (PDOException $e) { return false; }
}
function subscription_enabled(): bool { return upgrade_config()['PP_SUBSCRIPTIONS_ENABLED']; }
function subscription_initialize_owner(PDO $db,int $id): void
{
    if (!subscription_schema($db)) {
        if (subscription_enabled()) { throw new RuntimeException('Subscription migration is required.'); }
        return;
    }
    subscription_query($db,'INSERT IGNORE INTO owner_subscriptions (user_id) SELECT u.user_id FROM users u JOIN roles r ON r.role_id=u.role_id WHERE u.user_id=? AND r.role_type=\'owner\'',[$id]);
}
function subscription_state(PDO $db,int $id,?DateTimeImmutable $now=null): array
{
    $now??=new DateTimeImmutable('now',new DateTimeZone('Asia/Manila'));
    $row=subscription_query($db,'SELECT s.*,u.account_status FROM users u LEFT JOIN owner_subscriptions s ON s.user_id=u.user_id WHERE u.user_id=?',[$id])->fetch();
    if (!$row) { throw new InvalidArgumentException('Account not found.'); }
    $latest=subscription_query($db,'SELECT submission_id,status,review_note,submitted_at FROM subscription_payments WHERE user_id=? ORDER BY submission_id DESC LIMIT 1',[$id])->fetch()?:null;
    $active=$row['expires_at'] && $row['expires_at']>$now->format('Y-m-d H:i:s') && $row['started_at']<=$now->format('Y-m-d H:i:s');
    $state=match(true) {
        $row['account_status']!=='active'=>'suspended',
        (bool)$active=>'active',
        ($latest['status']??'')==='pending'=>'pending_verification',
        ($latest['status']??'')==='rejected'=>'rejected',
        !empty($row['expires_at'])=>'expired', default=>'pending_payment',
    };
    return $row+['state'=>$state,'active'=>(bool)$active && $row['account_status']==='active','latest'=>$latest];
}
function subscription_has_access(PDO $db,array $user): bool
{
    if (($user['role_type']??'')==='admin') { return true; }
    if (!subscription_enabled()) { return true; }
    if (!subscription_schema($db)) { return false; }
    return subscription_state($db,(int)$user['user_id'])['active'];
}
function subscription_require_access(array $user): void
{
    try { $allowed=subscription_has_access(db(),$user); } catch (Throwable $e) { $allowed=false; }
    if (!$allowed) { redirect_to('user/subscription.php'); }
}
function subscription_label(string $state): string { return ucwords(str_replace('_',' ',$state)); }
function subscription_month_end(DateTimeImmutable $from,int $anchor): DateTimeImmutable
{
    $target=$from->modify('first day of next month');
    return $target->setDate((int)$target->format('Y'),(int)$target->format('m'),min($anchor,(int)$target->format('t')));
}
function subscription_methods(PDO $db,bool $all=false): array
{
    require_once __DIR__.'/subscription_methods.php';
    $rows=subscription_query($db,'SELECT * FROM subscription_payment_methods'.($all?'':' WHERE is_enabled=1').' ORDER BY method_id')->fetchAll();
    return $all?$rows:array_values(array_filter($rows,'subscription_method_supported'));
}
function subscription_owner_lock(PDO $db,int $id): array
{
    if (!$db->inTransaction()) { throw new LogicException('Subscription changes require a transaction.'); }
    $u=subscription_query($db,"SELECT u.user_id,u.full_name,u.email,u.account_status FROM users u JOIN roles r ON r.role_id=u.role_id WHERE u.user_id=? AND r.role_type='owner' FOR UPDATE",[$id])->fetch();
    if (!$u || $u['account_status']!=='active') { throw new InvalidArgumentException('The owner account is unavailable or restricted.'); }
    subscription_initialize_owner($db,$id);
    subscription_query($db,'SELECT user_id FROM owner_subscriptions WHERE user_id=? FOR UPDATE',[$id])->fetch();
    return $u;
}
function subscription_storage(bool $create=false): string
{
    $path=(string)upgrade_config()['PP_SUBSCRIPTION_STORAGE'];
    if ($path==='' || !preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~',$path)) { throw new RuntimeException('Private proof storage is not configured.'); }
    $parent=$path;
    while (!is_dir($parent) && $parent!==dirname($parent)) { $parent=dirname($parent); }
    if (!realpath($parent) || upgrade_public_path(realpath($parent)) || preg_match('~(?:^|[\\\\/])\.\.(?:[\\\\/]|$)~',$path)) { throw new RuntimeException('Payment proof storage must be private.'); }
    if ($create && !is_dir($path) && !mkdir($path,0700,true) && !is_dir($path)) { throw new RuntimeException('Private proof storage is unavailable.'); }
    $resolved=realpath($path);
    if (!$resolved || upgrade_public_path($resolved) || !is_readable($resolved) || ($create && !is_writable($resolved))) { throw new RuntimeException('Private proof storage is unavailable.'); }
    return $resolved;
}
function subscription_validate_proof(string $path,int $size): array
{
    if ($size<1 || $size>5242880 || !is_file($path) || filesize($path)!==$size) { throw new InvalidArgumentException('Upload a JPG, PNG or PDF file up to 5 MB.'); }
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($path);
    if (!in_array($mime,['image/jpeg','image/png','application/pdf'],true)) { throw new InvalidArgumentException('The proof must be an actual JPG, PNG or PDF file.'); }
    if ($mime!=='application/pdf') {
        $image=@getimagesize($path);
        if (!$image || $image[0]<1 || $image[1]<1 || $image[0]*$image[1]>20000000) { throw new InvalidArgumentException('The image is invalid or too large.'); }
    } else {
        $handle=fopen($path,'rb'); $prefix=fread($handle,8); fclose($handle);
        if (!str_starts_with($prefix,'%PDF-')) { throw new InvalidArgumentException('The PDF file is invalid.'); }
    }
    return ['mime'=>$mime,'bytes'=>$size,'hash'=>hash_file('sha256',$path)];
}
function subscription_submit(PDO $db,int $owner,array $input,array $proof,string $key): array
{
    subscription_owner_lock($db,$owner);
    if (!preg_match('/\A[a-f0-9]{64}\z/',$key)) { throw new InvalidArgumentException('Reload this payment form before submitting.'); }
    $old=subscription_query($db,'SELECT submission_id FROM subscription_payments WHERE user_id=? AND request_key=?',[$owner,$key])->fetchColumn();
    if ($old) { return ['id'=>(int)$old,'replayed'=>true]; }
    if (subscription_query($db,"SELECT 1 FROM subscription_payments WHERE user_id=? AND status='pending'",[$owner])->fetchColumn()) { throw new InvalidArgumentException('You already have a payment awaiting verification.'); }
    $method=filter_var($input['method_id']??null,FILTER_VALIDATE_INT);
    $method=subscription_query($db,'SELECT * FROM subscription_payment_methods WHERE method_id=? AND is_enabled=1 FOR UPDATE',[$method?:0])->fetch();
    require_once __DIR__.'/subscription_methods.php';
    if (!$method || !subscription_method_supported($method)) { throw new InvalidArgumentException('Select an available payment method.'); }
    $reference=is_string($input['reference_number']??null)?trim($input['reference_number']):'';
    if (mb_strlen($reference)>150 || !mb_check_encoding($reference,'UTF-8')) { throw new InvalidArgumentException('Keep the payment reference within 150 characters.'); }
    if (!preg_match('/\A[a-f0-9]{64}\.bin\z/',$proof['name']??'') || !preg_match('/\A[a-f0-9]{64}\z/',$proof['hash']??'') || !in_array($proof['mime']??'', ['image/jpeg','image/png','application/pdf'],true)) { throw new InvalidArgumentException('Payment proof is invalid.'); }
    if (subscription_query($db,'SELECT 1 FROM subscription_payments WHERE user_id=? AND proof_sha256=?',[$owner,$proof['hash']])->fetchColumn()) { throw new InvalidArgumentException('This proof was already submitted. Use proof of a new payment.'); }
    $snapshot=array_intersect_key($method,array_flip(['method_name','recipient_name','account_identifier','instructions','method_category','bank_name','qr_filename']));
    $referenceKey=$reference!==''?hash('sha256',mb_strtolower($method['account_identifier']).':'.mb_strtoupper($reference)):null;
    if ($referenceKey && subscription_query($db,'SELECT 1 FROM subscription_payments WHERE reference_key=?',[$referenceKey])->fetchColumn()) { throw new InvalidArgumentException('This payment reference was already submitted for this receiving account. Contact support for a correction.'); }
    subscription_query($db,'INSERT INTO subscription_payments (user_id,method_id,method_snapshot,reference_number,reference_key,proof_name,proof_mime,proof_bytes,proof_sha256,request_key) VALUES (?,?,?,?,?,?,?,?,?,?)',[$owner,$method['method_id'],json_encode($snapshot,JSON_THROW_ON_ERROR),$reference?:null,$referenceKey,$proof['name'],$proof['mime'],$proof['bytes'],$proof['hash'],$key]);
    $id=(int)$db->lastInsertId();
    subscription_query($db,"INSERT INTO activity_logs (actor_user_id,action_code,entity_type,entity_id) VALUES (?,'SUBSCRIPTION_PROOF_SUBMITTED','subscription_payment',?)",[$owner,$id]);
    require_once __DIR__.'/notifications.php';
    require_once __DIR__.'/registration.php';
    if (!registration_complete_payment($db,$owner,$id)) { notification_enqueue($db,'subscription_submitted','subscription-submitted:'.$id,['user_id'=>$owner,'subscription_payment_id'=>$id]); }
    return ['id'=>$id,'replayed'=>false];
}
function subscription_receive_proof(PDO $db,int $owner,array $input,array $file,string $key,?string $onboardingToken=null): array
{
    if($db->inTransaction()){throw new LogicException('Proof uploads own their transaction.');}
    if((int)subscription_query($db,'SELECT COUNT(*) FROM subscription_payments WHERE user_id=? AND submitted_at>=DATE_SUB(CURRENT_TIMESTAMP,INTERVAL 1 DAY)',[$owner])->fetchColumn()>=10){throw new InvalidArgumentException('Too many submissions today. Contact support for assistance.');}
    if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_string($file['tmp_name']??null) || !is_uploaded_file($file['tmp_name'])){throw new InvalidArgumentException('Choose a JPG, PNG or PDF proof up to 5 MB.');}
    $proof=subscription_validate_proof($file['tmp_name'],(int)$file['size']);$proof['name']=bin2hex(random_bytes(32)).'.bin';$path=subscription_storage(true).'/'.$proof['name'];
    if(!move_uploaded_file($file['tmp_name'],$path)){throw new RuntimeException('Private proof storage failed.');}@chmod($path,0600);
    try{
        $db->beginTransaction();
        if($onboardingToken!==null){require_once __DIR__.'/registration.php';$result=registration_submit($db,$onboardingToken,$input,$proof,$key);}
        else{$result=subscription_submit($db,$owner,$input,$proof,$key);}
        $db->commit();if($result['replayed'] && is_file($path)){unlink($path);}return $result;
    }catch(Throwable $e){if($db->inTransaction()){$db->rollBack();}if(is_file($path)){unlink($path);}if($e instanceof PDOException && ($e->errorInfo[1]??0)===1062){throw new InvalidArgumentException('This proof or payment reference was already submitted. Refresh your payment history.');}throw $e;}
}
function subscription_review(PDO $db,int $actor,int $id,string $decision,string $note,bool $verified,?DateTimeImmutable $now=null): array
{
    require_once __DIR__.'/admin.php';
    admin_authorize($db,$actor,'users.manage',true);
    if (!in_array($decision,['approved','rejected'],true) || mb_strlen($note)>1000 || !mb_check_encoding($note,'UTF-8') || ($decision==='rejected' && trim($note)==='') || ($decision==='approved' && !$verified)) { throw new InvalidArgumentException('Confirm the actual received payment before approval, or give a clear rejection reason.'); }
    $owner=(int)subscription_query($db,'SELECT user_id FROM subscription_payments WHERE submission_id=?',[$id])->fetchColumn();
    subscription_owner_lock($db,$owner);
    $payment=subscription_query($db,'SELECT * FROM subscription_payments WHERE submission_id=? FOR UPDATE',[$id])->fetch();
    if (!$payment) { throw new InvalidArgumentException('Submission not found.'); }
    if ($payment['status']!== 'pending') {
        if ($payment['status']===$decision && (int)$payment['reviewed_by']===$actor && (string)$payment['review_note']===$note) { return ['replayed'=>true]+$payment; }
        throw new InvalidArgumentException('This payment was already reviewed. Refresh its details.');
    }
    $now=($now??new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->setTimezone(new DateTimeZone('Asia/Manila'));
    $subscription=subscription_state($db,$owner,$now); $start=null; $end=null; $renewal=$subscription['started_at']!==null;
    if ($decision==='approved') {
        $start=$subscription['active']?new DateTimeImmutable($subscription['expires_at'],new DateTimeZone('Asia/Manila')):$now;
        $anchor=$subscription['active']?(int)$subscription['billing_anchor']:(int)$start->format('j');
        $end=subscription_month_end($start,$anchor);
        subscription_query($db,'UPDATE owner_subscriptions SET started_at=?,expires_at=?,billing_anchor=? WHERE user_id=?',[$subscription['active']?$subscription['started_at']:$start->format('Y-m-d H:i:s'),$end->format('Y-m-d H:i:s'),$anchor,$owner]);
    }
    subscription_query($db,'UPDATE subscription_payments SET status=?,reviewed_by=?,reviewed_at=?,review_note=?,period_start=?,period_end=?,is_renewal=?,reference_key=CASE WHEN ?=\'rejected\' THEN NULL ELSE reference_key END WHERE submission_id=?',[$decision,$actor,$now->format('Y-m-d H:i:s'),$note,$start?->format('Y-m-d H:i:s'),$end?->format('Y-m-d H:i:s'),(int)$renewal,$decision,$id]);
    admin_audit($db,$actor,$owner,'SUBSCRIPTION_'.strtoupper($decision),['status'=>'pending'],['status'=>$decision,'submission_id'=>$id,'amount'=>SUBSCRIPTION_PRICE,'period_end'=>$end?->format('Y-m-d H:i:s')],$decision==='approved'?'Administrator confirmed receipt of payment':mb_substr($note,0,250),'subscription-review-'.$id,hash('sha256',$decision.':'.$id));
    require_once __DIR__.'/notifications.php';
    $type=$decision==='rejected'?'subscription_rejected':($renewal?'subscription_renewed':'subscription_approved');
    notification_enqueue($db,$type,'subscription-decision:'.$id,['user_id'=>$owner,'subscription_payment_id'=>$id]);
    return ['replayed'=>false,'status'=>$decision,'period_end'=>$end?->format('Y-m-d H:i:s')];
}
function subscription_save_method(PDO $db,int $actor,int $id,array $input,?array $qr=null): array
{
    require_once __DIR__.'/subscription_methods.php';
    return subscription_method_save($db,$actor,$id,$input,$qr);
}
function subscription_proof(PDO $db,array $user,int $id): array
{
    $row=subscription_query($db,'SELECT user_id,proof_name,proof_mime,proof_bytes FROM subscription_payments WHERE submission_id=?',[$id])->fetch();
    if (!$row) { throw new InvalidArgumentException('Payment proof not found.'); }
    if ((int)$row['user_id']!==(int)$user['user_id']) { require_once __DIR__.'/admin.php'; admin_authorize($db,(int)$user['user_id'],'users.manage'); }
    if (!preg_match('/\A[a-f0-9]{64}\.bin\z/',$row['proof_name'])) { throw new RuntimeException('Payment proof is unavailable.'); }
    $root=subscription_storage(); $path=realpath($root.'/'.$row['proof_name']);
    if (!$path || dirname($path)!==$root || !is_file($path)) { throw new InvalidArgumentException('Payment proof is unavailable.'); }
    return $row+['path'=>$path];
}
function subscription_generate_reminders(PDO $db,?DateTimeImmutable $now=null): int
{
    if (!subscription_enabled() || !subscription_schema($db)) { return 0; }
    require_once __DIR__.'/notifications.php'; $now=($now??new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->setTimezone(new DateTimeZone('Asia/Manila')); $count=0;
    foreach (['subscription_expiring'=>$now->modify('+3 days')->format('Y-m-d'),'subscription_expired'=>$now->format('Y-m-d')] as $type=>$date) {
        $operator=$type==='subscription_expired'?'<=':'=';
        $rows=subscription_query($db,"SELECT s.user_id,s.expires_at FROM owner_subscriptions s JOIN users u ON u.user_id=s.user_id WHERE u.account_status='active' AND DATE(s.expires_at) $operator ?".($type==='subscription_expired'?' AND s.expires_at<=?':''),$type==='subscription_expired'?[$date,$now->format('Y-m-d H:i:s')]:[$date])->fetchAll();
        foreach ($rows as $row) { if (notification_enqueue($db,$type,'subscription-reminder:'.$row['user_id'].':'.$row['expires_at'].':'.$type,['user_id'=>$row['user_id']])) { $count++; } }
    }
    return $count;
}
function subscription_notification(PDO $db,array $job): array
{
    if (!subscription_enabled()) { throw new DomainException('subscription_rollout_disabled'); }
    $user=subscription_query($db,"SELECT u.full_name,u.email,u.account_status FROM users u JOIN roles r ON r.role_id=u.role_id WHERE u.user_id=? AND r.role_type='owner'",[$job['user_id']])->fetch();
    if (!$user || $user['account_status']!=='active') { throw new DomainException('subscription_account_ineligible'); }
    $data=['name'=>$user['full_name'],'amount'=>SUBSCRIPTION_PRICE];
    if ($job['subscription_payment_id']) {
        $p=subscription_query($db,'SELECT * FROM subscription_payments WHERE submission_id=? AND user_id=?',[$job['subscription_payment_id'],$job['user_id']])->fetch();
        $expected=match($job['notification_type']) { 'subscription_submitted'=>'pending','subscription_rejected'=>'rejected',default=>'approved' };
        if (!$p || $p['status']!==$expected) { throw new DomainException('subscription_notice_obsolete'); }
        $data+=['submitted_at'=>$p['submitted_at'],'period_start'=>$p['period_start'],'period_end'=>$p['period_end'],'reason'=>$p['review_note']];
    } else {
        $s=subscription_state($db,(int)$job['user_id']); $end=$s['expires_at'];
        if (!$end || !hash_equals($job['event_key'],hash('sha256','subscription-reminder:'.$job['user_id'].':'.$end.':'.$job['notification_type'])) || ($job['notification_type']==='subscription_expiring' && !$s['active']) || ($job['notification_type']==='subscription_expired' && $s['active'])) { throw new DomainException('subscription_notice_obsolete'); }
        $data['period_end']=$end;
    }
    return ['data'=>$data,'email'=>$user['email'],'name'=>$user['full_name']];
}
