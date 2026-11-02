<?php
// This view is included only by the authenticated users.manage page.
if (!isset($user,$db,$permissions)) {return;}
$emailCheck=null;$emailCheckFailed=false;$approvalMail=null;
try {
    if (admin_can($permissions,'settings.manage')) {$emailCheck=email_diagnostic_status($db,email_config());}
    if ($detail && $detail['status']==='approved') {$approvalMail=subscription_email_status($db,(int)$detail['submission_id']);}
} catch (Throwable $e) {$emailCheckFailed=true;}
?>
<?php if ($emailCheck || $emailCheckFailed || ($detail && $detail['status']==='approved')): ?>
<section class="subscription-card" id="subscription-email-status" aria-labelledby="subscription-email-title">
    <h2 id="subscription-email-title">Email notifications</h2>
    <?php if ($emailCheckFailed): ?><p role="status">Email status could not be checked. Review the private email configuration and notification schema.</p><?php endif; ?>
    <?php if ($emailCheck): ?>
        <dl class="subscription-facts">
            <div><dt>Sending on this server</dt><dd><?= $emailCheck['sending_enabled']?'Enabled':'Disabled' ?></dd></div>
            <div><dt>Brevo configuration</dt><dd><?= $emailCheck['configuration_valid']?'Required sending settings are present':'Incomplete or invalid' ?></dd></div>
            <div><dt>Email queue tables</dt><dd><?= $emailCheck['notification_schema']?'Available':'Missing' ?></dd></div>
            <div><dt>PHP HTTPS client</dt><dd><?= $emailCheck['curl']?'Available':'cURL extension missing' ?></dd></div>
        </dl>
        <?php if ($emailCheck['missing_settings']): ?><p class="form-text">Missing private settings: <?= e(implode(', ',$emailCheck['missing_settings'])) ?>. Configure these outside public_html; never put the API key in GitHub.</p><?php endif; ?>
        <p class="form-text">Future payment-review emails are attempted after the review is committed. Scheduled reminders and deferred retries still need the notification cron job. Provider acceptance does not confirm inbox delivery.</p>
    <?php endif; ?>
    <?php if ($detail && $detail['status']==='approved'): ?>
        <h3>Approval email for submission #<?= e($detail['submission_id']) ?></h3>
        <p role="status"><?= e(subscription_email_message($approvalMail['status']??'not_queued')) ?></p>
        <?php if (!empty($approvalMail['failure_code'])): ?><p class="form-text">Delivery code: <?= e($approvalMail['failure_code']) ?></p><?php endif; ?>
        <?php $safeSkipped=$approvalMail && $approvalMail['status']==='skipped' && (int)$approvalMail['attempts']===0 && !$approvalMail['first_attempt_at'] && in_array($approvalMail['failure_code'],['configuration_missing','subscription_rollout_disabled'],true); ?>
        <?php if (!$emailCheckFailed && (!$approvalMail || $approvalMail['status']==='pending' || $safeSkipped)): ?>
        <form method="post" action="<?= e(app_url('admin/subscriptions.php?view='.$detail['submission_id'])) ?>" data-subscription-form>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="send_approval_email">
            <input type="hidden" name="submission_id" value="<?= e($detail['submission_id']) ?>">
            <label class="d-flex align-items-start gap-2 mb-3"><input class="form-check-input" type="checkbox" name="confirm_email" value="1" required> Send the approval notification to this account's registered email address.</label>
            <button class="btn btn-primary" type="submit" <?= $emailCheck && (!$emailCheck['sending_enabled'] || !$emailCheck['configuration_valid'])?'disabled':'' ?>>Send approval email</button>
            <p class="form-text mt-2 mb-0" data-submit-status aria-live="polite"></p>
        </form>
        <?php endif; ?>
    <?php endif; ?>
</section>
<?php endif; ?>
