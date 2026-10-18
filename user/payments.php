<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/payments.php';
require_once __DIR__.'/../includes/record_actions.php';
$user=require_business(); $db=db(); $page_title='Payment Management'; $active_page='payments'; $dashboard_layout=true; $error=null; $data=null; $notice=take_flash();
if ($_SERVER['REQUEST_METHOD']!=='GET') { http_response_code(405); header('Allow: GET'); exit; }
try { $filters=payment_filters($_GET); }
catch (InvalidArgumentException $e) { http_response_code(400); $error=$e->getMessage().' Showing default filters.'; $filters=payment_filters([]); }
try { $db->beginTransaction(); $data=payment_list($db,(int)$user['business_id'],$filters); $db->commit(); }
catch (Throwable $e) { if ($db->inTransaction()) { $db->rollBack(); } error_log('ProfitPocket: payment list unavailable, code '.$e->getCode()); http_response_code(503); $error='Payments are temporarily unavailable. Please try again.'; }
$action_url=app_url('user/payments_action.php');
require __DIR__.'/../includes/header.php'; require __DIR__.'/../includes/navbar.php';
?>
<?php require __DIR__.'/../includes/payments_page_content.php'; require __DIR__.'/../includes/footer.php'; ?>
