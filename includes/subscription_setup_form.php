<?php
// Included only by the permission-protected administrator subscription page.
if (!isset($user,$db,$permissions) || !admin_can($permissions,'settings.manage')) {return;}
$setupPlan=[];$setupPlanError=null;
try {$setupPlan=subscription_setup_plan($db);} catch (Throwable $e) {
    $setupPlanError=$e instanceof InvalidArgumentException?$e->getMessage():'The hosted schema could not be checked. Setup has not run.';
}
?>
<section class="subscription-card" aria-labelledby="subscription-install-title">
    <h2 id="subscription-install-title">Complete subscription database setup</h2>
    <p>Your deployed code needs the database additions below. Opening this page does not apply them.</p>
    <?php if ($setupPlanError): ?><div class="alert alert-warning" role="alert"><?= e($setupPlanError) ?></div>
    <?php else: ?>
        <?php if ($setupPlan): ?><ul><?php foreach ($setupPlan as $change): ?><li><?= e($change) ?></li><?php endforeach; ?></ul>
        <?php else: ?><p>The required tables and columns exist. Setup will verify and record migration completion.</p><?php endif; ?>
        <p>Existing owners will receive a Pending Payment record if they do not have a subscription. Existing payments and paid periods are preserved. This does not enable subscription enforcement, email sending, or receiving accounts.</p>
        <p>If subscription enforcement is already enabled, owners without paid access will need an approved payment to enter the paid business modules after setup.</p>
        <p class="form-text">MySQL structure changes commit separately. If setup stops partway through, report the diagnosis and retry after the cause is resolved; existing additions are preserved.</p>
        <form method="post" action="<?= e(app_url('admin/subscriptions.php')) ?>" data-subscription-form>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="setup_subscriptions">
            <input type="hidden" name="request_key" value="<?= e($_SESSION['subscription_setup_key']) ?>">
            <input type="hidden" name="plan_hash" value="<?= e(hash('sha256',json_encode($setupPlan,JSON_THROW_ON_ERROR))) ?>">
            <input type="hidden" name="owner_policy" value="pending-payment">
            <label class="d-flex align-items-start gap-2 mb-3"><input class="form-check-input" type="checkbox" name="approve_schema" value="1" required> I approve the listed database additions and the Pending Payment initialization policy.</label>
            <button class="btn btn-primary" type="submit">Apply reviewed subscription setup</button>
            <p class="form-text mt-2 mb-0" data-submit-status aria-live="polite"></p>
        </form>
    <?php endif; ?>
</section>
