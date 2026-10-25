<?php
declare(strict_types=1);
require_once __DIR__.'/auth.php';
require_once __DIR__.'/notifications.php';

function password_input_errors(string $password,string $confirmation): ?string
{
    if (strlen($password)<12 || strlen($password)>72 || str_contains($password,"\0") || $password!==$confirmation) { return 'Passwords must match and contain 12–72 bytes.'; }
    return null;
}
function password_request_reset(PDO $db,string $email,?array $config=null): void
{
    $config??=email_config();
    if (!email_ready($config) || !preg_match('/\A[a-f0-9]{64}\z/',(string)$config['PROFITPOCKET_NOTIFICATION_SECRET'])) { return; }
    if ($db->inTransaction()) { throw new LogicException('Reset requests own their transaction.'); }
    $lock='pp-reset:'.substr(hash('sha256',$db->query('SELECT DATABASE()')->fetchColumn()),0,32);
    $s=$db->prepare('SELECT GET_LOCK(?,0)'); $s->execute([$lock]); if (!(int)$s->fetchColumn()) { return; }
    try {
        $db->beginTransaction();
        $ip=auth_ip_key(); $emailHash=hash('sha256',mb_strtolower(trim($email)));
        $s=$db->prepare("SELECT COUNT(*) FROM activity_logs WHERE action_code='AUTH_RESET_REQUESTED' AND details=? AND occurred_at>=DATE_SUB(CURRENT_TIMESTAMP,INTERVAL 15 MINUTE)"); $s->execute([$ip]); $limited=(int)$s->fetchColumn()>=10;
        if (!$limited) { $db->prepare("INSERT INTO activity_logs (action_code,entity_type,details,after_data) VALUES ('AUTH_RESET_REQUESTED','authentication',?,?)")->execute([$ip,json_encode(['email_hash'=>$emailHash])]); }
        $s=$db->prepare("SELECT user_id,email FROM users WHERE email=? AND account_status='active' FOR UPDATE"); $s->execute([mb_strtolower(trim($email))]); $user=$s->fetch();
        if ($user && !$limited && filter_var($email,FILTER_VALIDATE_EMAIL)) {
            $s=$db->prepare('SELECT COUNT(*) FROM password_reset_tokens WHERE user_id=? AND created_at>=DATE_SUB(CURRENT_TIMESTAMP,INTERVAL 15 MINUTE)'); $s->execute([$user['user_id']]);
            if ((int)$s->fetchColumn()<3) {
                $nonce=bin2hex(random_bytes(32)); $token=notification_reset_token($nonce,$config);
                $db->prepare('UPDATE password_reset_tokens SET used_at=CURRENT_TIMESTAMP WHERE user_id=? AND used_at IS NULL')->execute([$user['user_id']]);
                $db->prepare('INSERT INTO password_reset_tokens (user_id,token_hash,expires_at) VALUES (?,?,DATE_ADD(CURRENT_TIMESTAMP,INTERVAL 30 MINUTE))')->execute([$user['user_id'],hash('sha256',$token)]); $reset=(int)$db->lastInsertId();
                if (!notification_enqueue($db,'password_reset','reset:'.$reset,['user_id'=>$user['user_id'],'reset_id'=>$reset,'reset_nonce'=>$nonce,'recipient_email'=>$user['email']],$config)) { throw new RuntimeException('Reset queue unavailable.'); }
            }
        }
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) { $db->rollBack(); } error_log('ProfitPocket email: reset_request_unavailable'); }
    finally { $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]); }
}
function password_complete_reset(PDO $db,string $token,string $password,string $confirmation,?array $config=null): void
{
    if ($error=password_input_errors($password,$confirmation)) { throw new InvalidArgumentException($error); }
    if (!preg_match('/\A[a-f0-9]{64}\z/',$token)) { throw new InvalidArgumentException('This reset code is invalid or expired. Request another link.'); }
    if (!$db->inTransaction()) { throw new LogicException('Password resets require a transaction.'); }
    // Lock the user first, matching request/password-change lock order.
    $s=$db->prepare('SELECT user_id FROM password_reset_tokens WHERE token_hash=?'); $s->execute([hash('sha256',$token)]); $id=$s->fetchColumn();
    $s=$db->prepare("SELECT user_id,email FROM users WHERE user_id=? AND account_status='active' FOR UPDATE"); $s->execute([$id?:0]); $user=$s->fetch();
    $s=$db->prepare('SELECT reset_id FROM password_reset_tokens WHERE user_id=? AND token_hash=? AND used_at IS NULL AND expires_at>CURRENT_TIMESTAMP FOR UPDATE'); $s->execute([$id?:0,hash('sha256',$token)]); $reset=$s->fetchColumn();
    if (!$user || !$reset) { throw new InvalidArgumentException('This reset code is invalid or expired. Request another link.'); }
    $db->prepare('UPDATE users SET password_hash=? WHERE user_id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$id]);
    require_once __DIR__.'/deployment.php'; bootstrap_password_changed($db,(int)$id);
    $db->prepare('UPDATE password_reset_tokens SET used_at=CURRENT_TIMESTAMP WHERE user_id=? AND used_at IS NULL')->execute([$id]);
    $db->prepare('UPDATE auth_sessions SET revoked_at=CURRENT_TIMESTAMP WHERE user_id=? AND revoked_at IS NULL')->execute([$id]);
    notification_enqueue($db,'password_changed','password-reset-complete:'.$reset,['user_id'=>$id,'recipient_email'=>$user['email']],$config);
}
function password_authenticated_change(PDO $db,int $id,string $current,string $password,string $confirmation,string $key): void
{
    if (!$db->inTransaction()) { throw new LogicException('Password changes require a transaction.'); }
    if ($error=password_input_errors($password,$confirmation)) { throw new InvalidArgumentException($error); }
    $s=$db->prepare("SELECT password_hash,email FROM users WHERE user_id=? AND account_status='active' FOR UPDATE"); $s->execute([$id]); $user=$s->fetch();
    if (!$user || strlen($current)>72 || !password_verify($current,$user['password_hash'])) { throw new InvalidArgumentException('Enter your correct current password.'); }
    require_once __DIR__.'/deployment.php';
    if (bootstrap_needs_password_change($db,$id) && password_verify($password,$user['password_hash'])) { throw new InvalidArgumentException('Choose a different password from the initial administrator password.'); }
    $db->prepare('UPDATE users SET password_hash=? WHERE user_id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$id]);
    bootstrap_password_changed($db,$id);
    $db->prepare('UPDATE password_reset_tokens SET used_at=CURRENT_TIMESTAMP WHERE user_id=? AND used_at IS NULL')->execute([$id]);
    $db->prepare('UPDATE auth_sessions SET revoked_at=CURRENT_TIMESTAMP WHERE user_id=? AND revoked_at IS NULL')->execute([$id]);
    notification_enqueue($db,'password_changed','password-change:'.$id.':'.$key,['user_id'=>$id,'recipient_email'=>$user['email']]);
}
