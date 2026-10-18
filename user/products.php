<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/products.php';
require_once __DIR__.'/../includes/record_actions.php';
$user=require_business(); $connection=db(); $page_title='Products'; $active_page='products'; $dashboard_layout=true; $error=null; $data=null; $notice=take_flash();
try { $filters=product_filters($_GET); } catch (InvalidArgumentException $exception) { http_response_code(400); $error=$exception->getMessage().' Showing default filters.'; $filters=product_filters([]); }
try { $connection->beginTransaction(); $data=product_list($connection,(int)$user['business_id'],$filters); $connection->commit(); }
catch (Throwable $exception) { if ($connection->inTransaction()) { $connection->rollBack(); } error_log('ProfitPocket: product directory unavailable.'); http_response_code(503); $error='Products are temporarily unavailable. Please try again.'; }
$action_url=app_url('user/product_action.php');
require __DIR__.'/../includes/header.php'; require __DIR__.'/../includes/navbar.php';
?>
<main class="dashboard-main" id="dashboard-content">
    <div class="dashboard-heading"><div><h1>Products</h1><p>Manage the products you sell.</p></div><a class="btn btn-primary btn-sm" data-product-modal="create" href="<?= e($action_url.'?action=create') ?>"><?= dashboard_icon('plus-lg') ?> Add Product</a></div>
    <div id="product-feedback" class="alert d-none" role="status" tabindex="-1"></div>
    <?php if ($notice): ?><div class="alert alert-success" role="status"><?= e($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
    <?php if ($data): ?>
    <section class="summary-grid product-summary" aria-label="Product summary">
        <?php foreach (['Total Products'=>[$data['summary']['total'],'Active and archived · your whole catalog'],'Active Products'=>[$data['summary']['active'],'Available for new sales']] as $label=>[$value,$hint]): ?><article class="summary-card"><div class="summary-label"><h2><?= e($label) ?></h2></div><p class="summary-amount"><?= e($value) ?></p><p class="summary-description"><?= e($hint) ?></p></article><?php endforeach; ?>
    </section>
    <section class="dashboard-panel filter-panel"><form class="dashboard-filter product-filter" method="get" action="<?= e(app_url('user/products.php')) ?>">
        <div class="search-control"><label for="q">Search products</label><input class="form-control form-control-sm" type="search" id="q" name="q" maxlength="100" value="<?= e($filters['q']) ?>" placeholder="Product name"></div>
        <div><label for="state">Status</label><select class="form-select form-select-sm" id="state" name="state"><?php foreach (['active'=>'Active','archived'=>'Archived','all'=>'All products'] as $key=>$label): ?><option value="<?= e($key) ?>" <?= $filters['state']===$key?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
        <div><label for="sort">Sort by</label><select class="form-select form-select-sm" id="sort" name="sort"><?php foreach (['name'=>'Name A–Z','price_low'=>'Selling price: low to high','price_high'=>'Selling price: high to low'] as $key=>$label): ?><option value="<?= e($key) ?>" <?= $filters['sort']===$key?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
        <button class="btn btn-primary btn-sm" type="submit">Apply</button><a class="sales-reset" href="<?= e(app_url('user/products.php')) ?>">Reset</a>
    </form></section>
    <section class="dashboard-panel product-directory"><div class="panel-heading"><div><h2>Product directory</h2><p><?= e($data['count']) ?> matching products</p></div><span class="table-period">Amounts in PHP</span></div>
        <?php if ($data['rows']): ?><table class="table transactions-table product-table mb-0"><caption class="visually-hidden">Products for <?= e($user['business_name']) ?></caption><thead><tr><th scope="col">Product name</th><th scope="col" class="text-end">Cost price</th><th scope="col" class="text-end">Selling price</th><th scope="col" class="text-end">Est. unit gross profit</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead><tbody>
        <?php foreach ($data['rows'] as $product): ?><tr>
            <td class="product-name" data-label="Product"><strong><?= e($product['product_name']) ?></strong><?php if ($product['sku']!==null): ?><span class="transaction-context"><?= e($product['sku']) ?></span><?php endif; ?></td>
            <td data-label="Cost price" class="text-end transaction-amount"><?= e(sale_money($product['default_unit_cost'])) ?></td><td data-label="Selling price" class="text-end transaction-amount"><?= e(sale_money($product['default_selling_price'])) ?></td><td data-label="Est. unit gross profit" class="text-end transaction-amount <?= bccomp($product['unit_profit'],'0',2)<0?'product-negative':'' ?>"><?= e(sale_money($product['unit_profit'])) ?></td>
            <td data-label="Status"><span class="product-status <?= $product['is_active']?'product-active':'product-archived' ?>"><?= $product['is_active']?'Active':'Archived' ?></span></td>
            <td class="product-actions"><?php $id=$product['product_id']; $state_action=$product['is_active']?'archive':'restore'; record_actions('product',$product['product_name'],$action_url.'?action=details&id='.$id,[['edit','Edit Product',$action_url.'?action=edit&id='.$id,false],[$state_action,$product['is_active']?'Archive Product':'Restore Product',$action_url.'?action='.$state_action.'&id='.$id,(bool)$product['is_active']]]); ?></td>
        </tr><?php endforeach; ?></tbody></table>
        <nav class="sales-pagination" aria-label="Product pages"><span>Page <?= e($data['page']) ?> of <?= e($data['pages']) ?></span><div><?php foreach (['Previous'=>$data['page']-1,'Next'=>$data['page']+1] as $label=>$target): ?><?php if ($target>=1 && $target<=$data['pages']): $params=$filters; $params['page']=$target; ?><a class="btn btn-outline-secondary btn-sm" href="<?= e(app_url('user/products.php').'?'.http_build_query($params)) ?>"><?= e($label) ?></a><?php else: ?><button class="btn btn-outline-secondary btn-sm" disabled><?= e($label) ?></button><?php endif; ?><?php endforeach; ?></div></nav>
        <?php else: ?><div class="empty-state"><h3>No products match these filters</h3><p>Add your first product or try another name or status.</p></div><?php endif; ?>
        <div class="table-footnote">Estimated unit gross profit = selling price − cost price, before discounts and operating expenses. Catalog changes apply to future sales; past sale amounts stay as recorded.</div>
    </section>
    <?php endif; ?>
</main>
<div class="modal" id="product-action-modal" tabindex="-1" aria-labelledby="product-modal-title" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down"><div class="modal-content"></div></div></div>
<?php require __DIR__.'/../includes/footer.php'; ?>
