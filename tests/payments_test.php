<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(403); exit; }
require __DIR__.'/../includes/auth.php';
require __DIR__.'/../includes/reports.php';
require __DIR__.'/../includes/record_actions.php';
function pay_expect(bool $ok,string $message): void { if (!$ok) { throw new RuntimeException($message); } }
function pay_counts(PDO $db): array {
    $counts=[]; foreach (['users','businesses','customers','products','sales','sale_items','sale_payments','customer_credits','activity_logs'] as $table) { $counts[$table]=$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn(); } return $counts;
}
function pay_reject(PDO $db,callable $action): void {
    $db->exec('SAVEPOINT payment_invalid');
    try { $action(); throw new LogicException('Invalid payment accepted.'); }
    catch (InvalidArgumentException|SaleNotFound $e) { $db->exec('ROLLBACK TO SAVEPOINT payment_invalid'); }
}
function pay_render_fixture(array $vars,string $name,bool $page=true): void {
    extract($vars); $page_title=$page?'Payment Management':($action==='payment_details'?'Payment details':'Payment history'); $active_page='payments'; $dashboard_layout=true; $error=null; $notice=null; $unavailable=false; $modal=true; $methods=[]; $token=[]; $customer=null;
    $_SERVER['REQUEST_METHOD']='GET'; $action_url=app_url('user/payments_action.php');
    ob_start();
    if ($page) { require __DIR__.'/../includes/header.php'; require __DIR__.'/../includes/navbar.php'; require __DIR__.'/../includes/payments_page_content.php'; require __DIR__.'/../includes/footer.php'; }
    else { require __DIR__.'/../includes/payment_content.php'; }
    file_put_contents(__DIR__.'/http-results/'.$name,ob_get_clean());
}
$db=db(); $before=pay_counts($db);
try {
    $db->beginTransaction();
    $suffix=bin2hex(random_bytes(10));
    $role=$db->query("SELECT role_id FROM roles WHERE role_code='business_owner'")->fetchColumn();
    customer_query($db,'INSERT INTO users (role_id,full_name,username,email,password_hash) VALUES (?,?,?,?,?)',[$role,'Rollback payment owner','payment_test_'.$suffix,'payment_test_'.$suffix.'@example.invalid',password_hash(bin2hex(random_bytes(24)),PASSWORD_DEFAULT)]);
    $owner=(int)$db->lastInsertId();
    customer_query($db,'INSERT INTO businesses (owner_user_id,business_category_id,business_name) VALUES (?,?,?)',[$owner,$db->query('SELECT business_category_id FROM business_categories LIMIT 1')->fetchColumn(),'Rollback payment business']);
    $business=(int)$db->lastInsertId(); $user=['user_id'=>$owner,'business_id'=>$business];
    $customer=customer_save($db,$user,0,['customer_name'=>'Payment test customer'],bin2hex(random_bytes(32)));
    $product=sale_product_create($db,$user,['product_name'=>'Payment test item','default_selling_price'=>'1000','default_unit_cost'=>'100'],bin2hex(random_bytes(32)));
    $ids=array_column(payment_method_catalog($db),'payment_method_id','method_code');
    pay_expect(isset($ids['cash'],$ids['gcash'],$ids['maya'],$ids['bank'],$ids['wallet_other']),'Approved methods missing');
    $period=['period'=>'custom','start'=>date('Y-m-d',strtotime('-7 days')),'end'=>date('Y-m-d')];
    $filters=fn(array $extra=[])=>payment_filters(array_replace($period,$extra));
    $base=['customer_id'=>(string)$customer,'sold_at'=>date('Y-m-d\TH:i',strtotime('-3 days')),'save_mode'=>'posted','payment_plan'=>'full','initial_payment'=>'1000','payment_category'=>'cash','payment_reference'=>'0','discount_type'=>'none','discount_value'=>'0','due_date'=>date('Y-m-d',strtotime('+7 days')),'items'=>[['product_id'=>(string)$product,'quantity'=>'1','unit_price'=>'1000']]];
    $make=function(string $total,string $paid,string $category='cash',string $wallet='',string $provider='') use ($db,$user,$base): int {
        return sale_create($db,$user,array_replace($base,['items'=>[['product_id'=>$base['items'][0]['product_id'],'quantity'=>'1','unit_price'=>$total]],'initial_payment'=>$paid,'payment_plan'=>bccomp($paid,$total,2)===0?'full':(bccomp($paid,'0',2)>0?'partial':'unpaid'),'payment_category'=>$category,'wallet_method_id'=>$wallet,'provider_name'=>$provider]),bin2hex(random_bytes(32)));
    };
    $full=$make('1000','1000'); $mixed=$make('2000','500','wallet',(string)$ids['gcash']); $bank=$make('3000','1000','bank','','BDO');
    pay_expect(sale_read($db,$business,$full)['balance_due']==='0.00','Cash full payment balance');
    $row=sale_read($db,$business,$mixed); pay_expect($row['balance_due']==='1500.00' && $row['payments'][0]['provider_name']==='GCash','GCash downpayment');
    pay_expect(sale_read($db,$business,$bank)['balance_due']==='2000.00' && sale_read($db,$business,$bank)['payments'][0]['provider_name']==='BDO','Bank downpayment');
    pay_expect(sale_read($db,$business,$full)['payments'][0]['reference_number']==='0','Reference zero lost');
    echo 'PASS: cash full payment, GCash/bank downpayments, structured providers and exact initial references'.PHP_EOL;
    $pay=['amount'=>'300','payment_category'=>'cash','paid_at'=>date('Y-m-d\TH:i',strtotime('-1 day')),'notes'=>'<safe payment>','reference_number'=>'CASH-300']; $key=bin2hex(random_bytes(32));
    $revenue=dashboard_data($db,$business,dashboard_period($period))['totals'];
    payment_collect($db,$user,$mixed,$pay,$key); pay_expect(sale_read($db,$business,$mixed)['balance_due']==='1200.00','Additional cash balance');
    pay_expect(payment_collect($db,$user,$mixed,$pay,$key)['replayed'],'Duplicate retry');
    pay_reject($db,fn()=>payment_collect($db,$user,$mixed,array_replace($pay,['amount'=>'301']),$key));
    payment_collect($db,$user,$mixed,array_replace($pay,['amount'=>'700','payment_category'=>'bank','provider_name'=>'BPI','reference_number'=>'BANK-700','paid_at'=>date('Y-m-d\TH:i',strtotime('-2 days'))]),bin2hex(random_bytes(32)));
    pay_reject($db,fn()=>payment_collect($db,$user,$mixed,array_replace($pay,['amount'=>'600']),bin2hex(random_bytes(32))));
    payment_collect($db,$user,$mixed,array_replace($pay,['amount'=>'500','payment_category'=>'wallet','wallet_method_id'=>(string)$ids['maya'],'reference_number'=>'MAYA-500']),bin2hex(random_bytes(32)));
    $row=sale_read($db,$business,$mixed); $types=payment_classify($row);
    pay_expect($row['balance_due']==='0.00' && array_values($types)===['downpayment','partial','partial','final'],'Multiple methods/classifications/settlement');
    pay_expect(dashboard_data($db,$business,dashboard_period($period))['totals']===$revenue,'Payments changed revenue or profit');
    $contact=customer_read($db,$business,$customer); pay_expect($contact['purchases']==='6000.00' && $contact['paid']==='4000.00' && $contact['outstanding']==='2000.00','Customer integration');
    $list=payment_list($db,$business,$filters());
    pay_expect($list['summary']['collected']==='4000.00' && $list['summary']['outstanding']==='2000.00' && (int)$list['summary']['partial']===1 && (int)$list['summary']['paid']===2,'Summary cards');
    pay_expect($list['breakdown']['cash']==='1300.00' && $list['breakdown']['wallet']==='1000.00' && $list['breakdown']['bank']==='1700.00','Method reconciliation');
    foreach ($list['rows'] as $p) { $sale=sale_read($db,$business,(int)$p['sale_id']); pay_expect(payment_classify($sale)[$p['payment_id']]===$p['payment_type'],'SQL/PHP classification mismatch'); }
    pay_expect(payment_list($db,$business,$filters(['type'=>'downpayment']))['count']===2 && payment_list($db,$business,$filters(['method'=>'bank']))['count']===2,'Payment filters');
    pay_expect(payment_list($db,$business,$filters(['q'=>'not found']))['count']===0 && payment_list($db,0,$filters())['count']===0,'Search/ownership');
    $report=report_data($db,$business,report_filters($period)); pay_expect($report['collections']['total']==='4000.00' && array_intersect_key($report['totals'],$revenue)===$revenue,'Report integration');
    // Render the real production templates with transaction-backed fixtures for
    // visual QA. All rows are rolled back; these protected artifacts are read-only.
    $renderUser=$user+['full_name'=>'Payment test owner','username'=>'payment_test','email'=>'payment_test@example.invalid','business_name'=>'Payment workflow test business'];
    pay_render_fixture(['user'=>$renderUser,'filters'=>$filters(),'data'=>$list],'payments-history-fixture.html');
    $detail=payment_read($db,$business,(int)$row['payments'][0]['payment_id']);
    pay_render_fixture(['row'=>$detail['sale'],'payment'=>$detail['payment'],'action'=>'payment_details'],'payments-details-fixture.html',false);
    pay_render_fixture(['row'=>sale_read($db,$business,$mixed),'payment'=>null,'action'=>'history'],'payments-history-modal-fixture.html',false);
    echo 'PASS: mixed methods, backdated history/types, retries, overpayment, filters, summaries, customer/dashboard/report integration without duplicate revenue'.PHP_EOL;
    foreach (['0','-1','1.001','1e2','999999999999'] as $amount) { pay_reject($db,fn()=>payment_collect($db,$user,$bank,array_replace($pay,['amount'=>$amount]),bin2hex(random_bytes(32)))); }
    foreach ([['payment_category'=>'bank','provider_name'=>''],['payment_category'=>'wallet','wallet_method_id'=>(string)$ids['wallet_other'],'provider_name'=>''],['payment_category'=>'wallet','wallet_method_id'=>(string)$ids['cash']],['paid_at'=>date('Y-m-d\TH:i',strtotime('+1 day'))],['paid_at'=>date('Y-m-d\TH:i',strtotime('-4 days'))],['provider_name'=>str_repeat('a',81)]] as $bad) { pay_reject($db,fn()=>payment_collect($db,$user,$bank,array_replace($pay,$bad),bin2hex(random_bytes(32)))); }
    pay_reject($db,fn()=>payment_collect($db,['business_id'=>0,'user_id'=>$owner],$bank,$pay,bin2hex(random_bytes(32))));
    pay_reject($db,fn()=>payment_read($db,0,(int)$row['payments'][0]['payment_id']));
    $other=payment_collect($db,$user,$bank,array_replace($pay,['amount'=>'1','payment_category'=>'wallet','wallet_method_id'=>(string)$ids['wallet_other'],'provider_name'=>'ShopeePay']),bin2hex(random_bytes(32)));
    pay_expect(payment_read($db,$business,$other['payment_id'])['payment']['provider_name']==='ShopeePay','Other wallet storage');
    $unpaid=$make('500','0'); $unpaidRow=payment_sale_read($db,$business,$unpaid);
    utang_edit_due($db,$user,$unpaid,['due_date'=>date('Y-m-d',strtotime('-1 day')),'revision'=>utang_due_revision($unpaidRow['due_date'])],bin2hex(random_bytes(32)));
    pay_expect(payment_list($db,$business,$filters(['tab'=>'balances','state'=>'overdue']))['count']===1,'Overdue condition');
    payment_collect($db,$user,$unpaid,array_replace($pay,['amount'=>'500']),bin2hex(random_bytes(32)));
    pay_expect(array_values(payment_classify(sale_read($db,$business,$unpaid)))===['full'],'First settlement mislabeled downpayment');
    $voidRow=sale_read($db,$business,$bank); sale_void($db,$user,$bank,['revision'=>sale_revision($voidRow),'void_reason'=>'Rollback test','refund_confirm'=>'1']);
    pay_reject($db,fn()=>payment_collect($db,$user,$bank,$pay,bin2hex(random_bytes(32))));
    $afterVoid=payment_list($db,$business,$filters()); pay_expect($afterVoid['summary']['collected']==='3500.00' && $afterVoid['breakdown']['bank']==='700.00','Voided/refunded collections included');
    pay_expect(sale_read($db,$business,$bank)['payments'][1]['provider_name']==='ShopeePay','Provider history changed');
    $oldDate=$make('500','0'); payment_collect($db,$user,$oldDate,array_replace($pay,['amount'=>'100','paid_at'=>date('Y-m-d\TH:i')]),bin2hex(random_bytes(32)));
    pay_expect(payment_list($db,$business,payment_filters(['period'=>'today']))['summary']['collected']==='100.00','Collections did not use payment dates');
    $original=sale_read($db,$business,$full)['payments'][0];
    customer_query($db,"INSERT INTO sale_payments (sale_id,business_id,payment_method_id,entered_by,payment_kind,amount,reverses_payment_id) VALUES (?,?,?,?,'refund',?,?)",[$full,$business,$ids['cash'],$owner,'100.00',$original['payment_id']]);
    $refunded=payment_read($db,$business,(int)$original['payment_id']);
    pay_expect($refunded['payment']['amount']==='1000.00' && $refunded['payment']['received_amount']==='900.00' && $refunded['sale']['balance_due']==='100.00','Partial refund history or balance lost');
    pay_expect(customer_read($db,$business,$customer)['outstanding']==='500.00' && dashboard_data($db,$business,dashboard_period($period))['outstanding']===50000 && report_data($db,$business,report_filters($period))['credit']['outstanding']==='500.00','Reopened immediate-sale balance did not reconcile');
    $db->exec('SAVEPOINT payment_late_failure'); $n=pay_counts($db); payment_collect($db,$user,$oldDate,$pay,bin2hex(random_bytes(32))); $db->exec('ROLLBACK TO SAVEPOINT payment_late_failure'); pay_expect(pay_counts($db)===$n,'Late failure left rows');
    echo 'PASS: provider/date/amount validation, Other wallet, due-date audit, first full settlement, void/refund exclusions, business isolation and rollback'.PHP_EOL;
    $db->rollBack(); pay_expect(pay_counts($db)===$before,'Test fixtures left behind');
    echo 'PASS: all payment fixtures and trigger audit records rolled back'.PHP_EOL;
} catch (Throwable $e) { if ($db->inTransaction()) { $db->rollBack(); } fwrite(STDERR,'FAIL: '.$e->getMessage().PHP_EOL); exit(1); }
