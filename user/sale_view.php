<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/sales.php';
require_once __DIR__ . '/../includes/sales_ui.php';
$modal=sale_modal_request();
$user=require_business();
$connection=db();
$dashboard_layout=true;
$active_page='sales';
$page_title='Sale details';
$sale=null;
$error=null;
$notice=$modal?null:take_flash();
try {
    $id=sale_id($_SERVER['REQUEST_METHOD']==='POST'?sale_text($_POST,'sale_id'):sale_text($_GET,'id'));
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        if (!valid_csrf() || !sale_valid_token($_POST,'void',$id)) { http_response_code(403); throw new InvalidArgumentException('This form expired or is invalid. Reload the sale details.'); }
        $connection->beginTransaction();
        sale_void($connection,$user,$id,$_POST);
        $connection->commit();
        if ($modal) { sale_ui_json(true,'Sale voided. Its payment and refund history has been kept.',['sale_id'=>$id]); }
        flash('Sale voided. The record and any authorized refunds remain in its history.');
        redirect_to('user/sale_view.php?id='.$id);
    }
    $connection->beginTransaction();
    $sale=sale_read($connection,(int)$user['business_id'],$id);
    $connection->commit();
} catch (SaleNotFound $exception) {
    if ($connection->inTransaction()) { $connection->rollBack(); }
    http_response_code(404); $error='Sale not found.';
} catch (InvalidArgumentException $exception) {
    if ($connection->inTransaction()) { $connection->rollBack(); }
    if (http_response_code()===200) { http_response_code(400); }
    $error=$exception->getMessage();
    if (isset($id)) { try { $sale=sale_read($connection,(int)$user['business_id'],$id); } catch (SaleNotFound $ignored) { http_response_code(404); } }
} catch (Throwable $exception) {
    if ($connection->inTransaction()) { $connection->rollBack(); }
    error_log('ProfitPocket: sale details failed, code '.$exception->getCode());
    http_response_code(503); $error='Sale details are temporarily unavailable.';
}
if ($modal && $_SERVER['REQUEST_METHOD']==='POST') { sale_ui_json(false,$error??'The sale could not be voided.',['fields'=>sale_ui_errors($error??'',$_POST)]); }
if ($modal) {
    header('Cache-Control: no-store');
    if (!$sale) { require __DIR__.'/../includes/sale_modal_error.php'; }
    elseif (($_GET['action']??'')==='void') { require __DIR__.'/../includes/sale_void_content.php'; }
    else { require __DIR__.'/../includes/sale_details_content.php'; }
    exit;
}
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/navbar.php';
?>
<main class="dashboard-main" id="dashboard-content">
    <div class="dashboard-heading"><div><a class="sales-back" href="<?= e(app_url('user/sales.php')) ?>">← Sales Management</a><h1>Sale details</h1><?php if ($sale): ?><p><?= e($sale['sale_number']) ?> · <?= e((new DateTimeImmutable($sale['sold_at']))->format('j M Y, g:i A')) ?> Manila time</p><?php endif; ?></div><?php if ($sale && $sale['sale_status']!=='void' && !($sale['sale_status']==='draft'&&$sale['payments'])): ?><a class="btn btn-outline-secondary btn-sm" href="<?= e(app_url('user/sale_form.php?id='.$sale['sale_id'])) ?>"><?= $sale['sale_status']==='posted'?'Edit notes':'Edit draft' ?></a><?php endif; ?></div>
    <?php if ($notice): ?><div class="alert alert-success" role="status"><?= e($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
    <?php if ($sale): ?>
    <section class="dashboard-panel sale-section"><div class="sale-detail-header"><div><span class="detail-label">Customer</span><h2><?= e($sale['customer_name']??'Walk-in customer') ?></h2><p><?= e($sale['phone']??'') ?><?= $sale['email']?' · '.e($sale['email']):'' ?></p><?php if ($sale['address']): ?><p><?= e($sale['address']) ?></p><?php endif; ?></div><div class="sale-detail-status"><span class="payment-badge status-<?= e(sale_badge($sale)) ?>"><?= e(sale_status($sale)) ?></span><p><?= $sale['sale_type']==='credit'?'Customer credit sale':'Immediate sale' ?></p><?php if ($sale['due_date']): ?><p>Due <?= e((new DateTimeImmutable($sale['due_date']))->format('j M Y')) ?></p><?php endif; ?></div></div></section>
    <section class="dashboard-panel"><div class="panel-heading"><div><h2>Items purchased</h2><p>Historical prices and costs recorded with this sale.</p></div></div><div class="table-responsive"><table class="table transactions-table mb-0"><thead><tr><th scope="col">Product</th><th scope="col" class="text-end">Quantity</th><th scope="col" class="text-end">Unit price</th><th scope="col" class="text-end">Unit cost</th><th scope="col" class="text-end">Line total</th></tr></thead><tbody><?php foreach ($sale['items'] as $item): ?><tr><td><?= e($item['product_name_snapshot']) ?></td><td class="text-end"><?= e(rtrim(rtrim($item['quantity'],'0'),'.')) ?></td><td class="text-end"><?= e(sale_money($item['unit_price'])) ?></td><td class="text-end"><?= e(sale_money($item['unit_cost'])) ?></td><td class="text-end transaction-amount"><?= e(sale_money(sale_round(bcmul($item['quantity'],$item['unit_price'],5)))) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
    <div class="sale-payment-grid"><section class="dashboard-panel sale-section"><div class="panel-heading"><h2>Transaction notes</h2></div><div class="sale-fields"><p class="sale-notes"><?= $sale['notes']!==null&&$sale['notes']!==''?nl2br(e($sale['notes'])):'No notes recorded.' ?></p><?php if ($sale['sale_status']==='draft'): ?><p class="form-text">Drafts do not contribute to dashboard revenue or utang until posted.</p><?php endif; ?><?php if ($sale['sale_status']==='void'): ?><p class="form-text">Voided <?= e((new DateTimeImmutable($sale['voided_at']))->format('j M Y, g:i A')) ?></p><p><strong>Reason:</strong> <?= e($sale['void_reason']) ?></p><?php endif; ?></div></section><section class="dashboard-panel sale-section"><div class="panel-heading"><h2>Financial summary</h2></div><div class="sale-fields"><dl class="sale-total-list"><div><dt>Subtotal</dt><dd><?= e(sale_money($sale['subtotal'])) ?></dd></div><div><dt>Discount<?= $sale['discount_type']==='percent'?' ('.e($sale['discount_value']).'%)':'' ?></dt><dd><?= e(sale_money(bcsub($sale['subtotal'],$sale['net_sale'],2))) ?></dd></div><div class="sale-grand-total"><dt>Final total</dt><dd><?= e(sale_money($sale['net_sale'])) ?></dd></div><div><dt>Net payments</dt><dd><?= e(sale_money($sale['total_paid'])) ?></dd></div><div><dt><?= $sale['sale_status']==='posted'?'Remaining balance':'Current debt' ?></dt><dd><?= e(sale_money($sale['sale_status']==='posted'?$sale['balance_due']:'0.00')) ?></dd></div></dl></div></section></div>
    <section class="dashboard-panel"><div class="panel-heading"><div><h2>Payment history</h2><p>Collections and refunds in chronological order. Refunds preserve the original collection.</p></div></div><?php if ($sale['payments']): ?><div class="table-responsive"><table class="table transactions-table mb-0"><thead><tr><th scope="col">Date &amp; time</th><th scope="col">Kind</th><th scope="col">Method</th><th scope="col">Reference</th><th scope="col" class="text-end">Amount</th></tr></thead><tbody><?php foreach ($sale['payments'] as $payment): ?><tr><td><?= e((new DateTimeImmutable($payment['paid_at']))->format('j M Y, g:i A')) ?></td><td><?= $payment['payment_kind']==='refund'?'Refund':'Collection' ?><?php if ($payment['reverses_payment_id']): ?><span class="transaction-context">Reverses payment #<?= e($payment['reverses_payment_id']) ?></span><?php endif; ?></td><td><?= e($payment['method_name']) ?><?php if ($payment['provider_name']): ?><span class="transaction-context"><?= e($payment['provider_name']) ?></span><?php endif; ?></td><td><?= e($payment['reference_number']??'—') ?></td><td class="text-end transaction-amount <?= $payment['payment_kind']==='refund'?'amount-negative':'' ?>"><?= $payment['payment_kind']==='refund'?'−':'' ?><?= e(sale_money($payment['amount'])) ?></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><div class="empty-state"><p>No payments have been recorded.</p></div><?php endif; ?></section>
    <?php if ($sale['sale_status']!=='void'): ?>
    <section class="dashboard-panel sale-void-panel" id="void-sale"><div><h2>Correct an incorrect transaction</h2><p>Posted financial fields cannot be edited. Void and recreate the sale when a financial correction is needed.</p></div><button class="btn btn-outline-danger btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#void-sale-modal">Void sale</button></section>
    <div class="modal fade" id="void-sale-modal" tabindex="-1" aria-labelledby="void-title" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="post" action="<?= e(app_url('user/sale_view.php')) ?>"><div class="modal-header"><h2 class="modal-title fs-6" id="void-title">Void <?= e($sale['sale_number']) ?>?</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="sale_id" value="<?= e($sale['sale_id']) ?>"><input type="hidden" name="revision" value="<?= e(sale_revision($sale)) ?>"><?php foreach (sale_form_token('void',(int)$sale['sale_id']) as $key=>$value): ?><input type="hidden" name="<?= e($key) ?>" value="<?= e($value) ?>"><?php endforeach; ?><p>The sale will be excluded from revenue and outstanding debt. Its items, payments, and audit history will be kept. This cannot be undone.</p><?php if (bccomp($sale['total_paid'],'0',2)>0): ?><div class="alert alert-warning"><?= e(sale_money($sale['total_paid'])) ?> is still collected. Return that money before confirming. Refund entries will reverse each remaining original collection using its original payment method.</div><label class="refund-confirm"><input type="checkbox" name="refund_confirm" value="1" required> I have returned <?= e(sale_money($sale['total_paid'])) ?> to the customer. Record the refunds and void this sale.</label><?php endif; ?><label class="form-label mt-3" for="void_reason">Reason for voiding</label><textarea class="form-control" id="void_reason" name="void_reason" maxlength="255" rows="3" required></textarea><p class="form-text">This records refunds; it does not send money through GCash, Maya, or a bank.</p></div><div class="modal-footer"><button class="btn btn-outline-secondary btn-sm" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger btn-sm" type="submit">Confirm void</button></div></form></div></div></div>
    <?php endif; ?>
    <?php endif; ?>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
