<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/subscriptions.php';
require_once __DIR__.'/../includes/email_templates.php';
require_once __DIR__.'/../includes/subscription_methods.php'; subscription_preview_headers();
$user=require_role('owner'); $page_title='Your subscription'; $active_page='subscription';
$db=db(); $error=null; $state=null; $methods=[]; $history=[]; $notice=take_flash();
$_SESSION['subscription_request_key']??=bin2hex(random_bytes(32));
try {
    if (!subscription_schema($db)) { throw new RuntimeException('Subscription deployment is not ready.'); }
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        if (!valid_csrf()) { http_response_code(403); throw new InvalidArgumentException('This form expired. Reload and try again.'); }
        $key=post_text('request_key');
        if (!hash_equals($_SESSION['subscription_request_key'],$key)) {
            if (subscription_query($db,'SELECT 1 FROM subscription_payments WHERE user_id=? AND request_key=?',[$user['user_id'],$key])->fetchColumn()) { flash('Your payment proof was already submitted.'); redirect_to('user/subscription.php'); }
            throw new InvalidArgumentException('Reload this form before submitting.');
        }
        subscription_receive_proof($db,(int)$user['user_id'],$_POST,$_FILES['proof']??[],$key);
        $_SESSION['subscription_request_key']=bin2hex(random_bytes(32));
        flash('Your payment proof has been submitted successfully and is awaiting administrator verification.'); redirect_to('user/subscription.php');
    }
    $state=subscription_state($db,(int)$user['user_id']); $methods=subscription_methods($db);
    $history=subscription_query($db,'SELECT submission_id,amount,status,submitted_at,reviewed_at,review_note,period_start,period_end FROM subscription_payments WHERE user_id=? ORDER BY submission_id DESC LIMIT 50',[$user['user_id']])->fetchAll();
} catch (InvalidArgumentException $e) { $error=$e->getMessage(); }
catch (Throwable $e) { http_response_code(503); $error='Subscription activation is temporarily unavailable. Contact support; your business records are preserved.'; error_log('ProfitPocket: subscription page unavailable.'); }
if (!$state && subscription_schema($db)) { $state=subscription_state($db,(int)$user['user_id']); $methods=subscription_methods($db); }
require __DIR__.'/../includes/header.php';
?>
<main class="subscription-shell">
<?php require __DIR__.'/../includes/subscription_navigation.php'; ?>
<div class="subscription-heading"><p class="eyebrow">YOUR PROFITPOCKET MEMBERSHIP</p><h1><?= !empty($state['expires_at'])?'Manage your subscription':'Activate your workspace' ?></h1><p>One monthly plan for your business. Manual payments, clear verification, and no automatic charges.</p></div>
<?php if ($notice): ?><div class="alert alert-success" role="status"><?= e($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if ($state): ?>
<div class="subscription-columns"><div>
<section class="subscription-card"><h2>ProfitPocket Monthly</h2><p class="subscription-price">₱100 <span>/ calendar month</span></p><span class="subscription-status" data-state="<?= e($state['state']) ?>"><?= e(subscription_label($state['state'])) ?></span>
<dl class="subscription-facts"><div><dt>Business owner</dt><dd><?= e($user['full_name']) ?></dd></div><div><dt>Current access begins</dt><dd><?= $state['started_at']?e(email_date($state['started_at'])):'After payment approval' ?></dd></div><div><dt>Access expires</dt><dd><?= $state['expires_at']?e(email_date($state['expires_at'])):'Not activated' ?></dd></div></dl>
<?php if ($state['active']): ?><a class="btn btn-primary mt-3" href="<?= e(app_url('user/dashboard.php')) ?>">Open business dashboard</a><?php else: ?><p class="form-text mt-3">Business tools open only after payment approval. Your existing records stay preserved.</p><?php endif; ?>
<?php if (($state['latest']['status']??'')==='rejected'): ?><div class="alert alert-warning mt-3"><strong>Payment review:</strong> <?= e($state['latest']['review_note']) ?> Please submit corrected proof below.</div><?php endif; ?>
</section>
<section class="subscription-card"><h2><?= $state['expires_at']?'Renew for one calendar month':'Submit payment proof' ?></h2>
<?php if (($state['latest']['status']??'')==='pending'): ?><div class="alert alert-info mb-0" role="status">Your payment proof has been submitted successfully and is awaiting administrator verification. You can check the decision here. No second submission is needed while it is pending.</div>
<?php elseif (!$methods): ?><p class="text-secondary">Payment instructions have not been configured. Contact the administrator before paying. Do not send money to an unlisted account.</p>
<?php else: ?>
<p class="form-text">Pay exactly ₱100 to one of the accounts listed below. Then upload proof for administrator review. An early renewal extends your current expiry; an expired period restarts on approval.</p>
<?php $paymentAction=app_url('user/subscription.php');$paymentKey=$_SESSION['subscription_request_key'];$paymentButton='Submit Payment';require __DIR__.'/../includes/subscription_payment_form.php'; ?>
<?php endif; ?></section>
</div><aside><section class="subscription-card"><h2>What happens next?</h2><ol class="ps-3 small lh-lg"><li>Pay ₱100 using the configured account details.</li><li>Submit the actual proof of your payment.</li><li>An administrator checks the receiving account.</li><li>Once approved, use all business tools for one calendar month.</li></ol><p class="form-text mb-0">Verification depends on administrator review. Contact support if you need an update.</p></section><section class="subscription-card"><h2>Your business tools</h2><p class="small lh-lg">Dashboard · Sales · Expenses · Customers · Products · Payment monitoring · Financial reports</p><a href="<?= e(app_url('user/support.php')) ?>">Need help with your subscription?</a></section></aside></div>
<section class="subscription-card"><h2>Payment submission history</h2><div class="table-responsive"><table class="table subscription-table"><thead><tr><th>Submission</th><th>Status</th><th>Review / access period</th><th class="amount">Amount</th><th>Proof</th></tr></thead><tbody><?php foreach ($history as $row): ?><tr><td>#<?= e($row['submission_id']) ?><small><?= e(email_date($row['submitted_at'])) ?></small></td><td><span class="subscription-status" data-state="<?= e($row['status']) ?>"><?= e(subscription_label($row['status'])) ?></span></td><td><?= e($row['review_note']?:'Awaiting review') ?><?php if ($row['period_end']): ?><small><?= e(email_date($row['period_start'])) ?> → <?= e(email_date($row['period_end'])) ?></small><?php endif; ?></td><td class="amount"><?= e(email_money($row['amount'])) ?></td><td><a href="<?= e(app_url('user/subscription_proof.php?id='.$row['submission_id'])) ?>">Download</a></td></tr><?php endforeach; ?><?php if (!$history): ?><tr><td colspan="5" class="text-center text-secondary py-4">No payment proof submitted yet.</td></tr><?php endif; ?></tbody></table></div></section>
<?php endif; ?>
</main><script src="<?= e(app_url('assets/js/subscriptions.js')) ?>" defer></script><?php require __DIR__.'/../includes/footer.php'; ?>
