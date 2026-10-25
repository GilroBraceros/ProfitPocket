<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){ require_once __DIR__.'/auth.php'; }
require_once __DIR__.'/subscription_methods.php';
const ONBOARDING_SECONDS=3600;
function registration_current(PDO $db): ?array
{
    $token=$_SESSION['onboarding_token']??($_SESSION['registration_pending_token']??'');
    if (!is_string($token) || !preg_match('/\A[a-f0-9]{64}\z/',$token) || !registration_payment_schema($db)) { return null; }
    $row=subscription_query($db,"SELECT o.*,u.full_name,u.email,b.business_name FROM registration_onboarding o JOIN users u ON u.user_id=o.user_id JOIN roles r ON r.role_id=u.role_id JOIN businesses b ON b.owner_user_id=u.user_id WHERE o.token_hash=? AND o.expires_at>CURRENT_TIMESTAMP AND u.account_status='active' AND r.role_type='owner'",[hash('sha256',$token)])->fetch()?:null;
    if($row){$_SESSION['onboarding_token']=$token;}
    return $row;
}
function registration_create(PDO $db,array $values,string $password,string $key,string $token): int
{
    if (!$db->inTransaction() || !registration_payment_schema($db)) { throw new RuntimeException('Registration payment setup is unavailable.'); }
    if (!preg_match('/\A[a-f0-9]{64}\z/',$key) || !preg_match('/\A[a-f0-9]{64}\z/',$token)) { throw new InvalidArgumentException('Reload the registration form.'); }
    $existing=subscription_query($db,'SELECT user_id,token_hash FROM registration_onboarding WHERE request_key=? FOR UPDATE',[$key])->fetch();
    if ($existing) {
        if (!hash_equals($existing['token_hash'],hash('sha256',$token))) { throw new InvalidArgumentException('Sign in to resume your existing account.'); }
        return (int)$existing['user_id'];
    }
    $id=create_owner($db,$values,$password,false);
    subscription_query($db,'INSERT INTO registration_onboarding (user_id,token_hash,request_key,expires_at) VALUES (?,?,?,DATE_ADD(CURRENT_TIMESTAMP,INTERVAL ? SECOND))',[$id,hash('sha256',$token),$key,ONBOARDING_SECONDS]);
    return $id;
}
function registration_submit(PDO $db,string $token,array $input,array $proof,string $key): array
{
    $id=(int)subscription_query($db,'SELECT user_id FROM registration_onboarding WHERE token_hash=?',[hash('sha256',$token)])->fetchColumn();
    subscription_owner_lock($db,$id);
    $onboarding=subscription_query($db,'SELECT * FROM registration_onboarding WHERE token_hash=? AND expires_at>CURRENT_TIMESTAMP FOR UPDATE',[hash('sha256',$token)])->fetch();
    if (!$onboarding) { throw new InvalidArgumentException('Your registration session expired. Sign in to resume payment safely.'); }
    if ($onboarding['initial_submission_id']) {
        $old=subscription_query($db,'SELECT submission_id,request_key FROM subscription_payments WHERE submission_id=? AND user_id=?',[$onboarding['initial_submission_id'],$id])->fetch();
        if ($old && hash_equals($old['request_key'],$key)) { return ['id'=>(int)$old['submission_id'],'replayed'=>true]; }
        throw new InvalidArgumentException('Your initial payment was already submitted. Sign in to view its review status.');
    }
    return subscription_submit($db,$id,$input,$proof,$key);
}
function registration_complete_payment(PDO $db,int $owner,int $payment): bool
{
    if (!registration_payment_schema($db)) { return false; }
    $row=subscription_query($db,'SELECT onboarding_id,initial_submission_id FROM registration_onboarding WHERE user_id=? FOR UPDATE',[$owner])->fetch();
    if (!$row) { return false; }
    $first=!$row['initial_submission_id'];
    if(!$first && subscription_query($db,"SELECT 1 FROM subscription_payments WHERE user_id=? AND status='approved' LIMIT 1",[$owner])->fetchColumn()){return false;}
    if($first){subscription_query($db,'UPDATE registration_onboarding SET initial_submission_id=?,completed_at=CURRENT_TIMESTAMP WHERE onboarding_id=?',[$payment,$row['onboarding_id']]);}
    require_once __DIR__.'/notifications.php';
    try {
        if($first){notification_enqueue($db,'registration_payment_submitted','registration-payment:'.$payment,['user_id'=>$owner,'subscription_payment_id'=>$payment]);}
        $admins=subscription_query($db,"SELECT DISTINCT u.user_id FROM users u JOIN roles r ON r.role_id=u.role_id JOIN role_permissions rp ON rp.role_id=r.role_id JOIN permissions p ON p.permission_id=rp.permission_id WHERE r.role_type='admin' AND u.account_status='active' AND p.permission_code='users.manage'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($admins as $admin) { notification_enqueue($db,'subscription_admin_review','registration-review:'.$payment.':'.$admin,['user_id'=>$admin,'subscription_payment_id'=>$payment]); }
    } catch(Throwable $e) { error_log('ProfitPocket: registration notice queue unavailable.'); }
    return $first;
}
function registration_notification(PDO $db,array $job): array
{
    $payment=subscription_query($db,"SELECT p.submission_id,p.user_id,p.submitted_at,o.initial_submission_id,u.full_name AS owner_name,b.business_name FROM subscription_payments p JOIN registration_onboarding o ON o.user_id=p.user_id JOIN users u ON u.user_id=p.user_id JOIN businesses b ON b.owner_user_id=u.user_id WHERE p.submission_id=? AND p.status='pending' AND u.account_status='active'",[$job['subscription_payment_id']])->fetch();
    if (!$payment) { throw new DomainException('registration_notice_obsolete'); }
    if($job['notification_type']==='registration_payment_submitted' && (int)$payment['initial_submission_id']!==(int)$payment['submission_id']){throw new DomainException('registration_notice_obsolete');}
    $recipient=subscription_query($db,'SELECT u.full_name,u.email,u.account_status,r.role_type,u.role_id FROM users u JOIN roles r ON r.role_id=u.role_id WHERE u.user_id=?',[$job['user_id']])->fetch();
    if (!$recipient || $recipient['account_status']!=='active') { throw new DomainException('registration_recipient_ineligible'); }
    if ($job['notification_type']==='subscription_admin_review') {
        $allowed=$recipient['role_type']==='admin' && subscription_query($db,"SELECT 1 FROM role_permissions rp JOIN permissions p ON p.permission_id=rp.permission_id WHERE rp.role_id=? AND p.permission_code='users.manage'",[$recipient['role_id']])->fetchColumn();
        if (!$allowed) { throw new DomainException('admin_permission_revoked'); }
    } elseif ((int)$job['user_id']!==(int)$payment['user_id'] || $recipient['role_type']!=='owner') { throw new DomainException('registration_recipient_mismatch'); }
    return ['email'=>$recipient['email'],'name'=>$recipient['full_name'],'data'=>$payment+['name'=>$recipient['full_name'],'amount'=>SUBSCRIPTION_PRICE]];
}
