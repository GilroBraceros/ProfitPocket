<?php
declare(strict_types=1);
require_once __DIR__.'/auth.php'; require_once __DIR__.'/admin.php'; require_once __DIR__.'/record_actions.php';
if (!isset($module,ADMIN_MODULES[$module])) { http_response_code(404); exit; }
$user=ADMIN_MODULES[$module][2]!==''?require_permission(ADMIN_MODULES[$module][2]):require_role('admin');
$permissions=admin_permissions(db(),(int)$user['role_id']); $page_title=ADMIN_MODULES[$module][0]; $dashboard_layout=true; $active_page='admin'; $error=null; $data=null; $notice=take_flash(); $db=db();
try {
    if ($_SERVER['REQUEST_METHOD']!=='GET') { http_response_code(405); throw new InvalidArgumentException('Use an authorized action form to make changes.'); }
    $filters=admin_filters($module,$_GET); $db->beginTransaction();
    if ($module==='dashboard') { $data=admin_dashboard($db,$permissions,(int)$user['user_id']); }
    elseif ($module==='settings') {
        $profile=admin_query($db,'SELECT full_name,email,phone,created_at,last_login_at FROM users WHERE user_id=?',[$user['user_id']])->fetch();
        $settings=admin_can($permissions,'settings.manage')?admin_query($db,"SELECT setting_key,setting_value,updated_at FROM platform_settings WHERE setting_key IN ('platform_name','platform_description','support_email','support_phone') ORDER BY setting_key")->fetchAll():[];
        $roles=admin_query($db,'SELECT r.role_name,p.description FROM roles r LEFT JOIN role_permissions rp ON rp.role_id=r.role_id LEFT JOIN permissions p ON p.permission_id=rp.permission_id WHERE r.role_type=\'admin\' ORDER BY r.role_id,p.permission_code')->fetchAll();
        $admins=admin_can($permissions,'settings.manage') && admin_can($permissions,'users.manage')?admin_query($db,"SELECT u.user_id,u.full_name,u.email,u.account_status,r.role_name FROM users u JOIN roles r ON r.role_id=u.role_id WHERE r.role_type='admin' ORDER BY u.full_name")->fetchAll():[];
        $backups=admin_can($permissions,'backups.manage')?admin_query($db,'SELECT backup_job_id,backup_status,storage_reference,started_at,finished_at,error_summary,created_at FROM backup_jobs ORDER BY created_at DESC,backup_job_id DESC LIMIT 20')->fetchAll():[];
    } else { $data=admin_list($db,$module,$filters); }
    $db->commit();
} catch (InvalidArgumentException $e) { if (http_response_code()===200) { http_response_code(400); } $error=$e->getMessage();
} catch (Throwable $e) { http_response_code(503); error_log('ProfitPocket: admin page unavailable, code '.$e->getCode()); $error='This administrative page is temporarily unavailable. Please try again.'; }
if ($db->inTransaction()) { $db->rollBack(); }
function admin_action_url(string $action,int $id=0,array $extra=[]): string { return app_url('admin/action.php').'?'.http_build_query(['action'=>$action,'id'=>$id]+$extra); }
function admin_badge(string $status): string { return '<span class="payment-badge admin-status status-'.e($status).'">'.e(ucwords(str_replace('_',' ',$status))).'</span>'; }
require __DIR__.'/header.php'; require __DIR__.'/admin_navbar.php';
?>
<main class="dashboard-main admin-main" id="dashboard-content">
    <div class="dashboard-heading"><div><h1><?= e($page_title) ?></h1><p><?= e($module==='dashboard'?'Overview of your ProfitPocket platform':ADMIN_MODULES[$module][1]) ?></p></div><?php if ($module==='announcements'): ?><a class="btn btn-primary" data-admin-modal href="<?= e(admin_action_url('announcement_create')) ?>">+ New Announcement</a><?php endif; ?></div>
    <?php if ($notice): ?><div class="alert alert-success" role="status"><?= e($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div>
    <?php elseif ($module==='dashboard'): ?><?php require __DIR__.'/admin_dashboard_content.php'; ?>
    <?php elseif ($module==='settings'): ?><?php require __DIR__.'/admin_settings.php'; ?>
    <?php else: ?><?php require __DIR__.'/admin_directory.php'; ?><?php endif; ?>
    <div class="modal fade platform-modal" id="admin-action-modal" tabindex="-1" aria-labelledby="admin-modal-title" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down"><div class="modal-content"></div></div></div>
</main>
<?php require __DIR__.'/footer.php'; ?>
