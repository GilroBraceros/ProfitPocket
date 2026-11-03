<?php
declare(strict_types=1);
require_once __DIR__.'/payments.php';
require_once __DIR__.'/notifications.php';
require_once __DIR__.'/subscriptions.php';
const PURCHASE_EMAIL_TYPES=['purchase_paid','purchase_unpaid','purchase_installment'];

function customer_mail_context(PDO $db,array $user,int $saleId): array
{
    $business=customer_query($db,"SELECT b.business_name FROM businesses b JOIN users u ON u.user_id=b.owner_user_id WHERE b.business_id=? AND b.owner_user_id=? AND u.account_status='active'",[$user['business_id'],$user['user_id']])->fetch();
    if (!$business) {throw new SaleNotFound('Transaction not found.');}
    $sale=sale_read($db,(int)$user['business_id'],$saleId);
    $customer=$sale['customer_id']?customer_query($db,'SELECT customer_id,customer_name,email,is_active FROM customers WHERE business_id=? AND customer_id=?',[$user['business_id'],$sale['customer_id']])->fetch():null;
    $preferences=$customer?notification_preferences($db,(int)$user['business_id'],(int)$customer['customer_id']):[];
    return compact('sale','customer','preferences','business');
}
function customer_mail_reason(array $context,bool $reminder=false): ?string
{
    if ($context['sale']['sale_status']!=='posted') {return 'not_posted';}
    if ($reminder && bccomp($context['sale']['balance_due'],'0',2)<=0) {return 'settled';}
    $customer=$context['customer'];
    if (!$customer || !trim((string)$customer['email'])) {return 'no_customer_email';}
    if (!$customer['is_active']) {return 'customer_ineligible';}
    if (!filter_var($customer['email'],FILTER_VALIDATE_EMAIL)) {return 'invalid_recipient';}
    $prefs=$context['preferences'];
    if (empty($prefs[$reminder?'reminders':'payment_receipts']) || !hash_equals((string)($prefs['email_hash']??''),hash('sha256',mb_strtolower(trim($customer['email']))))) {return 'consent_missing';}
    return null;
}
function customer_mail_recipient_revision(array $context): string
{
    return hash('sha256',($context['customer']['customer_id']??0).':'.mb_strtolower(trim((string)($context['customer']['email']??''))));
}
function customer_purchase_event(PDO $db,array $user,int $saleId,string $key): void
{
    try {
        $context=customer_mail_context($db,$user,$saleId);
        if (customer_mail_reason($context)!==null) {return;}
        $sale=$context['sale'];
        $type=bccomp($sale['balance_due'],'0',2)===0?'purchase_paid':(bccomp($sale['total_paid'],'0',2)>0?'purchase_installment':'purchase_unpaid');
        $payment=customer_query($db,'SELECT payment_id FROM sale_payments WHERE business_id=? AND sale_id=? AND idempotency_key=?',[$user['business_id'],$saleId,'initial-'.$key])->fetchColumn();
        notification_enqueue($db,$type,'purchase:'.$user['business_id'].':'.$saleId,['user_id'=>$user['user_id'],'business_id'=>$user['business_id'],'customer_id'=>$sale['customer_id'],'sale_id'=>$saleId,'payment_id'=>$payment?:null,'recipient_email'=>$context['customer']['email']]);
    } catch (Throwable $e) {error_log('ProfitPocket email: purchase_queue_unavailable');}
}
function customer_mail_data(array $context): array
{
    $sale=$context['sale'];$items=[];
    foreach ($sale['items'] as $item) {$items[]=['name'=>$item['product_name_snapshot'],'quantity'=>rtrim(rtrim($item['quantity'],'0'),'.'),'unit_price'=>$item['unit_price'],'total'=>sale_round(bcmul($item['quantity'],$item['unit_price'],5))];}
    return ['customer_name'=>$context['customer']['customer_name']??'Walk-in customer','business_name'=>$context['business']['business_name'],
        'sale_number'=>$sale['sale_number'],'sold_at'=>$sale['sold_at'],'total'=>$sale['net_sale'],'subtotal'=>$sale['subtotal'],'discount'=>bcsub($sale['subtotal'],$sale['net_sale'],2),'amount_paid'=>$sale['total_paid'],'remaining'=>$sale['balance_due'],'due_date'=>$sale['due_date'],'items'=>$items];
}
function customer_mail_resolve(PDO $db,array $job): array
{
    $owner=customer_query($db,"SELECT b.owner_user_id AS user_id,b.business_id,r.role_type FROM businesses b JOIN users u ON u.user_id=b.owner_user_id JOIN roles r ON r.role_id=u.role_id WHERE b.business_id=?",[$job['business_id']])->fetch();
    if (!$owner || !subscription_has_access($db,$owner)) {throw new DomainException('subscription_inactive');}
    $context=customer_mail_context($db,$owner,(int)$job['sale_id']);$manual=$job['notification_type']==='manual_reminder';
    $reason=customer_mail_reason($context,$manual);
    if ($reason) {throw new DomainException($reason);}
    if ((int)$context['sale']['customer_id']!==(int)$job['customer_id']) {throw new DomainException('customer_mismatch');}
    if (!$job['recipient_email'] || mb_strtolower($job['recipient_email'])!==mb_strtolower($context['customer']['email'])) {throw new DomainException('recipient_changed');}
    $data=customer_mail_data($context);
    if ($manual) {
        $log=customer_query($db,"SELECT after_data FROM activity_logs WHERE business_id=? AND actor_user_id=? AND entity_type='sale' AND entity_id=? AND action_code='CUSTOMER_REMINDER_REQUESTED' AND CAST(JSON_UNQUOTE(JSON_EXTRACT(after_data,'$.notification_id')) AS UNSIGNED)=? ORDER BY activity_id DESC LIMIT 1",[$job['business_id'],$job['user_id'],$job['sale_id'],$job['notification_id']])->fetchColumn();
        if (!$log) {throw new DomainException('reminder_record_missing');}
        $data['owner_message']=(json_decode($log,true)?:[])['message']??'';
    } else {
        $paid='0.00';
        foreach ($context['sale']['payments'] as $payment) {
            if ($payment['payment_kind']==='refund') {throw new DomainException('purchase_superseded');}
            if ((int)$payment['payment_id']===(int)$job['payment_id']) {$paid=$payment['amount'];}
            elseif ($payment['payment_kind']==='collection') {throw new DomainException('purchase_superseded');}
        }
        $data['amount_paid']=$paid;$data['remaining']=bcsub($data['total'],$paid,2);
        $expected=bccomp($data['remaining'],'0',2)===0?'purchase_paid':(bccomp($paid,'0',2)>0?'purchase_installment':'purchase_unpaid');
        if ($job['notification_type']!==$expected) {throw new DomainException('purchase_type_mismatch');}
    }
    return ['data'=>$data,'email'=>$context['customer']['email'],'name'=>$context['customer']['customer_name']];
}
function customer_mail_history(PDO $db,array $user,int $saleId): array
{
    return customer_query($db,"SELECT notification_id,notification_type,status,failure_code,recipient_email,message_id,created_at,accepted_at,attempts FROM notification_outbox WHERE business_id=? AND sale_id=? ORDER BY notification_id DESC LIMIT 30",[$user['business_id'],$saleId])->fetchAll();
}
function customer_reminder_cooldown(array $history): bool
{
    foreach ($history as $event) {
        if ($event['notification_type']==='manual_reminder' && strtotime($event['created_at'])>time()-86400) {return true;}
    }
    return false;
}
function customer_mail_label(string $status): string
{
    return match($status) {
        'accepted'=>'Accepted by Brevo; inbox delivery unconfirmed','pending','retry','busy'=>'Pending delivery',
        'failed'=>'Email sending failed','uncertain','processing'=>'Delivery outcome unconfirmed','skipped'=>'Email skipped',
        'no_customer_email'=>'No customer email available','invalid_recipient'=>'Customer email is invalid',
        'consent_missing'=>'Customer permission is required','not_posted'=>'Draft or voided sale: no email',
        'customer_ineligible'=>'Customer is inactive','settled'=>'This purchase is fully settled',
        'disabled'=>'Email sending is disabled or not configured','cooldown'=>'A reminder was requested in the last 24 hours; check its history before sending again','not_queued'=>'No purchase email queued',default=>'Email status unavailable',
    };
}
function customer_mail_dispatch(PDO $db,array $user,int $saleId,?int $paymentId=null,?array $config=null,?callable $transport=null): string
{
    try {
        $context=customer_mail_context($db,$user,$saleId);
        if ($reason=customer_mail_reason($context)) {return $reason;}
        $config??=email_config();if (!email_ready($config)) {return 'disabled';}
        $sql='SELECT notification_id FROM notification_outbox WHERE business_id=? AND sale_id=?';$values=[$user['business_id'],$saleId];
        if ($paymentId!==null) {$sql.=' AND payment_id=? AND notification_type IN (\'payment_recorded\',\'partial_payment\',\'balance_settled\')';$values[]=$paymentId;}
        else {$sql.=" AND notification_type IN ('purchase_paid','purchase_unpaid','purchase_installment')";}
        $id=customer_query($db,$sql.' ORDER BY notification_id LIMIT 1',$values)->fetchColumn();
        return $id?notification_process_one($db,(int)$id,$config,$transport):'not_queued';
    } catch (Throwable $e) {error_log('ProfitPocket email: customer_dispatch_unavailable');return 'unavailable';}
}
function customer_reminder_request(PDO $db,array $user,int $saleId,array $input,string $key): int
{
    sale_lock_business($db,$user);
    if (!preg_match('/\A[a-f0-9]{64}\z/',$key)) {throw new InvalidArgumentException('Reload the reminder form before sending.');}
    $context=customer_mail_context($db,$user,$saleId);
    if ($reason=customer_mail_reason($context,true)) {throw new InvalidArgumentException(customer_mail_label($reason));}
    if (!hash_equals(customer_mail_recipient_revision($context),sale_text($input,'recipient_revision'))) {throw new InvalidArgumentException('Customer contact details changed. Reopen the reminder and confirm the current recipient.');}
    if (!email_ready(email_config())) {throw new InvalidArgumentException(customer_mail_label('disabled'));}
    if (sale_text($input,'confirm_recipient')!=='1') {throw new InvalidArgumentException('Confirm the registered recipient before sending.');}
    $message=sale_text($input,'owner_message');
    if (!mb_check_encoding($message,'UTF-8') || mb_strlen($message)>500) {throw new InvalidArgumentException('Keep the optional message within 500 characters.');}
    $event=hash('sha256','manual-reminder:'.$user['business_id'].':'.$saleId.':'.$key);
    $previous=customer_query($db,'SELECT notification_id FROM notification_outbox WHERE business_id=? AND sale_id=? AND event_key=?',[$user['business_id'],$saleId,$event])->fetchColumn();
    if ($previous) {
        $log=customer_query($db,"SELECT after_data FROM activity_logs WHERE business_id=? AND actor_user_id=? AND action_code='CUSTOMER_REMINDER_REQUESTED' AND entity_id=? AND CAST(JSON_UNQUOTE(JSON_EXTRACT(after_data,'$.notification_id')) AS UNSIGNED)=? ORDER BY activity_id DESC LIMIT 1",[$user['business_id'],$user['user_id'],$saleId,$previous])->fetchColumn();
        if (!$log || (json_decode($log,true)['message']??'')!==$message) {throw new InvalidArgumentException('This reminder was already requested with different details. Reopen the transaction.');}
        return (int)$previous;
    }
    $recent=customer_query($db,"SELECT COUNT(*) FROM notification_outbox WHERE business_id=? AND sale_id=? AND notification_type='manual_reminder' AND created_at>DATE_SUB(CURRENT_TIMESTAMP,INTERVAL 24 HOUR)",[$user['business_id'],$saleId])->fetchColumn();
    if ($recent) {throw new InvalidArgumentException('A reminder was already requested for this purchase in the last 24 hours. Check its history before sending again.');}
    $customer=$context['customer'];
    $count=customer_query($db,"SELECT COUNT(*) FROM notification_outbox WHERE business_id=? AND customer_id=? AND notification_type='manual_reminder' AND created_at>DATE_SUB(CURRENT_TIMESTAMP,INTERVAL 24 HOUR)",[$user['business_id'],$customer['customer_id']])->fetchColumn();
    if ((int)$count>=3) {throw new InvalidArgumentException('This customer has reached the daily reminder limit.');}
    $id=notification_enqueue($db,'manual_reminder','manual-reminder:'.$user['business_id'].':'.$saleId.':'.$key,['user_id'=>$user['user_id'],'business_id'=>$user['business_id'],'customer_id'=>$customer['customer_id'],'sale_id'=>$saleId,'recipient_email'=>$customer['email']]);
    if (!$id) {throw new InvalidArgumentException('The reminder could not be queued. The transaction and its balance are unchanged.');}
    sale_audit($db,$user,$saleId,'CUSTOMER_REMINDER_REQUESTED',[],['notification_id'=>$id,'message'=>$message]);
    return $id;
}
