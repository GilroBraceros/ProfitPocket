<?php
declare(strict_types=1);
require_once __DIR__.'/subscriptions.php';
require_once __DIR__.'/notifications.php';
require_once __DIR__.'/email_diagnostics.php';
require_once __DIR__.'/admin.php';

function subscription_email_status(PDO $db,int $submission): ?array
{
    return subscription_query($db,'SELECT notification_id,status,failure_code,attempts,accepted_at,first_attempt_at FROM notification_outbox WHERE event_key=?',[hash('sha256','subscription-decision:'.$submission)])->fetch()?:null;
}
function subscription_email_message(string $status): string
{
    return match ($status) {
        'accepted'=>'Brevo accepted the subscription email. Inbox delivery is not yet confirmed.',
        'disabled'=>'Email sending is disabled or its private Brevo settings are incomplete on this server.',
        'pending','retry','busy'=>'The email is queued. Delivery is pending; the notification worker handles retries.',
        'not_queued'=>'No approval email was queued. Check the email configuration and notification queue.',
        'failed'=>'The email attempt failed. Check its delivery status and the private Brevo configuration.',
        'uncertain'=>'The delivery outcome is uncertain. It will not be resent automatically.',
        'skipped'=>'The email was skipped. Check the recipient and delivery status.',
        default=>'Email delivery is not confirmed.',
    };
}
function subscription_deliver_review_email(PDO $db,?int $notification): string
{
    try {return $notification?notification_process_one($db,$notification):(email_ready(email_config())?'not_queued':'disabled');}
    catch (Throwable $e) {error_log('ProfitPocket: subscription email delivery unavailable.');return 'not_queued';}
}
function subscription_request_approval_email(PDO $db,int $actor,int $submission): string
{
    if ($db->inTransaction()) {throw new LogicException('Email requests must own their transaction.');}
    admin_authorize($db,$actor,'users.manage');
    $config=email_config();
    if (!email_ready($config)) {return 'disabled';}
    $db->beginTransaction();
    try {
        admin_authorize($db,$actor,'users.manage',true);
        $payment=subscription_query($db,'SELECT * FROM subscription_payments WHERE submission_id=? FOR UPDATE',[$submission])->fetch();
        if (!$payment || $payment['status']!=='approved') {throw new InvalidArgumentException('Only an approved payment can send an approval email.');}
        $job=subscription_email_status($db,$submission);
        // These skipped states have never called Brevo and can safely be queued
        // after the configuration or old rollout restriction is corrected.
        if ($job && $job['status']==='skipped' && (int)$job['attempts']===0 && !$job['first_attempt_at'] && in_array($job['failure_code'],['configuration_missing','subscription_rollout_disabled'],true)) {
            subscription_query($db,"UPDATE notification_outbox SET status='pending',failure_code=NULL,available_at=CURRENT_TIMESTAMP WHERE notification_id=? AND status='skipped' AND attempts=0 AND first_attempt_at IS NULL",[$job['notification_id']]);
        }
        $notification=notification_enqueue($db,$payment['is_renewal']?'subscription_renewed':'subscription_approved','subscription-decision:'.$submission,['user_id'=>$payment['user_id'],'subscription_payment_id'=>$submission],$config);
        subscription_query($db,"INSERT INTO activity_logs(actor_user_id,action_code,entity_type,entity_id) VALUES(?,'SUBSCRIPTION_EMAIL_REQUESTED','platform',?)",[$actor,$submission]);
        $db->commit();
    } catch (Throwable $e) {if ($db->inTransaction()) {$db->rollBack();}throw $e;}
    return subscription_deliver_review_email($db,$notification);
}
