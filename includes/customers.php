<?php
declare(strict_types=1);
require_once __DIR__.'/sales.php';

class CustomerNotFound extends RuntimeException {}
class CustomerValidation extends InvalidArgumentException
{
    public function __construct(string $message, public string $field='', public bool $shared_phone_warning=false) { parent::__construct($message); }
}
function customer_query(PDO $db, string $sql, array $values=[]): PDOStatement
{
    $statement=$db->prepare($sql); $statement->execute($values); return $statement;
}
// Each sale is one row in this view; repayments never multiply purchase totals.
function customer_source(): string
{
    return " FROM customers c LEFT JOIN (
        SELECT f.customer_id,SUM(f.net_sale) AS purchases,SUM(f.total_paid) AS paid,COUNT(*) AS transactions,
            SUM(f.balance_due) AS outstanding,
            SUM(f.balance_due>0) AS unpaid_credits,
            SUM(f.balance_due>0 AND cr.due_date<CURRENT_DATE()) AS overdue_credits,
            MAX(GREATEST(f.sold_at,COALESCE((SELECT MAX(p.paid_at) FROM sale_payments p WHERE p.sale_id=f.sale_id AND p.business_id=f.business_id),f.sold_at))) AS last_activity_at
        FROM v_sale_financials f LEFT JOIN customer_credits cr ON cr.sale_id=f.sale_id
        WHERE f.business_id=? AND f.sale_status='posted' AND f.customer_id IS NOT NULL
        GROUP BY f.customer_id
    ) t ON t.customer_id=c.customer_id WHERE c.business_id=?";
}
function customer_columns(): string
{
    return 'c.*,COALESCE(t.purchases,0.00) AS purchases,COALESCE(t.paid,0.00) AS paid,COALESCE(t.transactions,0) AS transactions,COALESCE(t.outstanding,0.00) AS outstanding,COALESCE(t.unpaid_credits,0) AS unpaid_credits,COALESCE(t.overdue_credits,0) AS overdue_credits,GREATEST(c.updated_at,COALESCE(t.last_activity_at,c.created_at)) AS last_activity_at';
}
function customer_filters(array $input): array
{
    $filters=['q'=>sale_text($input,'q'),'balance'=>sale_text($input,'balance','all'),'state'=>sale_text($input,'state','active'),'sort'=>sale_text($input,'sort','name'),'page'=>sale_text($input,'page','1')];
    if (!mb_check_encoding($filters['q'],'UTF-8') || mb_strlen($filters['q'])>100 || !in_array($filters['balance'],['all','utang','clear','overdue'],true) || !in_array($filters['state'],['active','archived','all'],true) || !in_array($filters['sort'],['name','recent'],true) || !ctype_digit($filters['page']) || strlen($filters['page'])>6 || (int)$filters['page']<1) { throw new InvalidArgumentException('Choose valid customer search and filters.'); }
    $filters['page']=(int)$filters['page']; return $filters;
}
function customer_list(PDO $db, int $business, array $filters): array
{
    $source=customer_source(); $values=[$business,$business];
    $summary=customer_query($db,'SELECT COALESCE(SUM(c.is_active=1),0) AS active_customers,COALESCE(SUM(COALESCE(t.outstanding,0)>0),0) AS with_utang,COALESCE(SUM(t.outstanding),0.00) AS outstanding'.$source,$values)->fetch();
    if ($filters['state']!=='all') { $source.=' AND c.is_active=?'; $values[]=$filters['state']==='active'?1:0; }
    if ($filters['q']!=='') { $source.=' AND (LOCATE(?,c.customer_name)>0 OR LOCATE(?,COALESCE(c.phone,\'\'))>0)'; array_push($values,$filters['q'],customer_phone($filters['q'])??$filters['q']); }
    $conditions=['utang'=>'COALESCE(t.outstanding,0)>0','clear'=>'COALESCE(t.outstanding,0)=0','overdue'=>'COALESCE(t.overdue_credits,0)>0'];
    if ($filters['balance']!=='all') { $source.=' AND '.$conditions[$filters['balance']]; }
    $count=(int)customer_query($db,'SELECT COUNT(*)'.$source,$values)->fetchColumn();
    $pages=max(1,(int)ceil($count/15)); $page=min($filters['page'],$pages); $offset=($page-1)*15;
    $order=$filters['sort']==='name'?'c.customer_name,c.customer_id':'last_activity_at DESC,c.customer_id DESC';
    $rows=customer_query($db,'SELECT '.customer_columns().$source." ORDER BY $order LIMIT 15 OFFSET $offset",$values)->fetchAll();
    return ['summary'=>$summary,'rows'=>$rows,'count'=>$count,'pages'=>$pages,'page'=>$page];
}
function customer_read(PDO $db, int $business, int $id, bool $lock=false): array
{
    if ($lock && !customer_query($db,'SELECT customer_id FROM customers WHERE business_id=? AND customer_id=? FOR UPDATE',[$business,$id])->fetchColumn()) { throw new CustomerNotFound('Customer not found.'); }
    $row=customer_query($db,'SELECT '.customer_columns().customer_source().' AND c.customer_id=?',[$business,$business,$id])->fetch();
    if (!$row) { throw new CustomerNotFound('Customer not found.'); } return $row;
}
function customer_history(PDO $db, int $business, int $id): array
{
    customer_read($db,$business,$id);
    $purchases=customer_query($db,'SELECT f.*,cr.due_date FROM v_sale_financials f LEFT JOIN customer_credits cr ON cr.sale_id=f.sale_id WHERE f.business_id=? AND f.customer_id=? AND f.sale_status=\'posted\' ORDER BY f.sold_at DESC,f.sale_id DESC LIMIT 10',[$business,$id])->fetchAll();
    $credits=customer_query($db,'SELECT f.*,cr.due_date FROM v_sale_financials f LEFT JOIN customer_credits cr ON cr.sale_id=f.sale_id WHERE f.business_id=? AND f.customer_id=? AND f.sale_status=\'posted\' AND (f.sale_type=\'credit\' OR f.balance_due>0) ORDER BY (f.balance_due>0) DESC,cr.due_date IS NULL,cr.due_date,f.sold_at DESC LIMIT 10',[$business,$id])->fetchAll();
    $payments=customer_query($db,"SELECT p.*,m.method_name,m.method_code,s.sale_number FROM sale_payments p JOIN sales s ON s.sale_id=p.sale_id AND s.business_id=p.business_id JOIN payment_methods m ON m.payment_method_id=p.payment_method_id WHERE p.business_id=? AND s.customer_id=? AND s.sale_status='posted' ORDER BY p.paid_at DESC,p.payment_id DESC LIMIT 10",[$business,$id])->fetchAll();
    return ['purchases'=>$purchases,'credits'=>$credits,'payments'=>$payments];
}
function customer_balance_label(array $customer): string
{
    return (int)$customer['overdue_credits']>0?'Overdue':(bccomp((string)$customer['outstanding'],'0',2)>0?'Has Outstanding Balance':'No Outstanding Balance');
}
function customer_revision(array $row): string
{
    return hash('sha256',json_encode(array_intersect_key($row,array_flip(['customer_id','business_id','customer_name','phone','email','address','notes','is_active','created_at','updated_at'])),JSON_THROW_ON_ERROR));
}
function customer_phone(string $phone): ?string
{
    $clean=preg_replace('/[\s()\-]/u','',$phone);
    if (preg_match('/\A09[0-9]{9}\z/',$clean)) { return $clean; }
    if (preg_match('/\A\+?639[0-9]{9}\z/',$clean)) { return '0'.substr(ltrim($clean,'+'),2); }
    return null;
}
function customer_validate(PDO $db, int $business, int $id, array $input, ?array $existing): array
{
    $data=[]; foreach (['customer_name','phone','email','address','notes'] as $field) { $data[$field]=sale_text($input,$field); if (!mb_check_encoding($data[$field],'UTF-8')) { throw new CustomerValidation('Enter valid text.',$field); } }
    if ($data['customer_name']==='' || mb_strlen($data['customer_name'])>150) { throw new CustomerValidation('Enter a customer name of 1 to 150 characters.','customer_name'); }
    if ($data['phone']!=='') {
        if (mb_strlen($data['phone'])>30 || !($phone=customer_phone($data['phone']))) { throw new CustomerValidation('Enter a Philippine mobile number such as 09171234567 or +639171234567.','phone'); }
        $data['phone']=$phone;
        if (!$existing || customer_phone((string)($existing['phone']??''))!==$phone) {
            $phones=customer_query($db,'SELECT customer_name,phone,is_active FROM customers WHERE business_id=? AND customer_id<>? AND phone IS NOT NULL',[$business,$id])->fetchAll();
            foreach ($phones as $other) {
                if (customer_phone($other['phone'])===$phone && sale_text($input,'allow_shared_phone')!=='1') { throw new CustomerValidation('This number is already used by '.$other['customer_name'].($other['is_active']?'':' (archived)').'. Confirm a shared number only if these are different people.','phone',true); }
            }
        }
    }
    if ($data['email']!=='' && (strlen($data['email'])>191 || !filter_var($data['email'],FILTER_VALIDATE_EMAIL))) { throw new CustomerValidation('Enter a valid email address.','email'); }
    if (mb_strlen($data['address'])>255) { throw new CustomerValidation('Keep the address within 255 characters.','address'); }
    if (mb_strlen($data['notes'])>4000) { throw new CustomerValidation('Keep notes within 4,000 characters.','notes'); }
    return $data;
}
function customer_replay(PDO $db, array $user, string $action, string $key): ?int
{
    if (!preg_match('/\A[a-f0-9]{64}\z/',$key)) { throw new CustomerValidation('Invalid submission. Reopen this form.'); }
    $id=customer_query($db,"SELECT entity_id FROM activity_logs WHERE business_id=? AND actor_user_id=? AND entity_type='customer' AND action_code=? AND JSON_UNQUOTE(JSON_EXTRACT(after_data,'$.request_key'))=? LIMIT 1",[$user['business_id'],$user['user_id'],$action,$key])->fetchColumn();
    return $id===false?null:(int)$id;
}
function customer_save(PDO $db, array $user, int $id, array $input, string $key): int
{
    sale_lock_business($db,$user); $action=$id?'CUSTOMER_UPDATED':'CUSTOMER_CREATED';
    if ($replay=customer_replay($db,$user,$action,$key)) { if ($id && $replay!==$id) { throw new CustomerValidation('Invalid customer edit.'); } return $replay; }
    $old=$id?customer_read($db,(int)$user['business_id'],$id,true):null;
    if ($old && !hash_equals(customer_revision($old),sale_text($input,'revision'))) { throw new CustomerValidation('This customer changed. Reopen the form before saving.'); }
    $data=customer_validate($db,(int)$user['business_id'],$id,$input,$old);
    $values=[$data['customer_name']]; foreach (['phone','email','address','notes'] as $field) { $values[]=$data[$field]!==''?$data[$field]:null; }
    if ($id) { array_push($values,$user['business_id'],$id); customer_query($db,'UPDATE customers SET customer_name=?,phone=?,email=?,address=?,notes=? WHERE business_id=? AND customer_id=?',$values); }
    else { array_unshift($values,$user['business_id']); customer_query($db,'INSERT INTO customers (business_id,customer_name,phone,email,address,notes) VALUES (?,?,?,?,?,?)',$values); $id=(int)$db->lastInsertId(); }
    $before=$old?array_intersect_key($old,$data):[];
    sale_audit($db,$user,$id,$action,$before,['request_key'=>$key,'data'=>$data,'shared_phone_confirmed'=>sale_text($input,'allow_shared_phone')==='1'],'customer');
    return $id;
}
function customer_set_active(PDO $db, array $user, int $id, array $input, string $key, bool $active): void
{
    sale_lock_business($db,$user); $action=$active?'CUSTOMER_RESTORED':'CUSTOMER_ARCHIVED';
    if ($replay=customer_replay($db,$user,$action,$key)) { if ($replay!==$id) { throw new CustomerValidation('Invalid customer action.'); } return; }
    $row=customer_read($db,(int)$user['business_id'],$id,true);
    if (!hash_equals(customer_revision($row),sale_text($input,'revision'))) { throw new CustomerValidation('This customer changed. Reopen the confirmation.'); }
    if ((bool)$row['is_active']===$active) { throw new CustomerValidation($active?'This customer is already active.':'This customer is already archived.'); }
    if (sale_text($input,'confirm_customer_action')!=='1') { throw new CustomerValidation('Please confirm this customer action.'); }
    customer_query($db,'UPDATE customers SET is_active=? WHERE business_id=? AND customer_id=?',[$active?1:0,$user['business_id'],$id]);
    sale_audit($db,$user,$id,$action,['is_active'=>$row['is_active']],['request_key'=>$key,'is_active'=>$active?1:0],'customer');
}
