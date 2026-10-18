<?php
$is_form=!$unavailable && $action!=='details';
$values=['product_name'=>'','sku'=>'','description'=>'','default_unit_cost'=>'0.00','default_selling_price'=>'0.00'];
if ($row) { $values=array_replace($values,array_intersect_key($row,$values)); }
if ($_SERVER['REQUEST_METHOD']==='POST') { foreach ($values as $field=>$value) { if (is_string($_POST[$field]??null)) { $values[$field]=$_POST[$field]; } } }
$form_url=app_url('user/product_action.php').'?action='.$action.($modal?'&modal=1':'');
?>
<?php if ($is_form): ?><form class="product-form" method="post" action="<?= e($form_url) ?>" <?= $modal?'data-product-async novalidate':'' ?>><?php endif; ?>
<div class="<?= $modal?'modal-header':'product-form-heading' ?>"><h2 class="modal-title" id="product-modal-title"><?= e($page_title) ?></h2><?php if ($modal): ?><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button><?php endif; ?></div>
<div class="<?= $modal?'modal-body':'product-form-body' ?>">
    <div class="product-discard-prompt alert alert-light d-none" role="alert"><p>Discard your unsaved changes?</p><button type="button" class="btn btn-outline-secondary btn-sm" data-keep-product>Keep editing</button><button type="button" class="btn btn-danger btn-sm" data-discard-product>Discard changes</button></div>
    <div class="alert alert-danger product-submit-error <?= $error?'':'d-none' ?>" role="alert" tabindex="-1"><?= e($error??'') ?></div>
    <?php if ($unavailable): ?>
    <?php elseif ($action==='details'): ?>
        <section class="customer-detail-section"><div class="customer-detail-heading"><h3><?= e($row['product_name']) ?></h3><span class="product-status <?= $row['is_active']?'product-active':'product-archived' ?>"><?= $row['is_active']?'Active':'Archived' ?></span></div>
            <dl class="customer-contact-details"><div><dt>Product code / SKU</dt><dd><?= e($row['sku']??'Not provided') ?></dd></div><div><dt>Date added</dt><dd><?= e((new DateTimeImmutable($row['created_at']))->format('j M Y')) ?></dd></div></dl>
            <?php if ($row['description']!==null && $row['description']!==''): ?><h4>Description</h4><p class="sale-notes"><?= nl2br(e($row['description'])) ?></p><?php else: ?><p class="sale-modal-note">No description provided.</p><?php endif; ?>
        </section>
        <section class="customer-detail-section"><h3>Prices per unit</h3><div class="customer-detail-totals"><div><span>Cost price</span><strong><?= e(sale_money($row['default_unit_cost'])) ?></strong></div><div><span>Selling price</span><strong><?= e(sale_money($row['default_selling_price'])) ?></strong></div><div><span>Estimated unit gross profit</span><strong class="<?= bccomp($row['default_selling_price'],$row['default_unit_cost'],2)<0?'product-negative':'' ?>"><?= e(sale_money(bcsub($row['default_selling_price'],$row['default_unit_cost'],2))) ?></strong></div></div><p class="sale-modal-note">Selling price minus cost price, before discounts and operating expenses. Historical sale prices and costs stay as recorded.</p></section>
    <?php else: ?>
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="product_id" value="<?= e($row['product_id']??'') ?>">
        <?php foreach ($token as $field=>$value): ?><input type="hidden" name="<?= e($field) ?>" value="<?= e($value) ?>"><?php endforeach; ?>
        <?php if ($row): ?><input type="hidden" name="revision" value="<?= e($_SERVER['REQUEST_METHOD']==='POST' && is_string($_POST['revision']??null)?$_POST['revision']:product_revision($row)) ?>"><?php endif; ?>
        <?php if (in_array($action,['archive','restore'],true)): ?>
            <input type="hidden" name="confirm_product_action" value="1"><p class="product-confirm-name"><?= e($row['product_name']) ?></p>
            <p class="sale-modal-note"><?= $action==='archive'?'Archive this product? It will no longer be available for new sales. Past sales and their recorded prices and costs will be kept. Existing draft items can retain this product.':'Restore this product and make it available for new sales again?' ?></p>
        <?php else: ?>
            <?php if ($row && !$row['is_active']): ?><p class="alert alert-light">This product is archived. Editing it keeps its archived status.</p><?php endif; ?>
            <div class="row g-3">
                <div class="col-12"><label class="form-label" for="product_name">Product name</label><input class="form-control" id="product_name" name="product_name" maxlength="150" value="<?= e($values['product_name']) ?>" required></div>
                <div class="col-sm-6"><label class="form-label" for="default_unit_cost">Cost price (PHP)</label><input class="form-control" type="number" inputmode="decimal" id="default_unit_cost" name="default_unit_cost" min="0" max="99999999999.99" step="0.01" value="<?= e($values['default_unit_cost']) ?>" required></div>
                <div class="col-sm-6"><label class="form-label" for="default_selling_price">Selling price (PHP)</label><input class="form-control" type="number" inputmode="decimal" id="default_selling_price" name="default_selling_price" min="0" max="99999999999.99" step="0.01" value="<?= e($values['default_selling_price']) ?>" required></div>
                <div class="col-12"><div class="product-profit-preview" aria-live="polite" aria-atomic="true"><span>Estimated unit gross profit</span><strong data-product-profit>—</strong><p>Selling price − cost price, before discounts and operating expenses.</p></div><p class="product-price-warning alert alert-warning d-none" role="status">Selling price is below cost. You can save this product if the price is intentional.</p></div>
                <div class="col-12"><label class="form-label" for="sku">Product code / SKU <span class="field-optional">(optional)</span></label><input class="form-control" id="sku" name="sku" maxlength="60" value="<?= e($values['sku']) ?>"><p class="form-text">Use a unique code within your business, or leave it blank.</p></div>
                <div class="col-12"><label class="form-label" for="description">Description <span class="field-optional">(optional)</span></label><textarea class="form-control" id="description" name="description" maxlength="4000" rows="2"><?= e($values['description']) ?></textarea></div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
<div class="<?= $modal?'modal-footer':'product-form-footer' ?>">
    <?php if ($modal): ?><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal"><?= $is_form?'Cancel':'Close' ?></button><?php else: ?><a class="btn btn-outline-secondary" href="<?= e(app_url('user/products.php')) ?>">Back to Products</a><?php endif; ?>
    <?php if ($is_form): $submit_label=match($action) {'archive'=>'Archive Product','restore'=>'Restore Product',default=>'Save Product'}; ?><button class="btn <?= $action==='archive'?'btn-danger':'btn-primary' ?>" type="submit"><?= e($submit_label) ?></button><?php endif; ?>
</div>
<?php if ($is_form): ?></form><?php endif; ?>
