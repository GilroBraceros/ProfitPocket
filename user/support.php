<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/support.php';
$user=require_role('owner'); $page_title='Help & Support'; $active_page='support'; $dashboard_layout=true;
$error=null; $notice=take_flash();
try {
    if ($_SERVER['REQUEST_METHOD']!=='GET') { http_response_code(405); throw new InvalidArgumentException('Use a support form to submit a message.'); }
    $db=db(); $db->beginTransaction();
    $info=platform_information($db); $data=support_listing($db,(int)$user['user_id'],$_GET);
    $db->commit();
} catch (InvalidArgumentException $e) { if (http_response_code()===200) { http_response_code(400); } $error=$e->getMessage();
} catch (Throwable $e) { http_response_code(503); $error='Help & Support is temporarily unavailable. Please try again.'; error_log('ProfitPocket: support page failed.'); }
if (isset($db) && $db->inTransaction()) { $db->rollBack(); }
require __DIR__.'/../includes/header.php'; require __DIR__.'/../includes/navbar.php';
?>
<main class="dashboard-main support-main" id="dashboard-content">
    <div class="dashboard-heading"><div><h1>Help & Support</h1><p>Ask for help, read replies and share feedback.</p></div><a class="btn btn-primary btn-sm" data-support-modal href="<?= e(app_url('user/support_action.php?action=new_ticket')) ?>">New ticket</a></div>
    <?php if ($notice): ?><div class="alert alert-success" role="status"><?= e($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php else: ?>
    <section class="dashboard-panel support-contact">
        <div><h2><?= e($info['platform_name']??'ProfitPocket') ?> support</h2><p><?= e($info['platform_description']??'Your tickets and replies stay in the application.') ?></p></div>
        <div class="support-contact-details"><?php if (!empty($info['support_email'])): ?><span>Support email: <?= e($info['support_email']) ?></span><?php endif; ?><?php if (!empty($info['support_phone'])): ?><span>Support phone: <?= e($info['support_phone']) ?></span><?php endif; ?><span class="support-note">No automated emails are sent.</span></div>
    </section>
    <section class="dashboard-panel">
        <nav class="support-tabs" aria-label="Support sections"><?php foreach (['tickets'=>'My tickets','feedback'=>'My feedback'] as $tab=>$label): ?><a class="<?= $data['tab']===$tab?'active':'' ?>" href="?tab=<?= e($tab) ?>" <?= $data['tab']===$tab?'aria-current="page"':'' ?>><?= e($label) ?></a><?php endforeach; ?></nav>
        <form class="dashboard-filter support-filter" method="get"><input type="hidden" name="tab" value="<?= e($data['tab']) ?>"><div><label for="support-state">Status</label><select class="form-select form-select-sm" name="state" id="support-state"><option value="all">All statuses</option><?php foreach ($data['states'] as $state): ?><option value="<?= e($state) ?>" <?= $data['state']===$state?'selected':'' ?>><?= e(ucwords(str_replace('_',' ',$state))) ?></option><?php endforeach; ?></select></div><button class="btn btn-outline-secondary btn-sm" type="submit">Apply</button><span class="support-count"><?= e($data['count']) ?> <?= $data['tab']==='tickets'?'tickets':'feedback entries' ?></span><?php if ($data['tab']==='feedback'): ?><a class="btn btn-primary btn-sm ms-auto" data-support-modal href="<?= e(app_url('user/support_action.php?action=feedback')) ?>">Share feedback</a><?php endif; ?></form>
        <div class="support-records"><?php foreach ($data['rows'] as $row): ?><article class="support-record"><div><h2><?= e($data['tab']==='tickets'?$row['subject']:ucfirst($row['category']).' feedback') ?></h2><p><?= e(platform_date($row['created_at'])) ?><?php if ($data['tab']==='tickets'): ?> · TKT-<?= e($row['ticket_id']) ?><?php endif; ?></p><?php if ($data['tab']==='feedback'): ?><p class="support-text"><?= nl2br(e($row['message'])) ?></p><?php endif; ?></div><div class="support-record-action"><span class="payment-badge status-<?= e($row['status']) ?>"><?= e(ucwords(str_replace('_',' ',$row['status']))) ?></span><?php if ($data['tab']==='tickets'): ?><a class="record-action-view" data-support-modal href="<?= e(app_url('user/support_action.php?action=ticket&id='.$row['ticket_id'])) ?>">View conversation</a><?php endif; ?></div></article><?php endforeach; ?><?php if (!$data['rows']): ?><div class="empty-state"><h2><?= $data['tab']==='tickets'?'No tickets here':'No feedback here' ?></h2><p><?= $data['tab']==='tickets'?'Create a ticket when you need help.':'Share a suggestion or tell us about a problem.' ?></p></div><?php endif; ?></div>
        <nav class="sales-pagination" aria-label="Support pages"><?php if ($data['page']>1): ?><a class="btn btn-outline-secondary btn-sm" href="?<?= e(http_build_query(['tab'=>$data['tab'],'state'=>$data['state'],'page'=>$data['page']-1])) ?>">Previous</a><?php endif; ?><span>Page <?= e($data['page']) ?> of <?= e($data['pages']) ?></span><?php if ($data['page']<$data['pages']): ?><a class="btn btn-outline-secondary btn-sm" href="?<?= e(http_build_query(['tab'=>$data['tab'],'state'=>$data['state'],'page'=>$data['page']+1])) ?>">Next</a><?php endif; ?></nav>
    </section>
    <?php endif; ?>
    <?php require __DIR__.'/../includes/support_modal.php'; ?>
</main>
<?php require __DIR__.'/../includes/footer.php'; ?>
