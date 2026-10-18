<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(403); exit; }
require __DIR__.'/../config/database.php'; require __DIR__.'/../includes/reports.php';
date_default_timezone_set('Asia/Manila');
function report_expect(bool $ok,string $message): void { if (!$ok) { throw new RuntimeException($message); } }
function report_insert(PDO $db,string $sql,array $values): int { $db->prepare($sql)->execute($values); return (int)$db->lastInsertId(); }
function report_counts(PDO $db): array { $r=[]; foreach (['users','businesses','sales','sale_items','sale_payments','customer_credits','expenses','products','customers','activity_logs'] as $t) { $r[$t]=$db->query('SELECT COUNT(*) FROM '.$t)->fetchColumn(); } return $r; }
$db=db(); $baseline=report_counts($db); $today=new DateTimeImmutable('2026-10-09',new DateTimeZone('Asia/Manila'));
$filter=fn(array $changes=[])=>report_filters(array_replace(['period'=>'custom','start'=>'2026-10-09','end'=>'2026-10-09'],$changes),$today);
try {
    report_expect(report_period(['period'=>'year'],$today)['start']->format('Y-m-d')==='2026-01-01','Year start wrong');
    report_expect(report_period(['period'=>'year'],new DateTimeImmutable('2024-12-31',new DateTimeZone('Asia/Manila')))['days']===366,'Leap year rejected');
    report_expect(report_period(['period'=>'week'],$today)['start']->format('Y-m-d')==='2026-10-05','Week start wrong');
    report_expect(report_period(['period'=>'today'],$today)['days']===1 && report_period([],$today)['start']->format('Y-m-d')==='2026-10-01','Today/month bounds wrong');
    foreach ([['period'=>'no'],['period'=>[]],['period'=>'custom','start'=>'2026-02-30','end'=>'2026-03-01'],['start'=>'2026-10-10','end'=>'2026-10-09'],['start'=>'2024-01-01','end'=>'2026-01-01'],['tab'=>'bad'],['status'=>'overdue'],['page'=>'0'],['page'=>[]],['q'=>[]],['category'=>'-1']] as $bad) {
        try { $filter($bad); throw new RuntimeException('Malformed filter accepted'); } catch (InvalidArgumentException $expected) {}
    }
    echo 'PASS: Manila daily/weekly/monthly/year/leap-year/custom dates and invalid filters'.PHP_EOL;
    $db->beginTransaction(); $suffix=bin2hex(random_bytes(7));
    $role=$db->query("SELECT role_id FROM roles WHERE role_code='business_owner'")->fetchColumn(); $category=$db->query('SELECT business_category_id FROM business_categories LIMIT 1')->fetchColumn(); $method=$db->query('SELECT payment_method_id FROM payment_methods WHERE is_active=1 LIMIT 1')->fetchColumn();
    $owner=report_insert($db,'INSERT INTO users (role_id,full_name,username,email,password_hash) VALUES (?,?,?,?,?)',[$role,'Reports test','reports_'.$suffix,$suffix.'@example.invalid',password_hash(bin2hex(random_bytes(20)),PASSWORD_DEFAULT)]);
    $business=report_insert($db,'INSERT INTO businesses (owner_user_id,business_category_id,business_name) VALUES (?,?,?)',[$owner,$category,'Reports test']);
    $empty=report_data($db,$business,$filter()); report_expect($empty['totals']['sales']===0 && $empty['totals']['profit']===0 && !$empty['chart']['has_activity'] && !$empty['products'],'Empty report wrong');
    $customer=report_insert($db,'INSERT INTO customers (business_id,customer_name) VALUES (?,?)',[$business,' =1+1']);
    $p1=report_insert($db,'INSERT INTO products (business_id,product_name,default_unit_cost) VALUES (?,?,?)',[$business,'Current catalog name','999']);
    $p2=report_insert($db,'INSERT INTO products (business_id,product_name) VALUES (?,?)',[$business,'Product two']);
    $p3=report_insert($db,'INSERT INTO products (business_id,product_name) VALUES (?,?)',[$business,'Product three']);
    $cat=report_insert($db,'INSERT INTO expense_categories (business_id,category_name) VALUES (?,?)',[$business,'Rent']);
    $makeSale=function(string $reference,string $type,string $at,array $items,string $initial='0',string $discountType='none',string $discount='0',?string $due=null,bool $posted=true) use ($db,$business,$owner,$customer,$method): int {
        $id=report_insert($db,'INSERT INTO sales (business_id,customer_id,created_by,sale_number,sale_type,sold_at,discount_type,discount_value) VALUES (?,?,?,?,?,?,?,?)',[$business,$type==='credit'?$customer:null,$owner,$reference,$type,$at,$discountType,$discount]);
        foreach ($items as [$product,$quantity,$price,$cost]) { report_insert($db,'INSERT INTO sale_items (sale_id,business_id,product_id,product_name_snapshot,quantity,unit_price,unit_cost) VALUES (?,?,?,?,?,?,?)',[$id,$business,$product,'Historical product '.$product,$quantity,$price,$cost]); }
        if ($type==='credit') { $db->prepare('INSERT INTO customer_credits (sale_id,due_date) VALUES (?,?)')->execute([$id,$due]); }
        if (bccomp($initial,'0',2)>0) { report_insert($db,'INSERT INTO sale_payments (sale_id,business_id,payment_method_id,entered_by,amount,paid_at) VALUES (?,?,?,?,?,?)',[$id,$business,$method,$owner,$initial,$at]); }
        if ($posted) { $db->prepare("UPDATE sales SET sale_status='posted' WHERE sale_id=?")->execute([$id]); } return $id;
    };
    $pay=function(int $sale,string $amount,string $at) use ($db,$business,$owner,$method): int { return report_insert($db,'INSERT INTO sale_payments (sale_id,business_id,payment_method_id,entered_by,amount,paid_at) VALUES (?,?,?,?,?,?)',[$sale,$business,$method,$owner,$amount,$at]); };
    $expense=function(string $amount,string $date,string $type='operating') use ($db,$business,$owner,$cat,$method): int { return report_insert($db,'INSERT INTO expenses (business_id,expense_category_id,payment_method_id,entered_by,description,amount,expense_date,expense_treatment) VALUES (?,?,?,?,?,?,?,?)',[$business,$cat,$method,$owner,' =HYPERLINK("example")',$amount,$date,$type]); };
    $paid=$makeSale('R-PAID','immediate','2026-10-09 09:00:00',[[$p1,'2','2500','1000'],[$p2,'1','5000','2000']],'10000');
    $partial=$makeSale('R-PARTIAL','credit','2026-10-09 10:00:00',[[$p1,'2','5000','2000']],'5000','none','0',date('Y-m-d',strtotime('+7 days')));
    $expense('4000','2026-10-09'); $expense('99000','2026-10-09','inventory_purchase'); $expense('1000','2026-10-09','other_cash_outflow');
    $d=report_data($db,$business,$filter());
    report_expect($d['totals']===['sales'=>2000000,'expenses'=>400000,'cogs'=>800000,'profit'=>800000,'gross_profit'=>1200000],'20000 / 8000 / 4000 profit example wrong');
    report_expect($d['sales']['transactions']===2 && (int)$d['sales']['paid']===1 && (int)$d['sales']['partial']===1 && $d['expense_count']===1,'Counts multiplied by items/payments');
    $pay($partial,'2000','2026-10-10 12:00:00'); $repaid=report_data($db,$business,$filter());
    report_expect($repaid['totals']===$d['totals'] && $repaid['credit']['outstanding']==='3000.00' && $repaid['credit']['collected']==='5000.00' && $repaid['cash']===1500000,'Repayment/date scope changed profit');
    $next=report_data($db,$business,$filter(['start'=>'2026-10-10','end'=>'2026-10-10'])); report_expect($next['credit']['collected']==='2000.00' && $next['totals']['sales']===0 && $next['cash']===200000,'Later collection treated as sale');
    echo 'PASS: multiple items, paid/partial sales, operating-only expenses and exact profit; later repayment affects cash/current debt only'.PHP_EOL;
    $unpaid=$makeSale('R-OLD','credit','2026-09-30 09:00:00',[[$p1,'1','1000','200']],'0','none','0','2020-01-01');
    $settled=$makeSale('R-SETTLED','credit','2026-09-29 09:00:00',[[$p1,'1','100','20']],'100','none','0','2020-01-01');
    $draft=$makeSale('R-DRAFT','credit','2026-10-09 10:00:00',[[$p1,'1','777','1']],'0','none','0',null,false);
    $void=$makeSale('R-VOID','credit','2026-10-09 10:00:00',[[$p1,'1','500','300']],'500');
    $original=report_query($db,"SELECT payment_id FROM sale_payments WHERE sale_id=? AND payment_kind='collection'",[$void])->fetchColumn();
    report_insert($db,"INSERT INTO sale_payments (sale_id,business_id,payment_method_id,entered_by,payment_kind,amount,reverses_payment_id,paid_at) VALUES (?,?,?,?,'refund',?,?,?)",[$void,$business,$method,$owner,'500',$original,'2026-10-10 13:00:00']);
    $db->prepare("UPDATE sales SET sale_status='void',void_reason='Rollback test',voided_by=?,voided_at=CURRENT_TIMESTAMP WHERE sale_id=?")->execute([$owner,$void]);
    $voidExpense=$expense('500','2026-10-09'); $db->prepare("UPDATE expenses SET expense_status='void',void_reason='Rollback test',voided_by=?,voided_at=CURRENT_TIMESTAMP WHERE expense_id=?")->execute([$owner,$voidExpense]);
    $db->prepare('UPDATE customers SET is_active=0 WHERE customer_id=?')->execute([$customer]);
    $d=report_data($db,$business,$filter()); report_expect($d['totals']===$repaid['totals'] && $d['credit']['outstanding']==='4000.00' && $d['credit']['overdue']==='1000.00' && (int)$d['credit']['customers']===1,'Void/draft/archive/customer counting wrong');
    report_expect($d['credit']['collected']==='5500.00' && report_data($db,$business,$filter(['start'=>'2026-10-10','end'=>'2026-10-10']))['credit']['collected']==='1500.00','Refund dates not represented');
    report_expect(report_listing($db,$business,$filter(['tab'=>'utang','status'=>'overdue']))['count']===1 && report_listing($db,$business,$filter(['tab'=>'utang','status'=>'paid']))['count']===1,'Settled overdue/status wrong');
    $salesOnly=report_data($db,$business,$filter(['start'=>'2026-09-30','end'=>'2026-09-30'])); report_expect($salesOnly['totals']['sales']===100000 && $salesOnly['totals']['expenses']===0,'Sales-only day wrong');
    $expense('50','2026-10-08'); $expensesOnly=report_data($db,$business,$filter(['start'=>'2026-10-08','end'=>'2026-10-08'])); report_expect($expensesOnly['totals']['sales']===0 && $expensesOnly['totals']['profit']===-5000,'Expense-only loss wrong');
    echo 'PASS: draft/void exclusions, dated refunds, archived customers, multiple debts counted once, overdue/settled and sales-only/expense-only days'.PHP_EOL;
    $makeSale('R-CENTS','credit','2026-10-09 11:00:00',[[$p1,'1','0.01','0'],[$p2,'1','0.01','0'],[$p3,'1','0.01','0']],'0','fixed','0.01');
    $makeSale('R-PERCENT','credit','2026-10-09 11:30:00',[[$p2,'1','100','10']],'0','percent','10.33');
    $d=report_data($db,$business,$filter()); report_expect($d['totals']['sales']===2008969 && $d['totals']['cogs']===801000 && $d['totals']['profit']===807969 && $d['sales']['discounts']==='10.34','Discounts/cost/profit wrong');
    report_expect(array_sum(array_column($d['products'],'sales'))===$d['totals']['sales'] && array_sum(array_column($d['products'],'cogs'))===$d['totals']['cogs'] && array_sum(array_column($d['products'],'profit'))===$d['totals']['gross_profit'],'Product contributions do not reconcile');
    $allocate=report_allocate([['line_sales'=>'0.01'],['line_sales'=>'0.01'],['line_sales'=>'0.01']],2,3); report_expect($allocate===[1,1,0],'Centavo residual allocation wrong');
    $before=$d['products']; $db->prepare('UPDATE products SET product_name=?,default_unit_cost=9999,is_active=0 WHERE product_id=?')->execute(['Changed catalog name',$p1]); report_expect(report_data($db,$business,$filter())['products']===$before,'Catalog edits changed historical contributions');
    report_expect($d['zero_cost']===3,'Zero cost warning missing');
    foreach (['today','week','month','year'] as $choice) { $f=report_filters(['period'=>$choice],$today); $r=report_data($db,$business,$f); $dashboard=dashboard_data($db,$business,$f['period']); foreach (['sales','expenses','cogs','profit'] as $k) { report_expect($r['totals'][$k]===$dashboard['totals'][$k],'Dashboard mismatch: '.$choice.' '.$k); } report_expect((int)round(array_sum($r['chart']['profit'])*100)===$r['totals']['profit'],'Chart profit mismatch'); }
    report_expect(report_data($db,$business,report_filters(['period'=>'year'],$today))['chart']['grouping']==='monthly','Year chart grouping wrong');
    report_expect(report_data($db,$business,$filter(['start'=>'2026-07-01','end'=>'2026-10-09']))['chart']['grouping']==='weekly','Weekly chart grouping wrong');
    echo 'PASS: fixed/percentage discounts once, exact product allocations/historical costs, cost warning, chart grouping and Dashboard reconciliation'.PHP_EOL;
    report_expect(report_listing($db,$business,$filter(['q'=>'R-PARTIAL']))['count']===1 && report_listing($db,$business,$filter(['q'=>'No match']))['count']===0,'Search wrong');
    report_expect(report_listing($db,$business,$filter(['tab'=>'expenses','category'=>(string)$cat]))['count']===1,'Expense category wrong');
    $foreign=report_data($db,0,$filter()); report_expect($foreign['totals']['sales']===0 && $foreign['credit']['outstanding']==='0.00' && report_listing($db,0,$filter())['count']===0,'Business leak');
    for ($i=0;$i<16;$i++) { $makeSale('R-PAGE-'.$i,'credit','2026-10-09 12:00:00',[[$p3,'1','1','0']]); }
    $page=report_listing($db,$business,$filter(['page'=>'2'])); report_expect($page['count']===20 && $page['pages']===2 && count($page['rows'])===5,'Pagination wrong');
    report_expect(count(report_listing($db,$business,$filter(),true)['rows'])===20,'Print did not include all rows');
    $s=fopen('php://temp','w+'); report_csv($db,$business,'@Reports business',$filter(),$s); rewind($s); $csv=stream_get_contents($s); fclose($s);
    report_expect(str_contains($csv,"' =1+1") && str_contains($csv,"'@Reports business") && substr_count($csv,'R-PAGE-')===16 && !str_contains($csv,'R-VOID') && !str_contains($csv,'R-DRAFT'),'CSV scope/formula/all rows wrong');
    $s=fopen('php://temp','w+'); report_csv($db,$business,'Reports',$filter(['q'=>'R-PARTIAL','status'=>'partial']),$s); rewind($s);
    $exportRows=[]; $headerFound=false;
    while (($cells=fgetcsv($s,0,',','"',''))!==false) { if (($cells[0]??'')==='Transaction date') { $headerFound=true; continue; } if ($headerFound) { $exportRows[]=$cells; } }
    fclose($s); report_expect(count($exportRows)===1 && $exportRows[0][1]==='R-PARTIAL' && $exportRows[0][3]==='10000.00' && $exportRows[0][4]==='Partially Paid','Filtered CSV amount/status inaccurate');
    foreach (['=1+1',' +SUM(A1)','\t@malicious',"\n=1",'−safe'] as $v) { if ($v!=='−safe') { $actual=str_replace('\\t',"\t",$v); report_expect(str_starts_with(report_csv_cell($actual),"'"),'Formula unsafe'); } }
    foreach (['expenses','profit','utang'] as $tab) { $s=fopen('php://temp','w+'); report_csv($db,$business,'Reports',$filter(['tab'=>$tab]),$s); rewind($s); $csv=stream_get_contents($s); fclose($s); report_expect(str_contains($csv,$tab==='utang'?'Customer Receivables':ucfirst($tab).' report') && strlen($csv)>200,'CSV unavailable: '.$tab); }
    $db->rollBack(); report_expect(report_counts($db)===$baseline,'Fixtures remain');
    echo 'PASS: search/status/category/pagination, all-row print/CSV, formula protection, tenant isolation and complete fixture rollback'.PHP_EOL;
} catch (Throwable $e) { if ($db->inTransaction()) { $db->rollBack(); } fwrite(STDERR,'FAIL: '.$e->getMessage().PHP_EOL); exit(1); }
