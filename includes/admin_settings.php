<div class="admin-settings-layout">
    <nav class="admin-settings-nav" aria-label="Settings sections" data-admin-settings-nav>
        <a href="#profile" aria-current="location">Administrator profile</a>
        <?php if (admin_can($permissions,'settings.manage')): ?><a href="#platform-settings">Platform settings</a><?php endif; ?>
        <a href="#role-settings">Roles and permissions</a>
        <?php if (admin_can($permissions,'backups.manage')): ?><a href="#backup-history">Backup information</a><?php endif; ?>
    </nav>
    <div class="admin-settings-sections">
        <section class="dashboard-panel admin-settings-section" id="profile">
            <header class="admin-panel-header"><h2>Administrator profile</h2><a class="btn btn-outline-secondary" data-admin-modal href="<?= e(admin_action_url('profile',(int)$user['user_id'])) ?>">Edit profile</a></header>
            <div class="admin-section-body">
                <dl class="admin-details">
                    <div><dt>Full name</dt><dd><?= e($profile['full_name']) ?></dd></div>
                    <div><dt>Email</dt><dd><?= e($profile['email']) ?></dd></div>
                    <div><dt>Role</dt><dd><?= e($user['role_name']) ?></dd></div>
                    <div><dt>Last sign-in</dt><dd><?= e($profile['last_login_at']?platform_date($profile['last_login_at']):'Not recorded') ?></dd></div>
                </dl>
            </div>
            <div class="admin-section-note">Profile changes require your current password and sign you out of all sessions.</div>
        </section>
        <?php if (admin_can($permissions,'settings.manage')): ?>
        <section class="dashboard-panel admin-settings-section" id="platform-settings">
            <header class="admin-panel-header"><h2>Platform settings</h2></header>
            <div class="admin-section-body">
                <?php $settingLabels=['platform_name'=>'Support platform name','platform_description'=>'Support introduction','support_email'=>'Support email','support_phone'=>'Support phone'];
                foreach ($settings as $row): ?>
                <div class="admin-setting">
                    <div><strong><?= e($settingLabels[$row['setting_key']]) ?></strong><p><?= e($row['setting_value']) ?></p></div>
                    <a class="record-action-view" data-admin-modal href="<?= e(admin_action_url('setting',0,['setting_key'=>$row['setting_key']])) ?>" aria-label="Edit <?= e($settingLabels[$row['setting_key']]) ?>">Edit</a>
                </div>
                <?php endforeach; ?>
                <?php if (!$settings): ?><p class="admin-help">No general contact settings are configured.</p><?php endif; ?>
            </div>
            <div class="admin-section-note">Displayed on the owner's Help & Support page. Contact information only; email sending is not configured.</div>
        </section>
        <?php endif; ?>
        <section class="dashboard-panel admin-settings-section" id="role-settings">
            <header class="admin-panel-header"><h2>Roles and permissions</h2><span class="admin-read-only">Existing administrators</span></header>
            <?php if ($admins): ?>
            <div class="table-responsive">
                <table class="table admin-table">
                    <colgroup><col class="admin-col-38"><col class="admin-col-25"><col class="admin-col-16"><col class="admin-col-21"></colgroup>
                    <thead><tr><th scope="col">Administrator</th><th scope="col">Role</th><th scope="col">Status</th><th scope="col">Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($admins as $row): ?>
                        <tr>
                            <td data-label="Administrator"><strong><?= e($row['full_name']) ?></strong><span class="admin-cell-meta"><?= e($row['email']) ?></span></td>
                            <td data-label="Role"><?= e($row['role_name']) ?></td>
                            <td data-label="Status"><?= admin_badge($row['account_status']) ?></td>
                            <td data-label="Action"><a class="record-action-view" data-admin-modal href="<?= e(admin_action_url('role',(int)$row['user_id'])) ?>">Change role</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="admin-section-note">Role changes end the account's sessions. At least one active administrator must retain user and settings access.</div>
            <?php endif; ?>
            <details class="admin-permissions">
                <summary>View role permissions</summary>
                <div class="admin-permission-grid">
                    <?php $byRole=[]; foreach ($roles as $row) { $byRole[$row['role_name']][]=$row['description']??'No assigned permissions'; }
                    foreach ($byRole as $name=>$descriptions): ?>
                    <div><h3><?= e($name) ?></h3><ul><?php foreach ($descriptions as $description): ?><li><?= e($description) ?></li><?php endforeach; ?></ul></div>
                    <?php endforeach; ?>
                </div>
                <p class="admin-help">Permission definitions are read-only.</p>
            </details>
        </section>
        <?php if (admin_can($permissions,'backups.manage')): ?>
        <section class="dashboard-panel admin-settings-section" id="backup-history">
            <header class="admin-panel-header"><h2>Backup information</h2><span class="admin-read-only">Read only</span></header>
            <?php if (!$backups): ?><div class="admin-small-empty"><p>No backup jobs recorded.</p></div><?php else: ?>
            <div class="table-responsive">
                <table class="table admin-table admin-backup-table">
                    <thead><tr><th scope="col">Created</th><th scope="col">Status</th><th scope="col">File reference</th><th scope="col">Finished</th><th scope="col">Details</th></tr></thead>
                    <tbody>
                    <?php foreach ($backups as $row): ?>
                        <tr><td data-label="Created"><?= e(platform_date($row['created_at'])) ?></td><td data-label="Status"><?= admin_badge($row['backup_status']) ?></td><td data-label="File reference"><?= e($row['storage_reference']??'Not recorded') ?></td><td data-label="Finished"><?= e($row['finished_at']?platform_date($row['finished_at']):'Not finished') ?></td><td data-label="Details"><?= e($row['error_summary']??'—') ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            <div class="admin-section-note">Latest 20 recorded jobs. This page does not create or restore backups.</div>
        </section>
        <?php endif; ?>
    </div>
</div>
