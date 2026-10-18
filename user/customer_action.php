<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/customers.php';
$user=require_business(); $connection=db(); $row=null; $history=[]; $error=null; $unavailable=false; $id=0; $duplicate_warning=false;
$modal=($_GET['modal']??'')==='1'; $action=is_string($_GET['action']??null)?$_GET['action']:'create';
$titles=['create'=>'Add Customer','details'=>'Customer details','edit'=>'Edit Customer','archive'=>'Archive customer?','restore'=>'Restore customer?'];
try {
    if (!isset($titles[$action])) { throw new CustomerNotFound('Customer action not found.'); }
    if ($_SERVER['REQUEST_METHOD']!=='POST' && $action==='details') { $connection->beginTransaction(); }
    if ($action!=='create') {
        $id=sale_id($_SERVER['REQUEST_METHOD']==='POST'?sale_text($_POST,'customer_id'):sale_text($_GET,'id'));
        $row=customer_read($connection,(int)$user['business_id'],$id);
    }
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        if ($action==='details') { http_response_code(405); throw new CustomerValidation('Customer details are read-only.'); }
        if (!valid_csrf() || !sale_valid_token($_POST,'customer-'.$action,$id)) { http_response_code(403); throw new CustomerValidation('This form expired or is invalid. Reopen it before saving.'); }
        $connection->beginTransaction(); $key=sale_text($_POST,'request_key');
        if ($action==='create' || $action==='edit') { $id=customer_save($connection,$user,$id,$_POST,$key); }
        else { customer_set_active($connection,$user,$id,$_POST,$key,$action==='restore'); }
        $connection->commit();
        $message=match($action) {'archive'=>'Customer archived. Their transactions and outstanding utang have been kept.','restore'=>'Customer restored and available for new sales.',default=>'Customer saved successfully.'};
        if ($modal) { header('Content-Type: application/json; charset=UTF-8'); echo json_encode(['success'=>true,'message'=>$message,'id'=>$id],JSON_THROW_ON_ERROR); exit; }
        flash($message); redirect_to('user/customers.php');
    }
    if ($action==='details') { $history=customer_history($connection,(int)$user['business_id'],$id); $connection->commit(); }
    if ($row && (($action==='archive' && !$row['is_active']) || ($action==='restore' && $row['is_active']))) { throw new CustomerValidation('The customer status changed. Reopen their details.'); }
} catch (CustomerNotFound $exception) { http_response_code(404); $error=$exception->getMessage(); $unavailable=true;
} catch (InvalidArgumentException $exception) {
    if (http_response_code()===200) { http_response_code(400); } $error=$exception->getMessage();
    $duplicate_warning=$exception instanceof CustomerValidation && $exception->shared_phone_warning;
    if ($_SERVER['REQUEST_METHOD']!=='POST') { $unavailable=true; }
} catch (Throwable $exception) { error_log('ProfitPocket: customer action failed, code '.$exception->getCode()); http_response_code(503); $error='This customer action could not be completed. Please try again.'; $unavailable=true; }
if ($connection->inTransaction()) { $connection->rollBack(); }
if ($modal && $_SERVER['REQUEST_METHOD']==='POST') {
    header('Content-Type: application/json; charset=UTF-8');
    $fields=isset($exception) && $exception instanceof CustomerValidation && $exception->field!==''?[$exception->field=>$error]:[];
    echo json_encode(['success'=>false,'message'=>$error,'fields'=>$fields,'duplicate_phone'=>$duplicate_warning],JSON_THROW_ON_ERROR); exit;
}
$token=sale_form_token('customer-'.$action,$id);
if ($_SERVER['REQUEST_METHOD']==='POST') { try { if (sale_valid_token($_POST,'customer-'.$action,$id)) { $token=['request_key'=>sale_text($_POST,'request_key'),'request_signature'=>sale_text($_POST,'request_signature')]; } } catch (InvalidArgumentException $ignored) {} }
$page_title=$titles[$action]??'Customer'; $active_page='customers'; $dashboard_layout=true;
if (!$modal) { require __DIR__.'/../includes/header.php'; require __DIR__.'/../includes/navbar.php'; echo '<main class="dashboard-main" id="dashboard-content"><div class="dashboard-panel customer-fallback">'; }
require __DIR__.'/../includes/customer_content.php';
if (!$modal) { echo '</div></main>'; require __DIR__.'/../includes/footer.php'; }
