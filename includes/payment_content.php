<?php
$is_form=!$unavailable && in_array($action,['payment','due'],true);
$values=['amount'=>'','payment_method_id'=>'','payment_category'=>'cash','wallet_method_id'=>'','provider_name'=>'','paid_at'=>date('Y-m-d\TH:i'),'reference_number'=>'','notes'=>'','due_date'=>$row['due_date']??''];
if ($_SERVER['REQUEST_METHOD']==='POST') { foreach ($values as $field=>$value) { if (is_string($_POST[$field]??null)) { $values[$field]=$_POST[$field]; } } }
$form_url=app_url('user/payments_action.php').'?action='.$action.($modal?'&modal=1':'');
?>
<?php if ($is_form): ?><form class="utang-form payment-form" method="post" action="<?= e($form_url) ?>" <?= $modal?'data-utang-async novalidate':'' ?>><?php endif; ?>
<div class="<?= $modal?'modal-header':'utang-form-heading' ?>"><div><h2 class="modal-title" id="utang-modal-title"><?= e($page_title) ?></h2><?php if ($row): ?><p class="modal-subtitle"><?= e($row['sale_number']) ?> · <?= e($row['customer_name']??'Walk-in Customer') ?></p><?php endif; ?></div><?php if ($modal): ?><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button><?php endif; ?></div>
<div class="<?= $modal?'modal-body':'utang-form-body' ?>">
    <div class="utang-discard-prompt alert alert-light d-none" role="alert"><p>Discard your unsaved changes?</p><button type="button" class="btn btn-outline-secondary btn-sm" data-keep-utang>Keep editing</button><button type="button" class="btn btn-danger btn-sm" data-discard-utang>Discard changes</button></div>
    <div class="alert alert-danger utang-submit-error <?= $error?'':'d-none' ?>" role="alert" tabindex="-1"><?= e($error??'') ?></div>
    <?php if (!$unavailable && $row): ?>
    <?php if ($is_form): ?>
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="sale_id" value="<?= e($row['sale_id']) ?>">
        <?php foreach ($token as $field=>$value): ?><input type="hidden" name="<?= e($field) ?>" value="<?= e($value) ?>"><?php endforeach; ?>
        <?php if ($action==='payment'): ?>
        <section class="payment-form-section"><h3>Transaction information</h3><dl class="utang-payment-summary"><div><dt>Original sale</dt><dd><?= e(sale_money($row['net_sale'])) ?></dd></div><div><dt>Previously paid, net</dt><dd><?= e(sale_money($row['total_paid'])) ?></dd></div></dl><div class="utang-current-balance"><span>Remaining balance</span><strong><?= e(sale_money($row['balance_due'])) ?></strong></div></section>
        <section class="payment-form-section"><h3>Payment received</h3>
            <div class="mb-3"><label class="form-label" for="amount">Payment amount</label><input class="form-control" type="number" inputmode="decimal" id="amount" name="amount" step="0.01" min="0.01" max="<?= e($row['balance_due']) ?>" value="<?= e($values['amount']) ?>" required aria-describedby="amount-help"><p id="amount-help" class="form-text">Up to <?= e(sale_money($row['balance_due'])) ?>. Save only money you have received.</p></div>
            <?php $method_values=$values; require __DIR__.'/payment_method_fields.php'; ?>
            <div class="mt-3" data-payment-reference><label class="form-label" for="reference_number">Transaction reference number <span class="field-optional">(optional)</span></label><input class="form-control" id="reference_number" name="reference_number" maxlength="100" value="<?= e($values['reference_number']) ?>"></div>
            <div class="mt-3"><label class="form-label" for="paid_at">Payment date and time</label><input class="form-control" type="datetime-local" id="paid_at" name="paid_at" min="<?= e(str_replace(' ','T',$row['sold_at'])) ?>" max="<?= e(date('Y-m-d\TH:i:s')) ?>" step="1" value="<?= e($values['paid_at']) ?>" required><p class="form-text">Philippine time. Must be on or after the sale date.</p></div>
            <div class="mt-3"><label class="form-label" for="notes">Payment notes <span class="field-optional">(optional)</span></label><textarea class="form-control" id="notes" name="notes" maxlength="4000" rows="2"><?= e($values['notes']) ?></textarea></div>
        </section>
        <dl class="utang-payment-preview" data-balance="<?= e($row['balance_due']) ?>" aria-live="polite" aria-atomic="true"><div><dt>Current balance</dt><dd><?= e(sale_money($row['balance_due'])) ?></dd></div><div><dt>New payment</dt><dd data-preview-payment>₱0.00</dd></div><div><dt>Remaining after payment</dt><dd data-preview-balance><?= e(sale_money($row['balance_due'])) ?></dd></div></dl>
        <p class="sale-modal-note">The balance is checked again before saving. No email or SMS is sent.</p>
        <?php else: ?>
        <input type="hidden" name="revision" value="<?= e($_SERVER['REQUEST_METHOD']==='POST' && is_string($_POST['revision']??null)?$_POST['revision']:utang_due_revision($row['due_date'])) ?>">
        <label class="form-label" for="due_date">Due date <span class="field-optional">(optional)</span></label><input class="form-control" type="date" id="due_date" name="due_date" min="<?= e(substr($row['sold_at'],0,10)) ?>" value="<?= e($values['due_date']) ?>"><p class="form-text">Leave blank if no due date is agreed. A sale with a remaining balance becomes overdue after its due date.</p><p class="sale-modal-note">Due-date changes remain in the audit history. Payments and sale amounts stay unchanged.</p>
        <?php endif; ?>
    <?php elseif ($action==='payment_details' && $payment): ?>
        <div class="payment-detail-amount"><span><?= e($payment['payment_kind']==='refund'?'Refund':(PAYMENT_TYPES[$payment['payment_type']]??'Collection')) ?></span><strong><?= e(sale_money($payment['amount'])) ?></strong></div>
        <dl class="customer-contact-details payment-details">
        <?php foreach (['Payment date'=> (new DateTimeImmutable($payment['paid_at']))->format('j M Y, g:i:s a'),'Customer'=>$row['customer_name']??'Walk-in Customer','Sale reference'=>$row['sale_number'],'Payment method'=>payment_category_label(payment_category($payment['method_code'])),'Transaction reference'=>$payment['reference_number']??'Not provided','Recorded by'=>$payment['recorder'],'Recorded at'=>(new DateTimeImmutable($payment['created_at']))->format('j M Y, g:i:s a'),'Sale status'=>ucfirst($row['sale_status'])] as $label=>$value): ?><div><dt><?= e($label) ?></dt><dd><?= e($value) ?></dd></div><?php endforeach; ?>
        <?php if ($payment['method_code']!=='cash'): ?><div><dt><?= $payment['method_code']==='bank'?'Bank name':'Wallet provider' ?></dt><dd><?= e(payment_provider($payment)) ?></dd></div><?php endif; ?>
        <?php if (bccomp($payment['refunded'],'0',2)>0): ?><div><dt>Refunded</dt><dd><?= e(sale_money($payment['refunded'])) ?></dd></div><div><dt>Valid amount remaining</dt><dd><?= e(sale_money($payment['received_amount'])) ?></dd></div><?php endif; ?></dl>
        <?php if ($payment['notes']!==null && $payment['notes']!==''): ?><h3>Payment notes</h3><p class="sale-notes"><?= nl2br(e($payment['notes'])) ?></p><?php endif; ?>
        <p class="sale-modal-note">This recorded payment is read-only. Use the existing confirmed sale-void/refund workflow in Sales for a whole-sale reversal; individual payment editing or deletion is unavailable.</p>
        <a href="<?= e(app_url('user/payments_action.php?action=history&id='.$row['sale_id'])) ?>" data-utang-modal="history">View complete sale payment history</a>
    <?php else: ?>
        <?php if ($action==='details'): ?>
        <section class="customer-detail-section"><h3>Transaction information</h3><dl class="customer-contact-details"><div><dt>Customer</dt><dd><?= e($row['customer_name']??'Walk-in Customer') ?></dd></div><div><dt>Sale reference</dt><dd><a href="<?= e(app_url('user/sale_view.php?id='.$row['sale_id'])) ?>"><?= e($row['sale_number']) ?></a></dd></div><div><dt>Sale date</dt><dd><?= e((new DateTimeImmutable($row['sold_at']))->format('j M Y, g:i a')) ?></dd></div><div><dt>Due date</dt><dd><?= e($row['due_date']?(new DateTimeImmutable($row['due_date']))->format('j M Y'):'Not set') ?></dd></div><div><dt>Status</dt><dd><?= e(bccomp($row['balance_due'],'0',2)===0?'Fully Paid':utang_status($row)) ?><?php if (utang_overdue($row)): ?> <span class="payment-badge status-overdue">Overdue</span><?php endif; ?></dd></div></dl>
            <div class="customer-detail-totals"><div><span>Original sale</span><strong><?= e(sale_money($row['net_sale'])) ?></strong></div><div><span>Net payments</span><strong><?= e(sale_money($row['total_paid'])) ?></strong></div><div><span>Remaining</span><strong><?= e(sale_money($row['balance_due'])) ?></strong></div></div>
            <?php if ($customer): ?><p class="sale-modal-note">Customer’s total outstanding balance: <?= e(sale_money($customer['outstanding'])) ?>.</p><a href="<?= e(app_url('user/payments.php?tab=history&customer='.$row['customer_id'])) ?>">All payments for this customer</a><?php endif; ?>
            <?php foreach (['credit_notes'=>'Credit notes','notes'=>'Sale notes'] as $field=>$label): ?><?php if (($row[$field]??'')!==''): ?><h4><?= e($label) ?></h4><p class="sale-notes"><?= nl2br(e($row[$field])) ?></p><?php endif; ?><?php endforeach; ?>
        </section>
        <section class="customer-detail-section"><h3>Products purchased</h3><table class="table customer-detail-table"><thead><tr><th>Product</th><th>Quantity</th><th class="text-end">Unit price</th><th class="text-end">Line total</th></tr></thead><tbody><?php foreach ($row['items'] as $item): ?><tr><td data-label="Product"><?= e($item['product_name_snapshot']) ?></td><td data-label="Quantity"><?= e(rtrim(rtrim($item['quantity'],'0'),'.')) ?></td><td data-label="Unit price" class="text-end"><?= e(sale_money($item['unit_price'])) ?></td><td data-label="Line total" class="text-end"><?= e(sale_money(sale_round(bcmul($item['quantity'],$item['unit_price'],5)))) ?></td></tr><?php endforeach; ?></tbody></table><p class="sale-modal-note">Line totals before the sale’s discount.</p></section>
        <?php endif; ?>
        <section class="customer-detail-section"><h3>Payment history</h3>
        <?php $types=payment_classify($row); if ($row['payments']): ?>
        <table class="table customer-detail-table utang-history"><thead><tr><th>Date</th><th>Method / provider</th><th>Type</th><th class="text-end">Amount</th><th>Reference / notes</th></tr></thead><tbody>
            <?php foreach ($row['payments'] as $p): ?><tr><td data-label="Date"><?= e((new DateTimeImmutable($p['paid_at']))->format('j M Y, g:i a')) ?></td><td data-label="Method / provider"><?= e($p['method_name']) ?><?php if ($p['method_code']==='bank' || $p['method_code']==='wallet_other'): ?><span class="transaction-context"><?= e(payment_provider($p)) ?></span><?php endif; ?></td><td data-label="Type"><?= e($p['payment_kind']==='refund'?'Refund':PAYMENT_TYPES[$types[$p['payment_id']]]) ?></td><td data-label="Amount" class="text-end"><?= e(($p['payment_kind']==='refund'?'−':'').sale_money($p['amount'])) ?></td><td data-label="Reference / notes"><?= e($p['reference_number']??'Not provided') ?><?php if (($p['notes']??'')!==''): ?><span class="transaction-context"><?= e($p['notes']) ?></span><?php endif; ?></td></tr><?php endforeach; ?>
        </tbody></table><?php else: ?><p class="sale-modal-note">No payments have been recorded for this sale.</p><?php endif; ?>
        <p class="sale-modal-note">Complete chronological history, including initial payments and refunds. Payment types follow the recording sequence even when a payment date is backdated. Net payments: <?= e(sale_money($row['total_paid'])) ?>.</p></section>
    <?php endif; ?>
    <?php endif; ?>
</div>
<div class="<?= $modal?'modal-footer':'utang-form-footer' ?>">
    <?php if ($modal): ?><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal"><?= $is_form?'Cancel':'Close' ?></button><?php else: ?><a class="btn btn-outline-secondary" href="<?= e(app_url('user/payments.php')) ?>"><?= $is_form?'Cancel':'Back to Payments' ?></a><?php endif; ?>
    <?php if ($is_form): ?><button class="btn btn-primary" type="submit" <?= $action==='payment' && !$methods?'disabled':'' ?>><?= $action==='payment'?'Save Payment':'Save Due Date' ?></button><?php endif; ?>
</div>
<?php if ($is_form): ?></form><?php endif; ?>
