<?php
declare(strict_types=1);
require_once __DIR__.'/customers.php';

class UtangValidation extends InvalidArgumentException
{
    public function __construct(string $message, public string $field='') { parent::__construct($message); }
}
function utang_source(): string
{
    return " FROM v_sale_financials f JOIN customer_credits cr ON cr.sale_id=f.sale_id JOIN customers c ON c.customer_id=f.customer_id AND c.business_id=f.business_id WHERE f.business_id=? AND f.sale_type='credit' AND f.sale_status='posted'";
}
function utang_filters(array $input): array
{
    $f=[]; foreach (['q'=>'','state'=>'outstanding','customer'=>'','start'=>'','end'=>'','sort'=>'due','page'=>'1'] as $key=>$default) { $f[$key]=sale_text($input,$key,$default); }
    if (!mb_check_encoding($f['q'],'UTF-8') || mb_strlen($f['q'])>100 || !in_array($f['state'],['outstanding','all','unpaid','partial','overdue','paid'],true) || !in_array($f['sort'],['due','newest','oldest'],true) || !ctype_digit($f['page']) || strlen($f['page'])>6 || (int)$f['page']<1) { throw new UtangValidation('Choose valid search and filters.'); }
    if ($f['customer']!=='') { $f['customer']=(string)sale_id($f['customer']); }
    $f['period']=dashboard_period($f['start']!=='' || $f['end']!==''?['period'=>'custom','start'=>$f['start'],'end'=>$f['end']]:['period'=>'month']);
    $f['page']=(int)$f['page']; return $f;
}
function utang_params(array $filters): array { unset($filters['period']); return $filters; }
function utang_list(PDO $db, int $business, array $f): array
{
    $source=utang_source();
    $summary=customer_query($db,'SELECT COALESCE(SUM(f.balance_due),0.00) AS outstanding,COUNT(DISTINCT CASE WHEN f.balance_due>0 THEN f.customer_id END) AS customers,COALESCE(SUM(CASE WHEN cr.due_date<CURRENT_DATE() THEN f.balance_due ELSE 0 END),0.00) AS overdue'.$source,[$business])->fetch();
    // Payment dates define collections; table dates define the originating sales.
    // Net cash includes initial credit collections and subtracts actual refunds,
    // including a refunded sale later voided. It never becomes sales revenue.
    $summary['collected']=customer_query($db,"SELECT COALESCE(SUM(CASE p.payment_kind WHEN 'collection' THEN p.amount ELSE -p.amount END),0.00) FROM sale_payments p JOIN sales s ON s.sale_id=p.sale_id AND s.business_id=p.business_id JOIN customer_credits cr ON cr.sale_id=s.sale_id WHERE p.business_id=? AND s.sale_type='credit' AND s.sale_status IN ('posted','void') AND p.paid_at>=? AND p.paid_at<?",[$business,$f['period']['start']->format('Y-m-d'),$f['period']['exclusive_end']])->fetchColumn();
    $values=[$business];
    $states=['outstanding'=>'f.balance_due>0','unpaid'=>'f.balance_due>0 AND f.total_paid=0','partial'=>'f.balance_due>0 AND f.total_paid>0','overdue'=>'f.balance_due>0 AND cr.due_date<CURRENT_DATE()','paid'=>'f.balance_due=0'];
    if ($f['state']!=='all') { $source.=' AND '.$states[$f['state']]; }
    if ($f['q']!=='') { $source.=' AND (LOCATE(?,c.customer_name)>0 OR LOCATE(?,f.sale_number)>0)'; array_push($values,$f['q'],$f['q']); }
    if ($f['customer']!=='') { $source.=' AND c.customer_id=?'; $values[]=$f['customer']; }
    if ($f['start']!=='') { $source.=' AND f.sold_at>=? AND f.sold_at<?'; array_push($values,$f['start'],$f['period']['exclusive_end']); }
    $count=(int)customer_query($db,'SELECT COUNT(*)'.$source,$values)->fetchColumn(); $pages=max(1,(int)ceil($count/15)); $page=min($f['page'],$pages); $offset=($page-1)*15;
    $order=match($f['sort']) {'newest'=>'f.sold_at DESC,f.sale_id DESC','oldest'=>'f.sold_at,f.sale_id',default=>'(f.balance_due>0) DESC,cr.due_date IS NULL,cr.due_date,f.sold_at DESC,f.sale_id DESC'};
    $rows=customer_query($db,'SELECT f.*,cr.due_date,c.customer_name,c.is_active'.$source." ORDER BY $order LIMIT 15 OFFSET $offset",$values)->fetchAll();
    $customers=customer_query($db,'SELECT customer_id,customer_name,is_active FROM customers WHERE business_id=? ORDER BY customer_name',[$business])->fetchAll();
    return compact('summary','rows','customers','count','pages','page');
}
function utang_read(PDO $db, int $business, int $id): array
{
    if (!customer_query($db,"SELECT s.sale_id FROM sales s JOIN customer_credits cr ON cr.sale_id=s.sale_id WHERE s.business_id=? AND s.sale_id=? AND s.sale_type='credit' AND s.sale_status='posted'",[$business,$id])->fetchColumn()) { throw new SaleNotFound('Credit transaction not found.'); }
    $row=sale_read($db,$business,$id);
    $credit=customer_query($db,'SELECT cr.notes AS credit_notes,c.is_active FROM customer_credits cr JOIN sales s ON s.sale_id=cr.sale_id JOIN customers c ON c.customer_id=s.customer_id AND c.business_id=s.business_id WHERE s.business_id=? AND s.sale_id=?',[$business,$id])->fetch();
    return array_merge($row,$credit);
}
function utang_status(array $row): string { return bccomp($row['balance_due'],'0',2)===0?'Paid':(bccomp($row['total_paid'],'0',2)>0?'Partially Paid':'Unpaid'); }
function utang_overdue(array $row): bool { return bccomp($row['balance_due'],'0',2)>0 && $row['due_date']!==null && $row['due_date']<date('Y-m-d'); }
function utang_due_revision(?string $due): string { return hash('sha256',$due??'no due date'); }
function utang_lock_credit(PDO $db, array $user, int $id): array
{
    $row=customer_query($db,"SELECT s.sale_id,s.sold_at,s.sale_status,s.sale_type,cr.due_date FROM sales s JOIN customer_credits cr ON cr.sale_id=s.sale_id WHERE s.business_id=? AND s.sale_id=? FOR UPDATE",[$user['business_id'],$id])->fetch();
    if (!$row || $row['sale_status']!=='posted' || $row['sale_type']!=='credit') { throw new SaleNotFound('Credit transaction not found or no longer available.'); } return $row;
}
function utang_payment_data(array $input): array
{
    try { $amount=sale_decimal(sale_text($input,'amount'),2,'Payment amount'); } catch (InvalidArgumentException $e) { throw new UtangValidation('Enter a valid payment amount with up to two decimal places.','amount'); }
    if (bccomp($amount,'0',2)<=0) { throw new UtangValidation('Payment amount must be greater than zero.','amount'); }
    try { $method=sale_id(sale_text($input,'payment_method_id')); } catch (InvalidArgumentException $e) { throw new UtangValidation('Choose a payment method.','payment_method_id'); }
    $value=sale_text($input,'paid_at'); $format=strlen($value)===16?'Y-m-d\TH:i':'Y-m-d\TH:i:s';
    if (!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}(?::[0-9]{2})?\z/',$value)) { throw new UtangValidation('Enter a valid payment date and time.','paid_at'); }
    $date=DateTimeImmutable::createFromFormat('!'.$format,$value,new DateTimeZone('Asia/Manila'));
    if (!$date || $date->format($format)!==$value || (int)$date->format('Y')<1000 || $date>new DateTimeImmutable('now',new DateTimeZone('Asia/Manila'))) { throw new UtangValidation('Enter a valid payment date and time that is not in the future.','paid_at'); }
    $data=['amount'=>$amount,'payment_method_id'=>$method,'paid_at'=>$date->format('Y-m-d H:i:s')];
    foreach (['reference_number'=>100,'notes'=>4000] as $field=>$limit) { $text=sale_text($input,$field); if (!mb_check_encoding($text,'UTF-8') || mb_strlen($text)>$limit) { throw new UtangValidation('Keep '.str_replace('_',' ',$field).' within '.$limit.' characters.',$field); } $data[$field]=$text!==''?$text:null; }
    return $data;
}
function utang_collect(PDO $db, array $user, int $id, array $input, string $key): array
{
    require_once __DIR__.'/payments.php';
    return payment_collect($db,$user,$id,$input,$key);
}
function utang_edit_due(PDO $db, array $user, int $id, array $input, string $key): bool
{
    sale_lock_business($db,$user);
    if (!preg_match('/\A[a-f0-9]{64}\z/',$key)) { throw new UtangValidation('Invalid submission. Reopen the due-date form.'); }
    $replay=customer_query($db,"SELECT entity_id,JSON_UNQUOTE(JSON_EXTRACT(after_data,'$.due_date')) AS due_date FROM activity_logs WHERE business_id=? AND actor_user_id=? AND entity_type='credit' AND action_code='CREDIT_DUE_DATE_UPDATED' AND JSON_UNQUOTE(JSON_EXTRACT(after_data,'$.request_key'))=? LIMIT 1 FOR UPDATE",[$user['business_id'],$user['user_id'],$key])->fetch();
    if ($replay) { if ((int)$replay['entity_id']!==$id || ($replay['due_date']==='null'?'':$replay['due_date'])!==sale_text($input,'due_date')) { throw new UtangValidation('This due-date submission was already used.'); } return true; }
    $parent=utang_lock_credit($db,$user,$id);
    if (!hash_equals(utang_due_revision($parent['due_date']),sale_text($input,'revision'))) { throw new UtangValidation('The due date changed. Reopen this form before saving.'); }
    $value=sale_text($input,'due_date'); $date=$value!==''?dashboard_date($value):null;
    if ($value!=='' && (!$date || $value<substr($parent['sold_at'],0,10))) { throw new UtangValidation('Choose a valid due date on or after the sale, or leave it blank.','due_date'); }
    $due=$date?$date->format('Y-m-d'):null;
    customer_query($db,'UPDATE customer_credits cr JOIN sales s ON s.sale_id=cr.sale_id SET cr.due_date=? WHERE s.business_id=? AND s.sale_id=?',[$due,$user['business_id'],$id]);
    sale_audit($db,$user,$id,'CREDIT_DUE_DATE_UPDATED',['due_date'=>$parent['due_date']],['request_key'=>$key,'due_date'=>$due],'credit');
    return false;
}
