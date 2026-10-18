<main class="dashboard-main payments-page" id="dashboard-content">
    <div class="dashboard-heading"><div><h1>Payment Management</h1><p>Track customer payments and outstanding balances.</p></div></div>
    <div id="utang-feedback" class="alert d-none" role="status" tabindex="-1"></div>
    <?php if ($notice): ?><div class="alert alert-success" role="status"><?= e($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
    <?php if ($data): ?>
    <section class="summary-grid utang-summary" aria-label="Payment summary">
        <?php foreach (['Total Payments Collected'=>[sale_money($data['summary']['collected']),$filters['period']['label'].' · valid collections'],'Outstanding Balance'=>[sale_money($data['summary']['outstanding']),'Current balance · all sale dates'],'Partially Paid Sales'=>[(string)$data['summary']['partial'],'Current posted sales with a balance'],'Fully Paid Sales'=>[(string)$data['summary']['paid'],'Current posted sales with no balance']] as $label=>[$value,$hint]): ?>
        <article class="summary-card"><div class="summary-label"><h2><?= e($label) ?></h2></div><p class="summary-amount"><?= e($value) ?></p><p class="summary-description"><?= e($hint) ?></p></article><?php endforeach; ?>
    </section>
    <section class="dashboard-panel payments-collections" aria-labelledby="method-summary-title">
        <div class="payments-method-heading"><h2 id="method-summary-title">Collected by payment method</h2><span><?= e($filters['period']['label']) ?></span></div>
        <dl><?php foreach (['cash','wallet','bank'] as $category): ?><div><dt><?= e(payment_category_label($category)) ?></dt><dd><?= e(sale_money($data['breakdown'][$category])) ?></dd></div><?php endforeach; ?><?php if (bccomp($data['breakdown']['other'],'0',2)>0): ?><div><dt>Other historical methods</dt><dd><?= e(sale_money($data['breakdown']['other'])) ?></dd></div><?php endif; ?></dl>
        <p class="payments-scope">Collections use payment dates; search and table filters leave these business totals unchanged. Refunded amounts and voided sales are excluded. Balances and sale counts are current across all dates. Payments do not add sales revenue.</p>
    </section>
    <section class="dashboard-panel utang-directory payments-directory">
        <nav class="nav nav-tabs payments-tabs" aria-label="Payment views">
            <?php foreach (['history'=>'Payment History','balances'=>'Outstanding Balances'] as $tab=>$label): ?><a class="nav-link <?= $filters['tab']===$tab?'active':'' ?>" href="<?= e(payment_url($filters,['tab'=>$tab,'page'=>1])) ?>" <?= $filters['tab']===$tab?'aria-current="page"':'' ?>><?= e($label) ?></a><?php endforeach; ?>
        </nav>
        <form class="payments-filter" method="get" action="<?= e(app_url('user/payments.php')) ?>">
            <input type="hidden" name="tab" value="<?= e($filters['tab']) ?>">
            <div class="payments-search"><label for="q">Search</label><input class="form-control form-control-sm" id="q" name="q" type="search" maxlength="100" value="<?= e($filters['q']) ?>" placeholder="Customer or sale reference"></div>
            <div><label for="period">Reporting period</label><select class="form-select form-select-sm" name="period" id="period"><?php foreach (['today'=>'Today','week'=>'This week','month'=>'This month','custom'=>'Custom dates'] as $value=>$label): ?><option value="<?= e($value) ?>" <?= $filters['period']['choice']===$value?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
            <?php if ($filters['tab']==='history'): ?>
            <div><label for="method">Payment method</label><select class="form-select form-select-sm" id="method" name="method"><?php foreach (['all'=>'All methods','cash'=>'Cash','wallet'=>'Digital Wallet','bank'=>'Bank Transfer'] as $value=>$label): ?><option value="<?= e($value) ?>" <?= $filters['method']===$value?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
            <div><label for="type">Payment type</label><select class="form-select form-select-sm" id="type" name="type"><?php foreach (['all'=>'All types']+PAYMENT_TYPES as $value=>$label): ?><option value="<?= e($value) ?>" <?= $filters['type']===$value?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
            <?php else: ?>
            <div><label for="state">Payment status</label><select class="form-select form-select-sm" id="state" name="state"><?php foreach (['outstanding'=>'Outstanding','unpaid'=>'Unpaid','partial'=>'Partially Paid','overdue'=>'Overdue','paid'=>'Fully Paid','all'=>'All valid sales'] as $value=>$label): ?><option value="<?= e($value) ?>" <?= $filters['state']===$value?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
            <?php endif; ?>
            <div><label for="sort">Sort by</label><select class="form-select form-select-sm" id="sort" name="sort"><?php foreach (['newest'=>'Newest first','oldest'=>'Oldest first']+($filters['tab']==='balances'?['due'=>'Due date first']:[]) as $value=>$label): ?><option value="<?= e($value) ?>" <?= $filters['sort']===$value?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
            <div class="payments-filter-buttons"><button class="btn btn-primary btn-sm" type="submit">Apply</button><a class="sales-reset" href="<?= e(app_url('user/payments.php?tab='.$filters['tab'])) ?>">Reset</a></div>
            <div class="payments-custom-dates" id="custom-dates" <?= $filters['period']['choice']!=='custom'?'hidden':'' ?>>
                <div><label for="start">From payment date</label><input class="form-control form-control-sm" id="start" type="date" name="start" value="<?= e($filters['period']['start']->format('Y-m-d')) ?>" <?= $filters['period']['choice']!=='custom'?'disabled':'' ?>></div><div><label for="end">To payment date</label><input class="form-control form-control-sm" id="end" type="date" name="end" value="<?= e($filters['period']['end']->format('Y-m-d')) ?>" <?= $filters['period']['choice']!=='custom'?'disabled':'' ?>></div>
            </div>
            <?php if ($filters['customer']!==''): ?><input type="hidden" name="customer" value="<?= e($filters['customer']) ?>"><p class="payments-scope">Filtered to one customer. <a href="<?= e(payment_url($filters,['customer'=>'','page'=>1])) ?>">Show all customers</a></p><?php endif; ?>
        </form>
        <div class="panel-heading"><div><h2><?= $filters['tab']==='history'?'Payment History':'Outstanding Balances' ?></h2><p><?= e($data['count']) ?> matching <?= $filters['tab']==='history'?'payments':'sales' ?><?= $filters['tab']==='balances'?' · current balances, independent of reporting period':'' ?></p></div><span class="table-period">Amounts in PHP</span></div>
        <?php if ($data['rows']): ?>
        <table class="table transactions-table payments-table <?= $filters['tab']==='history'?'payments-history-table':'' ?> mb-0"><caption class="visually-hidden"><?= $filters['tab']==='history'?'Payments received':'Sale balances' ?> for <?= e($user['business_name']) ?></caption>
            <thead><tr><?php foreach ($filters['tab']==='history'?['Payment date','Customer','Sale reference','Payment type','Payment method','Amount','Actions']:['Customer','Sale reference','Sale amount','Total paid','Remaining balance','Due date','Status','Actions'] as $column): ?><th scope="col" class="<?= in_array($column,['Amount','Sale amount','Total paid','Remaining balance'],true)?'text-end':'' ?>"><?= e($column) ?></th><?php endforeach; ?></tr></thead>
            <tbody><?php foreach ($data['rows'] as $row): ?>
            <tr><?php if ($filters['tab']==='history'): ?>
                <td data-label="Payment date"><?= e((new DateTimeImmutable($row['paid_at']))->format('j M Y')) ?><span class="transaction-context"><?= e((new DateTimeImmutable($row['paid_at']))->format('g:i a')) ?></span></td>
                <td data-label="Customer"><strong><?= e($row['customer_name']??'Walk-in Customer') ?></strong></td><td data-label="Sale reference"><a href="<?= e(app_url('user/sale_view.php?id='.$row['sale_id'])) ?>"><?= e($row['sale_number']) ?></a></td>
                <td data-label="Payment type"><span class="payment-badge status-<?= in_array($row['payment_type'],['full','final'],true)?'paid':'partial' ?>"><?= e(PAYMENT_TYPES[$row['payment_type']]) ?></span></td>
                <td data-label="Payment method"><?= e(payment_category_label(payment_category($row['method_code']))) ?><?php if ($row['method_code']!=='cash'): ?><span class="transaction-context"><?= e(payment_provider($row)) ?></span><?php endif; ?></td>
                <td data-label="Amount" class="text-end transaction-amount"><?= e(sale_money($row['received_amount'])) ?><?php if (bccomp($row['received_amount'],$row['amount'],2)<0): ?><span class="transaction-context">After refund</span><?php endif; ?></td>
                <td class="utang-actions"><?php record_actions('utang','payment '.$row['payment_id'],$action_url.'?action=payment_details&id='.$row['payment_id'],[['history','Sale Payment History',$action_url.'?action=history&id='.$row['sale_id'],false],['details','View Associated Sale',$action_url.'?action=details&id='.$row['sale_id'],false]],null,'payment_details'); ?></td>
            <?php else: $paid=bccomp($row['balance_due'],'0',2)===0; ?>
                <td data-label="Customer"><strong><?= e($row['customer_name']??'Walk-in Customer') ?></strong></td><td data-label="Sale reference"><?= e($row['sale_number']) ?></td>
                <td data-label="Sale amount" class="text-end transaction-amount"><?= e(sale_money($row['net_sale'])) ?></td><td data-label="Total paid" class="text-end transaction-amount"><?= e(sale_money($row['total_paid'])) ?></td><td data-label="Remaining balance" class="text-end transaction-amount utang-balance"><?= e(sale_money($row['balance_due'])) ?></td>
                <td data-label="Due date"><?= e($row['due_date']?(new DateTimeImmutable($row['due_date']))->format('j M Y'):'Not set') ?></td><td data-label="Status"><span class="payment-badge status-<?= $paid?'paid':(bccomp($row['total_paid'],'0',2)>0?'partial':'unpaid') ?>"><?= e($paid?'Fully Paid':utang_status($row)) ?></span><?php if (utang_overdue($row)): ?> <span class="payment-badge status-overdue">Overdue</span><?php endif; ?></td>
                <td class="utang-actions"><?php $actions=[['history','Payment History',$action_url.'?action=history&id='.$row['sale_id'],false]]; if ($row['sale_type']==='credit') { $actions[]=['due','Edit Due Date',$action_url.'?action=due&id='.$row['sale_id'],false]; } record_actions('utang',$row['sale_number'],$action_url.'?action=details&id='.$row['sale_id'],$actions,$paid?null:['payment','Record Payment',$action_url.'?action=payment&id='.$row['sale_id']]); ?></td>
            <?php endif; ?></tr><?php endforeach; ?></tbody>
        </table>
        <nav class="sales-pagination" aria-label="Payment pages"><span>Page <?= e($data['page']) ?> of <?= e($data['pages']) ?></span><div><?php foreach (['Previous'=>$data['page']-1,'Next'=>$data['page']+1] as $label=>$page): ?><?php if ($page>=1 && $page<=$data['pages']): ?><a href="<?= e(payment_url($filters,['page'=>$page])) ?>"><?= e($label) ?></a><?php else: ?><span aria-disabled="true"><?= e($label) ?></span><?php endif; ?><?php endforeach; ?></div></nav>
        <?php else: ?><div class="dashboard-empty"><h3><?= $filters['tab']==='history'?'No matching payments':'No matching balances' ?></h3><p><?= $filters['tab']==='history'?'Record a paid sale or a payment against an outstanding balance.':'Try changing the filters or record a sale with a remaining balance.' ?></p><a href="<?= e(app_url('user/sales.php')) ?>">Open Sales Management</a></div><?php endif; ?>
    </section>
    <?php endif; ?>
</main>
<div class="modal fade utang-modal" id="utang-action-modal" tabindex="-1" aria-labelledby="utang-modal-title" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down"><div class="modal-content"></div></div></div>
