<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php'; require_once __DIR__.'/../includes/admin.php';
$action=is_string($_GET['action']??null)?$_GET['action']:''; if (!isset(ADMIN_ACTIONS[$action])) { http_response_code(404); exit('Action not found.'); }
[$module,$page_title,$mutable]=ADMIN_ACTIONS[$action]; $permission=admin_action_permission($action);
$user=$permission!==''?require_permission($permission):require_role('admin');
if ($action==='role') { $user=require_permission('settings.manage'); }
$permissions=admin_permissions(db(),(int)$user['role_id']); $db=db(); $modal=($_GET['modal']??'')==='1'; $row=null; $error=null; $unavailable=false; $id=0; $messages=[]; $history=[]; $roles=[];
try {
    if (!in_array($_SERVER['REQUEST_METHOD'],['GET','POST'],true)) { http_response_code(405); throw new InvalidArgumentException('Use an action form.'); }
    $value=sale_text($_SERVER['REQUEST_METHOD']==='POST'?$_POST:$_GET,'id','0');
    $id=in_array($action,['announcement_create','setting'],true)?0:sale_id($value);
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        if (!$mutable) { http_response_code(405); throw new InvalidArgumentException('These details are read-only.'); }
        if (!valid_csrf() || !sale_valid_token($_POST,'admin-'.$action,$id)) { http_response_code(403); throw new InvalidArgumentException('This form expired or is invalid. Reopen it before saving.'); }
        $db->beginTransaction(); $result=admin_change($db,$user,$action,$id,$_POST,sale_text($_POST,'request_key')); $db->commit();
        $message=$result['replayed']?'This action was already saved. No duplicate change was made.':match($action) {'suspend'=>'Account suspended. Sessions ended and business history preserved.','reactivate'=>'Account reactivated. The owner can sign in again.','ticket_update'=>'Ticket saved. Responses are stored in the platform; no email was sent.','profile'=>'Profile saved. Please sign in again.','role'=>'Administrator role saved. Target sessions ended.',default=>'Changes saved successfully.'};
        if ($result['logout']) { forget_login(); }
        if ($modal) { if (!$result['logout']) { flash($message); } header('Content-Type: application/json; charset=UTF-8'); echo json_encode(['success'=>true,'id'=>$result['id'],'message'=>$message,'logout'=>$result['logout'],'redirect'=>app_url('auth/login.php')],JSON_THROW_ON_ERROR); exit; }
        flash($message); redirect_to($result['logout']?'auth/login.php':'admin/'.$module.'.php');
    }
} catch (AdminDenied $e) { http_response_code(403); $error=$e->getMessage(); $unavailable=true;
} catch (AdminNotFound $e) { http_response_code(404); $error=$e->getMessage(); $unavailable=true;
} catch (InvalidArgumentException $e) { if (http_response_code()===200) { http_response_code(400); } $error=$e->getMessage();
} catch (Throwable $e) { http_response_code(503); error_log('ProfitPocket: admin action failed, code '.$e->getCode()); $error=$e instanceof PDOException && (int)($e->errorInfo[1]??0)===1062?'That email is already assigned to another account.':'This action could not be completed. Please try again.'; }
if ($db->inTransaction()) { $db->rollBack(); }
if ($modal && $_SERVER['REQUEST_METHOD']==='POST') { header('Content-Type: application/json; charset=UTF-8'); echo json_encode(['success'=>false,'message'=>$error],JSON_THROW_ON_ERROR); exit; }
try {
    if (!$unavailable) {
        if ($action==='profile') { if ($id!==(int)$user['user_id']) { throw new AdminDenied('Only your own profile may be updated.'); } $row=admin_query($db,'SELECT full_name,email,phone FROM users WHERE user_id=?',[$id])->fetch(); }
        elseif ($action==='setting') { $key=sale_text($_GET,'setting_key'); if (!in_array($key,ADMIN_SETTING_KEYS,true)) { throw new AdminDenied('Configuration is not available here.'); } $row=admin_query($db,'SELECT setting_key,setting_value,updated_at FROM platform_settings WHERE setting_key=?',[$key])->fetch(); if (!$row) { throw new AdminNotFound('Setting not configured.'); } }
        elseif ($action==='role') {
            $row=admin_query($db,"SELECT u.user_id,u.role_id,u.account_status,r.role_type FROM users u JOIN roles r ON r.role_id=u.role_id WHERE u.user_id=? AND r.role_type='admin'",[$id])->fetch(); if (!$row) { throw new AdminNotFound('Administrator not found.'); }
            $roles=admin_query($db,"SELECT role_id,role_name FROM roles WHERE role_type='admin' ORDER BY role_id")->fetchAll();
            $roleTarget=admin_query($db,'SELECT full_name,email FROM users WHERE user_id=?',[$id])->fetch();
        } elseif ($action!=='announcement_create') { $row=admin_read($db,$module,$id,sale_text($_GET,'source','admin')); }
        if ($module==='tickets' && $row) {
            $messages=admin_query($db,'SELECT m.message_body,m.created_at,u.full_name,r.role_name FROM ticket_messages m JOIN users u ON u.user_id=m.sender_user_id JOIN roles r ON r.role_id=u.role_id WHERE m.ticket_id=? ORDER BY m.created_at,m.message_id',[$id])->fetchAll();
            $history=admin_query($db,'SELECT h.old_status,h.new_status,h.changed_at,u.full_name FROM ticket_status_history h JOIN users u ON u.user_id=h.changed_by WHERE h.ticket_id=? ORDER BY h.changed_at,h.history_id',[$id])->fetchAll();
        }
        if ($module==='users' && $row) { $history=admin_query($db,'SELECT action_code,reason,created_at FROM admin_action_logs WHERE target_user_id=? ORDER BY created_at DESC,admin_action_id DESC LIMIT 30',[$id])->fetchAll(); }
    }
} catch (AdminDenied $e) { http_response_code(403); $error=$e->getMessage(); $unavailable=true;
} catch (AdminNotFound $e) { http_response_code(404); $error=$e->getMessage(); $unavailable=true;
} catch (Throwable $e) { http_response_code(503); $error='Details are temporarily unavailable.'; $unavailable=true; error_log('ProfitPocket: admin details query failed.'); }
$token=sale_form_token('admin-'.$action,$id);
if ($_SERVER['REQUEST_METHOD']==='POST' && sale_valid_token($_POST,'admin-'.$action,$id)) { $token=['request_key'=>sale_text($_POST,'request_key'),'request_signature'=>sale_text($_POST,'request_signature')]; }
$dashboard_layout=true; $active_page='admin';
if (!$modal) { require __DIR__.'/../includes/header.php'; require __DIR__.'/../includes/admin_navbar.php'; echo '<main class="dashboard-main admin-main" id="dashboard-content"><div class="dashboard-panel admin-fallback">'; }
require __DIR__.'/../includes/admin_content.php';
if (!$modal) { echo '</div></main>'; require __DIR__.'/../includes/footer.php'; }
