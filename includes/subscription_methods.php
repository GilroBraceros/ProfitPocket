<?php
declare(strict_types=1);
require_once __DIR__.'/subscriptions.php';
const REGISTRATION_PAYMENT_VERSION='20261025_registration_payments';
function registration_payment_schema(PDO $db): bool
{
    try { return (bool)subscription_query($db,'SELECT 1 FROM deployment_runs WHERE run_key=?',['migration:'.REGISTRATION_PAYMENT_VERSION])->fetchColumn(); }
    catch (PDOException $e) { return false; }
}
function subscription_method_supported(array $method): bool
{
    return !preg_match('/(?:^|[^a-z])(?:pay\s*maya|maya)(?:[^a-z]|$)/i',(string)$method['method_name']);
}
function subscription_preview_headers(): void
{
    header("Content-Security-Policy: default-src 'self'; style-src 'self' https://cdn.jsdelivr.net; script-src 'self' https://cdn.jsdelivr.net; img-src 'self' data: blob:; object-src 'none'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
}
function subscription_method_category(array $method): string
{
    if (mb_strtolower(trim($method['method_name']))==='gcash') { return 'gcash'; }
    return in_array($method['method_category']??'',['gcash','bank','other'],true)?$method['method_category']:'other';
}
function subscription_qr_directory(bool $create=false): string
{
    $root=subscription_storage($create); $path=$root.'/payment-qr';
    if ($create && !is_dir($path) && !mkdir($path,0700) && !is_dir($path)) { throw new RuntimeException('Private QR storage is unavailable.'); }
    $resolved=realpath($path);
    if (!$resolved || dirname($resolved)!==$root || !is_readable($resolved) || ($create && !is_writable($resolved))) { throw new RuntimeException('Private QR storage is unavailable.'); }
    return $resolved;
}
function subscription_validate_qr(string $path,int $size): array
{
    if ($size<1 || $size>2097152 || !is_file($path) || filesize($path)!==$size) { throw new InvalidArgumentException('Choose a QR image up to 2 MB.'); }
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($path); $dimensions=@getimagesize($path);
    if (!in_array($mime,['image/jpeg','image/png','image/webp'],true) || !$dimensions || ($dimensions['mime']??'')!==$mime || $dimensions[0]*$dimensions[1]>20000000) { throw new InvalidArgumentException('Upload an actual JPG, PNG or WebP QR image, up to 20 million pixels.'); }
    return ['name'=>bin2hex(random_bytes(32)).'.bin','mime'=>$mime];
}
function subscription_store_qr(array $file): ?array
{
    if (($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE) { return null; }
    if (($file['error']??null)!==UPLOAD_ERR_OK || !is_string($file['tmp_name']??null) || !is_uploaded_file($file['tmp_name'])) { throw new InvalidArgumentException('The QR upload failed. Choose a JPG, PNG or WebP up to 2 MB.'); }
    $qr=subscription_validate_qr($file['tmp_name'],(int)$file['size']); $qr['path']=subscription_qr_directory(true).'/'.$qr['name'];
    if (!move_uploaded_file($file['tmp_name'],$qr['path'])) { throw new RuntimeException('QR storage failed.'); }
    @chmod($qr['path'],0600); return $qr;
}
function subscription_method_save(PDO $db,int $actor,int $id,array $input,?array $qr=null): array
{
    require_once __DIR__.'/admin.php';
    admin_authorize($db,$actor,'settings.manage',true); admin_authorize($db,$actor,'users.manage');
    if (!registration_payment_schema($db)) { throw new InvalidArgumentException('Apply the approved registration/payment migration before editing payment methods.'); }
    $old=$id?subscription_query($db,'SELECT * FROM subscription_payment_methods WHERE method_id=? FOR UPDATE',[$id])->fetch():null;
    if ($id && !$old) { throw new InvalidArgumentException('Payment method not found.'); }
    $category=admin_text($input,'method_category',16);
    if ($category==='') { $category=mb_strtolower(admin_text($input,'method_name',80))==='gcash'?'gcash':'other'; }
    if (!in_array($category,['gcash','bank','other'],true)) { throw new InvalidArgumentException('Choose GCash, Bank Transfer or Other.'); }
    $bank=$category==='bank'?admin_text($input,'bank_name',80,true):'';
    $name=match($category) { 'gcash'=>'GCash','bank'=>'Bank Transfer',default=>admin_text($input,isset($input['custom_method_name'])?'custom_method_name':'method_name',80,true) };
    if($category==='other' && mb_strtolower(trim($name))==='gcash'){$category='gcash';$name='GCash';}
    if($category==='other' && mb_strtolower(trim($name))==='bank transfer'){throw new InvalidArgumentException('Choose Bank Transfer and enter the bank name.');}
    if (!subscription_method_supported(['method_name'=>$name.' '.$bank])) { throw new InvalidArgumentException('This payment provider is not available. Choose another method.'); }
    $recipient=admin_text($input,'recipient_name',150,true); $account=admin_text($input,'account_identifier',150,true); $instructions=admin_text($input,'instructions',2000,true);
    $canonical=fn(string $v)=>mb_strtolower(preg_replace('/[\s-]+/u','',trim($v)));
    foreach (subscription_methods($db,true) as $existing) {
        if ((int)$existing['method_id']===$id) { continue; }
        if (subscription_method_category($existing)===$category && ($category==='gcash' || ($category==='bank'?$canonical($existing['bank_name']??'')===$canonical($bank):$canonical($existing['method_name'])===$canonical($name))) && $canonical($existing['account_identifier'])===$canonical($account)) { throw new InvalidArgumentException('This receiving account already exists. Edit its payment method instead.'); }
    }
    $remove=($input['remove_qr']??'')==='1';
    if ($qr && $remove) { throw new InvalidArgumentException('Choose either a replacement QR image or Remove QR, then save.'); }
    if ($qr && (!preg_match('/\A[a-f0-9]{64}\.bin\z/',$qr['name']??'') || !is_file(subscription_qr_directory().'/'.$qr['name']))) { throw new InvalidArgumentException('The uploaded QR image is unavailable.'); }
    $filename=$qr['name']??($remove?null:($old['qr_filename']??null)); $enabled=($input['is_enabled']??'')==='1';
    $values=[$name,$recipient,$account,$instructions,(int)$enabled,$actor,$category,$bank?:null,$filename];
    if ($id) { subscription_query($db,'UPDATE subscription_payment_methods SET method_name=?,recipient_name=?,account_identifier=?,instructions=?,is_enabled=?,updated_by=?,method_category=?,bank_name=?,qr_filename=? WHERE method_id=?',array_merge($values,[$id])); }
    else { subscription_query($db,'INSERT INTO subscription_payment_methods (method_name,recipient_name,account_identifier,instructions,is_enabled,updated_by,method_category,bank_name,qr_filename) VALUES (?,?,?,?,?,?,?,?,?)',$values); $id=(int)$db->lastInsertId(); }
    admin_audit($db,$actor,null,'SUBSCRIPTION_METHOD_UPDATED',[],['method_id'=>$id,'enabled'=>$enabled,'qr_changed'=>($old['qr_filename']??null)!==$filename],'Subscription payment instructions updated',bin2hex(random_bytes(32)),hash('sha256',(string)$id));
    return ['id'=>$id,'previous_qr'=>$old['qr_filename']??null,'qr_filename'=>$filename];
}
function subscription_method_action(PDO $db,int $actor,int $id,string $action): ?string
{
    require_once __DIR__.'/admin.php'; admin_authorize($db,$actor,'settings.manage',true); admin_authorize($db,$actor,'users.manage');
    $method=subscription_query($db,'SELECT * FROM subscription_payment_methods WHERE method_id=? FOR UPDATE',[$id])->fetch();
    if (!$method || !in_array($action,['activate','deactivate','remove'],true)) { throw new InvalidArgumentException('Payment method action is unavailable.'); }
    if ($action==='activate' && !subscription_method_supported($method)) { throw new InvalidArgumentException('This payment provider is not available.'); }
    if ($action==='remove') {
        if (subscription_query($db,'SELECT 1 FROM subscription_payments WHERE method_id=? LIMIT 1',[$id])->fetchColumn()) { throw new InvalidArgumentException('This method has payment history. Deactivate it to preserve those records.'); }
        subscription_query($db,'DELETE FROM subscription_payment_methods WHERE method_id=?',[$id]);
    } else { subscription_query($db,'UPDATE subscription_payment_methods SET is_enabled=?,updated_by=? WHERE method_id=?',[(int)($action==='activate'),$actor,$id]); }
    admin_audit($db,$actor,null,'SUBSCRIPTION_METHOD_'.strtoupper($action),[],['method_id'=>$id],'Payment method availability updated',bin2hex(random_bytes(32)),hash('sha256',$action.':'.$id));
    return $action==='remove'?($method['qr_filename']??null):null;
}
function subscription_cleanup_qr(PDO $db,?string $filename): void
{
    if (!$filename || !preg_match('/\A[a-f0-9]{64}\.bin\z/',$filename)) { return; }
    if (subscription_query($db,'SELECT 1 FROM subscription_payment_methods WHERE qr_filename=? LIMIT 1',[$filename])->fetchColumn() || subscription_query($db,"SELECT 1 FROM subscription_payments WHERE JSON_UNQUOTE(JSON_EXTRACT(method_snapshot,'$.qr_filename'))=? LIMIT 1",[$filename])->fetchColumn()) { return; }
    try { $file=subscription_qr_directory().'/'.$filename; if (is_file($file)) { unlink($file); } } catch (Throwable $e) { error_log('ProfitPocket: unused QR cleanup unavailable.'); }
}
