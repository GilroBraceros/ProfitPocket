<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/dashboard.php';
require_once __DIR__ . '/../includes/payments.php';
require_once __DIR__ . '/../includes/platform.php';
$user = require_role('owner');
require_once __DIR__.'/../includes/subscriptions.php'; subscription_require_access($user);
$page_title = 'Dashboard';
$dashboard_layout = true;
$active_page = 'dashboard';
$announcements = []; $announcement_error = null;
try { $announcements = platform_announcements(db()); }
catch (Throwable $exception) { $announcement_error='Announcements are temporarily unavailable. Please try again.'; error_log('ProfitPocket: announcements unavailable.'); }
$notice = take_flash();
$filter_error = null;
$data_error = null;
$data = null;
try {
    $period = dashboard_period($_GET);
} catch (InvalidArgumentException $exception) {
    http_response_code(400);
    $filter_error = $exception->getMessage() . ' Showing this month instead.';
    $period = dashboard_period([]);
}
if ($user['business_id']) {
    $connection = db();
    try {
        // Keep summary cards, chart, and transactions on one database snapshot.
        $connection->beginTransaction();
        $data = dashboard_data($connection, (int) $user['business_id'], $period);
        $data['collections']=payment_collection_totals($connection,(int)$user['business_id'],$period);
        $connection->commit();
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) { $connection->rollBack(); }
        error_log('ProfitPocket: dashboard query failed.');
        http_response_code(503);
        $data_error = 'Your financial overview is temporarily unavailable. Please reload this page in a moment.';
    }
}
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/navbar.php';
?>
<main class="dashboard-main" id="dashboard-content">
    <div class="dashboard-heading"><div><h1>Business overview</h1><p>Sales, spending, and customer credit at a glance.</p></div><span class="period-label"><?= dashboard_icon('calendar3') ?> <?= e($period['label']) ?></span></div>
    <?php if ($notice): ?><div class="alert alert-warning" role="status"><?= e($notice) ?></div><?php endif; ?>
    <?php if ($filter_error): ?><div class="alert alert-danger" role="alert"><?= e($filter_error) ?></div><?php endif; ?>
    <?php if (!$user['business_id']): ?>
    <section class="dashboard-panel empty-state"><h2>No business linked</h2><p>Your account has no linked business. Please contact your administrator to continue.</p></section>
    <?php elseif ($data_error): ?>
    <div class="alert alert-danger" role="alert"><?= e($data_error) ?></div><a href="<?= e(app_url('user/dashboard.php')) ?>" class="btn btn-primary">Reload overview</a>
    <?php else: ?>
    <section class="dashboard-panel filter-panel" aria-label="Reporting period">
        <form method="get" action="<?= e(app_url('user/dashboard.php')) ?>" class="dashboard-filter">
            <div class="period-controls"><label for="period">Reporting period</label><select class="form-select form-select-sm" name="period" id="period"><?php foreach (['today' => 'Today', 'week' => 'This week', 'month' => 'This month', 'custom' => 'Custom range'] as $value => $label): ?><option value="<?= e($value) ?>" <?= $period['choice'] === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
            <div class="date-controls" id="custom-dates"><div><label for="start">From</label><input type="date" class="form-control form-control-sm" id="start" name="start" value="<?= e($period['start']->format('Y-m-d')) ?>"></div><span class="date-range-separator">–</span><div><label for="end">To</label><input type="date" class="form-control form-control-sm" id="end" name="end" value="<?= e($period['end']->format('Y-m-d')) ?>"></div></div>
            <button type="submit" class="btn btn-primary btn-sm filter-apply">Apply filter</button><p class="filter-note">Manila time · Up to 366 days</p>
        </form>
    </section>
    <section class="summary-grid" aria-label="Financial summary">
        <?php foreach (['sales' => ['Total sales', 'receipt', 'Posted sales after discounts'], 'expenses' => ['Operating expenses', 'arrow-up-right-square', 'Excludes inventory purchases'], 'profit' => ['Estimated net profit', 'graph-up', 'Sales − item costs − operating expenses']] as $key => [$label, $icon, $description]): ?>
        <article class="summary-card"><div class="summary-label"><h2><?= e($label) ?></h2><?= dashboard_icon($icon) ?></div><p class="summary-amount <?= $key === 'profit' ? ($data['totals'][$key] < 0 ? 'amount-negative' : 'amount-positive') : '' ?>"><?= e(dashboard_money($data['totals'][$key])) ?></p><p class="summary-description"><?= e($description) ?></p><p class="summary-comparison"><?= e(dashboard_comparison($data['totals'][$key], $data['previous'][$key])) ?></p></article>
        <?php endforeach; ?>
        <article class="summary-card"><div class="summary-label"><h2>Outstanding balance</h2><?= dashboard_icon('journal-text') ?></div><p class="summary-amount"><?= e(dashboard_money($data['outstanding'])) ?></p><p class="summary-description">Current balance · all dates</p><p class="summary-comparison"><?= e($data['open_credits']) ?> unpaid <?= $data['open_credits'] === 1 ? 'sale' : 'sales' ?> · partial payments included</p></article>
    </section>
    <div class="overview-notes"><p>Valid payments collected: <strong><?= e(sale_money($data['collections']['total'])) ?></strong> in <?= e($period['label']) ?>. <a href="<?= e(app_url('user/payments.php').'?'.http_build_query(['period'=>'custom','start'=>$period['start']->format('Y-m-d'),'end'=>$period['end']->format('Y-m-d')])) ?>">View payments</a></p><p>Compared with <?= e($period['previous_start']->format('j M')) ?>–<?= e($period['previous_end']->format('j M Y')) ?> (<?= e($period['days']) ?> <?= $period['days'] === 1 ? 'day' : 'days' ?>). Collections reduce outstanding balances; they do not add sales revenue.</p><?php if ($data['zero_cost_items']): ?><p class="cost-warning"><?= dashboard_icon('exclamation-circle') ?> <?= e($data['zero_cost_items']) ?> sold <?= $data['zero_cost_items'] === 1 ? 'item has' : 'items have' ?> zero recorded cost. Estimated profit may be overstated.</p><?php endif; ?></div>
    <section class="dashboard-panel chart-panel" aria-labelledby="chart-title">
        <div class="panel-heading"><div><h2 id="chart-title">Financial activity</h2><p>Net sales and operating expenses · <?= e(ucfirst($data['chart']['grouping'])) ?> totals</p></div><div class="chart-legend"><span><i class="legend-dot sales-dot"></i>Sales</span><span><i class="legend-dot expenses-dot"></i>Expenses</span></div></div>
        <?php if ($data['chart']['has_activity']): ?>
        <div class="financial-chart-wrap" data-chart="<?= e(json_encode($data['chart'], JSON_THROW_ON_ERROR)) ?>"><canvas id="financial-chart" role="img" aria-label="Net sales and operating expenses for <?= e($period['label']) ?>. A data table follows."></canvas><p id="chart-unavailable" class="chart-fallback" hidden>The chart could not load. Financial figures are available in the data table below.</p></div>
        <details class="chart-data-table"><summary>View chart data</summary><div class="table-responsive"><table class="table table-sm"><caption class="visually-hidden">Financial activity chart values in Philippine pesos</caption><thead><tr><th scope="col">Period</th><th scope="col" class="text-end">Sales</th><th scope="col" class="text-end">Operating expenses</th></tr></thead><tbody><?php foreach ($data['chart']['labels'] as $index => $label): ?><tr><th scope="row"><?= e($label) ?></th><td class="text-end"><?= e(dashboard_money((int) round($data['chart']['sales'][$index] * 100))) ?></td><td class="text-end"><?= e(dashboard_money((int) round($data['chart']['expenses'][$index] * 100))) ?></td></tr><?php endforeach; ?></tbody></table></div></details>
        <?php else: ?><div class="empty-state chart-empty"><?= dashboard_icon('bar-chart') ?><h3>No sales or operating expenses in this period</h3><p>Try a different reporting period to see existing financial activity.</p></div><?php endif; ?>
    </section>
    <section class="dashboard-panel quick-actions" aria-labelledby="actions-title"><div><h2 id="actions-title">Quick actions</h2><p>Record a sale, expense, customer or payment.</p></div><div class="action-buttons"><?php foreach (['Add Sale' => 'plus-lg', 'Add Expense' => 'plus-lg', 'Add Customer' => 'person-plus', 'Record Payment' => 'journal-plus'] as $label => $icon): ?><?php if ($label==='Add Sale'||$label==='Record Payment'): ?><a class="btn btn-outline-secondary btn-sm" href="<?= e(app_url($label==='Record Payment'?'user/payments.php?tab=balances':'user/sale_form.php')) ?>"><?= dashboard_icon($icon) ?> <?= e($label) ?></a><?php elseif ($label==='Add Expense'): ?><a class="btn btn-outline-secondary btn-sm" href="<?= e(app_url('user/expenses.php?new=1')) ?>" ><?= dashboard_icon($icon) ?> <?= e($label) ?></a><?php elseif ($label==='Add Customer'): ?><a class="btn btn-outline-secondary btn-sm" href="<?= e(app_url('user/customers.php?new=1')) ?>" ><?= dashboard_icon($icon) ?> <?= e($label) ?></a><?php endif; ?><?php endforeach; ?></div></section>
    <section class="dashboard-panel transactions-panel" aria-labelledby="transactions-title">
        <div class="panel-heading"><div><h2 id="transactions-title">Recent transactions</h2><p>Latest 10 activities in <?= e($period['label']) ?></p></div><span class="table-period">Newest first</span></div>
        <?php if ($data['transactions']): ?>
        <div class="table-responsive"><table class="table transactions-table mb-0"><caption class="visually-hidden">Recent transactions for <?= e($user['business_name']) ?></caption><thead><tr><th scope="col">Reference</th><th scope="col">Type</th><th scope="col">Customer / description</th><th scope="col" class="text-end">Amount</th><th scope="col">Date</th><th scope="col">Payment status</th></tr></thead><tbody>
        <?php foreach ($data['transactions'] as $transaction): ?>
        <tr><td class="transaction-reference"><?= e($transaction['reference']) ?></td><td><span class="transaction-type"><?= dashboard_icon($transaction['type'] === 'Sale' ? 'receipt' : ($transaction['type'] === 'Expense' ? 'arrow-up-right-square' : 'arrow-down-left-square')) ?><?= e($transaction['type']) ?></span></td><td class="transaction-description"><?= e($transaction['description']) ?><span class="transaction-context"><?= e($transaction['context']) ?></span></td><td class="text-end transaction-amount <?= dashboard_cents($transaction['amount']) < 0 ? 'amount-negative' : '' ?>"><?= e(dashboard_money(dashboard_cents($transaction['amount']))) ?></td><td class="transaction-date"><time datetime="<?= e(str_replace(' ', 'T', $transaction['event_date'])) ?>+08:00"><?= e((new DateTimeImmutable($transaction['event_date']))->format('j M Y')) ?></time></td><td><span class="payment-badge status-<?= e(strtolower($transaction['status'])) ?>"><?= e($transaction['status']) ?></span></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <?php else: ?><div class="empty-state"><h3>No transactions in this period</h3><p>Choose another period to review existing sales, expenses, or payments.</p></div><?php endif; ?>
        <div class="table-footnote">Expense entries include inventory purchases and other cash outflows. The expense summary and chart show operating expenses only.</div>
    </section>
    <?php endif; ?>
    <?php require __DIR__.'/../includes/announcements.php'; ?>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
