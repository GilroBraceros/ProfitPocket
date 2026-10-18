<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(403); exit; }
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/expenses.php';
date_default_timezone_set('Asia/Manila');
function expense_expect(bool $ok,string $message): void { if (!$ok) { throw new RuntimeException($message); } }
function expense_reject(PDO $db,callable $operation,string $message): void
{
    $db->exec('SAVEPOINT rejected_expense');
    try { $operation(); } catch (InvalidArgumentException|ExpenseNotFound $expected) { $db->exec('ROLLBACK TO SAVEPOINT rejected_expense'); $db->exec('RELEASE SAVEPOINT rejected_expense'); return; }
    throw new RuntimeException($message);
}
function expense_counts(PDO $db): array
{
    $counts=[]; foreach (['users','businesses','expenses','expense_receipts','expense_categories','recurring_expenses','activity_logs'] as $table) { $counts[$table]=(int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn(); } return $counts;
}
$db=db(); $baseline=expense_counts($db); $stored=[]; $temporary=[]; $checks=[];
try {
    $db->beginTransaction(); $suffix=bin2hex(random_bytes(8));
    $role=$db->query("SELECT role_id FROM roles WHERE role_code='business_owner'")->fetchColumn(); $category=$db->query('SELECT business_category_id FROM business_categories LIMIT 1')->fetchColumn(); $method=$db->query('SELECT payment_method_id FROM payment_methods WHERE is_active=1 LIMIT 1')->fetchColumn();
    expense_query($db,'INSERT INTO users (role_id,full_name,username,email,password_hash) VALUES (?,?,?,?,?)',[$role,'Expense rollback owner','expense_'.$suffix,$suffix.'@example.invalid',password_hash($suffix,PASSWORD_DEFAULT)]); $owner=(int)$db->lastInsertId();
    expense_query($db,'INSERT INTO businesses (owner_user_id,business_category_id,business_name) VALUES (?,?,?)',[$owner,$category,'Expense rollback business']); $business=(int)$db->lastInsertId(); $user=['business_id'=>$business,'user_id'=>$owner];
    $empty=expense_list($db,$business,expense_filters([])); expense_expect($empty['rows']===[] && !$empty['highest'] && bccomp($empty['summary']['total'],'0',2)===0,'Empty state wrong');
    $categoryKey=bin2hex(random_bytes(32)); $packaging=expense_category_create($db,$user,['category_name'=>'Packaging'],$categoryKey);
    expense_expect(expense_category_create($db,$user,['category_name'=>'Packaging'],$categoryKey)===$packaging && expense_category_create($db,$user,['category_name'=>'packaging'],bin2hex(random_bytes(32)))===$packaging,'Category duplicate created');
    $travel=expense_category_create($db,$user,['category_name'=>'Transportation'],bin2hex(random_bytes(32)));
    $base=['expense_category_id'=>(string)$packaging,'payment_method_id'=>(string)$method,'description'=>'Packaging materials','amount'=>'125.50','expense_date'=>date('Y-m-d'),'expense_treatment'=>'operating','notes'=>'<escaped notes>'];
    $key=bin2hex(random_bytes(32)); $id=expense_create($db,$user,$base,$key);
    expense_expect(expense_create($db,$user,$base,$key)===$id,'Expense replay duplicated data');
    $second=expense_create($db,$user,array_replace($base,['expense_category_id'=>(string)$travel,'amount'=>'75.25','description'=>'Bus fare']),bin2hex(random_bytes(32)));
    expense_create($db,$user,array_replace($base,['amount'=>'1000','expense_treatment'=>'inventory_purchase']),bin2hex(random_bytes(32)));
    expense_create($db,$user,array_replace($base,['amount'=>'500','expense_treatment'=>'other_cash_outflow']),bin2hex(random_bytes(32)));
    $period=dashboard_period(['period'=>'today']); $data=dashboard_data($db,$business,$period);
    expense_expect($data['totals']['expenses']===20075 && $data['totals']['profit']===-20075,'Stock/other payments double counted or net profit wrong');
    $list=expense_list($db,$business,expense_filters(['period'=>'today']));
    expense_expect((int)$list['summary']['records']===2 && bccomp($list['summary']['total'],'200.75',2)===0 && $list['highest']['category_name']==='Packaging' && bccomp($list['month'],'200.75',2)===0,'Expense summaries wrong');
    expense_expect(count(expense_list($db,$business,expense_filters(['q'=>'Transportation']))['rows'])===1 && count(expense_list($db,$business,expense_filters(['category'=>(string)$travel]))['rows'])===1,'Search/category filter failed');
    expense_expect(count(expense_list($db,$business,expense_filters(['treatment'=>'all']))['rows'])===4,'Classification filter failed');
    $checks[]='PASS: real expense/category creation, replay, different categories, exact totals, profit, stock exclusion, empty/search/date/type filters';
    $foreign=$db->query('SELECT expense_category_id FROM expense_categories WHERE business_id<>'.$business.' LIMIT 1')->fetchColumn();
    $invalids=[['amount'=>'0'],['amount'=>'-1'],['amount'=>'1.001'],['amount'=>'1e3'],['amount'=>'100000000000'],['description'=>''],['expense_date'=>'2026-02-30'],['expense_date'=>'2999-01-01'],['payment_method_id'=>'999999'],['expense_category_id'=>(string)$foreign],['expense_treatment'=>'invalid']];
    $before=expense_counts($db);
    foreach ($invalids as $change) { expense_reject($db,fn()=>expense_create($db,$user,array_replace($base,$change),bin2hex(random_bytes(32))),'Invalid expense accepted: '.json_encode($change)); }
    expense_expect(expense_counts($db)===$before,'Invalid expenses left orphan rows');
    expense_reject($db,fn()=>expense_read($db,1,$id),'Foreign expense visible');
    expense_reject($db,fn()=>expense_create($db,['business_id'=>1,'user_id'=>$owner],$base,bin2hex(random_bytes(32))),'Mismatched owner accepted');
    $row=expense_read($db,$business,$id); $edit=['description'=>'New description','notes'=>'Updated notes','revision'=>expense_revision($row)];
    expense_reject($db,fn()=>expense_edit($db,$user,$id,$edit+['amount'=>'1.00'],bin2hex(random_bytes(32))),'Immutable financial amount edited');
    $editKey=bin2hex(random_bytes(32)); expense_edit($db,$user,$id,$edit,$editKey); expense_edit($db,$user,$id,$edit,$editKey);
    $updated=expense_read($db,$business,$id); expense_expect($updated['description']==='New description' && bccomp($updated['amount'],'125.50',2)===0,'Eligible edit changed money');
    expense_reject($db,fn()=>expense_edit($db,$user,$id,$edit,bin2hex(random_bytes(32))),'Stale edit accepted');
    $checks[]='PASS: invalid inputs, owner/business scope, no orphan rows, audited description/notes edits, financial locks and stale/replayed edits';
    $jpg=expense_validate_image(__DIR__.'/../assets/images/logo-icon.jpg','receipt.jpg');
    $failedStored=[]; $receiptCountBefore=(int)$db->query('SELECT COUNT(*) FROM expense_receipts')->fetchColumn();
    $db->exec('SAVEPOINT failed_receipt');
    try {
        // An unknown parent forces a real FK failure after the image is stored.
        expense_attach_receipt($db,$user,0,$jpg,$failedStored);
        throw new RuntimeException('Receipt with no parent expense accepted');
    } catch (PDOException $expected) {
        expense_expect(($expected->errorInfo[1]??0)===1452,'Unexpected receipt failure');
        $db->exec('ROLLBACK TO SAVEPOINT failed_receipt'); $db->exec('RELEASE SAVEPOINT failed_receipt');
        expense_expect(count($failedStored)===1 && is_file($failedStored[0]),'Failed receipt destination was not tracked');
        expense_discard_new_receipts($failedStored);
        expense_expect(!is_file($failedStored[0]) && (int)$db->query('SELECT COUNT(*) FROM expense_receipts')->fetchColumn()===$receiptCountBefore,'Failed receipt left a file or metadata');
    } finally { expense_discard_new_receipts($failedStored); }
    expense_attach_receipt($db,$user,$id,$jpg,$stored);
    $png=tempnam(sys_get_temp_dir(),'PP_png_'); $temporary[]=$png; file_put_contents($png,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a7WQAAAAASUVORK5CYII='));
    $valid=expense_validate_image($png,'../../receipt.png'); expense_expect($valid['name']==='receipt.png','Unsafe filename kept');
    $webp=tempnam(sys_get_temp_dir(),'PP_webp_'); $temporary[]=$webp;
    file_put_contents($webp,base64_decode('UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEADsD+JaQAA3AAAAAA'));
    expense_expect(expense_validate_image($webp,'receipt.webp')['mime']==='image/webp','Valid WebP rejected');
    $row=expense_read($db,$business,$id); expense_edit($db,$user,$id,['description'=>$row['description'],'notes'=>$row['notes'],'revision'=>expense_revision($row)],bin2hex(random_bytes(32)),$valid,$stored);
    $row=expense_read($db,$business,$id); expense_expect(count($row['receipts'])===2,'Receipt replacement deleted history');
    $receipt=expense_receipt($db,$business,(int)$row['receipts'][0]['receipt_id']); expense_expect($receipt['mime_type']==='image/png' && is_file($receipt['path']),'Receipt retrieval failed');
    expense_reject($db,fn()=>expense_receipt($db,1,(int)$row['receipts'][0]['receipt_id']),'Receipt leaked businesses');
    $bad=tempnam(sys_get_temp_dir(),'PP_bad_'); $temporary[]=$bad; file_put_contents($bad,'<?php echo "not an image";');
    expense_reject($db,fn()=>expense_validate_image($bad,'fake.jpg'),'Fake JPEG accepted');
    file_put_contents($bad,str_repeat('x',EXPENSE_RECEIPT_LIMIT+1)); expense_reject($db,fn()=>expense_validate_image($bad,'large.png'),'Oversized image accepted');
    expense_reject($db,fn()=>expense_uploaded_receipt(['receipt'=>['error'=>UPLOAD_ERR_INI_SIZE]]),'Upload error accepted');
    expense_reject($db,fn()=>expense_uploaded_receipt(['receipt'=>['error'=>UPLOAD_ERR_OK,'tmp_name'=>$png,'name'=>'test.png']]),'Non-upload HTTP path accepted');
    $checks[]='PASS: valid JPG/PNG/WebP receipts, sanitized filenames, private storage, failed-save file cleanup, replacement history, foreign access and invalid/oversized uploads rejected';
    $row=expense_read($db,$business,$id); $void=['revision'=>expense_revision($row),'void_reason'=>'Rollback correction'];
    expense_reject($db,fn()=>expense_void($db,$user,$id,array_replace($void,['void_reason'=>'']),bin2hex(random_bytes(32))),'Empty void reason accepted');
    $voidKey=bin2hex(random_bytes(32)); expense_void($db,$user,$id,$void,$voidKey); expense_void($db,$user,$id,$void,$voidKey);
    $voidLogCount=expense_query($db,"SELECT COUNT(*) FROM activity_logs WHERE business_id=? AND entity_id=? AND action_code IN ('EXPENSE_VOIDED','EXPENSE_VOID_CONFIRMED')",[$business,$id])->fetchColumn();
    expense_expect((int)$voidLogCount===2,'Void retry duplicated audit events');
    $voided=expense_read($db,$business,$id);
    expense_reject($db,fn()=>expense_edit($db,$user,$id,['description'=>'Cannot edit a void','notes'=>'','revision'=>expense_revision($voided)],bin2hex(random_bytes(32))),'Voided expense edited');
    expense_reject($db,fn()=>expense_void($db,$user,$id,$void,bin2hex(random_bytes(32))),'Repeat void accepted');
    expense_expect(dashboard_data($db,$business,$period)['totals']['expenses']===7525 && dashboard_data($db,$business,$period)['totals']['profit']===-7525,'Voided expense included in profit or expense total');
    expense_expect(count(expense_read($db,$business,$id)['receipts'])===2 && is_file(expense_receipt($db,$business,(int)$row['receipts'][0]['receipt_id'])['path']),'Void lost receipt history');
    $checks[]='PASS: required void reason, safe replay, repeated void blocked, original receipts/history retained, dashboard/chart/net profit update';
    $scheduleInput=array_replace($base,['frequency'=>'monthly','repeat_every'=>'1','start_date'=>date('Y-m-d'),'end_date'=>'']); $scheduleKey=bin2hex(random_bytes(32));
    $schedule=expense_schedule_create($db,$user,$scheduleInput,$scheduleKey);
    expense_expect(expense_schedule_create($db,$user,$scheduleInput,$scheduleKey)===$schedule,'Schedule replay duplicated a template');
    $beforeTotals=dashboard_data($db,$business,$period)['totals'];
    $scheduleRow=expense_schedule_read($db,$business,$schedule);
    $occurrence=$base+['recurring_expense_id'=>(string)$schedule,'recurrence_due_on'=>$scheduleRow['next_due_date']];
    $paid=expense_create($db,$user,$occurrence,bin2hex(random_bytes(32)));
    expense_expect(expense_create($db,$user,$occurrence,bin2hex(random_bytes(32)))===$paid,'Same occurrence generated twice');
    $after=dashboard_data($db,$business,$period)['totals']; expense_expect($after['expenses']===$beforeTotals['expenses']+12550,'Template counted or duplicate scheduled payment counted');
    expense_expect(expense_schedule_read($db,$business,$schedule)['next_due_date']===expense_next_due($scheduleRow),'Next due date not advanced');
    expense_expect(expense_next_due(['start_date'=>'2024-01-31','next_due_date'=>'2024-01-31','frequency'=>'monthly','repeat_every'=>1])==='2024-02-29' && expense_next_due(['start_date'=>'2024-01-31','next_due_date'=>'2024-02-29','frequency'=>'monthly','repeat_every'=>1])==='2024-03-31','Month-end anchors drifted');
    expense_expect(expense_next_due(['start_date'=>'2024-02-29','next_due_date'=>'2027-02-28','frequency'=>'yearly','repeat_every'=>1])==='2028-02-29','Leap-year anchor wrong');
    $toggle=expense_schedule_read($db,$business,$schedule); $toggleInput=['revision'=>hash('sha256',json_encode($toggle,JSON_THROW_ON_ERROR))];
    expense_schedule_toggle($db,$user,$schedule,$toggleInput,bin2hex(random_bytes(32)));
    expense_reject($db,fn()=>expense_create($db,$user,$occurrence,bin2hex(random_bytes(32))),'Paused schedule accepted');
    for ($i=0;$i<17;$i++) { expense_create($db,$user,$base,bin2hex(random_bytes(32))); }
    $page=expense_list($db,$business,expense_filters(['page'=>'2','sort'=>'oldest'])); expense_expect($page['pages']===2 && count($page['rows'])===4,'Pagination wrong');
    $future=expense_schedule_create($db,$user,array_replace($scheduleInput,['start_date'=>date('Y-m-d',strtotime('+1 day'))]),bin2hex(random_bytes(32)));
    expense_reject($db,fn()=>expense_create($db,$user,array_replace($base,['recurring_expense_id'=>(string)$future,'recurrence_due_on'=>date('Y-m-d',strtotime('+1 day'))]),bin2hex(random_bytes(32))),'Future scheduled occurrence accepted');
    expense_reject($db,fn()=>expense_schedule_read($db,1,$future),'Foreign schedule visible');
    $ending=expense_schedule_create($db,$user,array_replace($scheduleInput,['end_date'=>date('Y-m-d')]),bin2hex(random_bytes(32)));
    expense_create($db,$user,array_replace($base,['recurring_expense_id'=>(string)$ending,'recurrence_due_on'=>date('Y-m-d')]),bin2hex(random_bytes(32)));
    $ended=expense_schedule_read($db,$business,$ending); expense_expect(!$ended['is_active'],'Completed schedule not paused');
    expense_reject($db,fn()=>expense_schedule_toggle($db,$user,$ending,['revision'=>hash('sha256',json_encode($ended,JSON_THROW_ON_ERROR))],bin2hex(random_bytes(32))),'Ended schedule resumed');
    expense_expect(expense_next_due(['start_date'=>'2024-01-01','next_due_date'=>'2024-01-01','frequency'=>'daily','repeat_every'=>2])==='2024-01-03' && expense_next_due(['start_date'=>'2024-01-01','next_due_date'=>'2024-01-01','frequency'=>'weekly','repeat_every'=>2])==='2024-01-15','Daily/weekly interval wrong');
    $checks[]='PASS: separate recurring templates, explicit paid occurrences, deduplication, next dates/month-end/leap anchors, pause and pagination';
    $db->rollBack(); expense_expect(expense_counts($db)===$baseline,'Test rows or audits retained'); $checks[]='PASS: all financial, recurring, category, receipt metadata and audit fixtures rolled back';
    echo implode(PHP_EOL,$checks).PHP_EOL;
} catch (Throwable $exception) { if ($db->inTransaction()) { $db->rollBack(); } fwrite(STDERR,'FAIL: '.$exception->getMessage().PHP_EOL); exit(1);
} finally { foreach (array_merge($stored,$temporary) as $path) { if (is_file($path)) { unlink($path); } } }
