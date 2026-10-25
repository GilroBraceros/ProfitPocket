<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/subscriptions.php';
require_once __DIR__.'/../includes/admin.php';
require_once __DIR__.'/../includes/email_templates.php';
require_once __DIR__.'/../includes/subscription_methods.php'; subscription_preview_headers();
$user=require_permission('users.manage'); $db=db(); $permissions=admin_permissions($db,(int)$user['role_id']);
$module='subscriptions'; $active_page='admin'; $subscription_page=true; $dashboard_layout=true; $page_title='Subscription Management';
$error=null; $notice=take_flash(); $rows=[]; $metrics=[]; $detail=null; $editMethod=null; $methods=[]; $pages=1; $page=1;
$_SESSION['subscription_method_key']??=bin2hex(random_bytes(32));
$qrUpload=null;$qrCommitted=false;$previousQr=null;
try {
    if (!subscription_schema($db)) { throw new RuntimeException('The subscription migration must be applied first.'); }
    if (isset($_GET['view'])) {
        $id=filter_var($_GET['view'],FILTER_VALIDATE_INT);
        $detail=subscription_query($db,'SELECT p.*,u.full_name,u.email,u.account_status,b.business_name,a.full_name AS reviewer FROM subscription_payments p JOIN users u ON u.user_id=p.user_id LEFT JOIN businesses b ON b.owner_user_id=u.user_id LEFT JOIN users a ON a.user_id=p.reviewed_by WHERE p.submission_id=?',[$id?:0])->fetch();
        if (!$detail) { throw new InvalidArgumentException('Payment submission not found.'); }
    }
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        if (!valid_csrf()) { http_response_code(403); throw new InvalidArgumentException('This form expired. Reload and try again.'); }
        $action=post_text('action'); $db->beginTransaction();
        if ($action==='method') {
            if (!hash_equals($_SESSION['subscription_method_key'],post_text('request_key'))) { throw new InvalidArgumentException('This payment-method form was already used. Reload before saving.'); }
            $qrUpload=subscription_store_qr($_FILES['qr_image']??[]);
            $savedMethod=subscription_save_method($db,(int)$user['user_id'],(int)post_text('method_id'),$_POST,$qrUpload);$previousQr=$savedMethod['previous_qr'];
        } elseif (in_array($action,['method_activate','method_deactivate','method_remove'],true)) {
            if(!hash_equals($_SESSION['subscription_method_key'],post_text('request_key')) || post_text('confirm_method')!=='1'){throw new InvalidArgumentException('Confirm this payment-method change using a fresh form.');}
            $previousQr=subscription_method_action($db,(int)$user['user_id'],(int)post_text('method_id'),substr($action,7));
        } elseif ($action==='review') {
            if (post_text('confirm')!=='1') { throw new InvalidArgumentException('Confirm your payment review decision.'); }
            subscription_review($db,(int)$user['user_id'],(int)post_text('submission_id'),post_text('decision'),post_text('review_note'),post_text('payment_verified')==='1');
        } else { throw new InvalidArgumentException('Choose a valid subscription action.'); }
        $db->commit();$qrCommitted=true;
        if($previousQr){try{subscription_cleanup_qr($db,$previousQr);}catch(Throwable $e){error_log('ProfitPocket: old QR retained for cleanup.');}}
        if (str_starts_with($action,'method')) { $_SESSION['subscription_method_key']=bin2hex(random_bytes(32)); }
        flash(str_starts_with($action,'method')?'Payment method updated.':'Payment review saved.'); redirect_to('admin/subscriptions.php');
    }
    $q=is_string($_GET['q']??null)?trim($_GET['q']):''; $filter=is_string($_GET['state']??null)?$_GET['state']:'pending';
    if (mb_strlen($q)>100 || !in_array($filter,['all','pending','approved','rejected'],true)) { throw new InvalidArgumentException('Choose valid search and status filters.'); }
    $page=filter_var($_GET['page']??1,FILTER_VALIDATE_INT); if (!$page || $page<1) { throw new InvalidArgumentException('Choose a valid page.'); }
    $metrics=subscription_query($db,"SELECT COUNT(*) AS owners,COALESCE(SUM(u.account_status='active' AND s.expires_at>CURRENT_TIMESTAMP AND s.started_at<=CURRENT_TIMESTAMP),0) AS active,
        COALESCE(SUM(s.expires_at<=CURRENT_TIMESTAMP),0) AS expired,
        COALESCE(SUM(s.expires_at IS NULL AND NOT EXISTS(SELECT 1 FROM subscription_payments p WHERE p.user_id=u.user_id AND p.status='pending')),0) AS pending_payment
        FROM users u JOIN roles r ON r.role_id=u.role_id LEFT JOIN owner_subscriptions s ON s.user_id=u.user_id WHERE r.role_type='owner'")->fetch();
    $paymentMetrics=subscription_query($db,"SELECT COALESCE(SUM(status='pending'),0) AS pending,COALESCE(SUM(status='rejected'),0) AS rejected,COALESCE(SUM(CASE WHEN status='approved' THEN amount ELSE 0 END),0.00) AS revenue FROM subscription_payments")->fetch();
    $metrics+=$paymentMetrics;
    $source=' FROM subscription_payments p JOIN users u ON u.user_id=p.user_id LEFT JOIN businesses b ON b.owner_user_id=u.user_id WHERE 1=1'; $values=[];
    if ($filter!=='all') { $source.=' AND p.status=?'; $values[]=$filter; }
    if ($q!=='') { $source.=' AND (LOCATE(?,u.full_name)>0 OR LOCATE(?,u.email)>0 OR LOCATE(?,COALESCE(b.business_name,\'\'))>0)'; array_push($values,$q,$q,$q); }
    $count=(int)subscription_query($db,'SELECT COUNT(*)'.$source,$values)->fetchColumn(); $pages=max(1,(int)ceil($count/15)); $page=min($page,$pages);
    $rows=subscription_query($db,'SELECT p.submission_id,p.amount,p.reference_number,p.submitted_at,p.status,p.method_snapshot,u.full_name,u.email,b.business_name'.$source.' ORDER BY p.submission_id DESC LIMIT 15 OFFSET '.(($page-1)*15),$values)->fetchAll();
    if (admin_can($permissions,'settings.manage')) {
        $methods=subscription_methods($db,true);
        if (isset($_GET['edit_method'])) { foreach ($methods as $method) { if ((string)$method['method_id']===(string)$_GET['edit_method']) { $editMethod=$method; } } if (!$editMethod) { throw new InvalidArgumentException('Payment method not found.'); } }
    }
} catch (InvalidArgumentException $e) { $error=$e->getMessage(); if ($db->inTransaction()) {$db->rollBack();} }
catch (AdminDenied $e) { http_response_code(403); $error='Your role cannot make this change.'; if ($db->inTransaction()) {$db->rollBack();} }
catch (Throwable $e) { http_response_code(503); $error='Subscription management is temporarily unavailable. Check the approved migration and private configuration.'; if ($db->inTransaction()) {$db->rollBack();} error_log('ProfitPocket: subscription administration unavailable.'); }
if ($_SERVER['REQUEST_METHOD']==='POST' && post_text('action')==='method' && $error) { $editMethod=['method_id'=>post_text('method_id'),'method_name'=>post_text('method_name'),'recipient_name'=>post_text('recipient_name'),'account_identifier'=>post_text('account_identifier'),'instructions'=>post_text('instructions'),'is_enabled'=>post_text('is_enabled')==='1']; }
if($qrUpload && !$qrCommitted && is_file($qrUpload['path'])){unlink($qrUpload['path']);}
require __DIR__.'/../includes/header.php'; require __DIR__.'/../includes/admin_navbar.php';
?>
<main class="dashboard-main admin-main" id="dashboard-content"><div class="dashboard-heading"><div><h1>Subscription Management</h1><p>Verify actual received payments before granting monthly business access.</p></div></div>
<?php if ($notice): ?><div class="alert alert-success" role="status"><?= e($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if ($metrics): ?><div class="subscription-metrics"><?php foreach (['owners'=>'Registered owners','active'=>'Active subscriptions','pending_payment'=>'Awaiting first payment','pending'=>'Pending verification','expired'=>'Expired subscriptions','rejected'=>'Rejected submissions','revenue'=>'Verified subscription revenue'] as $key=>$label): ?><article><span><?= e($label) ?></span><strong><?= e($key==='revenue'?email_money((string)$metrics[$key]):(string)$metrics[$key]) ?></strong></article><?php endforeach; ?></div><?php endif; ?>
<?php if ($detail): $snapshot=json_decode($detail['method_snapshot'],true); ?>
<section class="subscription-card" id="payment-review"><div class="d-flex flex-wrap justify-content-between gap-2 mb-3"><h2>Review submission #<?= e($detail['submission_id']) ?></h2><span class="subscription-status" data-state="<?= e($detail['status']) ?>"><?= e(subscription_label($detail['status'])) ?></span></div>
<div class="subscription-columns"><div><dl class="subscription-facts"><?php foreach (['Owner'=>$detail['full_name'],'Business'=>$detail['business_name']??'Not linked','Email'=>$detail['email'],'Account status'=>$detail['account_status'],'Amount'=>email_money($detail['amount']),'Method'=>$snapshot['method_name'].(!empty($snapshot['bank_name'])?' · '.$snapshot['bank_name']:''),'Recipient'=>$snapshot['recipient_name'],'Receiving account'=>$snapshot['account_identifier'],'Reference'=>$detail['reference_number']?:'Not provided','Submitted'=>email_date($detail['submitted_at'])] as $label=>$value): ?><div><dt><?= e($label) ?></dt><dd><?= e($value) ?></dd></div><?php endforeach; ?></dl><a class="btn btn-outline-secondary mt-3 subscription-proof-link" href="<?= e(app_url('user/subscription_proof.php?id='.$detail['submission_id'])) ?>"><?= dashboard_icon('journal-text') ?> Download private proof</a><p class="form-text">Check this proof against the actual receiving account. A screenshot alone does not establish receipt.</p></div><div>
<?php if ($detail['status']==='pending'): ?><form method="post" action="<?= e(app_url('admin/subscriptions.php?view='.$detail['submission_id'])) ?>" data-subscription-form><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="review"><input type="hidden" name="submission_id" value="<?= e($detail['submission_id']) ?>"><label class="form-label" for="review_note">Review remarks / rejection reason</label><textarea class="form-control mb-3" id="review_note" name="review_note" maxlength="1000" rows="4"><?= e(post_text('review_note')) ?></textarea><p class="form-text">A clear reason is required when rejecting a submission.</p><label class="d-flex gap-2 mb-3"><input class="form-check-input" type="checkbox" name="payment_verified" value="1"> I verified the actual ₱100 payment in the receiving account.</label><label class="d-flex gap-2 mb-3"><input class="form-check-input" type="checkbox" name="confirm" value="1" required> I confirm this review decision.</label><div class="d-flex gap-2 flex-wrap"><button class="btn btn-primary" type="submit" name="decision" value="approved">Approve and activate</button><button class="btn btn-outline-danger" type="submit" name="decision" value="rejected">Reject submission</button></div><p class="form-text mt-2" data-submit-status aria-live="polite"></p></form>
<?php else: ?><h3>Review completed</h3><p class="small">Reviewed by <?= e($detail['reviewer']) ?> on <?= e(email_date($detail['reviewed_at'])) ?>.</p><p class="small"><?= nl2br(e($detail['review_note']?:'Payment verified.')) ?></p><?php if ($detail['period_end']): ?><p class="small">Access added: <?= e(email_date($detail['period_start'])) ?> → <?= e(email_date($detail['period_end'])) ?></p><?php endif; ?><?php endif; ?>
</div></div></section>
<?php endif; ?>
<section class="subscription-card"><h2>Payment verification</h2><form class="subscription-filter" method="get"><div><label for="q">Search owner, business or email</label><input class="form-control" id="q" name="q" maxlength="100" value="<?= e($q??'') ?>"></div><div><label for="state">Submission status</label><select class="form-select" id="state" name="state"><?php foreach (['pending'=>'Pending verification','approved'=>'Approved','rejected'=>'Rejected','all'=>'All statuses'] as $value=>$label): ?><option value="<?= e($value) ?>" <?= ($filter??'pending')===$value?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></div><button class="btn btn-outline-secondary">Apply filters</button></form>
<div class="table-responsive"><table class="table subscription-table"><thead><tr><th>Submission / date</th><th>Owner / business</th><th>Method / reference</th><th class="amount">Amount</th><th>Status</th><th>Action</th></tr></thead><tbody><?php foreach ($rows as $row): $snapshot=json_decode($row['method_snapshot'],true); ?><tr><td>#<?= e($row['submission_id']) ?><small><?= e(email_date($row['submitted_at'])) ?></small></td><td><?= e($row['full_name']) ?><small><?= e($row['business_name']??'No linked business') ?></small><small><?= e($row['email']) ?></small></td><td><?= e($snapshot['method_name']) ?><small><?= e($row['reference_number']?:'No reference supplied') ?></small></td><td class="amount"><?= e(email_money($row['amount'])) ?></td><td><span class="subscription-status" data-state="<?= e($row['status']) ?>"><?= e(subscription_label($row['status'])) ?></span></td><td><a class="record-action-view" href="?<?= e(http_build_query(['view'=>$row['submission_id'],'state'=>$filter??'pending','q'=>$q??''])) ?>#payment-review">View</a></td></tr><?php endforeach; ?><?php if (!$rows): ?><tr><td colspan="6" class="text-center text-secondary py-4">No matching payment submissions.</td></tr><?php endif; ?></tbody></table></div><nav class="d-flex justify-content-end gap-3 align-items-center flex-wrap" aria-label="Subscription submission pages"><?php if ($page>1): ?><a href="?<?= e(http_build_query(['page'=>$page-1,'state'=>$filter,'q'=>$q])) ?>">Previous</a><?php endif; ?><span class="small">Page <?= e($page) ?> of <?= e($pages) ?></span><?php if ($page<$pages): ?><a href="?<?= e(http_build_query(['page'=>$page+1,'state'=>$filter,'q'=>$q])) ?>">Next</a><?php endif; ?></nav></section>
<?php if(admin_can($permissions,'settings.manage') && subscription_schema($db)){require __DIR__.'/../includes/subscription_method_management.php';} ?>
</main><script src="<?= e(app_url('assets/js/subscriptions.js')) ?>" defer></script><?php require __DIR__.'/../includes/footer.php'; ?>
