<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/sales.php';
$user=require_business();
$connection=db();
$dashboard_layout=true;
$active_page='sales';
$page_title='Add product reference';
$error=null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        if (!valid_csrf()||!sale_valid_token($_POST,'product')) { http_response_code(403); throw new InvalidArgumentException('This form expired or is invalid. Please reload it.'); }
        $connection->beginTransaction();
        sale_product_create($connection,$user,$_POST,sale_text($_POST,'request_key'));
        $connection->commit();
        flash('Product reference added. It is now available when recording a sale.');
        redirect_to('user/sales.php');
    } catch (InvalidArgumentException $exception) {
        if ($connection->inTransaction()) { $connection->rollBack(); }
        if (http_response_code()===200) { http_response_code(400); }
        $error=$exception->getMessage();
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) { $connection->rollBack(); }
        error_log('ProfitPocket: product reference creation failed.'); http_response_code(503);
        $error='The product could not be saved. Please try again.';
    }
}
$token=sale_form_token('product');
if ($_SERVER['REQUEST_METHOD']==='POST') { try { if (sale_valid_token($_POST,'product')) { $token=['request_key'=>sale_text($_POST,'request_key'),'request_signature'=>sale_text($_POST,'request_signature')]; } } catch (InvalidArgumentException $ignored) {} }
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/navbar.php';
?>
<main class="dashboard-main" id="dashboard-content"><div class="dashboard-heading"><div><a class="sales-back" href="<?= e(app_url('user/sale_form.php')) ?>">← Add sale</a><h1>Add product reference</h1><p>A name, selling price, and cost for use in sale items. Inventory management is not included.</p></div></div>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
<form method="post" action="<?= e(app_url('user/sale_product.php')) ?>" class="dashboard-panel sale-section product-reference-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><?php foreach ($token as $key=>$value): ?><input type="hidden" name="<?= e($key) ?>" value="<?= e($value) ?>"><?php endforeach; ?><div class="sale-fields"><label class="form-label" for="product_name">Product name</label><input class="form-control mb-3" id="product_name" name="product_name" maxlength="150" value="<?= e(post_text('product_name')) ?>" required><div class="row g-3"><div class="col-md-6"><label class="form-label" for="default_selling_price">Selling price (PHP)</label><input class="form-control" type="number" id="default_selling_price" name="default_selling_price" min="0" max="99999999999.99" step="0.01" value="<?= e(post_text('default_selling_price')?:'0.00') ?>" required></div><div class="col-md-6"><label class="form-label" for="default_unit_cost">Historical cost per unit (PHP)</label><input class="form-control" type="number" id="default_unit_cost" name="default_unit_cost" min="0" max="99999999999.99" step="0.01" value="<?= e(post_text('default_unit_cost')?:'0.00') ?>" required></div></div><p class="form-text mt-3">Enter a known cost for accurate estimated profit. Zero costs can overstate profit.</p><button class="btn btn-primary btn-sm mt-2" type="submit">Save product reference</button></div></form></main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
