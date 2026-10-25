<?php
$isForm=$mutable && !$unavailable;
$values=$row??[];
if ($_SERVER['REQUEST_METHOD']==='POST') {
    foreach ($_POST as $key=>$value) {
        if (is_string($value) && !str_contains($key,'password')) { $values[$key]=$value; }
    }
}
$field=function(string $name,string $label,string $type='text',int $max=255,bool $required=true) use ($values): void {
?>
    <div class="admin-field">
        <label class="form-label" for="admin-<?= e($name) ?>"><?= e($label) ?></label>
        <?php if ($type==='textarea'): ?>
        <textarea class="form-control" rows="5" name="<?= e($name) ?>" id="admin-<?= e($name) ?>" maxlength="<?= $max ?>" <?= $required?'required':'' ?>><?= e($values[$name]??'') ?></textarea>
        <?php else: ?>
        <input class="form-control" type="<?= e($type) ?>" name="<?= e($name) ?>" id="admin-<?= e($name) ?>" maxlength="<?= $max ?>" <?= $required?'required':'' ?> value="<?= $type==='password'?'':e($values[$name]??'') ?>" <?= $type==='password'?'autocomplete="'.($name==='current_password'?'current-password':'new-password').'"':'' ?>>
        <?php endif; ?>
    </div>
<?php
};
$statusSelect=function(array $statuses,string $value): void {
?>
    <div class="admin-field">
        <label class="form-label" for="admin-status">Status</label>
        <select class="form-select" id="admin-status" name="status"><?php foreach ($statuses as $status): ?><option value="<?= e($status) ?>" <?= $value===$status?'selected':'' ?>><?= e(ucwords(str_replace('_',' ',$status))) ?></option><?php endforeach; ?></select>
    </div>
<?php
};
$badge=fn(string $status)=>'<span class="payment-badge admin-status status-'.e($status).'">'.e(ucwords(str_replace('_',' ',$status))).'</span>';
?>
<?php if ($isForm): ?>
<form class="admin-form" method="post" action="<?= e(app_url('admin/action.php').'?'.http_build_query(['action'=>$action]+($action==='setting'?['setting_key'=>$row['setting_key']]:[])+($modal?['modal'=>1]:[]))) ?>" <?= $modal?'data-admin-async':'' ?>>
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= e($id) ?>">
    <?php foreach ($token as $key=>$value): ?><input type="hidden" name="<?= e($key) ?>" value="<?= e($value) ?>"><?php endforeach; ?>
    <input type="hidden" name="revision" value="<?= e($values['revision']??($row?admin_revision($row):'')) ?>">
<?php endif; ?>
<div class="modal-header">
    <h2 class="modal-title" id="admin-modal-title"><?= e($page_title) ?></h2>
    <?php if ($modal): ?><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button><?php endif; ?>
