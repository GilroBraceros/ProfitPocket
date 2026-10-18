<?php
$is_form=!$unavailable && $action!=='details';
$values=['customer_name'=>'','phone'=>'','email'=>'','address'=>'','notes'=>''];
if ($row) { $values=array_replace($values,array_intersect_key($row,$values)); }
if ($_SERVER['REQUEST_METHOD']==='POST') { foreach ($values as $field=>$value) { if (is_string($_POST[$field]??null)) { $values[$field]=$_POST[$field]; } } }
$form_url=app_url('user/customer_action.php').'?action='.$action.($modal?'&modal=1':'');
?>
<?php if ($is_form): ?><form class="customer-form" method="post" action="<?= e($form_url) ?>" <?= $modal?'data-customer-async novalidate':'' ?>><?php endif; ?>
<div class="<?= $modal?'modal-header':'customer-form-heading' ?>"><h2 class="modal-title" id="customer-modal-title"><?= e($page_title) ?></h2><?php if ($modal): ?><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button><?php endif; ?></div>
<div class="<?= $modal?'modal-body':'customer-form-body' ?>">
    <div class="customer-discard-prompt alert alert-light d-none" role="alert"><p>Discard your unsaved changes?</p><button type="button" class="btn btn-outline-secondary btn-sm" data-keep-customer>Keep editing</button><button type="button" class="btn btn-danger btn-sm" data-discard-customer>Discard changes</button></div>
    <div class="alert alert-danger customer-submit-error <?= $error?'':'d-none' ?>" role="alert" tabindex="-1"><?= e($error??'') ?></div>
    <?php if ($unavailable): ?>
    <?php elseif ($action==='details'): ?>
        <section class="customer-detail-section"><div class="customer-detail-heading"><h3><?= e($row['customer_name']) ?></h3><?php if (!$row['is_active']): ?><span class="customer-archive-label">Archived</span><?php endif; ?></div>
            <dl class="customer-contact-details"><?php foreach (['phone'=>'Contact number','email'=>'Email','address'=>'Address'] as $field=>$label): ?><div><dt><?= e($label) ?></dt><dd><?= e($row[$field]!==null && $row[$field]!==''?$row[$field]:'Not provided') ?></dd></div><?php endforeach; ?><div><dt>Date added</dt><dd><?= e((new DateTimeImmutable($row['created_at']))->format('j M Y')) ?></dd></div></dl>
            <?php if ($row['notes']!==null && $row['notes']!==''): ?><h4>Customer notes</h4><p class="sale-notes"><?= nl2br(e($row['notes'])) ?></p><?php endif; ?>
        </section>
        <section class="customer-detail-section"><h3>Purchase summary</h3><div class="customer-detail-totals"><div><span>Total purchases</span><strong><?= e(sale_money($row['purchases'])) ?></strong></div><div><span>Total paid, net</span><strong><?= e(sale_money($row['paid'])) ?></strong></div><div><span>Valid transactions</span><strong><?= e($row['transactions']) ?></strong></div><div><span>Outstanding balance</span><strong><?= e(sale_money($row['outstanding'])) ?></strong></div></div><p class="sale-modal-note">Posted sales only. Repayments do not add to purchase totals.</p></section>
        <section class="customer-detail-section"><h3>Recent purchases</h3>
            <?php if ($history['purchases']): ?><table class="table customer-detail-table"><thead><tr><th>Date</th><th>Reference</th><th class="text-end">Total</th><th>Payment status</th></tr></thead><tbody><?php foreach ($history['purchases'] as $sale): ?><tr><td data-label="Date"><?= e((new DateTimeImmutable($sale['sold_at']))->format('j M Y')) ?></td><td data-label="Reference"><a href="<?= e(app_url('user/sale_view.php?id='.$sale['sale_id'])) ?>"><?= e($sale['sale_number']) ?></a></td><td data-label="Total" class="text-end"><?= e(sale_money($sale['net_sale'])) ?></td><td data-label="Payment status"><?= e(sale_status($sale)) ?></td></tr><?php endforeach; ?></tbody></table><p class="sale-modal-note">Latest 10 posted purchases.</p><?php else: ?><p class="sale-modal-note">No posted purchases yet.</p><?php endif; ?>
        </section>
        <section class="customer-detail-section"><h3>Customer balance summary</h3><p class="customer-credit-total"><?= e(sale_money($row['outstanding'])) ?> outstanding <span>· <?= e($row['unpaid_credits']) ?> unpaid sales</span></p>
            <?php if ($history['credits']): ?><table class="table customer-detail-table"><thead><tr><th>Reference</th><th>Due date</th><th class="text-end">Remaining</th><th>Status</th></tr></thead><tbody><?php foreach ($history['credits'] as $sale): $overdue=bccomp($sale['balance_due'],'0',2)>0 && $sale['due_date'] && $sale['due_date']<date('Y-m-d'); ?><tr><td data-label="Reference"><a href="<?= e(app_url('user/sale_view.php?id='.$sale['sale_id'])) ?>"><?= e($sale['sale_number']) ?></a></td><td data-label="Due date"><?= e($sale['due_date']?(new DateTimeImmutable($sale['due_date']))->format('j M Y'):'Not set') ?></td><td data-label="Remaining" class="text-end"><?= e(sale_money($sale['balance_due'])) ?></td><td data-label="Status"><?= e($overdue?'Overdue':sale_status($sale)) ?></td></tr><?php endforeach; ?></tbody></table><p class="sale-modal-note">Up to 10 balance transactions, unpaid balances first. Collections remain in Payment Management.</p><?php else: ?><p class="sale-modal-note">No posted balance transactions yet.</p><?php endif; ?>
        </section>
        <section class="customer-detail-section"><h3>Recent payments</h3><p class="sale-modal-note">Latest 10 payment events on valid sales, including refunds.</p>
        <?php if ($history['payments']): ?><table class="table customer-detail-table"><thead><tr><th>Date</th><th>Sale reference</th><th>Method</th><th class="text-end">Amount</th></tr></thead><tbody><?php foreach ($history['payments'] as $p): ?><tr><td data-label="Date"><?= e((new DateTimeImmutable($p['paid_at']))->format('j M Y')) ?></td><td data-label="Sale reference"><?= e($p['sale_number']) ?></td><td data-label="Method"><?= e($p['method_name']) ?><?php if ($p['provider_name']): ?><span class="transaction-context"><?= e($p['provider_name']) ?></span><?php endif; ?></td><td data-label="Amount" class="text-end"><?= e(($p['payment_kind']==='refund'?'−':'').sale_money($p['amount'])) ?></td></tr><?php endforeach; ?></tbody></table><?php else: ?><p class="sale-modal-note">No payments recorded.</p><?php endif; ?>
        <a href="<?= e(app_url('user/payments.php?tab=history&customer='.$row['customer_id'])) ?>">View customer payments</a></section>
    <?php else: ?>
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="customer_id" value="<?= e($row['customer_id']??'') ?>">
        <?php foreach ($token as $field=>$value): ?><input type="hidden" name="<?= e($field) ?>" value="<?= e($value) ?>"><?php endforeach; ?>
        <?php if ($row): ?><input type="hidden" name="revision" value="<?= e(customer_revision($row)) ?>"><?php endif; ?>
        <?php if (in_array($action,['archive','restore'],true)): ?>
            <input type="hidden" name="confirm_customer_action" value="1"><p class="customer-confirm-name"><?= e($row['customer_name']) ?></p>
            <?php if ($action==='archive'): ?><p class="sale-modal-note">Archive this customer? Their sales, payments and credit history will be kept. They will be hidden from the active directory and new-sale selector.</p><?php if (bccomp($row['outstanding'],'0',2)>0): ?><div class="alert alert-light">Outstanding balance of <?= e(sale_money($row['outstanding'])) ?> remains owed and included in business totals.</div><?php endif; ?>
            <?php else: ?><p class="sale-modal-note">Restore this customer to the active directory and make them available for new sales?</p><?php endif; ?>
        <?php else: ?>
            <?php if ($row && !$row['is_active']): ?><p class="alert alert-light">This customer is archived. Editing their contact information preserves their archived status.</p><?php endif; ?>
            <div class="row g-3">
                <div class="col-12"><label class="form-label" for="customer_name">Customer name</label><input class="form-control" id="customer_name" name="customer_name" maxlength="150" value="<?= e($values['customer_name']) ?>" required autocomplete="name"></div>
                <div class="col-sm-6"><label class="form-label" for="phone">Contact number <span class="field-optional">(optional)</span></label><input class="form-control" type="tel" id="phone" name="phone" maxlength="30" value="<?= e($values['phone']) ?>" placeholder="09171234567" autocomplete="tel"><p class="form-text">Philippine mobile number; +63 format is also accepted.</p></div>
                <div class="col-sm-6"><label class="form-label" for="email">Email address <span class="field-optional">(optional)</span></label><input class="form-control" type="email" id="email" name="email" maxlength="191" value="<?= e($values['email']) ?>" autocomplete="email"></div>
                <div class="col-12 customer-shared-phone <?= $duplicate_warning?'':'d-none' ?>"><div class="alert alert-light mb-0"><label class="form-check"><input class="form-check-input" type="checkbox" name="allow_shared_phone" value="1" <?= post_text('allow_shared_phone')==='1'?'checked':'' ?>><span class="form-check-label">These are different people who share this contact number.</span></label></div></div>
                <div class="col-12"><label class="form-label" for="address">Address <span class="field-optional">(optional)</span></label><textarea class="form-control" id="address" name="address" maxlength="255" rows="2" autocomplete="street-address"><?= e($values['address']) ?></textarea></div>
                <div class="col-12"><label class="form-label" for="notes">Customer notes <span class="field-optional">(optional)</span></label><textarea class="form-control" id="notes" name="notes" maxlength="4000" rows="2"><?= e($values['notes']) ?></textarea></div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
<div class="<?= $modal?'modal-footer':'customer-form-footer' ?>">
    <?php if ($modal): ?><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal"><?= $is_form?'Cancel':'Close' ?></button><?php else: ?><a class="btn btn-outline-secondary" href="<?= e(app_url('user/customers.php')) ?>">Cancel</a><?php endif; ?>
    <?php if ($is_form): $submit_label=match($action) {'archive'=>'Archive Customer','restore'=>'Restore Customer',default=>'Save Customer'}; ?><button class="btn <?= $action==='archive'?'btn-danger':'btn-primary' ?>" type="submit" data-submit-label="<?= e($submit_label) ?>"><?= e($submit_label) ?></button><?php endif; ?>
</div>
<?php if ($is_form): ?></form><?php endif; ?>
