<section class="summary-grid admin-summary" aria-label="Platform summary">
    <?php foreach ([
        'total'=>['Total Users','Business-owner accounts'],
        'active'=>['Active Accounts','Owners with access'],
        'businesses'=>['Registered Businesses','Linked business profiles'],
        'tickets'=>['Open Support Tickets','Open and in progress'],
    ] as $key=>[$label,$note]): ?>
    <article class="summary-card">
        <div class="summary-label"><h2><?= e($label) ?></h2></div>
        <p class="summary-amount" data-admin-count="<?= e($key) ?>"><?= e($data['counts'][$key]) ?></p>
        <p class="summary-description"><?= e($note) ?></p>
    </article>
    <?php endforeach; ?>
</section>
<div class="admin-overview-grid">
    <?php if (admin_can($permissions,'users.manage')): ?>
    <section class="dashboard-panel admin-overview-users" aria-labelledby="recent-users-title">
        <header class="admin-panel-header"><h2 id="recent-users-title">Recent registrations</h2><a class="admin-text-link" href="<?= e(app_url('admin/users.php')) ?>">View all users</a></header>
        <?php if (!$data['recent']): ?>
        <div class="empty-state"><h3>No registrations yet</h3><p>New business-owner accounts will appear here.</p></div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table admin-table admin-recent-table">
                <colgroup><col class="admin-col-27"><col class="admin-col-25"><col class="admin-col-18"><col class="admin-col-17"><col class="admin-col-13"></colgroup>
                <thead><tr><th scope="col">User</th><th scope="col">Business</th><th scope="col">Joined</th><th scope="col">Status</th><th scope="col">Action</th></tr></thead>
                <tbody>
                <?php foreach ($data['recent'] as $row): ?>
                    <tr>
                        <td data-label="User"><strong><?= e($row['full_name']) ?></strong><span class="admin-cell-meta"><?= e($row['username']) ?></span></td>
                        <td data-label="Business"><?= e($row['business_name']??'No linked business') ?></td>
                        <td data-label="Joined" class="admin-cell-date"><?= e((new DateTimeImmutable($row['created_at']))->format('j M Y')) ?></td>
                        <td data-label="Status"><?= admin_badge($row['account_status']) ?></td>
                        <td data-label="Action"><a class="record-action-view" data-admin-modal href="<?= e(admin_action_url('user_view',(int)$row['user_id'])) ?>" aria-label="View <?= e($row['full_name']) ?>">View</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        <div class="admin-panel-footnote"><span>Suspended <strong><?= e($data['counts']['suspended']) ?></strong></span><span>Inactive / deactivated <strong><?= e($data['counts']['inactive']) ?></strong></span></div>
    </section>
    <?php endif; ?>
    <?php if (admin_can($permissions,'support.manage')): ?>
    <section class="dashboard-panel admin-support-overview" aria-labelledby="open-tickets-title">
        <header class="admin-panel-header"><h2 id="open-tickets-title">Open support tickets</h2><a class="admin-text-link" href="<?= e(app_url('admin/tickets.php')) ?>">View all</a></header>
        <div class="admin-work-list">
            <?php foreach ($data['support'] as $ticket): ?>
            <article class="admin-work-item">
                <div><a data-admin-modal href="<?= e(admin_action_url('ticket_view',(int)$ticket['ticket_id'])) ?>"><?= e($ticket['subject']) ?></a><p>TKT-<?= e($ticket['ticket_id']) ?> · <?= e($ticket['full_name']) ?></p></div>
                <?= admin_badge($ticket['ticket_status']) ?>
            </article>
            <?php endforeach; ?>
        </div>
        <?php if (!$data['support']): ?><div class="admin-small-empty"><h3>All caught up</h3><p>No open or in-progress tickets.</p></div><?php endif; ?>
    </section>
    <?php endif; ?>
    <section class="dashboard-panel admin-recent-activity" aria-labelledby="recent-activity-title">
        <header class="admin-panel-header"><h2 id="recent-activity-title">Recent administrative activity</h2><?php if (admin_can($permissions,'users.manage')): ?><a class="admin-text-link" href="<?= e(app_url('admin/logs.php')) ?>">View activity log</a><?php endif; ?></header>
        <div class="admin-activity-list">
            <?php foreach ($data['activity'] as $row): ?>
            <div class="admin-activity-row" data-action-code="<?= e($row['action_code']) ?>">
                <strong><?= e(admin_action_label($row['action_code'])) ?></strong><span><?= e($row['full_name']??'System') ?></span><time datetime="<?= e(str_replace(' ','T',$row['created_at'])) ?>+08:00"><?= e(platform_date($row['created_at'])) ?></time>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if (!$data['activity']): ?><div class="admin-small-empty"><p>No recent activity<?= admin_can($permissions,'users.manage')?'':' for your account' ?>.</p></div><?php endif; ?>
    </section>
</div>
