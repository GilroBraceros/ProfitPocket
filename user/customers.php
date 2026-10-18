<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/customers.php';
require_once __DIR__.'/../includes/record_actions.php';
$user=require_business(); $connection=db(); $page_title='Customers'; $active_page='customers'; $dashboard_layout=true; $error=null; $data=null; $notice=take_flash();
try { $filters=customer_filters($_GET); } catch (InvalidArgumentException $exception) { http_response_code(400); $error=$exception->getMessage().' Showing default filters.'; $filters=customer_filters([]); }
try { $connection->beginTransaction(); $data=customer_list($connection,(int)$user['business_id'],$filters); $connection->commit(); }
catch (Throwable $exception) { if ($connection->inTransaction()) { $connection->rollBack(); } error_log('ProfitPocket: customer directory unavailable.'); http_response_code(503); $error='Customers are temporarily unavailable. Please try again.'; }
$action_url=app_url('user/customer_action.php');
require __DIR__.'/../includes/header.php'; require __DIR__.'/../includes/navbar.php';
?>
<main class="dashboard-main" id="dashboard-content">
    <div class="dashboard-heading"><div><h1>Customers</h1><p>Manage your customers and keep track of their transactions.</p></div><a class="btn btn-primary btn-sm" data-customer-modal="create" href="<?= e($action_url.'?action=create') ?>"><?= dashboard_icon('plus-lg') ?> Add Customer</a></div>
    <div id="customer-feedback" class="alert d-none" role="status" tabindex="-1"></div>
    <?php if ($notice): ?><div class="alert alert-success" role="status"><?= e($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
    <?php if ($data): ?>
    <section class="summary-grid customer-summary" aria-label="Customer summary">
        <?php foreach (['Total Customers'=>[(string)$data['summary']['active_customers'],'Active customers in your business'],'Customers With Balances'=>[(string)$data['summary']['with_utang'],'Active and archived customers'],'Total Outstanding Balance'=>[sale_money($data['summary']['outstanding']),'Unpaid credit · all customers']] as $label=>[$value,$hint]): ?><article class="summary-card"><div class="summary-label"><h2><?= e($label) ?></h2></div><p class="summary-amount"><?= e($value) ?></p><p class="summary-description"><?= e($hint) ?></p></article><?php endforeach; ?>
    </section>
    <section class="dashboard-panel filter-panel"><form class="dashboard-filter customer-filter" method="get" action="<?= e(app_url('user/customers.php')) ?>">
        <div class="search-control"><label for="q">Search customers</label><input class="form-control form-control-sm" type="search" id="q" name="q" maxlength="100" value="<?= e($filters['q']) ?>" placeholder="Name or contact number"></div>
        <div><label for="balance">Balance</label><select class="form-select form-select-sm" id="balance" name="balance"><?php foreach (['all'=>'All balances','utang'=>'Has outstanding balance','clear'=>'No outstanding balance','overdue'=>'Overdue'] as $key=>$label): ?><option value="<?= e($key) ?>" <?= $filters['balance']===$key?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
        <div><label for="state">Directory</label><select class="form-select form-select-sm" id="state" name="state"><?php foreach (['active'=>'Active customers','archived'=>'Archived customers','all'=>'All customers'] as $key=>$label): ?><option value="<?= e($key) ?>" <?= $filters['state']===$key?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
        <div><label for="sort">Sort by</label><select class="form-select form-select-sm" id="sort" name="sort"><option value="name" <?= $filters['sort']==='name'?'selected':'' ?>>Name A–Z</option><option value="recent" <?= $filters['sort']==='recent'?'selected':'' ?>>Recent activity</option></select></div>
        <button class="btn btn-primary btn-sm" type="submit">Apply</button><a class="sales-reset" href="<?= e(app_url('user/customers.php')) ?>">Reset</a>
    </form></section>
    <section class="dashboard-panel customer-directory"><div class="panel-heading"><div><h2>Customer directory</h2><p><?= e($data['count']) ?> matching customers</p></div><span class="table-period">Amounts in PHP</span></div>
        <?php if ($data['rows']): ?><table class="table transactions-table customer-table mb-0"><caption class="visually-hidden">Customers for <?= e($user['business_name']) ?></caption><thead><tr><th scope="col">Customer name</th><th scope="col">Contact number</th><th scope="col" class="text-end">Total purchases</th><th scope="col" class="text-end">Outstanding balance</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead><tbody>
        <?php foreach ($data['rows'] as $customer): ?><tr>
            <td class="customer-name" data-label="Customer"><strong><?= e($customer['customer_name']) ?></strong><?php if ($customer['email']): ?><span class="transaction-context"><?= e($customer['email']) ?></span><?php endif; ?><?php if (!$customer['is_active']): ?><span class="customer-archive-label">Archived</span><?php endif; ?></td>
            <td data-label="Contact number"><?= e($customer['phone']?:'Not provided') ?></td><td data-label="Total purchases" class="text-end transaction-amount"><?= e(sale_money($customer['purchases'])) ?></td><td data-label="Outstanding balance" class="text-end transaction-amount"><?= e(sale_money($customer['outstanding'])) ?></td>
            <td data-label="Status"><span class="customer-balance-status <?= (int)$customer['overdue_credits']>0?'customer-overdue':(bccomp($customer['outstanding'],'0',2)>0?'customer-has-utang':'customer-clear') ?>"><?= e(customer_balance_label($customer)) ?></span></td>
            <td class="customer-actions"><?php $id=$customer['customer_id']; $state_action=$customer['is_active']?'archive':'restore'; record_actions('customer',$customer['customer_name'],$action_url.'?action=details&id='.$id,[['edit','Edit Customer',$action_url.'?action=edit&id='.$id,false],[$state_action,$customer['is_active']?'Archive Customer':'Restore Customer',$action_url.'?action='.$state_action.'&id='.$id,(bool)$customer['is_active']]]); ?></td>
        </tr><?php endforeach; ?></tbody></table>
        <nav class="sales-pagination" aria-label="Customer pages"><span>Page <?= e($data['page']) ?> of <?= e($data['pages']) ?></span><div><?php foreach (['Previous'=>$data['page']-1,'Next'=>$data['page']+1] as $label=>$target): ?><?php if ($target>=1 && $target<=$data['pages']): $params=$filters; $params['page']=$target; ?><a class="btn btn-outline-secondary btn-sm" href="<?= e(app_url('user/customers.php').'?'.http_build_query($params)) ?>"><?= e($label) ?></a><?php else: ?><button class="btn btn-outline-secondary btn-sm" disabled><?= e($label) ?></button><?php endif; ?><?php endforeach; ?></div></nav>
        <?php else: ?><div class="empty-state"><h3>No customers match these filters</h3><p>Add your first customer or try another search or directory filter.</p><a class="btn btn-primary btn-sm mt-3" data-customer-modal="create" href="<?= e($action_url.'?action=create') ?>">Add Customer</a></div><?php endif; ?>
        <div class="table-footnote">Purchase totals use posted sales. Utang includes unpaid credit balances, including archived customers. Payments are not counted as new purchases.</div>
    </section>
    <?php endif; ?>
</main>
<div class="modal" id="customer-action-modal" tabindex="-1" aria-labelledby="customer-modal-title" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down"><div class="modal-content"></div></div></div>
<?php require __DIR__.'/../includes/footer.php'; ?>
