<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/support.php';
$user=require_role('owner'); $action=is_string($_GET['action']??null)?$_GET['action']:'';
if (!isset(SUPPORT_ACTIONS[$action])) { http_response_code(404); exit('Action not found.'); }
[$page_title,$mutable]=SUPPORT_ACTIONS[$action];
$modal=($_GET['modal']??'')==='1'; $id=0; $row=null; $error=null; $unavailable=false; $db=db();
try {
    if (!in_array($_SERVER['REQUEST_METHOD'],['GET','POST'],true)) { http_response_code(405); throw new InvalidArgumentException('Use a support form.'); }
    $id=in_array($action,['ticket','reply','announcement'],true)?sale_id(sale_text($_SERVER['REQUEST_METHOD']==='POST'?$_POST:$_GET,'id')):0;
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        if (!$mutable) { http_response_code(405); throw new InvalidArgumentException('These details are read-only.'); }
        if (!valid_csrf() || !sale_valid_token($_POST,'support-'.$action,$id)) { http_response_code(403); throw new InvalidArgumentException('This form expired. Reopen it before saving.'); }
        $db->beginTransaction(); $result=support_change($db,(int)$user['user_id'],$action,$id,$_POST,sale_text($_POST,'request_key')); $db->commit();
        $message=$result['replayed']?'Your message was already saved. No duplicate was added.':($action==='feedback'?'Feedback saved. You can check its review status in My feedback.':'Ticket saved. Replies will appear in Help & Support.');
        if ($modal) { flash($message); header('Content-Type: application/json; charset=UTF-8'); echo json_encode(['success'=>true,'id'=>$result['id'],'message'=>$message,'redirect'=>app_url('user/support.php'.($action==='feedback'?'?tab=feedback':''))],JSON_THROW_ON_ERROR); exit; }
        flash($message); redirect_to('user/support.php'.($action==='feedback'?'?tab=feedback':''));
    }
    if ($action==='announcement') { $row=platform_announcement($db,$id); }
    elseif (in_array($action,['ticket','reply'],true)) { $row=support_ticket($db,(int)$user['user_id'],$id); }
} catch (OutOfBoundsException $e) { http_response_code(404); $error=$e->getMessage(); $unavailable=true;
} catch (DomainException $e) { http_response_code(403); $error=$e->getMessage(); $unavailable=true;
} catch (InvalidArgumentException $e) { if (http_response_code()===200) { http_response_code(400); } $error=$e->getMessage();
} catch (Throwable $e) { http_response_code(503); $error='This action is temporarily unavailable. Please try again.'; $unavailable=true; error_log('ProfitPocket: support action failed.'); }
if ($db->inTransaction()) { $db->rollBack(); }
if ($modal && $_SERVER['REQUEST_METHOD']==='POST') { header('Content-Type: application/json; charset=UTF-8'); echo json_encode(['success'=>false,'message'=>$error],JSON_THROW_ON_ERROR); exit; }
if (!$unavailable && $error && in_array($action,['ticket','reply'],true)) {
    try { $row=support_ticket($db,(int)$user['user_id'],$id); } catch (Throwable $e) { $unavailable=true; }
}
if (($action==='announcement' && !$row) || ($action==='reply' && ($row['ticket_status']??'')==='closed')) {
    $unavailable=true;
    if (!$error) { http_response_code(400); $error='This ticket is closed. Create a new ticket for further help.'; }
}
$token=sale_form_token('support-'.$action,$id);
if ($_SERVER['REQUEST_METHOD']==='POST' && sale_valid_token($_POST,'support-'.$action,$id)) { $token=['request_key'=>sale_text($_POST,'request_key'),'request_signature'=>sale_text($_POST,'request_signature')]; }
$active_page='support'; $dashboard_layout=true;
if (!$modal) { require __DIR__.'/../includes/header.php'; require __DIR__.'/../includes/navbar.php'; echo '<main class="dashboard-main support-main" id="dashboard-content"><div class="dashboard-panel support-fallback">'; }
require __DIR__.'/../includes/support_content.php';
if (!$modal) { echo '</div></main>'; require __DIR__.'/../includes/footer.php'; }
