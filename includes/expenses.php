<?php
declare(strict_types=1);
require_once __DIR__.'/sales.php'; // Existing date, decimal, owner-lock and signed-form helpers.

class ExpenseNotFound extends RuntimeException {}
class ExpenseValidation extends InvalidArgumentException
{
    public function __construct(string $message, public string $field = '') { parent::__construct($message); }
}
const EXPENSE_RECEIPT_LIMIT = 2097152;

function expense_query(PDO $db, string $sql, array $values = []): PDOStatement
{
    $statement=$db->prepare($sql); $statement->execute($values); return $statement;
}
function expense_catalog(PDO $db, int $business): array
{
    return ['categories'=>expense_query($db,'SELECT * FROM expense_categories WHERE business_id=? ORDER BY category_name',[$business])->fetchAll(),
        'methods'=>$db->query('SELECT * FROM payment_methods WHERE is_active=1 ORDER BY payment_method_id')->fetchAll()];
}
function expense_filters(array $input): array
{
    $filters=sale_filters(array_replace($input,['payment'=>'all']));
    if ($filters['state']==='draft') { throw new InvalidArgumentException('Choose posted, voided or all expenses.'); }
    $category=sale_text($input,'category'); $filters['category']=$category===''?null:sale_id($category);
    $treatment=sale_text($input,'treatment','operating');
    if (!in_array($treatment,['operating','inventory_purchase','other_cash_outflow','all'],true)) { throw new InvalidArgumentException('Choose a valid expense type.'); }
    $filters['treatment']=$treatment; return $filters;
}
function expense_list(PDO $db, int $business, array $filters): array
{
    $where='e.business_id=? AND e.expense_date>=? AND e.expense_date<?';
    $values=[$business,$filters['period']['start']->format('Y-m-d'),$filters['period']['exclusive_end']];
    if ($filters['state']!=='all') { $where.=' AND e.expense_status=?'; $values[]=$filters['state']; }
    if ($filters['treatment']!=='all') { $where.=' AND e.expense_treatment=?'; $values[]=$filters['treatment']; }
    if ($filters['category']) {
        if (!expense_query($db,'SELECT expense_category_id FROM expense_categories WHERE business_id=? AND expense_category_id=?',[$business,$filters['category']])->fetchColumn()) { throw new InvalidArgumentException('Choose a category belonging to your business.'); }
        $where.=' AND e.expense_category_id=?'; $values[]=$filters['category'];
    }
    if ($filters['q']!=='') { $where.=' AND (LOCATE(?,e.description)>0 OR LOCATE(?,c.category_name)>0)'; array_push($values,$filters['q'],$filters['q']); }
    $source=' FROM expenses e JOIN expense_categories c ON c.expense_category_id=e.expense_category_id AND c.business_id=e.business_id JOIN payment_methods m ON m.payment_method_id=e.payment_method_id WHERE '.$where;
    $summary=expense_query($db,"SELECT COUNT(*) AS records,COALESCE(SUM(CASE WHEN e.expense_status='posted' AND e.expense_treatment='operating' THEN e.amount ELSE 0 END),0) AS total".$source,$values)->fetch();
    $highest=expense_query($db,"SELECT c.category_name,SUM(e.amount) AS amount".$source." AND e.expense_status='posted' AND e.expense_treatment='operating' GROUP BY c.expense_category_id,c.category_name ORDER BY amount DESC,c.category_name LIMIT 1",$values)->fetch();
    $monthStart=date('Y-m-01'); $monthEnd=(new DateTimeImmutable($monthStart))->modify('+1 month')->format('Y-m-d');
    $month=expense_query($db,"SELECT COALESCE(SUM(amount),0) FROM expenses WHERE business_id=? AND expense_status='posted' AND expense_treatment='operating' AND expense_date>=? AND expense_date<?",[$business,$monthStart,$monthEnd])->fetchColumn();
    $pages=max(1,(int)ceil($summary['records']/15)); $page=min($filters['page'],$pages); $offset=($page-1)*15; $direction=$filters['sort']==='oldest'?'ASC':'DESC';
    $rows=expense_query($db,'SELECT e.*,c.category_name,m.method_name'.$source." ORDER BY e.expense_date $direction,e.expense_id $direction LIMIT 15 OFFSET $offset",$values)->fetchAll();
    return ['rows'=>$rows,'summary'=>$summary,'month'=>$month,'highest'=>$highest,'pages'=>$pages,'page'=>$page];
}
function expense_read(PDO $db, int $business, int $id, bool $lock=false): array
{
    if ($lock && !expense_query($db,'SELECT expense_id FROM expenses WHERE business_id=? AND expense_id=? FOR UPDATE',[$business,$id])->fetchColumn()) { throw new ExpenseNotFound('Expense not found.'); }
    $row=expense_query($db,'SELECT e.*,c.category_name,m.method_name FROM expenses e JOIN expense_categories c ON c.expense_category_id=e.expense_category_id AND c.business_id=e.business_id JOIN payment_methods m ON m.payment_method_id=e.payment_method_id WHERE e.business_id=? AND e.expense_id=?',[$business,$id])->fetch();
    if (!$row) { throw new ExpenseNotFound('Expense not found.'); }
    $row['receipts']=expense_query($db,'SELECT receipt_id,original_filename,mime_type,file_size_bytes,uploaded_at FROM expense_receipts WHERE business_id=? AND expense_id=? ORDER BY uploaded_at DESC,receipt_id DESC',[$business,$id])->fetchAll(); return $row;
}
function expense_revision(array $row): string { return hash('sha256',json_encode($row,JSON_THROW_ON_ERROR)); }
function expense_check_revision(array $row, array $input): void
{
    if (!hash_equals(expense_revision($row),sale_text($input,'revision'))) { throw new ExpenseValidation('This expense changed. Reopen it before saving.'); }
}
function expense_validate(PDO $db, array $user, array $input): array
{
    $description=sale_text($input,'description');
    if ($description==='' || mb_strlen($description)>255) { throw new ExpenseValidation('Enter a description of 1 to 255 characters.','description'); }
    try { $amount=sale_decimal(sale_text($input,'amount'),2,'Amount'); } catch (InvalidArgumentException $exception) { throw new ExpenseValidation($exception->getMessage(),'amount'); }
    if (bccomp($amount,'0',2)<=0) { throw new ExpenseValidation('Amount must be greater than zero.','amount'); }
    $date=sale_text($input,'expense_date');
    if (!dashboard_date($date) || $date< '1000-01-01' || $date>date('Y-m-d')) { throw new ExpenseValidation('Choose a valid date today or in the past.','expense_date'); }
    try { $category=sale_id(sale_text($input,'expense_category_id')); } catch (InvalidArgumentException $exception) { throw new ExpenseValidation('Choose an expense category.','expense_category_id'); }
    if (!expense_query($db,'SELECT expense_category_id FROM expense_categories WHERE business_id=? AND expense_category_id=? AND is_active=1 FOR UPDATE',[$user['business_id'],$category])->fetchColumn()) { throw new ExpenseValidation('Choose an active category belonging to your business.','expense_category_id'); }
    try { $method=sale_id(sale_text($input,'payment_method_id')); } catch (InvalidArgumentException $exception) { throw new ExpenseValidation('Choose a payment method.','payment_method_id'); }
    if (!expense_query($db,'SELECT payment_method_id FROM payment_methods WHERE payment_method_id=? AND is_active=1 FOR UPDATE',[$method])->fetchColumn()) { throw new ExpenseValidation('Choose an active payment method.','payment_method_id'); }
    $treatment=sale_text($input,'expense_treatment','operating');
    if (!in_array($treatment,['operating','inventory_purchase','other_cash_outflow'],true)) { throw new ExpenseValidation('Choose a valid expense type.','expense_treatment'); }
    $notes=sale_text($input,'notes'); if (mb_strlen($notes)>4000) { throw new ExpenseValidation('Notes may contain up to 4,000 characters.','notes'); }
    return ['category'=>$category,'method'=>$method,'description'=>$description,'amount'=>$amount,'date'=>$date,'treatment'=>$treatment,'notes'=>$notes];
}
function expense_audit(PDO $db, array $user, int $id, string $action, array $before, array $after, string $entity='expense'): void
{
    sale_audit($db,$user,$id,$action,$before,$after,$entity);
}
function expense_replay(PDO $db, array $user, string $action, string $key): ?int
{
    if (!preg_match('/\A[a-f0-9]{64}\z/',$key)) { throw new ExpenseValidation('Invalid submission key. Reopen the form.'); }
    $id=expense_query($db,"SELECT entity_id FROM activity_logs WHERE business_id=? AND actor_user_id=? AND action_code=? AND JSON_UNQUOTE(JSON_EXTRACT(after_data,'$.request_key'))=? LIMIT 1",[$user['business_id'],$user['user_id'],$action,$key])->fetchColumn();
    return $id===false?null:(int)$id;
}
function expense_create(PDO $db, array $user, array $input, string $key, ?array $receipt=null, array &$stored=[]): int
{
    sale_lock_business($db,$user);
    if ($id=expense_replay($db,$user,'EXPENSE_SUBMITTED',$key)) { return $id; }
    $data=expense_validate($db,$user,$input);
    $recurringValue=sale_text($input,'recurring_expense_id'); $recurring=null; $due=null;
    if ($recurringValue!=='') {
        $recurring=sale_id($recurringValue); $schedule=expense_schedule_read($db,(int)$user['business_id'],$recurring,true);
        $due=sale_text($input,'recurrence_due_on');
        if (!$schedule['is_active']) { throw new ExpenseValidation('This schedule is paused.'); }
        // A second form for the same occurrence safely reopens its original expense.
        $original=expense_query($db,'SELECT expense_id FROM expenses WHERE business_id=? AND recurring_expense_id=? AND recurrence_due_on=?',[$user['business_id'],$recurring,$due])->fetchColumn();
        if ($original) { return (int)$original; }
        if ($due!==$schedule['next_due_date'] || $due>date('Y-m-d') || ($schedule['end_date'] && $due>$schedule['end_date'])) { throw new ExpenseValidation('This scheduled occurrence is unavailable or not yet due.'); }
        foreach (['category'=>'expense_category_id','method'=>'payment_method_id','amount'=>'amount','treatment'=>'expense_treatment'] as $field=>$column) {
            if ((string)$data[$field] !== (string)$schedule[$column]) { throw new ExpenseValidation('The schedule changed. Reopen its payment form.'); }
        }
    }
    expense_query($db,'INSERT INTO expenses (business_id,expense_category_id,payment_method_id,entered_by,description,amount,expense_date,expense_treatment,notes,recurring_expense_id,recurrence_due_on) VALUES (?,?,?,?,?,?,?,?,?,?,?)',[$user['business_id'],$data['category'],$data['method'],$user['user_id'],$data['description'],$data['amount'],$data['date'],$data['treatment'],$data['notes'],$recurring,$due]);
    $id=(int)$db->lastInsertId();
    if ($receipt) { expense_attach_receipt($db,$user,$id,$receipt,$stored); }
    if ($recurring) { $next=expense_next_due($schedule); expense_query($db,'UPDATE recurring_expenses SET next_due_date=?,is_active=? WHERE business_id=? AND recurring_expense_id=?',[$next,(!$schedule['end_date']||$next<=$schedule['end_date'])?1:0,$user['business_id'],$recurring]); }
    expense_audit($db,$user,$id,'EXPENSE_SUBMITTED',[],['request_key'=>$key,'data'=>$data,'recurring_expense_id'=>$recurring,'recurrence_due_on'=>$due]);
    return $id;
}
function expense_edit(PDO $db, array $user, int $id, array $input, string $key, ?array $receipt=null, array &$stored=[]): void
{
    sale_lock_business($db,$user);
    if ($replay=expense_replay($db,$user,'EXPENSE_EDITED',$key)) { if ($replay!==$id) { throw new ExpenseValidation('Invalid edit request.'); } return; }
    $row=expense_read($db,(int)$user['business_id'],$id,true); expense_check_revision($row,$input);
    if ($row['expense_status']==='void') { throw new ExpenseValidation('Voided expenses cannot be edited.'); }
    foreach (['amount','expense_category_id','payment_method_id','expense_date','expense_treatment','recurring_expense_id','recurrence_due_on'] as $field) {
        if (isset($input[$field]) && sale_text($input,$field)!==(string)($row[$field]??'')) { throw new ExpenseValidation('Financial fields are finalized. Void and recreate the expense to correct them.',$field); }
    }
    $description=sale_text($input,'description'); $notes=sale_text($input,'notes');
    if ($description==='' || mb_strlen($description)>255) { throw new ExpenseValidation('Enter a description of 1 to 255 characters.','description'); }
    if (mb_strlen($notes)>4000) { throw new ExpenseValidation('Notes may contain up to 4,000 characters.','notes'); }
    expense_query($db,'UPDATE expenses SET description=?,notes=? WHERE business_id=? AND expense_id=?',[$description,$notes,$user['business_id'],$id]);
    if ($receipt) { expense_attach_receipt($db,$user,$id,$receipt,$stored); }
    expense_audit($db,$user,$id,'EXPENSE_EDITED',['description'=>$row['description'],'notes'=>$row['notes'],'receipts'=>$row['receipts']],['request_key'=>$key,'description'=>$description,'notes'=>$notes]);
}
function expense_void(PDO $db, array $user, int $id, array $input, string $key): void
{
    sale_lock_business($db,$user);
    if ($replay=expense_replay($db,$user,'EXPENSE_VOID_CONFIRMED',$key)) { if ($replay!==$id) { throw new ExpenseValidation('Invalid void request.'); } return; }
    $row=expense_read($db,(int)$user['business_id'],$id,true); expense_check_revision($row,$input);
    if ($row['expense_status']==='void') { throw new ExpenseValidation('This expense is already voided.'); }
    $reason=sale_text($input,'void_reason'); if ($reason==='' || mb_strlen($reason)>255) { throw new ExpenseValidation('Provide a reason of 1 to 255 characters.','void_reason'); }
    expense_query($db,"UPDATE expenses SET expense_status='void',void_reason=?,voided_by=?,voided_at=NOW() WHERE business_id=? AND expense_id=?",[$reason,$user['user_id'],$user['business_id'],$id]);
    expense_audit($db,$user,$id,'EXPENSE_VOID_CONFIRMED',['amount'=>$row['amount'],'status'=>'posted'],['request_key'=>$key,'reason'=>$reason,'status'=>'void']);
}

