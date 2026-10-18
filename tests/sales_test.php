<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/sales.php';
date_default_timezone_set('Asia/Manila');

function sales_expect(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}
function sales_insert(PDO $db, string $sql, array $values): int
{
    $db->prepare($sql)->execute($values);
    return (int)$db->lastInsertId();
}
function sales_reject(PDO $db, callable $operation, string $message): void
{
    $db->exec('SAVEPOINT rejected_operation');
    try { $operation(); }
    catch (InvalidArgumentException | SaleNotFound $expected) {
        $db->exec('ROLLBACK TO SAVEPOINT rejected_operation');
        $db->exec('RELEASE SAVEPOINT rejected_operation');
        return;
    }
    throw new RuntimeException($message);
}
function sales_counts(PDO $db): array
{
    $counts=[];
    foreach (['users','businesses','customers','products','sales','sale_items','sale_payments','customer_credits','activity_logs'] as $table) {
        $counts[$table]=(int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
    }
    return $counts;
}
$db=db();
$baseline=sales_counts($db);
$checks=[];
try {
    $db->beginTransaction(); // Never commit financial fixtures or delete their history.
    $suffix=bin2hex(random_bytes(8));
    $role=$db->query("SELECT role_id FROM roles WHERE role_code='business_owner'")->fetchColumn();
    $category=$db->query('SELECT business_category_id FROM business_categories LIMIT 1')->fetchColumn();
    $method=$db->query('SELECT payment_method_id FROM payment_methods WHERE is_active=1 LIMIT 1')->fetchColumn();
    $owner=sales_insert($db,'INSERT INTO users (role_id,full_name,username,email,password_hash) VALUES (?,?,?,?,?)',[$role,'Sales rollback test','sales_'.$suffix,$suffix.'@example.invalid',password_hash($suffix,PASSWORD_DEFAULT)]);
    $business=sales_insert($db,'INSERT INTO businesses (owner_user_id,business_category_id,business_name) VALUES (?,?,?)',[$owner,$category,'Sales rollback test']);
    $user=['user_id'=>$owner,'business_id'=>$business];
    $customer=sales_insert($db,'INSERT INTO customers (business_id,customer_name) VALUES (?,?)',[$business,'Rollback customer']);
    $key=bin2hex(random_bytes(32));
    $product=sale_product_create($db,$user,['product_name'=>'Rollback product','default_selling_price'=>'100.00','default_unit_cost'=>'40.00'],$key);
    sales_expect(sale_product_create($db,$user,[],$key)===$product,'Product replay duplicated a reference');
    $second=sale_product_create($db,$user,['product_name'=>'Second product','default_selling_price'=>'0.05','default_unit_cost'=>'0.02'],bin2hex(random_bytes(32)));
    $date=date('Y-m-d\TH:i');
    $base=['sold_at'=>$date,'save_mode'=>'posted','payment_plan'=>'full','customer_id'=>'','initial_payment'=>'100.00','payment_method_id'=>(string)$method,
        'items'=>[['product_id'=>(string)$product,'quantity'=>'1','unit_price'=>'100.00']], 'discount_type'=>'none','discount_value'=>'0','notes'=>'<safe notes>','payment_reference'=>'ROLLBACK-PAYMENT-REF'];
    $key=bin2hex(random_bytes(32));
    $paid=sale_create($db,$user,$base,$key);
    sales_expect(sale_create($db,$user,$base,$key)===$paid,'Sale replay duplicated a sale');
    $sale=sale_read($db,$business,$paid);
    sales_expect($sale['sale_status']==='posted' && sale_status($sale)==='Fully paid' && count($sale['payments'])===1 && bccomp($sale['balance_due'],'0',2)===0 && $sale['sale_type']==='immediate','Full payment/posting/idempotency failed');
    sales_expect($sale['payments'][0]['reference_number']==='ROLLBACK-PAYMENT-REF' && (int)$sale['payments'][0]['payment_method_id']===(int)$method,'Payment reference or method was lost');
    $statement=$db->prepare('SELECT COUNT(*) FROM customer_credits WHERE sale_id=?'); $statement->execute([$paid]);
    sales_expect((int)$statement->fetchColumn()===0,'Immediate sale created credit');
    $checks[]='PASS: actual product references, fully paid sale, payment reference/method, one payment, and durable submission replay';

    $multi=$base;
    $multi['items']=[['product_id'=>(string)$product,'quantity'=>'1.234','unit_price'=>'10.01'],['product_id'=>(string)$second,'quantity'=>'0.100','unit_price'=>'0.05']];
    $multi['discount_type']='percent'; $multi['discount_value']='12.50'; $multi['initial_payment']='10.82';
    $multi_id=sale_create($db,$user,$multi,bin2hex(random_bytes(32)));
    $calculated=sale_read($db,$business,$multi_id);
    sales_expect(bccomp($calculated['subtotal'],'12.36',2)===0 && bccomp($calculated['net_sale'],'10.82',2)===0 && count($calculated['items'])===2,'Fractional quantities/half-cent/percent arithmetic disagrees with SQL views');
    $fixed=$base; $fixed['discount_type']='fixed'; $fixed['discount_value']='15.25'; $fixed['initial_payment']='84.75';
    $fixed_id=sale_create($db,$user,$fixed,bin2hex(random_bytes(32)));
    sales_expect(bccomp(sale_read($db,$business,$fixed_id)['net_sale'],'84.75',2)===0,'Fixed discount wrong');
    $checks[]='PASS: multiple products, three-decimal quantities, half-cent rounding, fixed and percentage discounts';

    $foreign_product=$db->query('SELECT product_id FROM products WHERE business_id<>'.$business.' LIMIT 1')->fetchColumn();
    $foreign_customer=$db->query('SELECT customer_id FROM customers WHERE business_id<>'.$business.' LIMIT 1')->fetchColumn();
    $invalids=[['initial_payment'=>'-1'],['initial_payment'=>'100.01'],['initial_payment'=>'0'],['initial_payment'=>'1e2'],['discount_type'=>'fixed','discount_value'=>'101'],['discount_type'=>'percent','discount_value'=>'100.01'],['discount_type'=>'percent','discount_value'=>'100'],['payment_method_id'=>'999999'],['customer_id'=>(string)$foreign_customer],['sold_at'=>'2026-02-30T10:00'],['sold_at'=>'2999-01-01T10:00'],['save_mode'=>'draft'],['payment_plan'=>'partial','initial_payment'=>'10'],['payment_plan'=>'unpaid','initial_payment'=>'0'],['items'=>[]],['items'=>[['product_id'=>(string)$foreign_product,'quantity'=>'1','unit_price'=>'100']]],['items'=>[['product_id'=>(string)$product,'quantity'=>'0','unit_price'=>'100']]],['items'=>[['product_id'=>(string)$product,'quantity'=>'1.0001','unit_price'=>'100']]],['items'=>[['product_id'=>(string)$product,'quantity'=>'1','unit_price'=>'100.001']]],['items'=>[['product_id'=>(string)$product,'quantity'=>'999999999.999','unit_price'=>'99999999999.99']]],['initial_payment'=>['invalid']]];
    $before=sales_counts($db);
    foreach ($invalids as $changes) { sales_reject($db,fn()=>sale_create($db,$user,array_replace($base,$changes),bin2hex(random_bytes(32))),'Invalid input accepted: '.json_encode($changes)); }
    sales_expect(sales_counts($db)===$before,'Rejected input left financial rows or logs');
    sales_reject($db,fn()=>sale_create($db,['user_id'=>$owner,'business_id'=>1],$base,bin2hex(random_bytes(32))),'Mismatched owner/business accepted');
    sales_reject($db,fn()=>sale_read($db,1,$paid),'Cross-business sale was visible');
    $_SESSION=[]; $token=sale_form_token('edit',$paid);
    sales_expect(sale_valid_token($token,'edit',$paid) && !sale_valid_token($token,'create') && !sale_valid_token($token,'edit',$paid+1),'Signed action/record token binding failed');
    $checks[]='PASS: invalid money, discounts, dates, products, customers, methods, scope and signed tokens rejected without orphan rows';

    $partial=array_replace($base,['customer_id'=>(string)$customer,'payment_plan'=>'partial','initial_payment'=>'30','due_date'=>date('Y-m-d',strtotime('+7 days'))]);
    $credit=sale_create($db,$user,$partial,bin2hex(random_bytes(32)));
    $credit_sale=sale_read($db,$business,$credit);
    sales_expect(sale_status($credit_sale)==='Partially paid' && bccomp($credit_sale['balance_due'],'70',2)===0 && $credit_sale['due_date']===$partial['due_date'],'Partial credit wrong');
    $unpaid=array_replace($partial,['payment_plan'=>'unpaid','initial_payment'=>'0']);
    $unpaid_id=sale_create($db,$user,$unpaid,bin2hex(random_bytes(32)));
    sales_expect(sale_status(sale_read($db,$business,$unpaid_id))==='Unpaid','Unpaid credit wrong');
    $period=dashboard_period(['period'=>'today']);
    $summary=dashboard_data($db,$business,$period);
    $payment_insert='INSERT INTO sale_payments (sale_id,business_id,payment_method_id,entered_by,amount) VALUES (?,?,?,?,?)';
    sales_insert($db,$payment_insert,[$credit,$business,$method,$owner,'20']);
    $after=dashboard_data($db,$business,$period);
    sales_expect($after['totals']===$summary['totals'] && $after['outstanding']===$summary['outstanding']-2000,'Repayment counted as revenue or did not reduce utang');
    $filters=sale_filters(['period'=>'today']); $list=sale_list($db,$business,$filters);
    sales_expect((int)$list['summary']['completed']===3 && (int)$list['summary']['pending']===2 && bccomp($list['outstanding'],'150',2)===0,'List totals or payment counts wrong');
    $filters=sale_filters(['period'=>'today','payment'=>'partial']); sales_expect(count(sale_list($db,$business,$filters)['rows'])===1,'Payment filter failed');
    $filters=sale_filters(['period'=>'today','q'=>'Rollback customer']); sales_expect(count(sale_list($db,$business,$filters)['rows'])===2,'Customer search failed');
    $filters=sale_filters(['period'=>'today','q'=>sale_read($db,$business,$paid)['sale_number']]); sales_expect(count(sale_list($db,$business,$filters)['rows'])===1,'Reference search failed');
    $checks[]='PASS: partial/unpaid credits, due dates, filters, summary counts and repayments without duplicate revenue';

    $draft_input=array_replace($base,['save_mode'=>'draft','initial_payment'=>'0']);
    $draft=sale_create($db,$user,$draft_input,bin2hex(random_bytes(32)));
    $original=sale_read($db,$business,$draft);
    $db->prepare("UPDATE products SET product_name='Changed product',default_selling_price=500,default_unit_cost=300 WHERE product_id=?")->execute([$product]);
    $edit=$draft_input; $edit['revision']=sale_revision($original); $edit['notes']='Revised draft';
    $edit['items'][0]['sale_item_id']=(string)$original['items'][0]['sale_item_id']; $edit['items'][0]['quantity']='2';
    sale_edit($db,$user,$draft,$edit,bin2hex(random_bytes(32)));
    $edited=sale_read($db,$business,$draft);
    sales_expect(bccomp($edited['net_sale'],'200',2)===0 && $edited['items'][0]['product_name_snapshot']==='Rollback product' && bccomp($edited['items'][0]['unit_cost'],'40',2)===0,'Draft edits lost historical cost/name');
    sales_reject($db,fn()=>sale_edit($db,$user,$draft,$edit,bin2hex(random_bytes(32))),'Stale edit accepted');
    $notes=['revision'=>sale_revision(sale_read($db,$business,$paid)),'notes'=>'Audited note','discount_type'=>'percent','discount_value'=>'99','initial_payment'=>'0'];
    sale_edit($db,$user,$paid,$notes,bin2hex(random_bytes(32)));
    $updated=sale_read($db,$business,$paid);
    sales_expect($updated['notes']==='Audited note' && bccomp($updated['net_sale'],'100',2)===0 && $updated['items']===$sale['items'] && $updated['payments']===$sale['payments'],'Posted financial fields were changed');
    $draft_credit_input=array_replace($unpaid,['save_mode'=>'draft']);
    $draft_credit=sale_create($db,$user,$draft_credit_input,bin2hex(random_bytes(32)));
    $saved=sale_read($db,$business,$draft_credit);
    $post=array_replace($draft_credit_input,['revision'=>sale_revision($saved),'save_mode'=>'posted','payment_plan'=>'full','initial_payment'=>'100','payment_method_id'=>(string)$method]);
    $post['items'][0]['sale_item_id']=(string)$saved['items'][0]['sale_item_id'];
    sale_edit($db,$user,$draft_credit,$post,bin2hex(random_bytes(32)));
    sales_expect(sale_status(sale_read($db,$business,$draft_credit))==='Fully paid','Draft credit could not settle and post');
    $draft_with_payment=sale_create($db,$user,$draft_input,bin2hex(random_bytes(32)));
    sales_insert($db,$payment_insert,[$draft_with_payment,$business,$method,$owner,'1']);
    $saved=sale_read($db,$business,$draft_with_payment);
    sales_reject($db,fn()=>sale_edit($db,$user,$draft_with_payment,array_replace($draft_input,['revision'=>sale_revision($saved)]),bin2hex(random_bytes(32))),'Draft with history could be financially edited');
    $checks[]='PASS: audited draft edits, preserved snapshots, stale request prevention, notes-only posted edits and draft posting';

    $void_input=['revision'=>sale_revision($updated),'void_reason'=>'Test correction'];
    sales_reject($db,fn()=>sale_void($db,$user,$paid,$void_input),'Collected money voided without explicit refund confirmation');
    $void_input['refund_confirm']='1'; sale_void($db,$user,$paid,$void_input);
    $voided=sale_read($db,$business,$paid);
    sales_expect($voided['sale_status']==='void' && bccomp($voided['total_paid'],'0',2)===0 && count($voided['payments'])===2 && $voided['payments'][1]['payment_kind']==='refund' && (int)$voided['payments'][1]['reverses_payment_id']===(int)$sale['payments'][0]['payment_id'],'Paid void did not preserve and reverse original collection');
    sales_reject($db,fn()=>sale_void($db,$user,$paid,$void_input),'Repeated void accepted');
    $saved=sale_read($db,$business,$unpaid_id);
    sale_void($db,$user,$unpaid_id,['revision'=>sale_revision($saved),'void_reason'=>'Unpaid correction']);
    sales_expect(sale_read($db,$business,$unpaid_id)['payments']===[],'Unpaid void invented payments');
    $saved=sale_read($db,$business,$credit);
    sales_insert($db,"INSERT INTO sale_payments (sale_id,business_id,payment_method_id,entered_by,payment_kind,amount,reverses_payment_id) VALUES (?,?,?,?,'refund',?,?)",[$credit,$business,$method,$owner,'5',$saved['payments'][0]['payment_id']]);
    $saved=sale_read($db,$business,$credit);
    sale_void($db,$user,$credit,['revision'=>sale_revision($saved),'void_reason'=>'Partial correction','refund_confirm'=>'1']);
    $voided=sale_read($db,$business,$credit);
    $refunds=array_filter($voided['payments'],fn($p)=>$p['payment_kind']==='refund');
    sales_expect(bccomp($voided['total_paid'],'0',2)===0 && count($refunds)===3,'Existing partial refund or multiple collections reversed incorrectly');
    $remaining=dashboard_data($db,$business,$period);
    sales_expect($remaining['outstanding']===0 && $remaining['totals']['sales']===19557,'Dashboard failed to exclude void/draft revenue and debt');
    $audit=$db->prepare("SELECT DISTINCT action_code FROM activity_logs WHERE business_id=?"); $audit->execute([$business]);
    foreach (['SALE_CREATED','SALE_STATUS_CHANGED','SALE_NOTES_EDITED','SALE_DRAFT_EDITED','PRODUCT_REFERENCE_CREATED'] as $action) { sales_expect(in_array($action,$audit->fetchAll(PDO::FETCH_COLUMN),true),'Expected audit missing: '.$action); $audit->execute([$business]); }
    $checks[]='PASS: unpaid/paid/partial voids, explicit refunds, existing refund limits, immutable payment history and dashboard exclusion';

    // Enough drafts to exercise real SQL pagination without posting fake revenue.
    for ($i=0;$i<17;$i++) { sale_create($db,$user,$draft_input,bin2hex(random_bytes(32))); }
    $filters=sale_filters(['period'=>'today','state'=>'draft','page'=>'2','sort'=>'oldest']);
    $page=sale_list($db,$business,$filters);
    sales_expect($page['pages']===2 && $page['page']===2 && count($page['rows'])===4,'Pagination failed');
    $checks[]='PASS: actual SQL pagination and sorting';
    $db->rollBack();
    sales_expect(sales_counts($db)===$baseline,'Rollback did not restore original record counts');
    $checks[]='PASS: all test financial rows, products, accounts and audits rolled back; original records unchanged';
    echo implode(PHP_EOL,$checks).PHP_EOL;
} catch (Throwable $exception) {
    if ($db->inTransaction()) { $db->rollBack(); }
    fwrite(STDERR,'FAIL: '.$exception->getMessage().PHP_EOL); exit(1);
}
