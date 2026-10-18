<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/products.php';
$user=require_business(); $connection=db(); $row=null; $error=null; $unavailable=false; $id=0;
$modal=($_GET['modal']??'')==='1'; $action=is_string($_GET['action']??null)?$_GET['action']:'create';
$titles=['create'=>'Add Product','details'=>'Product details','edit'=>'Edit Product','archive'=>'Archive product?','restore'=>'Restore product?'];
try {
    if (!isset($titles[$action])) { throw new ProductNotFound('Product action not found.'); }
    if (!in_array($_SERVER['REQUEST_METHOD'],['GET','POST'],true)) { http_response_code(405); throw new ProductValidation('This request is not supported.'); }
    if ($action!=='create') { $id=sale_id($_SERVER['REQUEST_METHOD']==='POST'?sale_text($_POST,'product_id'):sale_text($_GET,'id')); $row=product_read($connection,(int)$user['business_id'],$id); }
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        if ($action==='details') { http_response_code(405); throw new ProductValidation('Product details are read-only.'); }
        if (!valid_csrf() || !sale_valid_token($_POST,'product-'.$action,$id)) { http_response_code(403); throw new ProductValidation('This form expired or is invalid. Reopen it before saving.'); }
        $connection->beginTransaction(); $key=sale_text($_POST,'request_key');
        if ($action==='create' || $action==='edit') { $id=product_save($connection,$user,$id,$_POST,$key); }
        else { product_set_active($connection,$user,$id,$_POST,$key,$action==='restore'); }
        $connection->commit();
        $message=match($action) {'archive'=>'Product archived. Historical sales have been kept.','restore'=>'Product restored and available for new sales.',default=>'Product saved successfully.'};
        if (in_array($action,['create','edit'],true)) { $prices=product_validate($_POST); if (bccomp($prices['default_selling_price'],$prices['default_unit_cost'],2)<0) { $message.=' Its selling price is below cost.'; } }
        if ($modal) { header('Content-Type: application/json; charset=UTF-8'); echo json_encode(['success'=>true,'message'=>$message,'id'=>$id],JSON_THROW_ON_ERROR); exit; }
        flash($message); redirect_to('user/products.php');
    }
    if ($row && (($action==='archive' && !$row['is_active']) || ($action==='restore' && $row['is_active']))) { throw new ProductValidation('The product status changed. Reopen its details.'); }
} catch (ProductNotFound $exception) { http_response_code(404); $error=$exception->getMessage(); $unavailable=true;
} catch (InvalidArgumentException $exception) { if (http_response_code()===200) { http_response_code(400); } $error=$exception->getMessage(); if ($_SERVER['REQUEST_METHOD']!=='POST') { $unavailable=true; }
} catch (PDOException $exception) {
    if (($exception->errorInfo[1]??null)===1062) { http_response_code(400); $exception=new ProductValidation('This product code is already used in your business.','sku'); $error=$exception->getMessage(); }
    else { error_log('ProfitPocket: product database action failed.'); http_response_code(503); $error='This product action could not be completed. Please try again.'; $unavailable=true; }
} catch (Throwable $exception) { error_log('ProfitPocket: product action failed.'); http_response_code(503); $error='This product action could not be completed. Please try again.'; $unavailable=true; }
if ($connection->inTransaction()) { $connection->rollBack(); }
if ($modal && $_SERVER['REQUEST_METHOD']==='POST') {
    header('Content-Type: application/json; charset=UTF-8'); $fields=isset($exception) && $exception instanceof ProductValidation && $exception->field!==''?[$exception->field=>$error]:[];
    echo json_encode(['success'=>false,'message'=>$error,'fields'=>$fields],JSON_THROW_ON_ERROR); exit;
}
$token=sale_form_token('product-'.$action,$id);
if ($_SERVER['REQUEST_METHOD']==='POST') { try { if (sale_valid_token($_POST,'product-'.$action,$action==='create'?0:$id)) { $token=['request_key'=>sale_text($_POST,'request_key'),'request_signature'=>sale_text($_POST,'request_signature')]; } } catch (InvalidArgumentException $ignored) {} }
$page_title=$titles[$action]??'Product'; $active_page='products'; $dashboard_layout=true;
if (!$modal) { require __DIR__.'/../includes/header.php'; require __DIR__.'/../includes/navbar.php'; echo '<main class="dashboard-main" id="dashboard-content"><div class="dashboard-panel product-fallback">'; }
require __DIR__.'/../includes/product_content.php';
if (!$modal) { echo '</div></main>'; require __DIR__.'/../includes/footer.php'; }