function expense_validate_image(string $path, string $name): array
{
    $size=is_file($path)?filesize($path):0;
    if (!$size || $size>EXPENSE_RECEIPT_LIMIT) { throw new ExpenseValidation('Choose a receipt image up to 2 MB.','receipt'); }
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($path); $image=@getimagesize($path);
    if (!in_array($mime,['image/jpeg','image/png','image/webp'],true) || !$image || ($image['mime']??'')!==$mime || $image[0]<1 || $image[1]<1 || $image[0]*$image[1]>16000000) { throw new ExpenseValidation('Choose a valid JPG, PNG or WebP image, at most 16 million pixels.','receipt'); }
    $name=mb_substr(str_replace(["\r","\n","\0"],'',basename(str_replace('\\','/',$name))),0,255);
    return ['path'=>$path,'name'=>$name?:'receipt','mime'=>$mime,'size'=>$size];
}
function expense_uploaded_receipt(array $files): ?array
{
    if (!isset($files['receipt'])) { return null; }
    $file=$files['receipt'];
    if (!is_array($file) || !isset($file['error']) || !is_int($file['error'])) { throw new ExpenseValidation('Invalid receipt upload.','receipt'); }
    if ($file['error']===UPLOAD_ERR_NO_FILE) { return null; }
    if ($file['error']!==UPLOAD_ERR_OK) { throw new ExpenseValidation('The receipt could not upload. Choose an image up to 2 MB.','receipt'); }
    if (!is_string($file['tmp_name']??null) || !is_string($file['name']??null) || !is_uploaded_file($file['tmp_name'])) { throw new ExpenseValidation('Invalid receipt upload.','receipt'); }
    return expense_validate_image($file['tmp_name'],$file['name']);
}
function expense_attach_receipt(PDO $db, array $user, int $id, array $file, array &$stored): void
{
    $key='receipts/'.bin2hex(random_bytes(32)).'.bin'; $path=__DIR__.'/../uploads/'.$key;
    // Track the destination before moving so a failed/partial write is cleaned up.
    $stored[]=$path;
    // CLI permits validated integration fixtures; HTTP accepts only PHP uploads.
    $moved=PHP_SAPI==='cli'?copy($file['path'],$path):move_uploaded_file($file['path'],$path);
    if (!$moved) { throw new RuntimeException('Receipt storage unavailable.'); }
    expense_query($db,'INSERT INTO expense_receipts (expense_id,business_id,uploaded_by,file_storage_key,original_filename,mime_type,file_size_bytes) VALUES (?,?,?,?,?,?,?)',[$id,$user['business_id'],$user['user_id'],$key,$file['name'],$file['mime'],$file['size']]);
    expense_audit($db,$user,$id,'EXPENSE_RECEIPT_ADDED',[],['receipt_id'=>(int)$db->lastInsertId(),'filename'=>$file['name']]);
}
// Only pass the new destinations recorded by expense_attach_receipt(), never
// existing attachment paths. The caller rolls back metadata before cleanup.
function expense_discard_new_receipts(array $stored): void
{
    foreach ($stored as $path) { if (is_file($path)) { unlink($path); } }
}
function expense_receipt(PDO $db, int $business, int $id): array
{
    $file=expense_query($db,'SELECT r.* FROM expense_receipts r JOIN expenses e ON e.expense_id=r.expense_id AND e.business_id=r.business_id WHERE r.business_id=? AND r.receipt_id=?',[$business,$id])->fetch();
    if (!$file || !preg_match('/\Areceipts\/[a-f0-9]{64}\.bin\z/',$file['file_storage_key'])) { throw new ExpenseNotFound('Receipt not found.'); }
    $root=realpath(__DIR__.'/../uploads/receipts'); $path=realpath(__DIR__.'/../uploads/'.$file['file_storage_key']);
    if (!$root || !$path || dirname($path)!==$root || !is_file($path) || !is_readable($path) || filesize($path)!==(int)$file['file_size_bytes'] || !in_array($file['mime_type'],['image/jpeg','image/png','image/webp'],true)) { throw new ExpenseNotFound('Receipt not found.'); }
    $file['path']=$path; return $file;
}
function expense_schedule_read(PDO $db, int $business, int $id, bool $lock=false): array
{
    $row=expense_query($db,'SELECT * FROM recurring_expenses WHERE business_id=? AND recurring_expense_id=?'.($lock?' FOR UPDATE':''),[$business,$id])->fetch();
    if (!$row) { throw new ExpenseNotFound('Schedule not found.'); } return $row;
}
function expense_next_due(array $schedule): string
{
    $start=new DateTimeImmutable($schedule['start_date']); $due=new DateTimeImmutable($schedule['next_due_date']); $interval=(int)$schedule['repeat_every'];
    if (in_array($schedule['frequency'],['daily','weekly'],true)) { return $due->modify('+'.$interval.' '.($schedule['frequency']==='daily'?'days':'weeks'))->format('Y-m-d'); }
    $months=((int)$due->format('Y')-(int)$start->format('Y'))*12+(int)$due->format('n')-(int)$start->format('n')+$interval*($schedule['frequency']==='yearly'?12:1);
    $month=$start->modify('first day of this month')->modify('+'.$months.' months');
    return $month->setDate((int)$month->format('Y'),(int)$month->format('n'),min((int)$start->format('j'),(int)$month->format('t')))->format('Y-m-d');
}
function expense_schedule_create(PDO $db, array $user, array $input, string $key): int
{
    sale_lock_business($db,$user);
    if ($id=expense_replay($db,$user,'EXPENSE_SCHEDULE_CREATED',$key)) { return $id; }
    // Templates may start in the future; validate their shared fields as of today.
    $data=expense_validate($db,$user,array_replace($input,['expense_date'=>date('Y-m-d')]));
    $frequency=sale_text($input,'frequency','monthly'); $interval=sale_text($input,'repeat_every','1');
    if (!in_array($frequency,['daily','weekly','monthly','yearly'],true) || !ctype_digit($interval) || strlen($interval)>3 || (int)$interval<1 || (int)$interval>365) { throw new ExpenseValidation('Choose a valid frequency and interval from 1 to 365.','repeat_every'); }
    $start=sale_text($input,'start_date'); $end=sale_text($input,'end_date');
    if (!dashboard_date($start) || $start<'1000-01-01') { throw new ExpenseValidation('Choose a valid schedule start date.','start_date'); }
    if ($end!=='' && (!dashboard_date($end) || $end<$start)) { throw new ExpenseValidation('End date must be on or after the start date.','end_date'); }
    expense_query($db,'INSERT INTO recurring_expenses (business_id,expense_category_id,payment_method_id,description,amount,expense_treatment,frequency,repeat_every,start_date,next_due_date,end_date) VALUES (?,?,?,?,?,?,?,?,?,?,?)',[$user['business_id'],$data['category'],$data['method'],$data['description'],$data['amount'],$data['treatment'],$frequency,(int)$interval,$start,$start,$end?:null]);
    $id=(int)$db->lastInsertId(); expense_audit($db,$user,$id,'EXPENSE_SCHEDULE_CREATED',[],['request_key'=>$key,'data'=>$data,'frequency'=>$frequency,'interval'=>$interval,'start'=>$start,'end'=>$end],'recurring_expense'); return $id;
}
function expense_schedule_toggle(PDO $db, array $user, int $id, array $input, string $key): void
{
    sale_lock_business($db,$user);
    if ($replay=expense_replay($db,$user,'EXPENSE_SCHEDULE_TOGGLED',$key)) { if ($replay!==$id) { throw new ExpenseValidation('Invalid schedule request.'); } return; }
    $row=expense_schedule_read($db,(int)$user['business_id'],$id,true);
    if (!hash_equals(hash('sha256',json_encode($row,JSON_THROW_ON_ERROR)),sale_text($input,'revision'))) { throw new ExpenseValidation('The schedule changed. Reopen it.'); }
    $active=$row['is_active']?0:1;
    if ($active && $row['end_date'] && $row['next_due_date']>$row['end_date']) { throw new ExpenseValidation('This schedule has reached its end date. Create a new schedule to continue.'); }
    expense_query($db,'UPDATE recurring_expenses SET is_active=? WHERE business_id=? AND recurring_expense_id=?',[$active,$user['business_id'],$id]);
    expense_audit($db,$user,$id,'EXPENSE_SCHEDULE_TOGGLED',['is_active'=>$row['is_active']],['request_key'=>$key,'is_active'=>$active],'recurring_expense');
}

function expense_category_create(PDO $db, array $user, array $input, string $key): int
{
    sale_lock_business($db,$user);
    if ($id=expense_replay($db,$user,'EXPENSE_CATEGORY_SAVED',$key)) { return $id; }
    $name=sale_text($input,'category_name');
    if ($name==='' || mb_strlen($name)>100) { throw new ExpenseValidation('Enter a category name of 1 to 100 characters.','category_name'); }
    $existing=expense_query($db,'SELECT * FROM expense_categories WHERE business_id=? AND category_name=? FOR UPDATE',[$user['business_id'],$name])->fetch();
    if ($existing) { $id=(int)$existing['expense_category_id']; expense_query($db,'UPDATE expense_categories SET is_active=1 WHERE business_id=? AND expense_category_id=?',[$user['business_id'],$id]); }
    else { expense_query($db,'INSERT INTO expense_categories (business_id,category_name) VALUES (?,?)',[$user['business_id'],$name]); $id=(int)$db->lastInsertId(); }
    expense_audit($db,$user,$id,'EXPENSE_CATEGORY_SAVED',$existing?:[],['request_key'=>$key,'category_name'=>$name,'is_active'=>1],'expense_category'); return $id;
}
