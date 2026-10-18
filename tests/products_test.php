<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(403); exit; }
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/products.php';
date_default_timezone_set('Asia/Manila');
function product_expect(bool $condition,string $message): void { if (!$condition) { throw new RuntimeException($message); } }
function product_reject(PDO $db,callable $operation,string $message): void {
    $db->exec('SAVEPOINT rejected_product');
    try { $operation(); } catch (InvalidArgumentException|ProductNotFound $expected) { $db->exec('ROLLBACK TO SAVEPOINT rejected_product'); return; }
    throw new RuntimeException($message);
}
function product_counts(PDO $db): array {
    $counts=[]; foreach (['users','businesses','customers','products','sales','sale_items','sale_payments','customer_credits','activity_logs'] as $table) { $counts[$table]=(int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn(); } return $counts;
}
$db=db(); $baseline=product_counts($db);
try {
    $db->beginTransaction(); $suffix=bin2hex(random_bytes(8));
    $role=$db->query("SELECT role_id FROM roles WHERE role_code='business_owner'")->fetchColumn(); $category=$db->query('SELECT business_category_id FROM business_categories LIMIT 1')->fetchColumn(); $method=$db->query('SELECT payment_method_id FROM payment_methods WHERE is_active=1 LIMIT 1')->fetchColumn();
    product_query($db,'INSERT INTO users (role_id,full_name,username,email,password_hash) VALUES (?,?,?,?,?)',[$role,'Product rollback owner','product_'.$suffix,$suffix.'@example.invalid',password_hash($suffix,PASSWORD_DEFAULT)]); $owner=(int)$db->lastInsertId();
    product_query($db,'INSERT INTO businesses (owner_user_id,business_category_id,business_name) VALUES (?,?,?)',[$owner,$category,'Product rollback business']); $business=(int)$db->lastInsertId(); $user=['business_id'=>$business,'user_id'=>$owner];
    $empty=product_list($db,$business,product_filters([])); product_expect($empty['rows']===[] && (int)$empty['summary']['total']===0,'Empty state failed');
    $base=['product_name'=>'Product <test>','sku'=>'TEST-001','description'=>'0','default_unit_cost'=>'3.45','default_selling_price'=>'10.20']; $key=bin2hex(random_bytes(32));
    $id=product_save($db,$user,0,$base,$key); product_expect(product_save($db,$user,0,$base,$key)===$id,'Create duplicated');
    $row=product_read($db,$business,$id); product_expect($row['description']==='0' && $row['sku']==='TEST-001' && (int)$row['is_active']===1,'Supported fields lost');
    $blank=product_save($db,$user,0,['product_name'=>'Blank code','default_unit_cost'=>'0','default_selling_price'=>'0'],bin2hex(random_bytes(32)));
    $sameName=product_save($db,$user,0,['product_name'=>'Blank code','default_unit_cost'=>'0.01','default_selling_price'=>'0'],bin2hex(random_bytes(32)));
    product_expect(product_read($db,$business,$blank)['sku']===null && product_read($db,$business,$sameName)['sku']===null,'Blank codes are not nullable');
    product_reject($db,fn()=>product_save($db,$user,0,$base,bin2hex(random_bytes(32))),'Duplicate SKU accepted');
    product_reject($db,fn()=>product_save($db,$user,0,array_replace($base,['sku'=>'test-001']),bin2hex(random_bytes(32))),'Case-insensitive duplicate SKU accepted');
    product_reject($db,fn()=>product_save($db,$user,0,array_replace($base,['product_name'=>'Changed retry']),$key),'Changed replay accepted');
    $counts=product_counts($db);
    foreach ([['product_name'=>''],['product_name'=>str_repeat('x',151)],['product_name'=>"\xFF"],['sku'=>str_repeat('x',61)],['description'=>str_repeat('x',4001)],['default_unit_cost'=>'-1'],['default_selling_price'=>'-1'],['default_selling_price'=>'1.001'],['default_unit_cost'=>'1e2'],['default_selling_price'=>'999999999999'],['default_unit_cost'=>'1,000'],['default_selling_price'=>'']] as $change) { product_reject($db,fn()=>product_save($db,$user,0,array_replace($base,$change),bin2hex(random_bytes(32))),'Invalid input accepted'); }
    product_expect(product_counts($db)===$counts,'Validation left rows');
    product_reject($db,fn()=>product_read($db,1,$id),'Foreign product visible');
    product_reject($db,fn()=>product_save($db,['business_id'=>1,'user_id'=>$owner],0,$base,bin2hex(random_bytes(32))),'Wrong owner accepted');
    $foreign=(int)$db->query('SELECT product_id FROM products WHERE business_id<>'.$business.' LIMIT 1')->fetchColumn();
    if ($foreign) { product_reject($db,fn()=>product_save($db,$user,$foreign,array_replace($base,['revision'=>'invalid']),bin2hex(random_bytes(32))),'Foreign product edit accepted'); product_reject($db,fn()=>product_set_active($db,$user,$foreign,['revision'=>'invalid','confirm_product_action'=>'1'],bin2hex(random_bytes(32)),false),'Foreign archive accepted'); }
    echo 'PASS: actual creation/replay, nullable unique SKU, zero prices, below-cost prices, validation, UTF-8 and business isolation'.PHP_EOL;
    $saleBase=['sold_at'=>date('Y-m-d\TH:i'),'save_mode'=>'posted','payment_plan'=>'full','customer_id'=>'','initial_payment'=>'20.40','payment_method_id'=>(string)$method,'due_date'=>'','items'=>[['product_id'=>(string)$id,'quantity'=>'2','unit_price'=>'10.20']],'discount_type'=>'none','discount_value'=>'0','notes'=>''];
    $sale=sale_create($db,$user,$saleBase,bin2hex(random_bytes(32)));
    $draft=sale_create($db,$user,array_replace($saleBase,['save_mode'=>'draft','initial_payment'=>'0']),bin2hex(random_bytes(32)));
    $snapshot=sale_read($db,$business,$sale); $draftRow=sale_read($db,$business,$draft); $before=dashboard_data($db,$business,dashboard_period(['period'=>'today']))['totals'];
    $edit=array_replace($base,['product_name'=>'Updated product','sku'=>'UPDATED','description'=>'<safe description>','default_unit_cost'=>'5.95','default_selling_price'=>'2.45','revision'=>product_revision($row)]); $editKey=bin2hex(random_bytes(32));
    product_save($db,$user,$id,$edit,$editKey); product_save($db,$user,$id,$edit,$editKey);
    product_reject($db,fn()=>product_save($db,$user,$id,$edit,bin2hex(random_bytes(32))),'Stale edit accepted');
    $updated=product_read($db,$business,$id); $catalog=array_column(sale_catalog($db,$business)['products'],null,'product_id');
    product_expect($catalog[$id]['default_selling_price']==='2.45' && $catalog[$id]['default_unit_cost']==='5.95','Catalog prices not updated');
    product_expect(sale_read($db,$business,$sale)===$snapshot && sale_read($db,$business,$draft)===$draftRow && dashboard_data($db,$business,dashboard_period(['period'=>'today']))['totals']===$before,'Catalog edit changed financial history');
    $future=sale_create($db,$user,array_replace($saleBase,['initial_payment'=>'2.45','items'=>[['product_id'=>(string)$id,'quantity'=>'1','unit_price'=>$catalog[$id]['default_selling_price']]]]),bin2hex(random_bytes(32)));
    $futureRow=sale_read($db,$business,$future); product_expect($futureRow['items'][0]['unit_price']==='2.45' && $futureRow['items'][0]['unit_cost']==='5.95' && $futureRow['items'][0]['product_name_snapshot']==='Updated product','Future sale used old defaults');
    echo 'PASS: audited edits/replay/stale protection, updated future Sales defaults, unchanged posted/draft names/prices/costs and dashboard profit'.PHP_EOL;
    $archive=['revision'=>product_revision($updated),'confirm_product_action'=>'1']; $archiveKey=bin2hex(random_bytes(32));
    product_reject($db,fn()=>product_set_active($db,$user,$id,array_replace($archive,['confirm_product_action'=>'']),bin2hex(random_bytes(32)),false),'Unconfirmed archive accepted');
    product_set_active($db,$user,$id,$archive,$archiveKey,false); product_set_active($db,$user,$id,$archive,$archiveKey,false);
    product_expect(!(bool)product_read($db,$business,$id)['is_active'] && product_list($db,$business,product_filters(['state'=>'archived']))['count']===1,'Archive failed');
    product_reject($db,fn()=>sale_create($db,$user,$saleBase,bin2hex(random_bytes(32))),'Archived product accepted in new sale');
    $draftEdit=array_replace($saleBase,['save_mode'=>'draft','initial_payment'=>'0','revision'=>sale_revision($draftRow),'items'=>[['sale_item_id'=>(string)$draftRow['items'][0]['sale_item_id'],'product_id'=>(string)$id,'quantity'=>'3','unit_price'=>'10.20']]]);
    sale_edit($db,$user,$draft,$draftEdit,bin2hex(random_bytes(32))); $retained=sale_read($db,$business,$draft);
    product_expect($retained['items'][0]['unit_cost']==='3.45' && $retained['items'][0]['product_name_snapshot']==='Product <test>','Existing draft snapshot lost on archive');
    $archived=product_read($db,$business,$id); $archivedEdit=array_replace($edit,['revision'=>product_revision($archived),'description'=>'Archived edit']); product_save($db,$user,$id,$archivedEdit,bin2hex(random_bytes(32)));
    product_expect(!(bool)product_read($db,$business,$id)['is_active'],'Archived edit reactivated product');
    $restore=['revision'=>product_revision(product_read($db,$business,$id)),'confirm_product_action'=>'1']; $restoreKey=bin2hex(random_bytes(32)); product_set_active($db,$user,$id,$restore,$restoreKey,true); product_set_active($db,$user,$id,$restore,$restoreKey,true);
    product_expect((bool)product_read($db,$business,$id)['is_active'] && sale_read($db,$business,$sale)===$snapshot,'Restore/history failed');
    product_expect(product_save($db,$user,0,$base,$key)===$id,'Original create retry after SKU edit duplicated product');
    echo 'PASS: confirmed archive/restore/replay, archived edits, new-sale exclusion, existing draft references and historical sales preserved'.PHP_EOL;
    product_expect(product_list($db,$business,product_filters(['q'=>'Updated']))['count']===1 && product_list($db,$business,product_filters(['q'=>'Missing']))['count']===0,'Search failed');
    $low=product_list($db,$business,product_filters(['sort'=>'price_low']))['rows']; $high=product_list($db,$business,product_filters(['sort'=>'price_high']))['rows']; product_expect($low[0]['default_selling_price']==='0.00' && $high[0]['product_id']===$id && $high[0]['unit_profit']==='-3.50','Sort/unit profit wrong');
    for ($i=0;$i<14;$i++) { product_save($db,$user,0,['product_name'=>'Page product '.$i,'default_unit_cost'=>'0','default_selling_price'=>'0'],bin2hex(random_bytes(32))); }
    $page=product_list($db,$business,product_filters(['page'=>'2','state'=>'all'])); product_expect($page['count']===17 && $page['pages']===2 && count($page['rows'])===2 && (int)$page['summary']['active']===17,'Pagination/summary failed');
    $db->exec('SAVEPOINT product_rollback'); $n=product_counts($db); product_save($db,$user,0,['product_name'=>'Rollback','default_unit_cost'=>'0','default_selling_price'=>'0'],bin2hex(random_bytes(32))); $db->exec('ROLLBACK TO SAVEPOINT product_rollback'); product_expect(product_counts($db)===$n,'Rollback left product/audit');
    $db->rollBack(); product_expect(product_counts($db)===$baseline,'Test fixtures retained');
    echo 'PASS: search, price sorting, exact negative unit profit, summaries, pagination and rollback; all fixtures removed'.PHP_EOL;
} catch (Throwable $e) { if ($db->inTransaction()) { $db->rollBack(); } fwrite(STDERR,'FAIL: '.$e->getMessage().PHP_EOL); exit(1); }
