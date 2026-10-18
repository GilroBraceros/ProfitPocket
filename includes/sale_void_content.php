<?php if ($sale['sale_status']==='void'): $error='This sale is already voided.'; require __DIR__.'/sale_modal_error.php'; return; endif; ?>
<form method="post" action="<?= e(app_url('user/sale_view.php').'?modal=1') ?>" data-sale-async novalidate>
<div class="modal-header"><h2 class="modal-title" id="sales-modal-title">Void sale?</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div>
<div class="modal-body">
    <div class="alert alert-danger d-none sale-submit-error" role="alert" tabindex="-1"></div>
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="sale_id" value="<?= e($sale['sale_id']) ?>"><input type="hidden" name="revision" value="<?= e(sale_revision($sale)) ?>">
    <?php foreach (sale_form_token('void',(int)$sale['sale_id']) as $key=>$value): ?><input type="hidden" name="<?= e($key) ?>" value="<?= e($value) ?>"><?php endforeach; ?>
    <dl class="sale-void-summary"><div><dt>Reference</dt><dd><?= e($sale['sale_number']) ?></dd></div><div><dt>Customer</dt><dd><?= e($sale['customer_name']??'Walk-in customer') ?></dd></div><div><dt>Total sale amount</dt><dd><?= e(sale_money($sale['net_sale'])) ?></dd></div></dl>
    <p>This removes the sale from sales totals and outstanding debt. Its history is kept. Voiding cannot be undone.</p>
    <?php if (bccomp($sale['total_paid'],'0',2)>0): ?><div class="alert alert-warning">Return the collected <?= e(sale_money($sale['total_paid'])) ?> first. This action records the refunds; it does not send money.</div><label class="refund-confirm"><input type="checkbox" name="refund_confirm" value="1" required> I have returned <?= e(sale_money($sale['total_paid'])) ?> to the customer.</label><?php endif; ?>
    <label class="form-label mt-3" for="void_reason">Reason for voiding</label><textarea class="form-control" id="void_reason" name="void_reason" maxlength="255" rows="3" placeholder="Briefly explain the correction" required></textarea>
</div>
<div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger" type="submit" data-submit-label="Confirm Void">Confirm Void</button></div>
</form>
