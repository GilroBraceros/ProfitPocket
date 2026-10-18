<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/sales.php';
require_once __DIR__ . '/../includes/record_actions.php';
$user = require_business();
$page_title = 'Sales';
$active_page = 'sales';
$dashboard_layout = true;
$notice = take_flash();
$error = null;
$data = null;
try {
    $filters = sale_filters($_GET);
} catch (InvalidArgumentException $exception) {
    http_response_code(400);
    $error = $exception->getMessage() . ' Showing default filters.';
    $filters = sale_filters([]);
}
$connection = db();
try {
    $connection->beginTransaction();
    $data = sale_list($connection, (int) $user['business_id'], $filters);
    $connection->commit();
} catch (Throwable $exception) {
    if ($connection->inTransaction()) { $connection->rollBack(); }
    error_log('ProfitPocket: sales list failed.');
    http_response_code(503);
    $error = 'Sales are temporarily unavailable. Please try again later.';
}
$period = $filters['period'];
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/navbar.php';
?>
<main class="dashboard-main" id="dashboard-content">
    <div class="dashboard-heading"><div><h1>Sales Management</h1><p>Manage and monitor your business transactions.</p></div><a class="btn btn-primary btn-sm" data-sale-modal="create" href="<?= e(app_url('user/sale_form.php')) ?>"><?= dashboard_icon('plus-lg') ?> New Sale</a></div>
    <div id="sales-feedback" class="alert d-none" role="status" tabindex="-1"></div>
    <?php if ($notice): ?><div class="alert alert-success" role="status"><?= e($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
    <section class="dashboard-panel filter-panel"><form method="get" action="<?= e(app_url('user/sales.php')) ?>" class="dashboard-filter sales-filter">
        <div class="search-control"><label for="q">Search sales</label><input class="form-control form-control-sm" type="search" id="q" name="q" maxlength="100" placeholder="Reference or customer name" value="<?= e($filters['q']) ?>"></div>
        <div class="period-controls"><label for="period">Period</label><select class="form-select form-select-sm" name="period" id="period"><?php foreach (['today'=>'Today','week'=>'This week','month'=>'This month','custom'=>'Custom range'] as $value=>$label): ?><option value="<?= e($value) ?>" <?= $period['choice']===$value?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
        <div class="date-controls" id="custom-dates"><div><label for="start">From</label><input type="date" class="form-control form-control-sm" id="start" name="start" value="<?= e($period['start']->format('Y-m-d')) ?>"></div><div><label for="end">To</label><input type="date" class="form-control form-control-sm" id="end" name="end" value="<?= e($period['end']->format('Y-m-d')) ?>"></div></div>
        <div><label for="state">Record status</label><select class="form-select form-select-sm" id="state" name="state"><?php foreach (['posted'=>'Posted','draft'=>'Drafts','void'=>'Voided','all'=>'All records'] as $value=>$label): ?><option value="<?= e($value) ?>" <?= $filters['state']===$value?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
        <div><label for="payment">Payment status</label><select class="form-select form-select-sm" id="payment" name="payment"><?php foreach (['all'=>'Any payment','paid'=>'Fully paid','partial'=>'Partially paid','unpaid'=>'Unpaid'] as $value=>$label): ?><option value="<?= e($value) ?>" <?= $filters['payment']===$value?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
        <div><label for="sort">Date order</label><select class="form-select form-select-sm" id="sort" name="sort"><option value="newest" <?= $filters['sort']==='newest'?'selected':'' ?>>Newest first</option><option value="oldest" <?= $filters['sort']==='oldest'?'selected':'' ?>>Oldest first</option></select></div>
        <button class="btn btn-primary btn-sm" type="submit">Apply</button><a class="sales-reset" href="<?= e(app_url('user/sales.php')) ?>">Reset</a>
    </form></section>
    <?php if ($data): ?>
    <section class="summary-grid sales-summary" aria-label="Sales summary">
        <?php foreach (['Total sales'=>[sale_money($data['summary']['sales']),'Posted sales in current filters'], 'Completed sales'=>[(string)$data['summary']['completed'],'Fully paid · current filters'], 'Pending payments'=>[(string)$data['summary']['pending'],'Unpaid or partial · current filters'], 'Outstanding balance'=>[sale_money($data['outstanding']),'Current balance · all dates']] as $label=>[$value,$hint]): ?>
        <article class="summary-card"><div class="summary-label"><h2><?= e($label) ?></h2></div><p class="summary-amount"><?= e($value) ?></p><p class="summary-description"><?= e($hint) ?></p></article>
        <?php endforeach; ?>
    </section>
    <section class="dashboard-panel sales-history"><div class="panel-heading"><div><h2>Sale transactions</h2><p><?= e($period['label']) ?> · <?= e($data['summary']['records']) ?> matching records</p></div><span class="table-period">Amounts in PHP</span></div>
        <?php if ($data['rows']): ?>
        <div class="table-responsive"><table class="table transactions-table sales-table mb-0"><caption class="visually-hidden">Sales for <?= e($user['business_name']) ?></caption><thead><tr><th scope="col">Reference</th><th scope="col">Date &amp; time</th><th scope="col">Customer</th><th scope="col" class="text-end">Items</th><th scope="col" class="text-end">Total</th><th scope="col" class="text-end">Paid (net)</th><th scope="col" class="text-end">Balance</th><th scope="col">Payment status</th><th scope="col">Actions</th></tr></thead><tbody>
        <?php foreach ($data['rows'] as $sale): ?>
        <tr><td class="transaction-reference"><a data-sale-modal="details" href="<?= e(app_url('user/sale_view.php?id='.$sale['sale_id'])) ?>"><?= e($sale['sale_number']) ?></a></td><td class="transaction-date"><?= e((new DateTimeImmutable($sale['sold_at']))->format('j M Y')) ?><span class="transaction-context"><?= e((new DateTimeImmutable($sale['sold_at']))->format('g:i A')) ?></span></td><td><?= e($sale['customer_name'] ?? 'Walk-in customer') ?></td><td class="text-end" title="Product lines"><?= e($sale['item_count']) ?></td><td class="text-end transaction-amount"><?= e(sale_money($sale['net_sale'])) ?></td><td class="text-end transaction-amount"><?= e(sale_money($sale['total_paid'])) ?></td><td class="text-end transaction-amount"><?= $sale['sale_status']==='posted' ? e(sale_money($sale['balance_due'])) : '—' ?></td><td><span class="payment-badge status-<?= e(sale_badge($sale)) ?>"><?= e(sale_status($sale)) ?></span><?php if ($sale['sale_status']==='posted' && bccomp($sale['balance_due'],'0',2)>0 && $sale['due_date'] && $sale['due_date']<date('Y-m-d')): ?><span class="overdue-label">Overdue</span><?php endif; ?></td><td class="sale-actions"><?php $saleActions=[]; if ($sale['sale_status']!=='void') { $saleActions=[['edit','Edit',app_url('user/sale_form.php?id='.$sale['sale_id']),false],['void','Void sale',app_url('user/sale_view.php?id='.$sale['sale_id'].'#void-sale'),true]]; } record_actions('sale',$sale['sale_number'],app_url('user/sale_view.php?id='.$sale['sale_id']),$saleActions); ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <nav class="sales-pagination" aria-label="Sales pages"><span>Page <?= e($data['page']) ?> of <?= e($data['pages']) ?></span><div><?php foreach (['Previous'=> $data['page']-1,'Next'=> $data['page']+1] as $label=>$target): ?><?php if ($target>=1 && $target<=$data['pages']): ?><a class="btn btn-outline-secondary btn-sm" href="<?= e(app_url('user/sales.php').'?'.http_build_query(['period'=>$period['choice'],'start'=>$period['start']->format('Y-m-d'),'end'=>$period['end']->format('Y-m-d'),'q'=>$filters['q'],'state'=>$filters['state'],'payment'=>$filters['payment'],'sort'=>$filters['sort'],'page'=>$target])) ?>"><?= e($label) ?></a><?php else: ?><button class="btn btn-outline-secondary btn-sm" disabled><?= e($label) ?></button><?php endif; ?><?php endforeach; ?></div></nav>
        <?php else: ?><div class="empty-state"><h3>No sales match these filters</h3><p>Try another date or search, or record your first sale.</p><a class="btn btn-primary btn-sm mt-3" data-sale-modal="create" href="<?= e(app_url('user/sale_form.php')) ?>">New Sale</a></div><?php endif; ?>
        <div class="table-footnote">Drafts are not revenue. Voided records remain in history. Payment-status filters apply to posted transactions.<span class="d-block d-md-none mt-2">Scroll the table sideways to see amounts and actions.</span></div>
    </section>
    <?php endif; ?>
</main>
<div class="modal" id="sales-action-modal" tabindex="-1" aria-labelledby="sales-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down"><div class="modal-content"></div></div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
