<form method="post" action="<?= e(app_url('user/sale_email.php?surface='.$surface.($modal?'&modal=1':''))) ?>" <?= $modal?($surface==='sale'?'data-sale-async':'data-utang-async'):'' ?> data-customer-reminder>
<div class="modal-header"><div><h2 class="modal-title" id="<?= e($titleId) ?>">Send Payment Reminder</h2><p class="modal-subtitle">Preview and confirm the registered customer before sending.</p></div><?php if ($modal): ?><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button><?php endif; ?></div>
<div class="modal-body">
    <?php if ($surface==='utang'): ?><div class="utang-discard-prompt alert alert-light d-none" role="alert"><p>Discard your unsaved reminder?</p><button type="button" class="btn btn-outline-secondary btn-sm" data-keep-utang>Keep editing</button><button type="button" class="btn btn-danger btn-sm" data-discard-utang>Discard changes</button></div><?php endif; ?>
    <div class="alert alert-danger sale-submit-error utang-submit-error <?= $error?'':'d-none' ?>" role="alert" tabindex="-1"><?= e($error??'') ?></div>
    <?php if ($context): ?>
    <?php $sale=$context['sale'];$customer=$context['customer']; ?>
    <dl class="customer-contact-details"><div><dt>Customer</dt><dd><?= e($customer['customer_name']??'Walk-in customer') ?></dd></div><div><dt>Recipient</dt><dd><?= e($customer['email']??'No email available') ?></dd></div><div><dt>Business</dt><dd><?= e($context['business']['business_name']) ?></dd></div><div><dt>Transaction number</dt><dd><?= e($sale['sale_number']) ?></dd></div><div><dt>Total purchase</dt><dd><?= e(sale_money($sale['net_sale'])) ?></dd></div><div><dt>Already paid</dt><dd><?= e(sale_money($sale['total_paid'])) ?></dd></div><div><dt>Remaining balance</dt><dd><?= e(sale_money($sale['balance_due'])) ?></dd></div><?php if($sale['due_date']): ?><div><dt>Due date</dt><dd><?= e(email_date($sale['due_date'],false)) ?></dd></div><?php endif; ?></dl>
    <?php $last=null;foreach($history as $event){if(in_array($event['notification_type'],['manual_reminder','upcoming_due','overdue'],true)){$last=$event;break;}} ?>
    <p class="form-text">Last reminder: <?= $last?e(email_date($last['created_at']).' — '.customer_mail_label($last['status'])):'None recorded' ?>. Limit: one reminder per purchase every 24 hours, up to three per customer each day.</p>
    <?php if ($reason): ?><p class="alert alert-info"><?= e(customer_mail_label($reason)) ?>. Customer receipt/reminder permission is managed in Customers.</p><?php endif; ?>
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="sale_id" value="<?= e($id) ?>"><input type="hidden" name="intent" value="send">
    <input type="hidden" name="recipient_revision" value="<?= e(customer_mail_recipient_revision($context)) ?>">
    <?php foreach ($token as $field=>$value): ?><input type="hidden" name="<?= e($field) ?>" value="<?= e($value) ?>"><?php endforeach; ?>
    <label class="form-label" for="owner_message">Optional short message</label><textarea class="form-control mb-3" id="owner_message" name="owner_message" maxlength="500" rows="3"><?= e(sale_text($_POST,'owner_message')) ?></textarea>
    <button class="btn btn-outline-secondary btn-sm mb-3" type="button" data-email-preview>Update email preview</button><p class="form-text" data-email-preview-status aria-live="polite"></p>
    <iframe class="customer-email-preview" title="Payment reminder email preview" sandbox="" srcdoc="<?= e($preview) ?>"></iframe>
    <label class="d-flex gap-2 mt-3"><input class="form-check-input" type="checkbox" name="confirm_recipient" value="1" required <?= $reason?'disabled':'' ?>> I confirm this reminder should be sent to the customer email shown above.</label>
    <?php endif; ?>
</div>
<div class="modal-footer"><?php if($modal): ?><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button><?php endif; ?><button class="btn btn-primary" type="submit" <?= !$context || $reason?'disabled':'' ?>>Send Reminder</button></div>
</form>
