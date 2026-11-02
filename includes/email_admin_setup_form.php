<?php
if (!isset($user,$db,$permissions) || !admin_can($permissions,'settings.manage')) {return;}
$emailForm=[];try {$emailForm=email_config();}catch(Throwable $e){}
$emailSecure=email_setup_https($_SERVER);
?>
<details class="mt-3" <?= empty($emailCheck['sending_enabled'])?'open':'' ?>>
    <summary class="fw-semibold">Configure hosted Brevo email</summary>
    <p class="form-text mt-2">Settings are saved privately on this server, outside the public website and GitHub. The API key is never shown again. Brevo must confirm an active sender before sending is enabled.</p>
    <?php if (!$emailSecure): ?><p class="alert alert-info">Open this page on https://profitpocket.online to configure or test hosted email.</p><?php endif; ?>
    <form method="post" action="<?= e(app_url('admin/subscriptions.php#subscription-email-status')) ?>" data-subscription-form>
        <fieldset <?= !$emailSecure?'disabled':'' ?>>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="configure_email">
            <div class="row g-3">
                <div class="col-12"><label class="form-label" for="brevo_api_key">Brevo API key</label><input class="form-control" type="password" id="brevo_api_key" name="brevo_api_key" maxlength="512" autocomplete="new-password" spellcheck="false" aria-describedby="brevo_key_help"><p class="form-text" id="brevo_key_help">Use a replacement key if an earlier key was shared in chat. Leave blank to keep an existing key. Do not paste it into chat or GitHub.</p></div>
                <div class="col-md-6"><label class="form-label" for="sender_email">Verified Brevo sender email</label><input class="form-control" type="email" id="sender_email" name="sender_email" value="<?= e($emailForm['BREVO_SENDER_EMAIL']??'') ?>" maxlength="191" autocomplete="email" required></div>
                <div class="col-md-6"><label class="form-label" for="sender_name">Sender name</label><input class="form-control" id="sender_name" name="sender_name" value="<?= e($emailForm['BREVO_SENDER_NAME']??'ProfitPocket') ?>" maxlength="100" required></div>
                <div class="col-12"><label class="form-label" for="reply_to_email">Reply-to email (optional)</label><input class="form-control" type="email" id="reply_to_email" name="reply_to_email" value="<?= e($emailForm['BREVO_REPLY_TO_EMAIL']??'') ?>" maxlength="191"></div>
            </div>
            <p class="form-text mt-3">The HTTPS application URL and an initial password-reset secret are configured automatically. An existing valid secret is preserved.</p>
            <label class="d-flex align-items-start gap-2 mb-3"><input class="form-check-input" type="checkbox" name="confirm_email_setup" value="1" required> I authorize this sender for the implemented ProfitPocket email notifications.</label>
            <button class="btn btn-primary" type="submit">Verify sender and enable email</button>
            <p class="form-text mt-2 mb-0" data-submit-status aria-live="polite"></p>
        </fieldset>
    </form>
</details>
<?php if ($emailSecure && !empty($emailCheck['sending_enabled']) && !empty($emailCheck['configuration_valid'])): ?>
<form class="mt-4" method="post" action="<?= e(app_url('admin/subscriptions.php#subscription-email-status')) ?>" data-subscription-form>
    <h3>Send one hosted test email</h3>
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="test_email">
    <label class="form-label" for="test_email">Administrator-controlled inbox</label><input class="form-control mb-3" type="email" id="test_email" name="test_email" maxlength="191" required>
    <label class="d-flex align-items-start gap-2 mb-3"><input class="form-check-input" type="checkbox" name="confirm_test_email" value="1" required> I control this inbox and authorize one real ProfitPocket test email.</label>
    <button class="btn btn-outline-primary" type="submit">Send test email</button><p class="form-text mt-2 mb-0" data-submit-status aria-live="polite"></p>
</form>
<?php endif; ?>
