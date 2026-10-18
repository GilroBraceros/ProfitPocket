<?php
declare(strict_types=1);
require_once __DIR__.'/sales.php';

class ProductNotFound extends RuntimeException {}
class ProductValidation extends InvalidArgumentException
{
    public function __construct(string $message, public string $field='') { parent::__construct($message); }
}
function product_query(PDO $db,string $sql,array $values=[]): PDOStatement
{
    $statement=$db->prepare($sql); $statement->execute($values); return $statement;
}
function product_filters(array $input): array
{
    $f=[]; foreach (['q'=>'','state'=>'active','sort'=>'name','page'=>'1'] as $key=>$default) { $f[$key]=sale_text($input,$key,$default); }
    if (!mb_check_encoding($f['q'],'UTF-8') || mb_strlen($f['q'])>100 || !in_array($f['state'],['active','archived','all'],true) || !in_array($f['sort'],['name','price_low','price_high'],true) || !ctype_digit($f['page']) || strlen($f['page'])>6 || (int)$f['page']<1) { throw new ProductValidation('Choose valid product search and filters.'); }
    $f['page']=(int)$f['page']; return $f;
}
function product_list(PDO $db,int $business,array $filters): array
{
    $summary=product_query($db,'SELECT COUNT(*) AS total,COALESCE(SUM(is_active=1),0) AS active FROM products WHERE business_id=?',[$business])->fetch();
    $source=' FROM products WHERE business_id=?'; $values=[$business];
    if ($filters['state']!=='all') { $source.=' AND is_active=?'; $values[]=$filters['state']==='active'?1:0; }
    if ($filters['q']!=='') { $source.=' AND LOCATE(?,product_name)>0'; $values[]=$filters['q']; }
    $count=(int)product_query($db,'SELECT COUNT(*)'.$source,$values)->fetchColumn(); $pages=max(1,(int)ceil($count/15)); $page=min($filters['page'],$pages); $offset=($page-1)*15;
    $order=match($filters['sort']) {'price_low'=>'default_selling_price,product_name,product_id','price_high'=>'default_selling_price DESC,product_name,product_id',default=>'product_name,product_id'};
    $rows=product_query($db,'SELECT *,default_selling_price-default_unit_cost AS unit_profit'.$source." ORDER BY $order LIMIT 15 OFFSET $offset",$values)->fetchAll();
    return compact('summary','rows','count','page','pages');
}
function product_read(PDO $db,int $business,int $id,bool $lock=false): array
{
    $row=product_query($db,'SELECT * FROM products WHERE business_id=? AND product_id=?'.($lock?' FOR UPDATE':''),[$business,$id])->fetch();
    if (!$row) { throw new ProductNotFound('Product not found.'); } return $row;
}
function product_revision(array $row): string
{
    return hash('sha256',json_encode(array_intersect_key($row,array_flip(['product_id','business_id','product_name','sku','description','default_selling_price','default_unit_cost','is_active','created_at','updated_at'])),JSON_THROW_ON_ERROR));
}
function product_validate(array $input): array
{
    $data=[];
    foreach (['product_name'=>150,'sku'=>60,'description'=>4000] as $field=>$limit) {
        $text=sale_text($input,$field);
        if (!mb_check_encoding($text,'UTF-8') || mb_strlen($text)>$limit || ($field==='product_name' && $text==='')) { throw new ProductValidation($field==='product_name'?'Enter a product name of 1 to 150 characters.':'Keep '.($field==='sku'?'the product code':'the description').' within '.$limit.' characters.',$field); }
        $data[$field]=$text!==''?$text:null;
    }
    foreach (['default_unit_cost'=>'Cost price','default_selling_price'=>'Selling price'] as $field=>$label) {
        try { $data[$field]=sale_decimal(sale_text($input,$field),2,$label); }
        catch (InvalidArgumentException $e) { throw new ProductValidation($label.' must be zero or greater, with up to two decimal places.',$field); }
    }
    return $data;
}
function product_replay(PDO $db,array $user,string $action,int $id,string $key,array $payload): ?int
{
    if (!preg_match('/\A[a-f0-9]{64}\z/',$key)) { throw new ProductValidation('Invalid submission. Reopen the product form.'); }
    $row=product_query($db,"SELECT entity_id,after_data FROM activity_logs WHERE business_id=? AND actor_user_id=? AND entity_type='product' AND action_code=? AND JSON_UNQUOTE(JSON_EXTRACT(after_data,'$.request_key'))=? LIMIT 1 FOR UPDATE",[$user['business_id'],$user['user_id'],$action,$key])->fetch();
    if (!$row) { return null; }
    $after=json_decode($row['after_data'],true,512,JSON_THROW_ON_ERROR);
    if (($id && (int)$row['entity_id']!==$id) || !hash_equals($after['payload_hash'],hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR)))) { throw new ProductValidation('This submission was already saved with different details. Reopen the form.'); }
    return (int)$row['entity_id'];
}
function product_audit(PDO $db,array $user,int $id,string $action,array $before,array $after,string $key,array $payload): void
{
    sale_audit($db,$user,$id,$action,$before,['request_key'=>$key,'payload_hash'=>hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR)),'data'=>$after],'product');
}
function product_save(PDO $db,array $user,int $id,array $input,string $key): int
{
    sale_lock_business($db,$user); $data=product_validate($input); $action=$id?'PRODUCT_UPDATED':'PRODUCT_CREATED';
    $payload=$data; if ($id) { $payload['revision']=sale_text($input,'revision'); }
    if ($replay=product_replay($db,$user,$action,$id,$key,$payload)) { return $replay; }
    $old=$id?product_read($db,(int)$user['business_id'],$id,true):null;
    if ($old && !hash_equals(product_revision($old),sale_text($input,'revision'))) { throw new ProductValidation('This product changed. Reopen the form before saving.'); }
    if ($data['sku']!==null && product_query($db,'SELECT product_id FROM products WHERE business_id=? AND sku=? AND product_id<>? FOR UPDATE',[$user['business_id'],$data['sku'],$id])->fetchColumn()) { throw new ProductValidation('This product code is already used in your business. Choose another code or leave it blank.','sku'); }
    $values=[$data['product_name'],$data['sku'],$data['description'],$data['default_selling_price'],$data['default_unit_cost']];
    if ($id) { array_push($values,$user['business_id'],$id); product_query($db,'UPDATE products SET product_name=?,sku=?,description=?,default_selling_price=?,default_unit_cost=? WHERE business_id=? AND product_id=?',$values); }
    else { array_unshift($values,$user['business_id']); product_query($db,'INSERT INTO products (business_id,product_name,sku,description,default_selling_price,default_unit_cost) VALUES (?,?,?,?,?,?)',$values); $id=(int)$db->lastInsertId(); }
    product_audit($db,$user,$id,$action,$old?array_intersect_key($old,$data):[],$data,$key,$payload);
    return $id;
}
function product_set_active(PDO $db,array $user,int $id,array $input,string $key,bool $active): void
{
    sale_lock_business($db,$user); $action=$active?'PRODUCT_RESTORED':'PRODUCT_ARCHIVED';
    $payload=['revision'=>sale_text($input,'revision'),'confirm_product_action'=>sale_text($input,'confirm_product_action')];
    if (product_replay($db,$user,$action,$id,$key,$payload)) { return; }
    $row=product_read($db,(int)$user['business_id'],$id,true);
    if (!hash_equals(product_revision($row),$payload['revision'])) { throw new ProductValidation('This product changed. Reopen the confirmation.'); }
    if ((bool)$row['is_active']===$active) { throw new ProductValidation($active?'This product is already active.':'This product is already archived.'); }
    if ($payload['confirm_product_action']!=='1') { throw new ProductValidation('Please confirm this product action.'); }
    product_query($db,'UPDATE products SET is_active=? WHERE business_id=? AND product_id=?',[$active?1:0,$user['business_id'],$id]);
    product_audit($db,$user,$id,$action,['is_active'=>$row['is_active']],['is_active'=>$active?1:0],$key,$payload);
}
