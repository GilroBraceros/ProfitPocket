<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(403); exit; }
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/utang.php';
date_default_timezone_set('Asia/Manila');
$db=db();
if (($argv[1]??'')==='worker') {
    $sale=(int)$argv[2]; $business=(int)$argv[3]; $owner=(int)$argv[4]; $method=(int)$argv[5]; $direct=($argv[6]??'')==='direct';
    $db->exec('SET SESSION innodb_lock_wait_timeout=1'); $db->beginTransaction();
    try {
        if ($direct) { customer_query($db,'INSERT INTO sale_payments (sale_id,business_id,payment_method_id,entered_by,amount) VALUES (?,?,?,?,?)',[$sale,$business,$method,$owner,'0.01']); }
        else { utang_collect($db,['business_id'=>$business,'user_id'=>$owner],$sale,['amount'=>'0.01','payment_method_id'=>(string)$method,'paid_at'=>date('Y-m-d\TH:i:s')],bin2hex(random_bytes(32))); }
        echo 'FAIL: concurrent request bypassed the lock'; exit(1);
    } catch (PDOException $e) { if (($e->errorInfo[1]??null)!==1205) { throw $e; } echo 'PASS: competing request waits for the lock'; }
    finally { if ($db->inTransaction()) { $db->rollBack(); } }
    exit;
}
$credit=$db->query("SELECT f.sale_id,f.business_id,b.owner_user_id FROM v_sale_financials f JOIN customer_credits cr ON cr.sale_id=f.sale_id JOIN businesses b ON b.business_id=f.business_id JOIN users u ON u.user_id=b.owner_user_id WHERE f.sale_status='posted' AND f.sale_type='credit' AND f.balance_due>0 AND u.account_status='active' AND f.sold_at<=NOW() LIMIT 1")->fetch();
if (!$credit) { echo 'SKIP: no existing eligible credit for rollback lock testing'.PHP_EOL; exit; }
$method=(int)$db->query('SELECT payment_method_id FROM payment_methods WHERE is_active=1 LIMIT 1')->fetchColumn();
$before=$db->query('SELECT COUNT(*) FROM sale_payments')->fetchColumn(); $auditBefore=$db->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn();
try {
    $db->beginTransaction();
    utang_collect($db,['business_id'=>(int)$credit['business_id'],'user_id'=>(int)$credit['owner_user_id']],(int)$credit['sale_id'],['amount'=>'0.01','payment_method_id'=>(string)$method,'paid_at'=>date('Y-m-d\TH:i:s')],bin2hex(random_bytes(32)));
    foreach (['application','direct'] as $mode) {
        $pipes=[]; $process=proc_open([PHP_BINARY,__FILE__,'worker',(string)$credit['sale_id'],(string)$credit['business_id'],(string)$credit['owner_user_id'],(string)$method,$mode],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if (!is_resource($process)) { throw new RuntimeException('Could not start lock-test worker'); }
        $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $code=proc_close($process);
        if ($code!==0 || !str_starts_with($out,'PASS:')) { throw new RuntimeException('Lock test failed: '.$out.$err); }
        echo $out.' ('.$mode.')'.PHP_EOL;
    }
    $db->rollBack();
    if ($db->query('SELECT COUNT(*) FROM sale_payments')->fetchColumn()!==$before || $db->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn()!==$auditBefore) { throw new RuntimeException('Lock fixtures retained'); }
    echo 'PASS: production business lock and database sale lock serialize competing payments; all payments/audits rolled back'.PHP_EOL;
} catch (Throwable $e) { if ($db->inTransaction()) { $db->rollBack(); } fwrite(STDERR,'FAIL: '.$e->getMessage().PHP_EOL); exit(1); }
