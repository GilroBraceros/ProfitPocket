<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(403); exit; }
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/customers.php';
date_default_timezone_set('Asia/Manila');
function customer_expect(bool $condition,string $message): void { if (!$condition) { throw new RuntimeException($message); } }
function customer_counts(PDO $db): array
{
    $counts=[]; foreach (['users','businesses','customers','products','sales','sale_items','sale_payments','customer_credits','activity_logs'] as $table) { $counts[$table]=(int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn(); } return $counts;
}
function customer_reject(PDO $db,callable $operation,string $message): void
{
    $db->exec('SAVEPOINT rejected_customer');
    try { $operation(); } catch (InvalidArgumentException|CustomerNotFound $expected) { $db->exec('ROLLBACK TO SAVEPOINT rejected_customer'); $db->exec('RELEASE SAVEPOINT rejected_customer'); return; }
    throw new RuntimeException($message);
}
$db=db(); $baseline=customer_counts($db); $checks=[];
try {
    $db->beginTransaction(); $suffix=bin2hex(random_bytes(8));
    $role=$db->query("SELECT role_id FROM roles WHERE role_code='business_owner'")->fetchColumn(); $category=$db->query('SELECT business_category_id FROM business_categories LIMIT 1')->fetchColumn(); $method=$db->query('SELECT payment_method_id FROM payment_methods WHERE is_active=1 LIMIT 1')->fetchColumn();
    customer_query($db,'INSERT INTO users (role_id,full_name,username,email,password_hash) VALUES (?,?,?,?,?)',[$role,'Customer rollback owner','customer_'.$suffix,$suffix.'@example.invalid',password_hash($suffix,PASSWORD_DEFAULT)]); $owner=(int)$db->lastInsertId();
    customer_query($db,'INSERT INTO businesses (owner_user_id,business_category_id,business_name) VALUES (?,?,?)',[$owner,$category,'Customer rollback business']); $business=(int)$db->lastInsertId(); $user=['business_id'=>$business,'user_id'=>$owner];
    $empty=customer_list($db,$business,customer_filters([])); customer_expect($empty['rows']===[] && (int)$empty['summary']['active_customers']===0,'Empty customer state failed');
    $base=['customer_name'=>'Juan Dela Cruz','phone'=>'+63 917 123 4567','email'=>'juan@example.invalid','address'=>'0','notes'=>'0'];
    $key=bin2hex(random_bytes(32)); $id=customer_save($db,$user,0,$base,$key); customer_expect(customer_save($db,$user,0,$base,$key)===$id,'Customer submission duplicated');
    $row=customer_read($db,$business,$id); customer_expect($row['phone']==='09171234567' && $row['address']==='0' && $row['notes']==='0','Phone normalization or zero text failed');
    $other=customer_save($db,$user,0,array_replace($base,['phone'=>'09171234568']),bin2hex(random_bytes(32)));
    customer_reject($db,fn()=>customer_save($db,$user,0,array_replace($base,['customer_name'=>'Another person','phone'=>'639171234567']),bin2hex(random_bytes(32))),'Duplicate contact accepted without warning');
    $shared=customer_save($db,$user,0,array_replace($base,['customer_name'=>'Shared contact','allow_shared_phone'=>'1']),bin2hex(random_bytes(32)));
    customer_expect(customer_list($db,$business,customer_filters(['q'=>'+63 917 123 4567']))['count']===2,'International phone search failed');
    $checks[]='PASS: real customer creation/replay, optional fields, PH mobile formats, identical names and explicitly shared contacts';
    $before=customer_counts($db);
    foreach ([['customer_name'=>''],['customer_name'=>str_repeat('x',151)],['phone'=>'12345'],['phone'=>'091712345670'],['email'=>'bad email'],['address'=>str_repeat('x',256)],['notes'=>str_repeat('x',4001)],['customer_name'=>"\xFF"]] as $change) { customer_reject($db,fn()=>customer_save($db,$user,0,array_replace($base,$change),bin2hex(random_bytes(32))),'Invalid customer accepted'); }
    customer_expect(customer_counts($db)===$before,'Validation left orphan data');
    customer_reject($db,fn()=>customer_read($db,1,$id),'Foreign customer visible');
    customer_reject($db,fn()=>customer_history($db,1,$id),'Foreign purchase history visible');
    customer_reject($db,fn()=>customer_save($db,['user_id'=>$owner,'business_id'=>1],0,$base,bin2hex(random_bytes(32))),'Mismatched owner accepted');
    $edit=array_replace($base,['customer_name'=>'Juan Updated','notes'=>'<safe notes>','revision'=>customer_revision($row)]); $editKey=bin2hex(random_bytes(32));
    customer_save($db,$user,$id,$edit,$editKey); customer_save($db,$user,$id,$edit,$editKey);
    customer_reject($db,fn()=>customer_save($db,$user,$id,$edit,bin2hex(random_bytes(32))),'Stale customer edit accepted');
    $checks[]='PASS: validation, UTF-8, ownership/scope, audited edits, stale requests and repeat-edit protection';
    $product=sale_product_create($db,$user,['product_name'=>'Customer test product','default_selling_price'=>'100','default_unit_cost'=>'40'],bin2hex(random_bytes(32)));
    $saleBase=['sold_at'=>date('Y-m-d\TH:i',strtotime('-2 days')),'save_mode'=>'posted','payment_plan'=>'partial','customer_id'=>(string)$id,'initial_payment'=>'600','payment_method_id'=>(string)$method,'due_date'=>date('Y-m-d',strtotime('-1 day')),'items'=>[['product_id'=>(string)$product,'quantity'=>'1','unit_price'=>'1000']],'discount_type'=>'none','discount_value'=>'0','notes'=>''];
    $first=sale_create($db,$user,$saleBase,bin2hex(random_bytes(32)));
    $second=sale_create($db,$user,array_replace($saleBase,['initial_payment'=>'200','due_date'=>date('Y-m-d',strtotime('+7 days')),'items'=>[['product_id'=>(string)$product,'quantity'=>'1','unit_price'=>'500']]]),bin2hex(random_bytes(32)));
    $row=customer_read($db,$business,$id); customer_expect(bccomp($row['purchases'],'1500',2)===0 && bccomp($row['outstanding'],'700',2)===0 && (int)$row['unpaid_credits']===2 && customer_balance_label($row)==='Overdue','Example purchases/700 utang/overdue failed');
    foreach (['100','50'] as $amount) { customer_query($db,'INSERT INTO sale_payments (sale_id,business_id,payment_method_id,entered_by,amount) VALUES (?,?,?,?,?)',[$first,$business,$method,$owner,$amount]); }
    $row=customer_read($db,$business,$id); customer_expect(bccomp($row['outstanding'],'550',2)===0 && bccomp($row['purchases'],'1500',2)===0,'Repayments inflated revenue or balance');
    sale_create($db,$user,array_replace($saleBase,['payment_plan'=>'full','initial_payment'=>'90','discount_type'=>'fixed','discount_value'=>'10','items'=>[['product_id'=>(string)$product,'quantity'=>'1','unit_price'=>'100']]]),bin2hex(random_bytes(32)));
    $settled=sale_create($db,$user,array_replace($saleBase,['payment_plan'=>'unpaid','initial_payment'=>'0','items'=>[['product_id'=>(string)$product,'quantity'=>'1','unit_price'=>'200']]]),bin2hex(random_bytes(32)));
    customer_query($db,'INSERT INTO sale_payments (sale_id,business_id,payment_method_id,entered_by,amount) VALUES (?,?,?,?,?)',[$settled,$business,$method,$owner,'200']);
    sale_create($db,$user,array_replace($saleBase,['save_mode'=>'draft','payment_plan'=>'unpaid','initial_payment'=>'0']),bin2hex(random_bytes(32)));
    $voidSale=sale_create($db,$user,array_replace($saleBase,['payment_plan'=>'unpaid','initial_payment'=>'0','items'=>[['product_id'=>(string)$product,'quantity'=>'1','unit_price'=>'300']]]),bin2hex(random_bytes(32)));
    $saleRow=sale_read($db,$business,$voidSale); sale_void($db,$user,$voidSale,['revision'=>sale_revision($saleRow),'void_reason'=>'Rollback test']);
    $row=customer_read($db,$business,$id); $history=customer_history($db,$business,$id);
    customer_expect(bccomp($row['purchases'],'1790',2)===0 && (int)$row['transactions']===4 && bccomp($row['outstanding'],'550',2)===0 && count($history['purchases'])===4 && count($history['credits'])===3,'Draft/void/settled/discounted history counted incorrectly');
    $list=customer_list($db,$business,customer_filters([])); customer_expect((int)$list['summary']['with_utang']===1 && bccomp($list['summary']['outstanding'],'550',2)===0,'Business utang summary wrong');
    customer_expect(customer_list($db,$business,customer_filters(['balance'=>'utang']))['count']===1 && customer_list($db,$business,customer_filters(['balance'=>'overdue']))['count']===1 && customer_list($db,$business,customer_filters(['balance'=>'clear']))['count']===2,'Balance filters wrong');
    customer_expect(customer_list($db,$business,customer_filters(['q'=>'Juan Updated']))['count']===1 && customer_list($db,$business,customer_filters(['q'=>'09171234568']))['count']===1,'Name/contact search failed');
    $checks[]='PASS: 700 example utang, multiple repayments, settled/discounted sales, overdue/due dates, draft/void exclusion, history, search and balance filters';
    $financialBefore=dashboard_data($db,$business,dashboard_period(['period'=>'week']));
    $archive=['revision'=>customer_revision($row),'confirm_customer_action'=>'1']; $archiveKey=bin2hex(random_bytes(32));
    customer_reject($db,fn()=>customer_set_active($db,$user,$id,array_replace($archive,['confirm_customer_action'=>'']),bin2hex(random_bytes(32)),false),'Archive without confirmation accepted');
    customer_set_active($db,$user,$id,$archive,$archiveKey,false); customer_set_active($db,$user,$id,$archive,$archiveKey,false);
    $archived=customer_read($db,$business,$id); customer_expect(!$archived['is_active'] && customer_list($db,$business,customer_filters([]))['count']===2 && customer_list($db,$business,customer_filters(['state'=>'archived']))['count']===1,'Archive directory wrong');
    customer_expect(bccomp(customer_list($db,$business,customer_filters([]))['summary']['outstanding'],'550',2)===0 && dashboard_data($db,$business,dashboard_period(['period'=>'week']))['totals']===$financialBefore['totals'],'Archive lost debt or changed dashboard');
    customer_reject($db,fn()=>sale_create($db,$user,$saleBase,bin2hex(random_bytes(32))),'Archived customer usable in new sale');
    customer_expect(sale_read($db,$business,$first)['customer_name']==='Juan Updated' && count(customer_history($db,$business,$id)['purchases'])===4,'Archive/edit lost sales relationships');
    customer_set_active($db,$user,$id,['revision'=>customer_revision($archived),'confirm_customer_action'=>'1'],bin2hex(random_bytes(32)),true);
    customer_expect(customer_read($db,$business,$id)['is_active'] && in_array($id,array_column(sale_catalog($db,$business)['customers'],'customer_id')),'Restore/sales catalog failed');
    $saleRow=sale_read($db,$business,$first); sale_void($db,$user,$first,['revision'=>sale_revision($saleRow),'void_reason'=>'Rollback correction','refund_confirm'=>'1']);
    $row=customer_read($db,$business,$id); customer_expect(bccomp($row['purchases'],'790',2)===0 && bccomp($row['outstanding'],'300',2)===0,'Refund/void changed remaining customer figures incorrectly');
    $checks[]='PASS: confirmed archive/replay/restore, archived debt/history retained, active Sales selection and unchanged dashboard calculations';
    for ($i=0;$i<15;$i++) { customer_save($db,$user,0,['customer_name'=>'Page customer '.$i],bin2hex(random_bytes(32))); }
    $page=customer_list($db,$business,customer_filters(['page'=>'2','sort'=>'recent'])); customer_expect($page['pages']===2 && count($page['rows'])===3,'Customer pagination wrong');
    $db->rollBack(); customer_expect(customer_counts($db)===$baseline,'Test data retained'); $checks[]='PASS: pagination; all customer, financial, payment, credit and audit fixtures rolled back';
    echo implode(PHP_EOL,$checks).PHP_EOL;
} catch (Throwable $exception) { if ($db->inTransaction()) { $db->rollBack(); } fwrite(STDERR,'FAIL: '.$exception->getMessage().PHP_EOL); exit(1); }
