<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/customer_email.php';
require_once __DIR__.'/../includes/sales_ui.php';
$user=require_business();$connection=db();$modal=sale_modal_request();$surface=($_GET['surface']??'')==='utang'?'utang':'sale';
$titleId=$surface==='utang'?'utang-modal-title':'sales-modal-title';$error=null;$context=null;$history=[];$reason=null;$preview='';$id=0;
header('Cache-Control: no-store');
try {
    $id=sale_id($_SERVER['REQUEST_METHOD']==='POST'?sale_text($_POST,'sale_id'):sale_text($_GET,'id'));
    $context=customer_mail_context($connection,$user,$id);$reason=customer_mail_reason($context,true);
    $config=email_config();if (!$reason && !email_ready($config)) {$reason='disabled';}
    $data=customer_mail_data($context);$data['owner_message']=sale_text($_POST,'owner_message');
    if (mb_strlen($data['owner_message'])>500 || !mb_check_encoding($data['owner_message'],'UTF-8')) {throw new InvalidArgumentException('Keep the optional message within 500 characters.');}
    $previewConfig=$config;
    if (parse_url($previewConfig['PROFITPOCKET_BASE_URL'],PHP_URL_SCHEME)!=='https') {$previewConfig['PROFITPOCKET_BASE_URL']='https://profitpocket.online'.APP_BASE_PATH;}
    $preview=email_render('manual_reminder',$data,$previewConfig)['html'];
    $preview=email_browser_preview($preview,APP_BASE_PATH);
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        if (!valid_csrf() || !sale_valid_token($_POST,'customer-reminder',$id)) {http_response_code(403);throw new InvalidArgumentException('This reminder form expired. Reopen it before sending.');}
        if (!hash_equals(customer_mail_recipient_revision($context),sale_text($_POST,'recipient_revision'))) {throw new InvalidArgumentException('Customer contact details changed. Reopen the reminder and confirm the current recipient.');}
        if (sale_text($_POST,'intent')==='preview') {sale_ui_json(true,'Email preview updated.',['html'=>$preview]);}
        $connection->beginTransaction();$job=customer_reminder_request($connection,$user,$id,$_POST,sale_text($_POST,'request_key'));$connection->commit();
        try {$status=notification_process_one($connection,$job);}catch(Throwable $e) {$status='unavailable';error_log('ProfitPocket email: reminder_delivery_unavailable');}
        $message='Reminder requested. '.customer_mail_label($status).'.';
        if ($modal) {sale_ui_json(true,$message,['sale_id'=>$id]);}
        flash($message);redirect_to('user/sale_view.php?id='.$id);
    }
    $history=customer_mail_history($connection,$user,$id);
    if (!$reason && customer_reminder_cooldown($history)) {$reason='cooldown';}
} catch (SaleNotFound $e) {http_response_code(404);$context=null;$error='Transaction not found.';}
catch (InvalidArgumentException $e) {if(http_response_code()===200){http_response_code(400);}$error=$e->getMessage();}
catch (Throwable $e) {http_response_code(503);$error='Customer email is temporarily unavailable.';error_log('ProfitPocket email: reminder_page_unavailable');}
if ($connection->inTransaction()) {$connection->rollBack();}
if ($_SERVER['REQUEST_METHOD']==='POST' && ($modal || sale_text($_POST,'intent')==='preview')) {sale_ui_json(false,$error??'Reminder could not be sent.');}
$token=sale_form_token('customer-reminder',$id);
$page_title='Send Payment Reminder';$active_page=$surface==='utang'?'payments':'sales';$dashboard_layout=true;
if (!$modal) {require __DIR__.'/../includes/header.php';require __DIR__.'/../includes/navbar.php';echo '<main class="dashboard-main" id="dashboard-content"><section class="dashboard-panel">';}
require __DIR__.'/../includes/customer_reminder_form.php';
if (!$modal) {echo '</section></main>';require __DIR__.'/../includes/footer.php';}
