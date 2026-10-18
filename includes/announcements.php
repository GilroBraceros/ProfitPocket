<section class="dashboard-panel owner-announcements" aria-labelledby="announcements-title">
    <div class="panel-heading"><div><h2 id="announcements-title">Announcements</h2><p>Latest updates from ProfitPocket.</p></div></div>
    <?php if ($announcement_error): ?><p class="announcement-empty" role="status"><?= e($announcement_error) ?></p>
    <?php elseif (!$announcements): ?><p class="announcement-empty">No published announcements at the moment.</p>
    <?php else: ?><div class="announcement-list"><?php foreach ($announcements as $announcement): ?><article class="announcement-item"><div><h3><?= e($announcement['title']) ?></h3><time datetime="<?= e(str_replace(' ','T',$announcement['published_at'])) ?>+08:00"><?= e(platform_date($announcement['published_at'])) ?></time><p><?= e(mb_strimwidth($announcement['body'],0,160,'…')) ?></p></div><a class="record-action-view" data-support-modal href="<?= e(app_url('user/support_action.php?action=announcement&id='.$announcement['announcement_id'])) ?>">Read announcement</a></article><?php endforeach; ?></div><?php endif; ?>
</section>
<?php require __DIR__.'/support_modal.php'; ?>