</div>
<div class="modal-body">
    <div class="alert alert-danger admin-error <?= !$error?'d-none':'' ?>" role="alert" tabindex="-1"><?= e($error??'') ?></div>
    <?php if ($unavailable): ?>
    <?php elseif (in_array($action,['suspend','reactivate'],true)): ?>
    <div class="admin-confirm">
        <h3><?= e($row['full_name']) ?></h3><p><?= e($row['email']) ?></p><p>Current status: <?= $badge($row['state']) ?></p>
        <p><?= $action==='suspend'?'This owner will lose access immediately. Their business and financial history will be preserved.':'This owner can sign in again. Previous sessions stay revoked and financial history is preserved.' ?></p>
    </div>
    <?php $field('reason','Reason for this change','textarea',255); ?>
    <label class="admin-check"><input type="checkbox" class="form-check-input" name="confirm" value="1" required <?= ($values['confirm']??'')==='1'?'checked':'' ?>>I confirm this account status change.</label>
    <?php elseif ($action==='user_view' || $action==='business_view'): ?>
    <div class="admin-record-header"><div><h3><?= e($action==='user_view'?$row['full_name']:$row['business_name']) ?></h3><p><?= $action==='user_view'?'Business owner':'Registered business' ?></p></div><?= $badge($row['state']) ?></div>
    <dl class="admin-details">
        <?php
        $labels=$action==='user_view'
            ?['username'=>'Username','email'=>'Email','business_name'=>'Business','created_at'=>'Registered','last_login_at'=>'Last sign-in','phone'=>'Contact phone']
            :['category_name'=>'Category','description'=>'Description','full_name'=>'Owner','email'=>'Owner email','created_at'=>'Registered','address'=>'Address','contact_number'=>'Contact number'];
        foreach ($labels as $key=>$label):
            $value=$row[$key]??'Not recorded';
            if (in_array($key,['created_at','last_login_at'],true) && !empty($row[$key])) { $value=platform_date($row[$key]); }
        ?>
        <div><dt><?= e($label) ?></dt><dd><?= e($value) ?></dd></div>
        <?php endforeach; ?>
    </dl>
    <?php if ($action==='user_view'): ?>
    <hr class="admin-section-divider">
    <details class="admin-history">
        <summary>Account status history</summary>
        <p>Latest 30 recorded administrative actions.</p>
        <?php if (!$history): ?><p>No recorded changes.</p><?php endif; ?>
        <?php foreach ($history as $event): ?><div class="admin-event"><strong><?= e(admin_action_label($event['action_code'])) ?></strong><p><?= e(platform_date($event['created_at'])) ?> · <?= e($event['reason']??'Administrative change') ?></p></div><?php endforeach; ?>
    </details>
    <?php endif; ?>
    <?php elseif ($module==='tickets'): ?>
    <div class="admin-record-header"><div><h3><?= e($row['subject']) ?></h3><p>TKT-<?= e($id) ?> · <?= e($row['full_name']) ?> · <?= e(platform_date($row['created_at'])) ?></p></div><?= $badge($row['state']) ?></div>
    <p class="admin-text"><?= nl2br(e($row['description'])) ?></p>
    <h3 class="admin-conversation-heading">Conversation</h3>
    <?php if (!$messages): ?><p class="admin-help">No replies yet.</p><?php endif; ?>
    <?php foreach ($messages as $message): ?>
    <article class="admin-message"><strong><?= e($message['full_name']) ?></strong><span><?= e($message['role_name']) ?> · <?= e(platform_date($message['created_at'])) ?></span><p><?= nl2br(e($message['message_body'])) ?></p></article>
    <?php endforeach; ?>
    <?php if ($history): ?>
    <details class="admin-history"><summary>Status history</summary><?php foreach ($history as $event): ?><p><?= e(platform_date($event['changed_at'])) ?> · <?= e($event['full_name']) ?> · <?= e(ucwords(str_replace('_',' ',$event['old_status']))) ?> → <?= e(ucwords(str_replace('_',' ',$event['new_status']))) ?></p><?php endforeach; ?></details>
    <?php endif; ?>
    <?php if ($action==='ticket_update'): ?>
    <section class="admin-reply-area" aria-label="Reply to ticket">
        <h3>Reply and update status</h3>
        <?php $field('message','Your reply','textarea',4000,false); $statusSelect(['open','in_progress','resolved','closed'],$values['status']??$row['state']); ?>
        <p class="admin-help">Replies appear in the owner's Help & Support conversation. No email is sent.</p>
    </section>
    <?php else: ?>
    <hr class="admin-section-divider">
    <a class="btn btn-primary" data-admin-modal="ticket_update" href="<?= e(app_url('admin/action.php?action=ticket_update&id='.$id)) ?>">Reply / update status</a>
    <?php endif; ?>
    <?php elseif ($module==='feedback'): ?>
    <div class="admin-record-header"><div><h3><?= e($row['full_name']) ?></h3><p><?= e(ucfirst($row['category'])) ?> · <?= e(platform_date($row['created_at'])) ?></p></div><?= $badge($row['state']) ?></div>
    <p class="admin-text"><?= nl2br(e($row['message'])) ?></p>
    <?php if ($action==='feedback_update'): ?><hr class="admin-section-divider"><?php $statusSelect(['new','reviewed','archived'],$values['status']??$row['state']); ?><?php endif; ?>
    <?php elseif ($action==='announcement_view'): ?>
    <div class="admin-record-header"><div><h3><?= e($row['title']) ?></h3><p><?= e($row['full_name']) ?> · <?= e(platform_date($row['created_at'])) ?></p></div><?= $badge($row['state']) ?></div>
    <div class="admin-text"><?= nl2br(e($row['body'])) ?></div>
    <hr class="admin-section-divider"><p class="admin-help">Published: <?= e($row['published_at']?platform_date($row['published_at']):'Not published') ?></p>
    <?php elseif (in_array($action,['announcement_create','announcement_edit'],true)): ?>
    <?php $field('title','Title','text',200); $field('body','Announcement content','textarea',8000); $statusSelect(['draft','published','archived'],$values['status']??$row['state']??'draft'); ?>
    <p class="admin-help">Published announcements appear on owners' dashboards. No automated emails are sent.</p>
    <?php elseif ($action==='publish' || $action==='unpublish'): ?>
    <div class="admin-confirm"><h3><?= e($row['title']) ?></h3><p><?= $action==='publish'?'This announcement will appear on business owners’ dashboards.':'This announcement will return to draft and disappear from business owners’ dashboards.' ?></p></div>
    <label class="admin-check"><input type="checkbox" class="form-check-input" name="confirm" value="1" required>Confirm this publication change.</label>
    <?php elseif ($action==='profile'): ?>
    <section class="admin-form-section">
        <h3>Personal information</h3>
        <?php $field('full_name','Full name','text',150); ?>
        <div class="admin-form-grid"><?php $field('email','Email','email',191); $field('phone','Phone (optional)','tel',30,false); ?></div>
    </section>
    <section class="admin-form-section">
        <h3>Change password <span class="admin-help">(optional)</span></h3>
        <div class="admin-form-grid"><?php $field('new_password','New password','password',72,false); $field('confirm_password','Confirm new password','password',72,false); ?></div>
        <p class="admin-help">Use 12–72 bytes. Leave both fields empty to keep your current password.</p>
    </section>
    <hr class="admin-section-divider">
    <?php $field('current_password','Confirm with your current password','password',72); ?>
    <p class="admin-help">Saving signs you out of all sessions.</p>
    <?php elseif ($action==='setting'): ?>
    <input type="hidden" name="setting_key" value="<?= e($row['setting_key']) ?>">
    <?php $settingLabels=['platform_name'=>'Support platform name','platform_description'=>'Support introduction','support_email'=>'Support email','support_phone'=>'Support phone'];
    $field('setting_value',$settingLabels[$row['setting_key']],$row['setting_key']==='support_email'?'email':($row['setting_key']==='platform_description'?'textarea':'text'),$row['setting_key']==='platform_description'?2000:191); ?>
    <p class="admin-help">Shown on the owner's Help & Support page. Transactional email uses separate private server configuration.</p>
    <?php elseif ($action==='role'): ?>
    <div class="admin-record-header"><div><h3><?= e($roleTarget['full_name']) ?></h3><p><?= e($roleTarget['email']) ?></p></div></div>
    <div class="admin-field"><label for="admin-role" class="form-label">Administrator role</label><select class="form-select" id="admin-role" name="role_id"><?php foreach ($roles as $role): ?><option value="<?= e($role['role_id']) ?>" <?= (string)($values['role_id']??'')===(string)$role['role_id']?'selected':'' ?>><?= e($role['role_name']) ?></option><?php endforeach; ?></select></div>
    <?php $field('reason','Reason for this change','textarea',255); ?>
    <label class="admin-check"><input class="form-check-input" type="checkbox" name="confirm" value="1" required>Confirm this role change and end the account's sessions.</label>
    <p class="admin-help">At least one active administrator must retain user and settings access.</p>
    <?php elseif ($action==='log_view'): ?>
    <dl class="admin-details">
        <div><dt>Affected record</dt><dd><?= e(admin_log_record($row)) ?></dd></div>
        <div><dt>Date / time</dt><dd><?= e(platform_date($row['created_at'])) ?></dd></div>
        <div><dt>Actor</dt><dd><?= e($row['full_name']??'System') ?></dd></div>
        <div><dt>Activity</dt><dd><?= e(admin_action_label($row['action_code'])) ?></dd></div>
        <div><dt>Source</dt><dd><?= e(ucfirst($row['source'])) ?></dd></div>
        <div><dt>Description</dt><dd><?= e($row['reason']??'Not recorded') ?></dd></div>
        <div><dt>IP address</dt><dd><?= e(filter_var(json_decode($row['new_value']??'',true)['_ip']??'',FILTER_VALIDATE_IP)?:'Not recorded') ?></dd></div>
    </dl>
    <hr class="admin-section-divider">
    <div class="admin-log-values">
        <?php foreach (['old_value'=>'Before','new_value'=>'After'] as $key=>$label): ?>
        <section><h3><?= e($label) ?></h3><dl class="admin-details"><?php $safe=admin_safe_log($row[$key]); foreach ($safe as $name=>$value): ?><div><dt><?= e(str_replace('_',' ',$name)) ?></dt><dd><?= e($value) ?></dd></div><?php endforeach; ?></dl><?php if (!$safe): ?><p class="admin-help">No permitted details recorded.</p><?php endif; ?></section>
        <?php endforeach; ?>
    </div>
    <p class="admin-help">Read-only audit. Private financial information and internal credentials are excluded.</p>
    <?php endif; ?>
</div>
<div class="modal-footer">
    <?php if ($modal): ?><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= $isForm?'Cancel':'Close' ?></button><?php else: ?><a class="btn btn-outline-secondary" href="<?= e(app_url('admin/'.$module.'.php')) ?>">Back</a><?php endif; ?>
    <?php if ($isForm): ?><button class="btn <?= $action==='suspend'?'btn-danger':'btn-primary' ?>" type="submit"><?= e(match($action) {'suspend'=>'Suspend account','reactivate'=>'Reactivate account','ticket_update'=>'Save reply / status','announcement_create'=>'Save announcement','publish'=>'Publish announcement','unpublish'=>'Unpublish announcement',default=>'Save changes'}) ?></button><?php endif; ?>
</div>
<?php if ($isForm): ?></form><?php endif; ?>
