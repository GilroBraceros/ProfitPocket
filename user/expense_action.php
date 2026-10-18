<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/expenses.php';
$user=require_business(); $connection=db();
$modal=($_GET['modal']??'')==='1'; $row=null; $schedule=null; $error=null; $unavailable=false; $stored=[]; $committed=false;
$action=is_string($_GET['action']??null)?$_GET['action']:'create';
$id=0; $allowed=['create','details','edit','void','schedule','confirm','toggle','category'];
$titles=['create'=>'Add Expense','details'=>'Expense details','edit'=>'Edit expense','void'=>'Void expense?','schedule'=>'New recurring schedule','confirm'=>'Record scheduled payment','toggle'=>'Change schedule status','category'=>'Add expense category'];
try {
    if (!in_array($action,$allowed,true)) { throw new ExpenseNotFound('Action not found.'); }
    if (in_array($action,['details','edit','void','confirm','toggle'],true)) {
        $field=in_array($action,['confirm','toggle'],true)?'schedule_id':'expense_id';
        $id=sale_id($_SERVER['REQUEST_METHOD']==='POST'?sale_text($_POST,$field):sale_text($_GET,'id'));
        if (in_array($action,['confirm','toggle'],true)) { $schedule=expense_schedule_read($connection,(int)$user['business_id'],$id); }
        else { $row=expense_read($connection,(int)$user['business_id'],$id); }
    }
    // A retried void POST must reach the signed-key replay check. New voids and
    // every edit remain blocked by the financial helpers for a voided record.
    if ($row && in_array($action,['edit','void'],true) && $row['expense_status']==='void' && !($action==='void' && $_SERVER['REQUEST_METHOD']==='POST')) { throw new ExpenseValidation('This expense is already voided and cannot be changed.'); }
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        if ($action==='details') { http_response_code(405); throw new ExpenseValidation('Expense details are read-only.'); }
        if (!valid_csrf() || !sale_valid_token($_POST,'expense-'.$action,$id)) { http_response_code(403); throw new ExpenseValidation('This form expired or is invalid. Reopen it before saving.'); }
        $receipt=in_array($action,['create','edit','confirm'],true)?expense_uploaded_receipt($_FILES):null;
        $connection->beginTransaction(); $key=sale_text($_POST,'request_key');
        switch ($action) {
            case 'create': $id=expense_create($connection,$user,$_POST,$key,$receipt,$stored); break;
            case 'confirm':
                if ((string)$id!==sale_text($_POST,'recurring_expense_id')) { throw new ExpenseValidation('Invalid schedule reference.'); }
                $id=expense_create($connection,$user,$_POST,$key,$receipt,$stored); break;
            case 'edit': expense_edit($connection,$user,$id,$_POST,$key,$receipt,$stored); break;
            case 'void': expense_void($connection,$user,$id,$_POST,$key); break;
            case 'schedule': $id=expense_schedule_create($connection,$user,$_POST,$key); break;
            case 'toggle': expense_schedule_toggle($connection,$user,$id,$_POST,$key); break;
            case 'category': $id=expense_category_create($connection,$user,$_POST,$key); break;
        }
        $connection->commit(); $committed=true;
        $message=match($action) {'void'=>'Expense voided. Its history has been kept.','schedule'=>'Schedule saved. It does not affect totals until a payment is recorded.','toggle'=>'Schedule status updated.','category'=>'Category saved. Existing categories are reused.','edit'=>'Expense details saved.',default=>'Expense saved successfully.'};
        if ($modal) { header('Content-Type: application/json; charset=UTF-8'); echo json_encode(['success'=>true,'message'=>$message,'id'=>$id],JSON_THROW_ON_ERROR); exit; }
        flash($message); redirect_to('user/expenses.php');
    }
} catch (ExpenseNotFound $exception) { http_response_code(404); $error=$exception->getMessage(); $unavailable=true;
} catch (InvalidArgumentException $exception) {
    if (http_response_code()===200) { http_response_code(400); } $error=$exception->getMessage();
    if ($_SERVER['REQUEST_METHOD']!=='POST') { $unavailable=true; }
} catch (Throwable $exception) { error_log('ProfitPocket: expense action failed, code '.$exception->getCode()); http_response_code(503); $error='The action could not be completed. Please try again.'; }
if ($connection->inTransaction()) { $connection->rollBack(); }
if (!$committed) { expense_discard_new_receipts($stored); }
if ($modal && $_SERVER['REQUEST_METHOD']==='POST') {
    header('Content-Type: application/json; charset=UTF-8');
    $fields=isset($exception) && $exception instanceof ExpenseValidation && $exception->field!==''?[$exception->field=>$error]:[];
    echo json_encode(['success'=>false,'message'=>$error,'fields'=>$fields],JSON_THROW_ON_ERROR); exit;
}
$catalog=['categories'=>[],'methods'=>[]];
try { $catalog=expense_catalog($connection,(int)$user['business_id']); }
catch (Throwable $exception) { error_log('ProfitPocket: expense options unavailable.'); http_response_code(503); $error='Expense options are temporarily unavailable. Please try again.'; $unavailable=true; }
$page_title=$titles[$action]??'Expense'; $dashboard_layout=true; $active_page='expenses';
$token=sale_form_token('expense-'.$action,$id);
if ($_SERVER['REQUEST_METHOD']==='POST') { try { if (sale_valid_token($_POST,'expense-'.$action,$id)) { $token=['request_key'=>sale_text($_POST,'request_key'),'request_signature'=>sale_text($_POST,'request_signature')]; } } catch (InvalidArgumentException $ignored) {} }
if (!$modal) { require __DIR__.'/../includes/header.php'; require __DIR__.'/../includes/navbar.php'; echo '<main class="dashboard-main" id="dashboard-content"><div class="dashboard-heading"><h1>'.e($page_title).'</h1></div>'; }
require __DIR__.'/../includes/expense_content.php';
if (!$modal) { echo '</main>'; require __DIR__.'/../includes/footer.php'; }
