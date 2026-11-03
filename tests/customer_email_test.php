<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(403);exit;}
require __DIR__.'/../includes/auth.php';require __DIR__.'/../includes/customer_email.php';
function cm_expect(bool $ok,string $message):void {if (!$ok) {throw new RuntimeException($message);}}
function cm_reject(PDO $db,callable $call):void
{
    $db->exec('SAVEPOINT customer_email_reject');
    try {$call();throw new LogicException('Invalid reminder was accepted.');}
    catch (InvalidArgumentException|SaleNotFound $e) {$db->exec('ROLLBACK TO SAVEPOINT customer_email_reject');}
}
function cm_job(PDO $db,int $sale,string $type):array
{
    $job=customer_query($db,'SELECT * FROM notification_outbox WHERE sale_id=? AND notification_type=?',[$sale,$type])->fetch();
    cm_expect((bool)$job,'Expected notification was not queued: '.$type);return $job;
}
function cm_fixture(PDO $db,string $suffix):array
{
    $owner=create_owner($db,['full_name'=>'Customer mail fixture','username'=>'customer_mail_'.$suffix,'email'=>'owner_'.$suffix.'@example.invalid','business_name'=>'Fixture store <safe>','business_category_id'=>(string)$db->query('SELECT business_category_id FROM business_categories LIMIT 1')->fetchColumn()],'Customer-mail-fixture-123!',false);
    $business=(int)customer_query($db,'SELECT business_id FROM businesses WHERE owner_user_id=?',[$owner])->fetchColumn();$user=['user_id'=>$owner,'business_id'=>$business];
    $customer=customer_save($db,$user,0,['customer_name'=>'Fixture customer <safe>','email'=>'customer_'.$suffix.'@example.invalid'],bin2hex(random_bytes(32)));
    notification_save_preferences($db,$user,$customer,['email'=>'customer_'.$suffix.'@example.invalid','email_preferences_present'=>'1','email_receipts'=>'1','email_reminders'=>'1','email_consent_note'=>'Explicit fictional test permission','email_consent_confirm'=>'1'],null);
    $product=sale_product_create($db,$user,['product_name'=>'First item <safe>','default_selling_price'=>'100','default_unit_cost'=>'10'],bin2hex(random_bytes(32)));
    $second=sale_product_create($db,$user,['product_name'=>'Second item','default_selling_price'=>'100','default_unit_cost'=>'10'],bin2hex(random_bytes(32)));
    $base=['customer_id'=>(string)$customer,'sold_at'=>date('Y-m-d\TH:i',strtotime('-1 day')),'save_mode'=>'posted','payment_plan'=>'unpaid','initial_payment'=>'0','payment_category'=>'cash','discount_type'=>'none','discount_value'=>'0','due_date'=>'','items'=>[['product_id'=>(string)$product,'quantity'=>'2','unit_price'=>'100'],['product_id'=>(string)$second,'quantity'=>'1','unit_price'=>'100']]];
    return compact('owner','business','user','customer','base');
}
function cm_snapshot(array $user,array $context,array $config,string $resultDirectory):void
{
    $modal=true;$surface='sale';$titleId='sales-modal-title';$id=(int)$context['sale']['sale_id'];$reason=null;$history=[];$error=null;$token=sale_form_token('customer-reminder',$id);
    $preview=email_render('manual_reminder',customer_mail_data($context)+['owner_message'=>''],$config)['html'];
    $preview=email_browser_preview($preview,'/profitpocket');
    $dashboard_layout=false;$active_page='sales';$page_title='Payment reminder test';
    ob_start();require __DIR__.'/../includes/header.php';echo '<main class="container py-3"></main><div id="sales-action-modal" class="modal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down"><div class="modal-content">';require __DIR__.'/../includes/customer_reminder_form.php';echo '</div></div></div>';require __DIR__.'/../includes/footer.php';file_put_contents($resultDirectory.'/reminder-page.html',ob_get_clean());
}
function cm_counts(PDO $db):array
{
    $out=[];foreach(['users','businesses','customers','products','sales','sale_items','sale_payments','customer_credits','activity_logs','notification_outbox','customer_email_preferences'] as $table) {$out[$table]=$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();}return $out;
}
$config=['BREVO_EMAIL_ENABLED'=>true,'BREVO_API_KEY'=>'fabricated-customer-mail-test-only','BREVO_SENDER_EMAIL'=>'notify@example.invalid','BREVO_SENDER_NAME'=>'ProfitPocket','BREVO_REPLY_TO_EMAIL'=>'support@example.invalid','PROFITPOCKET_BASE_URL'=>'https://profitpocket.example.test','PROFITPOCKET_NOTIFICATION_SECRET'=>str_repeat('a',64),'PP_SUBSCRIPTIONS_ENABLED'=>false];
$environment=[];foreach($config as $key=>$value){$environment[$key]=getenv($key);putenv($key.'='.(is_bool($value)?($value?'true':'false'):$value));}
$db=db();$calls=0;$payloads=[];$before=cm_counts($db);
$resultDirectory=__DIR__.'/http-results/browser-customer-email';if(!is_dir($resultDirectory)){mkdir($resultDirectory,0700,true);}
$transport=static function(array $payload)use(&$calls,&$payloads):array {$calls++;$payloads[]=$payload;cm_expect(!str_contains($payload['htmlContent'],'<script>'),'Unsafe email HTML');return ['http'=>201,'body'=>'{"messageId":"<customer-test-'.$calls.'@example.invalid>"}'];};
try {
    $db->beginTransaction();$fixture=cm_fixture($db,bin2hex(random_bytes(7)));extract($fixture);
    foreach (['purchase_paid'=>['payment_plan'=>'full','initial_payment'=>'300'],'purchase_unpaid'=>[],'purchase_installment'=>['payment_plan'=>'partial','initial_payment'=>'100']] as $type=>$changes) {
        $key=bin2hex(random_bytes(32));$id=sale_create($db,$user,array_replace($base,$changes),$key);$sales[$type]=$id;
        $job=cm_job($db,$id,$type);$data=notification_resolve($db,$job,$config)['data'];
        file_put_contents($resultDirectory.'/'.$type.'.html',email_render($type,$data,$config)['html']);
        cm_expect(count($data['items'])===2 && $data['total']==='300.00' && $data['remaining']===match($type){'purchase_paid'=>'0.00','purchase_installment'=>'200.00',default=>'300.00'},'Purchase amounts/items wrong.');
        cm_expect(notification_attempt($db,$job,$config,$transport)==='accepted','Purchase email not accepted.');
        cm_expect(!str_contains(end($payloads)['htmlContent'],'Due date'),'Missing due date was invented.');
        $count=$calls;sale_create($db,$user,array_replace($base,$changes),$key);notification_attempt($db,$job,$config,$transport);
        cm_expect($calls===$count && (int)customer_query($db,'SELECT COUNT(*) FROM notification_outbox WHERE sale_id=?',[$id])->fetchColumn()===1,'Purchase replay duplicated a notification.');
    }
    $unpaid=$sales['purchase_unpaid'];$context=customer_mail_context($db,$user,$unpaid);$preview=customer_mail_data($context)+['owner_message'=>'Please call <script>alert(1)</script>'];
    $discounted=sale_create($db,$user,array_replace($base,['discount_type'=>'fixed','discount_value'=>'30','payment_plan'=>'full','initial_payment'=>'270']),bin2hex(random_bytes(32)));
    $discountData=notification_resolve($db,cm_job($db,$discounted,'purchase_paid'),$config)['data'];cm_expect($discountData['total']==='270.00' && $discountData['discount']==='30.00' && str_contains(email_render('purchase_paid',$discountData,$config)['html'],'Discount'),'Discounted purchase details do not match the existing sale calculation.');
    cm_expect(str_contains(email_render('manual_reminder',$preview,$config)['html'],'&lt;script&gt;'),'Personal reminder message was not escaped.');
    file_put_contents($resultDirectory.'/manual_reminder.html',email_render('manual_reminder',$preview,$config)['html']);
    file_put_contents($resultDirectory.'/preview-data.json',json_encode(customer_mail_data($context),JSON_THROW_ON_ERROR));
    cm_snapshot($user,$context,$config,$resultDirectory);
    $input=['confirm_recipient'=>'1','owner_message'=>'Please coordinate with our store.','recipient_email'=>'unrelated@example.invalid','recipient_revision'=>customer_mail_recipient_revision($context)];$key=bin2hex(random_bytes(32));
    cm_reject($db,fn()=>customer_reminder_request($db,$user,$unpaid,array_replace($input,['recipient_revision'=>'stale']),$key));
    $reminder=customer_reminder_request($db,$user,$unpaid,$input,$key);$job=customer_query($db,'SELECT * FROM notification_outbox WHERE notification_id=?',[$reminder])->fetch();
    cm_expect($job['recipient_email']===customer_query($db,'SELECT email FROM customers WHERE business_id=? AND customer_id=?',[$business,$customer])->fetchColumn(),'Reminder recipient was overridden.');
    cm_expect(customer_reminder_request($db,$user,$unpaid,$input,$key)===$reminder,'Reminder replay created a new event.');
    cm_reject($db,fn()=>customer_reminder_request($db,$user,$unpaid,array_replace($input,['owner_message'=>'Different']),$key));
    cm_reject($db,fn()=>customer_reminder_request($db,$user,$unpaid,$input,bin2hex(random_bytes(32))));
    cm_expect(notification_attempt($db,$job,$config,$transport)==='accepted','Manual reminder did not send.');
    cm_expect(notification_resolve($db,$job,$config)['data']['owner_message']===$input['owner_message'],'Reminder audit/message linkage missing.');
    $installment=$sales['purchase_installment'];$pending=customer_reminder_request($db,$user,$installment,$input,bin2hex(random_bytes(32)));
    $paymentInput=['amount'=>'100','payment_category'=>'cash','paid_at'=>date('Y-m-d\TH:i'),'reference_number'=>'TEST-ADDITIONAL'];
    payment_collect($db,$user,$installment,$paymentInput,bin2hex(random_bytes(32)));$partial=cm_job($db,$installment,'partial_payment');
    $data=notification_resolve($db,$partial,$config)['data'];cm_expect($data['previous']==='200.00' && $data['remaining']==='100.00','Installment balance calculation changed.');
    cm_expect(notification_attempt($db,$partial,$config,$transport)==='accepted','Additional installment receipt failed.');
    payment_collect($db,$user,$installment,$paymentInput,bin2hex(random_bytes(32)));$settled=cm_job($db,$installment,'balance_settled');
    cm_expect(notification_attempt($db,$settled,$config,$transport)==='accepted','Settlement email failed.');
    $count=$calls;$obsolete=customer_query($db,'SELECT * FROM notification_outbox WHERE notification_id=?',[$pending])->fetch();
    cm_expect(notification_attempt($db,$obsolete,$config,$transport)==='skipped' && $calls===$count,'Settled purchase received an unpaid reminder.');
    cm_reject($db,fn()=>customer_reminder_request($db,$user,$installment,$input,bin2hex(random_bytes(32))));
    $firstSettlement=sale_create($db,$user,$base,bin2hex(random_bytes(32)));
    payment_collect($db,$user,$firstSettlement,array_replace($paymentInput,['amount'=>'300']),bin2hex(random_bytes(32)));
    cm_expect(notification_attempt($db,cm_job($db,$firstSettlement,'balance_settled'),$config,$transport)==='accepted','First payment settling an unpaid purchase was not a settlement notice.');
    $third=sale_create($db,$user,$base,bin2hex(random_bytes(32)));customer_reminder_request($db,$user,$third,$input,bin2hex(random_bytes(32)));
    $fourth=sale_create($db,$user,$base,bin2hex(random_bytes(32)));cm_reject($db,fn()=>customer_reminder_request($db,$user,$fourth,$input,bin2hex(random_bytes(32))));
    $noEmail=customer_save($db,$user,0,['customer_name'=>'No email fixture'],bin2hex(random_bytes(32)));
    $noSale=sale_create($db,$user,array_replace($base,['customer_id'=>(string)$noEmail]),bin2hex(random_bytes(32)));
    cm_expect(customer_mail_reason(customer_mail_context($db,$user,$noSale))==='no_customer_email' && !customer_query($db,'SELECT 1 FROM notification_outbox WHERE sale_id=?',[$noSale])->fetchColumn(),'Missing email prevented sale or queued mail.');
    $foreign=cm_fixture($db,bin2hex(random_bytes(7)));$foreignSale=sale_create($db,$foreign['user'],$foreign['base'],bin2hex(random_bytes(32)));
    cm_reject($db,fn()=>customer_reminder_request($db,$user,$foreignSale,$input,bin2hex(random_bytes(32))));
    $failure=sale_create($db,$user,$base,bin2hex(random_bytes(32)));$failed=cm_job($db,$failure,'purchase_unpaid');
    cm_expect(notification_attempt($db,$failed,$config,fn()=>['http'=>401,'body'=>'{}'])==='failed' && sale_read($db,$business,$failure)['sale_status']==='posted','Provider failure removed a successful sale.');
    $beforeDispatch=$calls;cm_expect(customer_mail_dispatch($db,$user,$unpaid,null,$config,$transport)==='unavailable' && $calls===$beforeDispatch,'Purchase dispatcher sent before commit.');
    $db->rollBack();cm_expect(cm_counts($db)===$before,'Rollback retained customer email test rows.');
    echo "PASS: paid/unpaid/installment multi-item purchases, no invented due date, exact balances, duplicate prevention, manual reminder consent/recipient/message/history/cooldown, additional installments, settlement suppression, missing email, owner isolation and Brevo failure; all financial fixtures rolled back.\n";
} finally {
    if($db->inTransaction()){$db->rollBack();}
    foreach($environment as$key=>$value){putenv($value===false?$key:$key.'='.$value);}
}
