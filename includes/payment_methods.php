<?php
declare(strict_types=1);

function payment_category(string $code): string
{
    return match ($code) { 'cash'=>'cash','gcash','maya','wallet_other'=>'wallet','bank'=>'bank',default=>'other' };
}
function payment_category_label(string $category): string
{
    return ['cash'=>'Cash','wallet'=>'Digital Wallet','bank'=>'Bank Transfer','other'=>'Other'][$category]??'Other';
}
function payment_method_catalog(PDO $db): array
{
    return $db->query('SELECT payment_method_id,method_code,method_name,is_active FROM payment_methods WHERE is_active=1 ORDER BY payment_method_id')->fetchAll();
}
// Used by both Add Sale and later collections. Method IDs always come from MySQL.
function payment_method_data(PDO $db, array $input): array
{
    $category=sale_text($input,'payment_category');
    if ($category!=='') {
        if (!in_array($category,['cash','wallet','bank'],true)) { throw new InvalidArgumentException('Choose Cash, Digital Wallet or Bank Transfer.'); }
        if ($category==='wallet') {
            $id=sale_id(sale_text($input,'wallet_method_id'));
            $statement=$db->prepare("SELECT * FROM payment_methods WHERE payment_method_id=? AND method_code IN ('gcash','maya','wallet_other') AND is_active=1 FOR UPDATE");
            $statement->execute([$id]);
        } else {
            $statement=$db->prepare('SELECT * FROM payment_methods WHERE method_code=? AND is_active=1 FOR UPDATE');
            $statement->execute([$category]);
        }
    } else {
        // Retain compatibility with the existing authenticated payment callers.
        $statement=$db->prepare('SELECT * FROM payment_methods WHERE payment_method_id=? AND is_active=1 FOR UPDATE');
        $statement->execute([sale_id(sale_text($input,'payment_method_id'))]);
    }
    $method=$statement->fetch();
    if (!$method || payment_category($method['method_code'])==='other') { throw new InvalidArgumentException('Choose an available payment method.'); }
    $provider=sale_text($input,'provider_name');
    if (!mb_check_encoding($provider,'UTF-8') || mb_strlen($provider)>80 || preg_match('/[\x00-\x1f\x7f]/u',$provider)) { throw new InvalidArgumentException('Keep the bank or wallet provider name within 80 readable characters.'); }
    if (in_array($method['method_code'],['bank','wallet_other'],true) && $provider==='') { throw new InvalidArgumentException($method['method_code']==='bank'?'Enter the bank name.':'Enter the other wallet provider name.'); }
    $provider=match ($method['method_code']) { 'cash'=>null,'gcash'=>'GCash','maya'=>'Maya',default=>$provider };
    return ['payment_method_id'=>(int)$method['payment_method_id'],'provider_name'=>$provider];
}
function payment_provider(array $row): string
{
    return $row['provider_name']??match ($row['method_code']??'') { 'gcash'=>'GCash','maya'=>'Maya',default=>'Not recorded' };
}
