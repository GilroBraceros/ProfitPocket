<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/sales.php';
require_once __DIR__ . '/../includes/sales_ui.php';
$modal = sale_modal_request();
$user = require_business();
$connection = db();
$dashboard_layout = true;
$active_page = 'sales';
$page_title = 'Add sale';
$error = null;
$sale = null;
$id = 0;
$unavailable = false;
$form = ['customer_id'=>'','sold_at'=>date('Y-m-d\TH:i'),'save_mode'=>'posted','payment_plan'=>'full',
    'discount_type'=>'none','discount_value'=>'0.00','initial_payment'=>'0.00','payment_method_id'=>'',
    'payment_reference'=>'','payment_category'=>'','wallet_method_id'=>'','provider_name'=>'','payment_notes'=>'','due_date'=>'','notes'=>'','items'=>[['product_id'=>'','quantity'=>'1','unit_price'=>'0.00','sale_item_id'=>'']]];
if (($_GET['credit']??'')==='1') { $form['payment_plan']='unpaid'; }
try {
    $id_value = $_SERVER['REQUEST_METHOD']==='POST' ? sale_text($_POST,'sale_id') : sale_text($_GET,'id');
    $id = $id_value === '' ? 0 : sale_id($id_value);
    if ($id) {
        $sale = sale_read($connection, (int)$user['business_id'], $id);
        if ($sale['sale_status']==='void') { throw new InvalidArgumentException('Voided sales cannot be edited.'); }
        if ($sale['sale_status']==='draft' && $sale['payments']) { throw new InvalidArgumentException('This draft has payment history. Item edits are blocked. Open its details to void it safely if needed.'); }
        $form = array_replace($form, ['customer_id'=>(string)($sale['customer_id']??''),'sold_at'=>(new DateTimeImmutable($sale['sold_at']))->format('Y-m-d\TH:i'),
            'save_mode'=>'draft','payment_plan'=>$sale['sale_type']==='credit'?'unpaid':'full','discount_type'=>$sale['discount_type'],
            'discount_value'=>$sale['discount_value'],'notes'=>$sale['notes']??'','due_date'=>$sale['due_date']??'',
            'items'=>array_map(fn($item)=>['product_id'=>(string)$item['product_id'],'quantity'=>$item['quantity'],'unit_price'=>$item['unit_price'],'sale_item_id'=>(string)$item['sale_item_id'],'snapshot_cost'=>$item['unit_cost']], $sale['items'])]);
        $page_title = $sale['sale_status']==='posted' ? 'Edit sale notes' : 'Edit draft sale';
    }
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        if (!valid_csrf() || !sale_valid_token($_POST, $id ? 'edit' : 'create', $id)) { http_response_code(403); throw new InvalidArgumentException('This form expired or is invalid. Reload it before submitting.'); }
        $connection->beginTransaction();
        if ($id) { sale_edit($connection, $user, $id, $_POST, sale_text($_POST,'request_key')); }
        else { $id = sale_create($connection, $user, $_POST, sale_text($_POST,'request_key')); }
        $connection->commit();
        if ($modal) { sale_ui_json(true, $sale && $sale['sale_status']==='posted' ? 'Sale notes saved.' : 'Sale saved successfully.', ['sale_id'=>$id]); }
        flash('Sale saved successfully.');
        redirect_to('user/sale_view.php?id='.$id);
    }
} catch (SaleNotFound $exception) {
    if ($connection->inTransaction()) { $connection->rollBack(); }
    http_response_code(404); $error='Sale not found.'; $unavailable=true;
} catch (InvalidArgumentException $exception) {
    if ($connection->inTransaction()) { $connection->rollBack(); }
    if (http_response_code()===200) { http_response_code(400); }
    $error=$exception->getMessage();
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        foreach ($form as $key=>$value) { if ($key!=='items' && isset($_POST[$key]) && is_string($_POST[$key])) { $form[$key]=trim($_POST[$key]); } }
        if (isset($_POST['items']) && is_array($_POST['items'])) {
            $form['items']=[];
            foreach (array_slice($_POST['items'],0,50) as $row) {
                if (!is_array($row)) { continue; }
                $clean=[];
                foreach (['product_id','quantity','unit_price','sale_item_id'] as $key) { $clean[$key]=isset($row[$key])&&is_string($row[$key])?$row[$key]:''; }
                foreach ($sale['items']??[] as $old_item) {
                    if ((string)$old_item['sale_item_id']===$clean['sale_item_id'] && (string)$old_item['product_id']===$clean['product_id']) { $clean['snapshot_cost']=$old_item['unit_cost']; break; }
                }
                $form['items'][]=$clean;
            }
        }
    } else { $unavailable=true; }
} catch (Throwable $exception) {
    if ($connection->inTransaction()) { $connection->rollBack(); }
    error_log('ProfitPocket: save sale failed, code '.$exception->getCode());
    http_response_code(503); $error='The sale could not be saved. No incomplete transaction was kept. Please try again.';
}
if ($modal && $_SERVER['REQUEST_METHOD']==='POST') { sale_ui_json(false, $error ?? 'The sale could not be saved.', ['fields'=>sale_ui_errors($error??'',$_POST)]); }
$catalog = sale_catalog($connection,(int)$user['business_id']);
$old_ids = array_column($sale['items']??[], 'product_id');
$products=array_values(array_filter($catalog['products'],fn($product)=>$product['is_active']||in_array($product['product_id'],$old_ids)));
$customers=array_values(array_filter($catalog['customers'],fn($customer)=>$customer['is_active']||($sale&&(int)$sale['customer_id']===(int)$customer['customer_id'])));
$notes_only = $sale && $sale['sale_status']==='posted';
$token = sale_form_token($sale ? 'edit' : 'create', $sale ? (int)$sale['sale_id'] : 0);
// Preserve a valid creation key after validation errors, so retrying a lost
// response cannot create another sale. Invalid or expired keys are replaced.
if ($_SERVER['REQUEST_METHOD']==='POST' && !$sale) {
    try { if (sale_valid_token($_POST,'create')) { $token=['request_key'=>sale_text($_POST,'request_key'),'request_signature'=>sale_text($_POST,'request_signature')]; } } catch (InvalidArgumentException $ignored) {}
}
if ($modal) {
    header('Cache-Control: no-store');
    if ($unavailable) { require __DIR__.'/../includes/sale_modal_error.php'; }
    else { require __DIR__.'/../includes/sale_form_content.php'; }
    exit;
}
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/navbar.php';
?>
<main class="dashboard-main" id="dashboard-content">
    <div class="dashboard-heading"><div><a class="sales-back" href="<?= e(app_url('user/sales.php')) ?>">← Sales Management</a><h1><?= e($page_title) ?></h1></div></div>
    <?php if ($unavailable): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php else: ?>
    <?php require __DIR__.'/../includes/sale_form_content.php'; ?>
    <?php endif; ?>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
