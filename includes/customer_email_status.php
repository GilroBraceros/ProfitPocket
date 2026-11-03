<?php
if (!isset($emailSale,$user)) {return;}
try {
    require_once __DIR__.'/customer_email.php';$mailDb=db();
    $mailContext=customer_mail_context($mailDb,$user,(int)$emailSale['sale_id']);
    $mailHistory=customer_mail_history($mailDb,$user,(int)$emailSale['sale_id']);$purchase=null;
    foreach ($mailHistory as $event) {if(in_array($event['notification_type'],PURCHASE_EMAIL_TYPES,true)){$purchase=$event;break;}}
    $mailReason=customer_mail_reason($mailContext,true);
    if (!$mailReason && customer_reminder_cooldown($mailHistory)) {$mailReason='cooldown';}
    if (!$mailReason && !email_ready(email_config())) {$mailReason='disabled';}
    $purchaseStatus=$purchase['status']??(customer_mail_reason($mailContext)??(email_ready(email_config())?'not_queued':'disabled'));
?>
<section class="customer-detail-section customer-email-section"><h3>Customer email notifications</h3><p role="status">Purchase email: <?= e(customer_mail_label($purchaseStatus)) ?>.</p>
    <?php if (!$mailReason): ?><a class="btn btn-outline-primary btn-sm" data-<?= ($emailSurface??'sale')==='utang'?'utang':'sale' ?>-modal="reminder" href="<?= e(app_url('user/sale_email.php?id='.$emailSale['sale_id'].'&surface='.($emailSurface??'sale'))) ?>">Send Reminder</a>
    <?php elseif (bccomp($emailSale['balance_due'],'0',2)>0 && $emailSale['sale_status']==='posted'): ?><p class="form-text">Reminder unavailable: <?= e(customer_mail_label($mailReason)) ?>.<?php if($mailReason==='consent_missing'): ?> Customer communication permission is managed in Customers.<?php endif; ?></p><?php endif; ?>
    <?php if ($mailHistory): ?><details class="mt-3"><summary>Email and reminder history</summary><ul class="customer-email-history"><?php foreach($mailHistory as $event): ?><li><strong><?= e(str_replace('_',' ',ucfirst($event['notification_type']))) ?></strong><span><?= e(email_date($event['created_at'])) ?> — <?= e(customer_mail_label($event['status'])) ?></span><?php if($event['failure_code']): ?><small>Code: <?= e($event['failure_code']) ?></small><?php endif; ?><?php if($event['message_id']): ?><small>Brevo reference: <?= e($event['message_id']) ?></small><?php endif; ?></li><?php endforeach; ?></ul></details><?php endif; ?>
</section>
<?php } catch (Throwable $e) {echo '<p class="form-text">Customer email status is currently unavailable.</p>';} ?>
