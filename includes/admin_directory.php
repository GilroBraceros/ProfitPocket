<?php
$columns=match($module) {
    'users'=>['User','Business','Status','Joined','Actions'],
    'businesses'=>['Business','Owner','Account status','Registered','Actions'],
    'tickets'=>['Reference','Subject','Submitted by','Submitted','Status','Actions'],
    'feedback'=>['User','Feedback','Submitted','Status','Actions'],
    'announcements'=>['Announcement','Created','Status','Actions'],
    'logs'=>['Activity','Actor','Date / time','Source','Actions'],
};
$widths=match($module) {
    'users','businesses'=>[28,25,16,16,15],
    'tickets'=>[10,29,20,13,13,15],
    'feedback'=>[23,32,15,15,15],
    'announcements'=>[49,20,16,15],
    'logs'=>[37,20,20,9,14],
};
$states=match($module) {
    'users','businesses'=>['active','inactive','suspended','deactivated'],
    'tickets'=>['open','in_progress','resolved','closed'],
    'feedback'=>['new','reviewed','archived'],
    'announcements'=>['draft','published','archived'],
    'logs'=>['admin','platform'],
};
$advanced=$module==='logs' || $filters['sort']!=='newest' || $filters['start']!=='' || $filters['end']!=='' || $filters['actor']!=='' || $filters['kind']!=='';
?>
<section class="dashboard-panel admin-directory" aria-label="<?= e($page_title) ?> directory">
    <form method="get" class="admin-filter">
        <div class="admin-filter-primary">
            <div class="admin-search">
                <label for="q">Search</label>
                <div class="admin-search-control">
                    <input class="form-control" id="q" name="q" maxlength="100" value="<?= e($filters['q']) ?>" type="search" placeholder="<?= e(match($module) {'users'=>'Name, username or email','businesses'=>'Business or owner','tickets'=>'Subject or submitted by','feedback'=>'Message or submitted by','announcements'=>'Announcement title',default=>'Activity or administrator'}) ?>">
                </div>
            </div>
            <div><label for="state"><?= $module==='logs'?'Source':'Status' ?></label><select class="form-select" id="state" name="state"><option value="all">All <?= $module==='logs'?'sources':'statuses' ?></option><?php foreach ($states as $state): ?><option value="<?= e($state) ?>" <?= $filters['state']===$state?'selected':'' ?>><?= e(ucwords(str_replace('_',' ',$state))) ?></option><?php endforeach; ?></select></div>
            <?php if (in_array($module,['businesses','feedback'],true)): ?>
            <div><label for="category">Category</label><select class="form-select" id="category" name="category"><option value="">All categories</option><?php $categories=$module==='businesses'?admin_query($db,'SELECT business_category_id AS id,category_name AS name FROM business_categories ORDER BY category_name')->fetchAll():array_map(fn($c)=>['id'=>$c,'name'=>ucfirst($c)],['bug','suggestion','compliment','other']); foreach ($categories as $category): ?><option value="<?= e($category['id']) ?>" <?= $filters['category']===(string)$category['id']?'selected':'' ?>><?= e($category['name']) ?></option><?php endforeach; ?></select></div>
            <?php endif; ?>
            <div class="admin-filter-buttons"><button class="btn btn-outline-secondary" type="submit">Apply</button><a class="btn btn-outline-secondary" href="<?= e(app_url('admin/'.$module.'.php')) ?>">Reset</a></div>
        </div>
        <details class="admin-filter-advanced" <?= $advanced?'open':'' ?>>
            <summary><?= $module==='logs'?'Date and activity filters':'More filters' ?></summary>
            <div class="admin-filter-fields">
                <div><label for="sort">Date order</label><select class="form-select" id="sort" name="sort"><option value="newest">Newest first</option><option value="oldest" <?= $filters['sort']==='oldest'?'selected':'' ?>>Oldest first</option></select></div>
                <?php if ($module==='logs'): ?>
                <div><label for="start">From</label><input class="form-control" type="date" id="start" name="start" value="<?= e($filters['start']) ?>"></div>
                <div><label for="end">To</label><input class="form-control" type="date" id="end" name="end" value="<?= e($filters['end']) ?>"></div>
                <div><label for="actor">Administrator</label><select class="form-select" id="actor" name="actor"><option value="">All administrators</option><?php foreach (admin_query($db,"SELECT u.user_id,u.full_name FROM users u JOIN roles r ON r.role_id=u.role_id WHERE r.role_type='admin' ORDER BY u.full_name")->fetchAll() as $admin): ?><option value="<?= e($admin['user_id']) ?>" <?= $filters['actor']===(string)$admin['user_id']?'selected':'' ?>><?= e($admin['full_name']) ?></option><?php endforeach; ?></select></div>
                <div><label for="kind">Activity type</label><select class="form-select" id="kind" name="kind"><option value="">All activities</option><?php [, $logSource]=admin_sources('logs'); foreach (admin_query($db,'SELECT DISTINCT l.action_code '.$logSource.' ORDER BY l.action_code')->fetchAll(PDO::FETCH_COLUMN) as $kind): ?><option value="<?= e($kind) ?>" <?= $filters['kind']===$kind?'selected':'' ?>><?= e(admin_action_label($kind)) ?></option><?php endforeach; ?></select></div>
                <?php endif; ?>
            </div>
        </details>
    </form>
    <div class="admin-directory-meta"><span><?= e($data['count']) ?> <?= $data['count']===1?'record':'records' ?></span><?php if ($module==='users'): ?><span>Business-owner accounts</span><?php elseif ($module==='logs'): ?><span class="admin-read-only">Read-only audit</span><?php endif; ?></div>
    <?php if (!$data['rows']): ?>
    <div class="empty-state"><h2>No matching records</h2><p>Try another search or clear the filters.</p></div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table admin-table">
            <colgroup><?php foreach ($widths as $width): ?><col class="admin-col-<?= $width ?>"><?php endforeach; ?></colgroup>
            <thead><tr><?php foreach ($columns as $column): ?><th scope="col"><?= e($column) ?></th><?php endforeach; ?></tr></thead>
            <tbody>
            <?php foreach ($data['rows'] as $row):
                $id=(int)$row['id']; $actions=[]; $extra=[];
                $view=match($module) {'users'=>'user_view','businesses'=>'business_view','tickets'=>'ticket_view','feedback'=>'feedback_view','announcements'=>'announcement_view','logs'=>'log_view'};
                if ($module==='users' && in_array($row['state'],['active','suspended'],true)) { $a=$row['state']==='active'?'suspend':'reactivate'; $actions[]=[$a,ucfirst($a).' account',admin_action_url($a,$id),$a==='suspend']; }
                if ($module==='tickets') { $actions[]=['ticket_update','Respond / update status',admin_action_url('ticket_update',$id),false]; }
                if ($module==='feedback') { $actions[]=['feedback_update','Review feedback',admin_action_url('feedback_update',$id),false]; }
                if ($module==='announcements') { $actions[]=['announcement_edit','Edit',admin_action_url('announcement_edit',$id),false]; $a=$row['state']==='published'?'unpublish':'publish'; $actions[]=[$a,ucfirst($a),admin_action_url($a,$id),false]; }
                if ($module==='logs') { $extra=['source'=>$row['source']]; }
                $primary=match($module) {'users'=>$row['full_name'],'businesses'=>$row['business_name'],'tickets'=>$row['subject'],'feedback'=>$row['full_name'],'announcements'=>$row['title'],'logs'=>admin_action_label($row['action_code'])};
                $secondary=match($module) {'users'=>$row['username'],'businesses'=>$row['category_name'],'tickets'=>ucfirst($row['priority']).' priority','feedback'=>ucfirst($row['category']),'announcements'=>$row['full_name'],'logs'=>admin_log_record($row).' · '.($row['reason']??'Platform event')};
                $date=(new DateTimeImmutable($row['created_at']))->format('j M Y');
            ?>
                <tr>
                    <?php if ($module==='tickets'): ?><td data-label="Reference"><span class="admin-reference">TKT-<?= e($id) ?></span></td><?php endif; ?>
                    <td data-label="<?= e($module==='tickets'?'Subject':$columns[0]) ?>" class="admin-cell-identity"><strong><?= e($primary) ?></strong><span class="admin-cell-meta"><?= e($secondary) ?></span></td>
                    <?php if (in_array($module,['users','businesses'],true)): ?>
                    <td data-label="<?= $module==='users'?'Business':'Owner' ?>"><?= e($module==='users'?($row['business_name']??'No linked business'):$row['full_name']) ?></td>
                    <td data-label="Status"><?= admin_badge($row['state']) ?></td>
                    <td data-label="<?= $module==='users'?'Joined':'Registered' ?>" class="admin-cell-date"><?= e($date) ?></td>
                    <?php elseif (in_array($module,['tickets','feedback'],true)): ?>
                    <td data-label="<?= $module==='tickets'?'Submitted by':'Feedback' ?>"><?php if ($module==='tickets'): ?><?= e($row['full_name']) ?><?php else: ?><span class="admin-preview"><?= e(mb_strimwidth($row['message'],0,110,'…')) ?></span><?php endif; ?></td>
                    <td data-label="Submitted" class="admin-cell-date"><?= e($date) ?></td>
                    <td data-label="Status"><?= admin_badge($row['state']) ?></td>
                    <?php elseif ($module==='announcements'): ?>
                    <td data-label="Created" class="admin-cell-date"><?= e($date) ?></td><td data-label="Status"><?= admin_badge($row['state']) ?></td>
                    <?php else: ?>
                    <td data-label="Actor"><?= e($row['full_name']??'System') ?></td><td data-label="Date / time" class="admin-cell-date"><?= e(platform_date($row['created_at'])) ?></td><td data-label="Source"><span class="admin-cell-meta"><?= e(ucfirst($row['source'])) ?></span></td>
                    <?php endif; ?>
                    <td data-label="Actions"><?php record_actions('admin',$primary,admin_action_url($view,$id,$extra),$actions); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <nav class="sales-pagination" aria-label="Directory pages">
        <?php if ($data['page']>1): ?><a class="btn btn-outline-secondary" href="?<?= e(http_build_query(array_replace($filters,['page'=>$data['page']-1]))) ?>">Previous</a><?php endif; ?>
        <span>Page <?= e($data['page']) ?> of <?= e($data['pages']) ?></span>
        <?php if ($data['page']<$data['pages']): ?><a class="btn btn-outline-secondary" href="?<?= e(http_build_query(array_replace($filters,['page'=>$data['page']+1]))) ?>">Next</a><?php endif; ?>
    </nav>
    <?php endif; ?>
</section>
