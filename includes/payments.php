<?php
declare(strict_types=1);
require_once __DIR__.'/utang.php';

const PAYMENT_TYPES=['full'=>'Full Payment','downpayment'=>'Downpayment','partial'=>'Partial Payment','final'=>'Final Payment'];

function payment_filters(array $input): array
{
    $f=[];
    foreach (['tab'=>'history','q'=>'','method'=>'all','type'=>'all','state'=>'outstanding','customer'=>'','sort'=>'newest','page'=>'1'] as $key=>$default) { $f[$key]=sale_text($input,$key,$default); }
    if (!in_array($f['tab'],['history','balances'],true) || !mb_check_encoding($f['q'],'UTF-8') || mb_strlen($f['q'])>100 ||
        !in_array($f['method'],['all','cash','wallet','bank'],true) || !in_array($f['type'],array_merge(['all'],array_keys(PAYMENT_TYPES)),true) ||
        !in_array($f['state'],['outstanding','unpaid','partial','overdue','paid','all'],true) || !in_array($f['sort'],['newest','oldest','due'],true) ||
        !ctype_digit($f['page']) || strlen($f['page'])>6 || (int)$f['page']<1) { throw new InvalidArgumentException('Choose valid payment search and filters.'); }
    if ($f['customer']!=='') { $f['customer']=(string)sale_id($f['customer']); }
    $f['period']=dashboard_period($input); $f['page']=(int)$f['page'];
    return $f;
}
function payment_params(array $f): array
{
    $period=$f['period']; unset($f['period']);
    return $f+['period'=>$period['choice'],'start'=>$period['start']->format('Y-m-d'),'end'=>$period['end']->format('Y-m-d')];
}
function payment_url(array $f, array $changes=[]): string
{
    return app_url('user/payments.php').'?'.http_build_query(array_replace(payment_params($f),$changes));
}
function payment_sale_read(PDO $db, int $business, int $id): array
{
    $sale=sale_read($db,$business,$id);
    if ($sale['sale_status']!=='posted') { throw new SaleNotFound('Payment transaction not found or no longer available.'); }
    $sale['credit_notes']=customer_query($db,'SELECT notes FROM customer_credits WHERE sale_id=?',[$id])->fetchColumn()?:null;
    return $sale;
}
// Classification follows the immutable recording sequence, not user labels or
// backdated paid_at values. The first partial collection is the only downpayment.
function payment_classify(array $sale): array
{
    $sequence=$sale['payments']; usort($sequence,fn($a,$b)=>(int)$a['payment_id']<=>(int)$b['payment_id']);
    $before='0.00'; $collections=0; $types=[];
    foreach ($sequence as $p) {
        if ($p['payment_kind']==='refund') { $before=bcsub($before,$p['amount'],2); continue; }
        $settles=bccomp(bcadd($before,$p['amount'],2),$sale['net_sale'],2)>=0;
        $types[$p['payment_id']]=$settles?($collections===0?'full':'final'):($collections===0?'downpayment':'partial');
        $before=bcadd($before,$p['amount'],2); $collections++;
    }
    return $types;
}
function payment_type_sql(): string
{
    return "CASE WHEN (SELECT COALESCE(SUM(CASE b.payment_kind WHEN 'collection' THEN b.amount ELSE -b.amount END),0) FROM sale_payments b WHERE b.business_id=p.business_id AND b.sale_id=p.sale_id AND b.payment_id<p.payment_id)+p.amount>=f.net_sale
        THEN CASE WHEN EXISTS(SELECT 1 FROM sale_payments b WHERE b.business_id=p.business_id AND b.sale_id=p.sale_id AND b.payment_kind='collection' AND b.payment_id<p.payment_id) THEN 'final' ELSE 'full' END
        ELSE CASE WHEN EXISTS(SELECT 1 FROM sale_payments b WHERE b.business_id=p.business_id AND b.sale_id=p.sale_id AND b.payment_kind='collection' AND b.payment_id<p.payment_id) THEN 'partial' ELSE 'downpayment' END END";
}
function payment_history_source(): string
{
    return " FROM sale_payments p JOIN v_sale_financials f ON f.sale_id=p.sale_id AND f.business_id=p.business_id
        JOIN payment_methods m ON m.payment_method_id=p.payment_method_id
        LEFT JOIN customers c ON c.customer_id=f.customer_id AND c.business_id=f.business_id
        LEFT JOIN (SELECT reverses_payment_id,sale_id,business_id,SUM(amount) AS refunded FROM sale_payments WHERE payment_kind='refund' GROUP BY reverses_payment_id,sale_id,business_id) r ON r.reverses_payment_id=p.payment_id AND r.sale_id=p.sale_id AND r.business_id=p.business_id
        WHERE p.business_id=? AND f.sale_status='posted' AND p.payment_kind='collection' AND p.amount>COALESCE(r.refunded,0)";
}
function payment_balance_source(): string
{
    return " FROM v_sale_financials f LEFT JOIN customers c ON c.customer_id=f.customer_id AND c.business_id=f.business_id LEFT JOIN customer_credits cr ON cr.sale_id=f.sale_id WHERE f.business_id=? AND f.sale_status='posted'";
}
function payment_list(PDO $db, int $business, array $f): array
{
    $summary=customer_query($db,"SELECT COALESCE(SUM(balance_due),0.00) AS outstanding,COALESCE(SUM(balance_due>0 AND total_paid>0),0) AS partial,COALESCE(SUM(balance_due=0),0) AS paid FROM v_sale_financials WHERE business_id=? AND sale_status='posted'",[$business])->fetch();
    $source=payment_history_source().' AND p.paid_at>=? AND p.paid_at<?';
    $values=[$business,$f['period']['start']->format('Y-m-d'),$f['period']['exclusive_end']];
    $collections=payment_collection_totals($db,$business,$f['period']);
    $breakdown=$collections['methods']; $summary['collected']=$collections['total'];
    if ($f['tab']==='history') {
        if ($f['q']!=='') { $source.=' AND (LOCATE(?,COALESCE(c.customer_name,\'Walk-in Customer\'))>0 OR LOCATE(?,f.sale_number)>0)'; array_push($values,$f['q'],$f['q']); }
        if ($f['customer']!=='') { $source.=' AND f.customer_id=?'; $values[]=$f['customer']; }
        if ($f['method']!=='all') { $source.=' AND '.match($f['method']) { 'cash'=>"m.method_code='cash'",'wallet'=>"m.method_code IN ('gcash','maya','wallet_other')",'bank'=>"m.method_code='bank'" }; }
        if ($f['type']!=='all') { $source.=' AND ('.payment_type_sql().')=?'; $values[]=$f['type']; }
        $count=(int)customer_query($db,'SELECT COUNT(*)'.$source,$values)->fetchColumn();
        $pages=max(1,(int)ceil($count/15)); $page=min($f['page'],$pages); $offset=($page-1)*15;
        $order=$f['sort']==='oldest'?'p.paid_at,p.payment_id':'p.paid_at DESC,p.payment_id DESC';
        $rows=customer_query($db,'SELECT p.*,m.method_code,m.method_name,f.sale_number,c.customer_name,p.amount-COALESCE(r.refunded,0) AS received_amount,'.payment_type_sql().' AS payment_type'.$source." ORDER BY $order LIMIT 15 OFFSET $offset",$values)->fetchAll();
    } else {
        $source=payment_balance_source();
        $values=[$business];
        $states=['outstanding'=>'f.balance_due>0','unpaid'=>'f.balance_due>0 AND f.total_paid=0','partial'=>'f.balance_due>0 AND f.total_paid>0','paid'=>'f.balance_due=0','overdue'=>'f.balance_due>0 AND cr.due_date<CURRENT_DATE()'];
        if ($f['state']!=='all') { $source.=' AND '.$states[$f['state']]; }
        if ($f['q']!=='') { $source.=' AND (LOCATE(?,COALESCE(c.customer_name,\'Walk-in Customer\'))>0 OR LOCATE(?,f.sale_number)>0)'; array_push($values,$f['q'],$f['q']); }
        if ($f['customer']!=='') { $source.=' AND f.customer_id=?'; $values[]=$f['customer']; }
        $count=(int)customer_query($db,'SELECT COUNT(*)'.$source,$values)->fetchColumn();
        $pages=max(1,(int)ceil($count/15)); $page=min($f['page'],$pages); $offset=($page-1)*15;
        $order=match($f['sort']) { 'oldest'=>'f.sold_at,f.sale_id','due'=>'cr.due_date IS NULL,cr.due_date,f.sold_at,f.sale_id',default=>'f.sold_at DESC,f.sale_id DESC' };
        $rows=customer_query($db,'SELECT f.*,c.customer_name,cr.due_date'.$source." ORDER BY $order LIMIT 15 OFFSET $offset",$values)->fetchAll();
    }
    $customers=customer_query($db,'SELECT customer_id,customer_name,is_active FROM customers WHERE business_id=? ORDER BY customer_name',[$business])->fetchAll();
    return compact('summary','breakdown','rows','count','page','pages','customers');
}
function payment_collection_totals(PDO $db, int $business, array $period): array
{
    $rows=customer_query($db,'SELECT m.method_code,SUM(p.amount-COALESCE(r.refunded,0)) AS amount'.payment_history_source().' AND p.paid_at>=? AND p.paid_at<? GROUP BY m.method_code',[$business,$period['start']->format('Y-m-d'),$period['exclusive_end']])->fetchAll();
    $methods=['cash'=>'0.00','wallet'=>'0.00','bank'=>'0.00','other'=>'0.00'];
    foreach ($rows as $row) { $category=payment_category($row['method_code']); $methods[$category]=bcadd($methods[$category],$row['amount'],2); }
    return ['total'=>array_reduce($methods,fn($sum,$value)=>bcadd($sum,$value,2),'0.00'),'methods'=>$methods];
}
function payment_read(PDO $db, int $business, int $id): array
{
    $payment=customer_query($db,'SELECT p.*,m.method_name,m.method_code,u.full_name AS recorder FROM sale_payments p JOIN payment_methods m ON m.payment_method_id=p.payment_method_id JOIN users u ON u.user_id=p.entered_by WHERE p.business_id=? AND p.payment_id=?',[$business,$id])->fetch();
    if (!$payment) { throw new SaleNotFound('Payment not found.'); }
    $sale=sale_read($db,$business,(int)$payment['sale_id']);
    $payment['payment_type']=payment_classify($sale)[$id]??null;
    $payment['refunded']='0.00';
    foreach ($sale['payments'] as $p) { if ($p['payment_kind']==='refund' && (int)$p['reverses_payment_id']===$id) { $payment['refunded']=bcadd($payment['refunded'],$p['amount'],2); } }
    $payment['received_amount']=bcsub($payment['amount'],$payment['refunded'],2);
    return ['payment'=>$payment,'sale'=>$sale];
}
function payment_collect(PDO $db, array $user, int $id, array $input, string $key): array
{
    sale_lock_business($db,$user);
    if (!preg_match('/\A[a-f0-9]{64}\z/',$key)) { throw new UtangValidation('Invalid submission. Reopen the payment form.'); }
    try { $method=payment_method_data($db,$input); } catch (InvalidArgumentException $e) { throw new UtangValidation($e->getMessage(),str_contains($e->getMessage(),'name')?'provider_name':'payment_category'); }
    $normalized=array_replace($input,['payment_method_id'=>(string)$method['payment_method_id']]);
    $data=utang_payment_data($normalized)+['provider_name'=>$method['provider_name']]; $durable='utang:'.$key;
    $previous=customer_query($db,'SELECT * FROM sale_payments WHERE business_id=? AND idempotency_key=? FOR UPDATE',[$user['business_id'],$durable])->fetch();
    if ($previous) {
        if ((int)$previous['sale_id']!==$id || (int)$previous['entered_by']!==(int)$user['user_id'] || $previous['payment_kind']!=='collection') { throw new UtangValidation('Invalid payment submission.'); }
        foreach ($data as $field=>$value) { if ((string)$previous[$field] !== (string)$value) { throw new UtangValidation('This submission was already saved with different details. Reopen the payment form.'); } }
        return ['payment_id'=>(int)$previous['payment_id'],'replayed'=>true];
    }
    $parent=customer_query($db,'SELECT sold_at,sale_status FROM sales WHERE business_id=? AND sale_id=? FOR UPDATE',[$user['business_id'],$id])->fetch();
    if (!$parent || $parent['sale_status']!=='posted') { throw new SaleNotFound('Transaction not found or no longer available for payment.'); }
    if ($data['paid_at']<$parent['sold_at']) { throw new UtangValidation('Payment date cannot be earlier than the sale.','paid_at'); }
    $sale=sale_read($db,(int)$user['business_id'],$id);
    $net='0.00';
    $payments=customer_query($db,'SELECT payment_kind,amount FROM sale_payments WHERE business_id=? AND sale_id=? FOR UPDATE',[$user['business_id'],$id])->fetchAll();
    foreach ($payments as $p) { $net=$p['payment_kind']==='collection'?bcadd($net,$p['amount'],2):bcsub($net,$p['amount'],2); }
    $balance=bcsub($sale['net_sale'],$net,2);
    if (bccomp($balance,'0',2)<=0) { throw new UtangValidation('This sale is already fully paid. Its history is still available.','amount'); }
    if (bccomp($data['amount'],$balance,2)>0) { throw new UtangValidation('Payment cannot exceed the remaining balance of '.sale_money($balance).'.','amount'); }
    customer_query($db,"INSERT INTO sale_payments (sale_id,business_id,payment_method_id,provider_name,entered_by,payment_kind,amount,paid_at,reference_number,idempotency_key,notes) VALUES (?,?,?,?,?,'collection',?,?,?,?,?)",[$id,$user['business_id'],$data['payment_method_id'],$data['provider_name'],$user['user_id'],$data['amount'],$data['paid_at'],$data['reference_number'],$durable,$data['notes']]);
    return ['payment_id'=>(int)$db->lastInsertId(),'replayed'=>false];
}
