<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/payments.php';
$user=require_business(); $connection=db(); $row=null; $payment=null; $customer=null; $error=null; $unavailable=false; $id=0; $methods=[]; $errorFields=[];
$modal=($_GET['modal']??'')==='1'; $action=is_string($_GET['action']??null)?$_GET['action']:'details';
$titles=['details'=>'Transaction details','history'=>'Payment history','payment_details'=>'Payment details','payment'=>'Record Payment','due'=>'Edit Due Date'];
header('Cache-Control: no-store');
try {
    if (!isset($titles[$action])) { throw new SaleNotFound('Payment action not found.'); }
    if (!in_array($_SERVER['REQUEST_METHOD'],['GET','POST'],true)) { http_response_code(405); throw new UtangValidation('This request is not supported.'); }
    $id=sale_id($_SERVER['REQUEST_METHOD']==='POST'?sale_text($_POST,'sale_id'):sale_text($_GET,'id'));
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        if (!in_array($action,['payment','due'],true)) { http_response_code(405); throw new UtangValidation('Payment details and history are read-only.'); }
        if (!valid_csrf() || !sale_valid_token($_POST,'utang-'.$action,$id)) { http_response_code(403); throw new UtangValidation('This form expired or is invalid. Reopen it before saving.'); }
        $connection->beginTransaction();
        if ($action==='payment') { $saved=payment_collect($connection,$user,$id,$_POST,sale_text($_POST,'request_key')); $replayed=$saved['replayed']; }
        else { $replayed=utang_edit_due($connection,$user,$id,$_POST,sale_text($_POST,'request_key')); }
        $connection->commit();
        $message=$action==='payment'?($replayed?'This payment was already recorded. No second payment was added.':'Payment recorded successfully.'):($replayed?'This due-date change was already saved.':'Due date updated successfully.');
        if ($modal) { header('Content-Type: application/json; charset=UTF-8'); echo json_encode(['success'=>true,'message'=>$message,'id'=>$id],JSON_THROW_ON_ERROR); exit; }
        flash($message); redirect_to('user/payments.php?tab=balances');
    }
    $connection->beginTransaction();
    if ($action==='payment_details') { $record=payment_read($connection,(int)$user['business_id'],$id); $payment=$record['payment']; $row=$record['sale']; }
    else { $row=payment_sale_read($connection,(int)$user['business_id'],$id); }
    if ($row['customer_id']) { $customer=customer_read($connection,(int)$user['business_id'],(int)$row['customer_id']); }
    $methods=payment_method_catalog($connection);
    $connection->commit();
    if ($action==='payment' && bccomp($row['balance_due'],'0',2)<=0) { throw new UtangValidation('This sale is fully paid. Its payment history is still available.'); }
    if ($action==='due' && $row['sale_type']!=='credit') { throw new SaleNotFound('This sale has no credit due date to edit.'); }
} catch (SaleNotFound|CustomerNotFound $e) { http_response_code(404); $error=$e->getMessage(); $unavailable=true;
} catch (InvalidArgumentException $e) {
    if (http_response_code()===200) { http_response_code(400); } $error=$e->getMessage();
    if ($e instanceof UtangValidation && $e->field!=='') { $errorFields=[$e->field=>$error]; }
    if ($_SERVER['REQUEST_METHOD']!=='POST') { $unavailable=true; }
} catch (Throwable $e) { error_log('ProfitPocket: payment action failed, code '.$e->getCode()); http_response_code(503); $error='This payment action could not be completed. Please try again.'; $unavailable=true; }
if ($connection->inTransaction()) { $connection->rollBack(); }
if ($modal && $_SERVER['REQUEST_METHOD']==='POST') { header('Content-Type: application/json; charset=UTF-8'); echo json_encode(['success'=>false,'message'=>$error,'fields'=>$errorFields],JSON_THROW_ON_ERROR); exit; }
if ($_SERVER['REQUEST_METHOD']==='POST' && !$unavailable && $id) {
    try { $row=payment_sale_read($connection,(int)$user['business_id'],$id); $methods=payment_method_catalog($connection); }
    catch (Throwable $e) { $unavailable=true; }
}
$token=sale_form_token('utang-'.$action,$id);
if ($_SERVER['REQUEST_METHOD']==='POST') { try { if (sale_valid_token($_POST,'utang-'.$action,$id)) { $token=['request_key'=>sale_text($_POST,'request_key'),'request_signature'=>sale_text($_POST,'request_signature')]; } } catch (InvalidArgumentException $e) {} }
$page_title=$titles[$action]??'Payment'; $active_page='payments'; $dashboard_layout=true;
if (!$modal) { require __DIR__.'/../includes/header.php'; require __DIR__.'/../includes/navbar.php'; echo '<main class="dashboard-main payments-page" id="dashboard-content"><div class="dashboard-panel utang-fallback">'; }
require __DIR__.'/../includes/payment_content.php';
if (!$modal) { echo '</div></main>'; require __DIR__.'/../includes/footer.php'; }
