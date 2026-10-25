<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(403); exit; }
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/utang.php';
date_default_timezone_set('Asia/Manila');
function utang_expect(bool $condition,string $message): void { if (!$condition) { throw new RuntimeException($message); } }
function utang_reject(PDO $db,callable $operation,string $message): void {
    $db->exec('SAVEPOINT rejected_utang');
    try { $operation(); } catch (InvalidArgumentException|SaleNotFound $expected) { $db->exec('ROLLBACK TO SAVEPOINT rejected_utang'); return; }
    throw new RuntimeException($message);
}
function utang_counts(PDO $db): array {
    $counts=[]; foreach (['users','businesses','customers','products','sales','sale_items','sale_payments','customer_credits','activity_logs'] as $table) { $counts[$table]=(int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn(); } return $counts;
}
$db=db(); $baseline=utang_counts($db);
try {
    $db->beginTransaction(); $suffix=bin2hex(random_bytes(8));
    $role=$db->query("SELECT role_id FROM roles WHERE role_code='business_owner'")->fetchColumn(); $category=$db->query('SELECT business_category_id FROM business_categories LIMIT 1')->fetchColumn(); $method=$db->query('SELECT payment_method_id FROM payment_methods WHERE is_active=1 LIMIT 1')->fetchColumn();
    customer_query($db,'INSERT INTO users (role_id,full_name,username,email,password_hash) VALUES (?,?,?,?,?)',[$role,'Utang rollback owner','utang_'.$suffix,$suffix.'@example.invalid',password_hash($suffix,PASSWORD_DEFAULT)]); $owner=(int)$db->lastInsertId();
    customer_query($db,'INSERT INTO businesses (owner_user_id,business_category_id,business_name) VALUES (?,?,?)',[$owner,$category,'Utang rollback business']); $business=(int)$db->lastInsertId(); $user=['business_id'=>$business,'user_id'=>$owner];
    $empty=utang_list($db,$business,utang_filters([])); utang_expect($empty['rows']===[] && bccomp($empty['summary']['outstanding'],'0',2)===0,'Empty totals wrong');
    $id=customer_save($db,$user,0,['customer_name'=>'Credit customer','phone'=>'09171234569'],bin2hex(random_bytes(32)));
    $product=sale_product_create($db,$user,['product_name'=>'Utang product','default_selling_price'=>'100','default_unit_cost'=>'40'],bin2hex(random_bytes(32)));
    $saleBase=['sold_at'=>date('Y-m-d\TH:i',strtotime('-2 days')),'save_mode'=>'posted','payment_plan'=>'unpaid','customer_id'=>(string)$id,'initial_payment'=>'0','payment_method_id'=>(string)$method,'due_date'=>date('Y-m-d',strtotime('-1 day')),'items'=>[['product_id'=>(string)$product,'quantity'=>'1','unit_price'=>'1000']],'discount_type'=>'none','discount_value'=>'0','notes'=>''];
    $first=sale_create($db,$user,$saleBase,bin2hex(random_bytes(32))); $row=utang_read($db,$business,$first); utang_expect(utang_status($row)==='Unpaid' && $row['balance_due']==='1000.00' && utang_overdue($row),'Unpaid/overdue wrong');
    $payment=['amount'=>'300','payment_method_id'=>(string)$method,'paid_at'=>date('Y-m-d\TH:i'),'reference_number'=>'0','notes'=>'<safe notes>']; $key=bin2hex(random_bytes(32));
    // Keep the two-day-old fixtures in scope across Monday/week boundaries.
    $financialPeriod=dashboard_period(['period'=>'custom','start'=>substr($saleBase['sold_at'],0,10),'end'=>date('Y-m-d')]);
    $totals=dashboard_data($db,$business,$financialPeriod)['totals'];
    $saved=utang_collect($db,$user,$first,$payment,$key); utang_expect(!$saved['replayed'] && utang_collect($db,$user,$first,$payment,$key)['replayed'],'Replay did not deduplicate');
    $row=utang_read($db,$business,$first); utang_expect($row['balance_due']==='700.00' && utang_status($row)==='Partially Paid' && utang_overdue($row) && count($row['payments'])===1,'300 partial failed');
    utang_reject($db,fn()=>utang_collect($db,$user,$first,array_replace($payment,['amount'=>'200']),$key),'Changed replay accepted');
    $counts=utang_counts($db);
    foreach (['0','-1','701','abc','1.001','1e2','1,000','999999999999',''] as $amount) { utang_reject($db,fn()=>utang_collect($db,$user,$first,array_replace($payment,['amount'=>$amount]),bin2hex(random_bytes(32))),'Invalid amount accepted: '.$amount); }
    foreach (["2026-10-09T12:\00000",'2026-02-30T12:00',date('Y-m-d\TH:i',strtotime('+1 day')),date('Y-m-d\TH:i',strtotime('-3 days'))] as $date) { utang_reject($db,fn()=>utang_collect($db,$user,$first,array_replace($payment,['paid_at'=>$date]),bin2hex(random_bytes(32))),'Invalid date accepted'); }
    utang_reject($db,fn()=>utang_collect($db,$user,$first,array_replace($payment,['payment_method_id'=>'65535']),bin2hex(random_bytes(32))),'Unknown method accepted');
    utang_reject($db,fn()=>utang_collect($db,$user,$first,array_replace($payment,['notes'=>str_repeat('x',4001)]),bin2hex(random_bytes(32))),'Long notes accepted');
    utang_expect(utang_counts($db)===$counts,'Failed input left rows');
    utang_collect($db,$user,$first,array_replace($payment,['amount'=>'200']),bin2hex(random_bytes(32))); utang_expect(utang_read($db,$business,$first)['balance_due']==='500.00','200 additional failed');
    utang_reject($db,fn()=>utang_collect($db,$user,$first,array_replace($payment,['amount'=>'600']),bin2hex(random_bytes(32))),'Overpayment accepted');
    $finalKey=bin2hex(random_bytes(32)); $final=array_replace($payment,['amount'=>'500']); utang_collect($db,$user,$first,$final,$finalKey);
    utang_expect(utang_collect($db,$user,$first,$final,$finalKey)['replayed'],'Settled payment retry failed');
    $row=utang_read($db,$business,$first); utang_expect($row['balance_due']==='0.00' && utang_status($row)==='Paid' && !utang_overdue($row) && count($row['payments'])===3,'Full settlement failed');
    utang_reject($db,fn()=>utang_collect($db,$user,$first,array_replace($payment,['amount'=>'1']),bin2hex(random_bytes(32))),'Settled credit accepted new payment');
    echo 'PASS: partial/additional/full payments, exact amounts, invalid input, durable retries, immutable payment history'.PHP_EOL;
    $second=sale_create($db,$user,array_replace($saleBase,['items'=>[['product_id'=>(string)$product,'quantity'=>'1','unit_price'=>'500']],'due_date'=>'']),bin2hex(random_bytes(32)));
    $third=sale_create($db,$user,array_replace($saleBase,['items'=>[['product_id'=>(string)$product,'quantity'=>'1','unit_price'=>'800']],'due_date'=>date('Y-m-d',strtotime('+7 days'))]),bin2hex(random_bytes(32)));
    $summary=utang_list($db,$business,utang_filters([]))['summary']; utang_expect($summary['customers']===1 && $summary['outstanding']==='1300.00' && $summary['overdue']==='0.00' && $summary['collected']==='1000.00','Summary/multiple customers wrong');
    $due=utang_read($db,$business,$second); $dueInput=['due_date'=>date('Y-m-d',strtotime('-1 day')),'revision'=>utang_due_revision($due['due_date'])]; $dueKey=bin2hex(random_bytes(32));
    utang_expect(!utang_edit_due($db,$user,$second,$dueInput,$dueKey) && utang_edit_due($db,$user,$second,$dueInput,$dueKey),'Due edit replay failed');
    utang_reject($db,fn()=>utang_edit_due($db,$user,$second,$dueInput,bin2hex(random_bytes(32))),'Stale due date accepted');
    $due=utang_read($db,$business,$second); utang_expect(utang_overdue($due),'Edited due not overdue');
    utang_reject($db,fn()=>utang_edit_due($db,$user,$second,['due_date'=>'2026-02-30','revision'=>utang_due_revision($due['due_date'])],bin2hex(random_bytes(32))),'Invalid due accepted');
    $clearInput=['due_date'=>'','revision'=>utang_due_revision($due['due_date'])]; $clearKey=bin2hex(random_bytes(32)); utang_edit_due($db,$user,$second,$clearInput,$clearKey); utang_expect(utang_edit_due($db,$user,$second,$clearInput,$clearKey),'Blank due replay failed');
    utang_expect(!utang_overdue(utang_read($db,$business,$second)),'No due date overdue');
    $customer=customer_read($db,$business,$id); customer_set_active($db,$user,$id,['revision'=>customer_revision($customer),'confirm_customer_action'=>'1'],bin2hex(random_bytes(32)),false);
    utang_collect($db,$user,$second,array_replace($payment,['amount'=>'100']),bin2hex(random_bytes(32)));
    utang_expect(utang_list($db,$business,utang_filters([]))['summary']['outstanding']==='1200.00','Archived debt lost');
    utang_expect(customer_read($db,$business,$id)['outstanding']==='1200.00','Customer totals disconnected');
    $totalsAfter=dashboard_data($db,$business,$financialPeriod)['totals'];
    // Additional sales add revenue, but collecting on any existing sale does not.
    utang_expect($totalsAfter['sales']-$totals['sales']===130000,'Collections inflated revenue');
    $before=dashboard_data($db,$business,$financialPeriod);
    utang_collect($db,$user,$third,array_replace($payment,['amount'=>'0.01']),bin2hex(random_bytes(32)));
    $after=dashboard_data($db,$business,$financialPeriod); utang_expect($before['totals']===$after['totals'],'Centavo payment changed revenue/profit/expenses/COGS');
    // Create a second active contact for a voidable credit and pagination fixtures.
    $other=customer_save($db,$user,0,['customer_name'=>'Other credit customer'],bin2hex(random_bytes(32)));
    $draft=sale_create($db,$user,array_replace($saleBase,['save_mode'=>'draft','customer_id'=>(string)$other,'initial_payment'=>'0']),bin2hex(random_bytes(32)));
    $void=sale_create($db,$user,array_replace($saleBase,['customer_id'=>(string)$other]),bin2hex(random_bytes(32))); sale_void($db,$user,$void,['revision'=>sale_revision(sale_read($db,$business,$void)),'void_reason'=>'Rollback test']);
    foreach ([$void,$draft] as $bad) { utang_reject($db,fn()=>utang_read($db,$business,$bad),'Draft/void visible'); utang_reject($db,fn()=>utang_collect($db,$user,$bad,$payment,bin2hex(random_bytes(32))),'Draft/void payment accepted'); }
    utang_reject($db,fn()=>utang_read($db,1,$first),'Foreign credit visible');
    $foreign=(int)$db->query("SELECT s.sale_id FROM sales s JOIN customer_credits cr ON cr.sale_id=s.sale_id WHERE s.business_id<>".$business." AND s.sale_status='posted' LIMIT 1")->fetchColumn();
    if ($foreign) { utang_reject($db,fn()=>utang_collect($db,$user,$foreign,$payment,bin2hex(random_bytes(32))),'Owned account accepted foreign sale payment'); utang_reject($db,fn()=>utang_edit_due($db,$user,$foreign,['due_date'=>'','revision'=>'invalid'],bin2hex(random_bytes(32))),'Owned account accepted foreign due edit'); }
    utang_reject($db,fn()=>utang_collect($db,['business_id'=>1,'user_id'=>$owner],$first,$payment,bin2hex(random_bytes(32))),'Foreign owner accepted');
    $list=utang_list($db,$business,utang_filters(['state'=>'all'])); utang_expect($list['count']===3 && utang_list($db,$business,utang_filters(['state'=>'paid']))['count']===1 && utang_list($db,$business,utang_filters(['state'=>'partial']))['count']===2,'State filters wrong');
    utang_expect(utang_list($db,$business,utang_filters(['q'=>$row['sale_number'],'state'=>'all']))['count']===1 && utang_list($db,$business,utang_filters(['customer'=>(string)$id,'state'=>'all']))['count']===3,'Search/customer history wrong');
    $dateFilters=['state'=>'all','start'=>date('Y-m-d'),'end'=>date('Y-m-d')]; utang_expect(utang_list($db,$business,utang_filters($dateFilters))['count']===0 && utang_list($db,$business,utang_filters($dateFilters))['summary']['collected']==='1100.01','Sale/payment date scopes wrong');
    for ($i=0;$i<14;$i++) { sale_create($db,$user,array_replace($saleBase,['customer_id'=>(string)$other,'due_date'=>'']),bin2hex(random_bytes(32))); }
    $page=utang_list($db,$business,utang_filters(['state'=>'all','page'=>'2'])); utang_expect($page['pages']===2 && count($page['rows'])===2,'Pagination failed');
    echo 'PASS: overdue/no due/settled, audited due corrections, archived customers, multiple debts, search/filters/dates/pagination, business isolation and dashboard integration'.PHP_EOL;
    $refundBefore=utang_list($db,$business,utang_filters([]))['summary']; $purchasesBefore=customer_read($db,$business,$id)['purchases'];
    $collectionId=$row['payments'][0]['payment_id'];
    customer_query($db,"INSERT INTO sale_payments (sale_id,business_id,payment_method_id,entered_by,payment_kind,amount,reverses_payment_id,paid_at) VALUES (?,?,?,?,'refund',?,?,?)",[$first,$business,$method,$owner,'25.00',$collectionId,date('Y-m-d H:i:s')]);
    $refunded=utang_read($db,$business,$first); $refundAfter=utang_list($db,$business,utang_filters([]))['summary'];
    utang_expect($refunded['total_paid']==='975.00' && $refunded['balance_due']==='25.00' && count($refunded['payments'])===4 && utang_overdue($refunded),'Refund net/history/overdue wrong');
    utang_expect(bcsub($refundBefore['collected'],$refundAfter['collected'],2)==='25.00' && customer_read($db,$business,$id)['purchases']===$purchasesBefore,'Refund distorted cash/purchases');
    utang_reject($db,fn()=>utang_collect($db,$user,$second,$payment,$key),'Payment key reused for another sale');
    echo 'PASS: refund history, net collections, reopened balance/overdue and cross-sale idempotency protection'.PHP_EOL;
    // Force a late failure: both the inserted payment and trigger audit roll back.
    $db->exec('SAVEPOINT late_failure'); $n=utang_counts($db); utang_collect($db,$user,$second,array_replace($payment,['amount'=>'1']),bin2hex(random_bytes(32))); $db->exec('ROLLBACK TO SAVEPOINT late_failure'); utang_expect(utang_counts($db)===$n,'Rollback left a payment/audit');
    $db->rollBack(); utang_expect(utang_counts($db)===$baseline,'Fixtures retained');
    echo 'PASS: all financial, customer, credit, audit and fixture records rolled back'.PHP_EOL;
} catch (Throwable $e) { if ($db->inTransaction()) { $db->rollBack(); } fwrite(STDERR,'FAIL: '.$e->getMessage().PHP_EOL); exit(1); }
