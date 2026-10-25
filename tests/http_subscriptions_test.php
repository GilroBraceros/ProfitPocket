<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(403);exit;}
require __DIR__.'/../includes/auth.php'; require __DIR__.'/../includes/deployment.php'; require __DIR__.'/../includes/admin.php';
function sh_expect(bool $ok,string $message): void {if(!$ok){throw new RuntimeException($message);}}
function sh_client(): CurlHandle {$c=curl_init();curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_COOKIEFILE=>'',CURLOPT_TIMEOUT=>20]);return $c;}
function sh_request(CurlHandle $client,string $path,?array $post=null,bool $multipart=false): array {
    $headers=[];curl_setopt($client,CURLOPT_URL,'http://localhost/profitpocket/'.$path);curl_setopt($client,CURLOPT_POST,$post!==null);
    if($post!==null){curl_setopt($client,CURLOPT_POSTFIELDS,$multipart?$post:http_build_query($post));}
    curl_setopt($client,CURLOPT_HEADERFUNCTION,static function($curl,$line)use(&$headers){$parts=explode(':',$line,2);if(count($parts)===2){$headers[strtolower(trim($parts[0]))]=trim($parts[1]);}return strlen($line);});
    $body=curl_exec($client);if($body===false){throw new RuntimeException('Local HTTP request failed.');}return ['status'=>curl_getinfo($client,CURLINFO_HTTP_CODE),'body'=>$body,'headers'=>$headers];
}
function sh_value(string $body,string $name): string {if(!preg_match('/name="'.preg_quote($name,'/').'" value="([^"]*)"/',$body,$m)){throw new RuntimeException('Missing form field '.$name);}return html_entity_decode($m[1],ENT_QUOTES,'UTF-8');}
function sh_login(CurlHandle $c,string $name,string $password): array {$form=sh_request($c,'auth/login.php');$r=sh_request($c,'auth/login.php',['csrf_token'=>sh_value($form['body'],'csrf_token'),'identifier'=>$name,'password'=>$password]);sh_expect($r['status']===303,'Fixture login failed');return $r;}
function sh_upload(CurlHandle $c,int $method,string $path,string $reference): array {$form=sh_request($c,'user/subscription.php');return sh_request($c,'user/subscription.php',['csrf_token'=>sh_value($form['body'],'csrf_token'),'request_key'=>sh_value($form['body'],'request_key'),'method_id'=>(string)$method,'reference_number'=>$reference,'proof'=>new CURLFile($path)],true);}
function sh_review(CurlHandle $c,int $id,string $decision,string $reason='',bool $verified=true): array {$form=sh_request($c,'admin/subscriptions.php?view='.$id);return sh_request($c,'admin/subscriptions.php?view='.$id,['csrf_token'=>sh_value($form['body'],'csrf_token'),'action'=>'review','submission_id'=>(string)$id,'decision'=>$decision,'review_note'=>$reason,'confirm'=>'1','payment_verified'=>$verified?'1':'0']);}
function sh_snapshot(string $name,string $html): void {file_put_contents(__DIR__.'/http-results/subscription-'.$name.'.html',$html);}
$db=db();$ids=[];$method=null;$clients=[];$temporary=tempnam(sys_get_temp_dir(),'pp-sub-http-');$failed=false;$stage='setup';
try {
    sh_expect(subscription_enabled() && subscription_schema($db),'Enable the approved local subscription rollout before this test.');
    $suffix=bin2hex(random_bytes(7));$password='HTTP-subscription-test-'.$suffix.'!';$roles=array_column($db->query('SELECT role_id,role_code FROM roles')->fetchAll(),'role_id','role_code');
    $db->beginTransaction();
    foreach (['admin'=>'platform_admin','admin2'=>'platform_admin','support'=>'support_admin'] as $key=>$role) {subscription_query($db,'INSERT INTO users (role_id,full_name,username,email,password_hash) VALUES (?,?,?,?,?)',[$roles[$role],'Temporary subscription '.$key,'sub_http_'.$key.'_'.$suffix,'sub_http_'.$key.'_'.$suffix.'@example.invalid',password_hash($password,PASSWORD_DEFAULT)]);$ids[$key]=(int)$db->lastInsertId();}
    foreach (['owner','other'] as $key) {$ids[$key]=create_owner($db,['full_name'=>'Temporary subscription '.$key,'username'=>'sub_http_'.$key.'_'.$suffix,'email'=>'sub_http_'.$key.'_'.$suffix.'@example.invalid','business_name'=>'Temporary subscription business '.$key,'business_category_id'=>(string)$db->query('SELECT business_category_id FROM business_categories LIMIT 1')->fetchColumn()],$password);}
    subscription_save_method($db,$ids['admin'],0,['method_name'=>'Fictional test wallet','recipient_name'=>'Fictional test receiver','account_identifier'=>'NOT-A-REAL-PAYMENT-ACCOUNT-'.$suffix,'instructions'=>'Test-only instructions. No money is transferred.','is_enabled'=>'1']);$method=(int)$db->query('SELECT MAX(method_id) FROM subscription_payment_methods')->fetchColumn();$db->commit();
    foreach (['guest','owner','other','admin','admin2','support'] as $name) {$clients[$name]=sh_client();}
    $home=sh_request($clients['guest'],'');sh_expect($home['status']===200 && str_contains($home['body'],'Manage smarter.') && str_contains($home['body'],'₱100'),'Public homepage missing');sh_snapshot('homepage',$home['body']);
    foreach (['bin/deploy_upgrade.php','bin/subscription_maintenance.php','bin/send_test_email.php','bin/activate_email.php','bin/production_setup.php','bin/check_test_email.php','config/upgrade.php','config/database.example.php','includes/production.php','includes/production_test_accounts.php'] as $path) {sh_expect(sh_request($clients['guest'],$path)['status']===403,'Private route exposed: '.$path);}
    $login=sh_login($clients['owner'],'sub_http_owner_'.$suffix,$password);sh_expect(str_ends_with($login['headers']['location']??'','/user/subscription.php'),'Unpaid owner redirected to paid features');
    $protected=['user/dashboard.php','user/sales.php','user/sale_form.php','user/sale_view.php?id=1','user/sale_product.php','user/expenses.php','user/expense_action.php?action=create','user/expense_receipt.php?id=1','user/customers.php','user/customer_action.php?action=details&id=1','user/products.php','user/product_action.php?action=edit&id=1','user/payments.php','user/payments_action.php?action=collect&id=1','user/utang.php','user/utang_action.php?action=collect&id=1','user/reports.php','user/reports.php?export=csv'];
    foreach($protected as $path){$r=sh_request($clients['owner'],$path);sh_expect($r['status']===303 && str_ends_with($r['headers']['location']??'','/user/subscription.php'),'Unpaid access bypass: '.$path);}
    foreach(['user/settings.php','user/support.php','auth/change_password.php']as$path){sh_expect(sh_request($clients['owner'],$path)['status']===200,'Necessary account access blocked: '.$path);}
    sh_expect(sh_request($clients['owner'],'admin/subscriptions.php')['status']===403,'Owner reached subscription administration');
    sh_expect(sh_request($clients['owner'],'user/subscription.php',['csrf_token'=>'invalid'])['status']===403,'Owner submission CSRF bypass');
    sh_snapshot('pending-payment',sh_request($clients['owner'],'user/subscription.php')['body']);
    echo "PASS: public homepage, unpaid login routing, all 18 paid page/action/export guards, necessary account access and private/role/CSRF protection.\n";
    $stage='upload and rejection';
    file_put_contents($temporary,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Z6ZAAAAAASUVORK5CYII='));
    $r=sh_upload($clients['owner'],$method,$temporary,'TEST-HTTP-'.$suffix);sh_expect($r['status']===303,'Actual multipart PNG proof failed');
    $id=(int)subscription_query($db,'SELECT MAX(submission_id) FROM subscription_payments WHERE user_id=?',[$ids['owner']])->fetchColumn();
    sh_expect(subscription_state($db,$ids['owner'])['state']==='pending_verification','Upload status missing');
    sh_snapshot('pending-verification',sh_request($clients['owner'],'user/subscription.php')['body']);
    $proof=sh_request($clients['owner'],'user/subscription_proof.php?id='.$id);sh_expect($proof['status']===200 && ($proof['headers']['content-type']??'')==='image/png' && str_contains($proof['headers']['content-disposition']??'','attachment'),'Private PNG download failed');
    sh_login($clients['other'],'sub_http_other_'.$suffix,$password);sh_expect(in_array(sh_request($clients['other'],'user/subscription_proof.php?id='.$id)['status'],[403,404],true),'Foreign owner read private proof');
    subscription_query($db,'INSERT INTO deployment_runs (run_key,details) VALUES (?,?)',['bootstrap-password-required:'.$ids['admin2'],'{}']);
    $initialLogin=sh_login($clients['admin2'],'sub_http_admin2_'.$suffix,$password);
    sh_expect(str_ends_with($initialLogin['headers']['location']??'','/auth/change_password.php') && sh_request($clients['admin2'],'admin/subscriptions.php')['status']===303,'Initial administrator password did not restrict direct access');
    $change=sh_request($clients['admin2'],'auth/change_password.php');$newAdminPassword='New-HTTP-admin-private-'.$suffix.'!';
    $changed=sh_request($clients['admin2'],'auth/change_password.php',['csrf_token'=>sh_value($change['body'],'csrf_token'),'request_key'=>sh_value($change['body'],'request_key'),'current_password'=>$password,'password'=>$newAdminPassword,'password_confirmation'=>$newAdminPassword]);
    sh_expect($changed['status']===303 && !bootstrap_needs_password_change($db,$ids['admin2']),'Initial password update failed');
    sh_login($clients['admin2'],'sub_http_admin2_'.$suffix,$newAdminPassword);
    foreach(['admin','support']as$name){sh_login($clients[$name],'sub_http_'.$name.'_'.$suffix,$password);}
    sh_expect(sh_request($clients['support'],'admin/subscriptions.php')['status']===403,'Support-only administrator reviewed payments');
    $detail=sh_request($clients['admin'],'admin/subscriptions.php?view='.$id);sh_expect($detail['status']===200 && str_contains($detail['body'],'Receiving account'),'Administrative review unavailable');sh_snapshot('admin-review',$detail['body']);
    sh_expect(sh_request($clients['admin'],'user/subscription_proof.php?id='.$id)['status']===200,'Authorized administrator could not read proof');
    sh_expect(sh_request($clients['admin'],'admin/subscriptions.php',['csrf_token'=>'invalid','action'=>'review'])['status']===403,'Review CSRF bypass');
    $r=sh_review($clients['admin'],$id,'approved','',false);sh_expect($r['status']===200 && subscription_state($db,$ids['owner'])['state']==='pending_verification','Unverified receipt activated subscription');
    sh_expect(sh_review($clients['admin'],$id,'rejected','Test reference did not match the receipt.',false)['status']===303,'Rejection failed');
    $rejected=sh_request($clients['owner'],'user/subscription.php');sh_expect(str_contains($rejected['body'],'Test reference did not match'),'Owner rejection reason missing');sh_snapshot('rejected',$rejected['body']);
    file_put_contents($temporary,"%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n");
    sh_expect(sh_upload($clients['owner'],$method,$temporary,'TEST-HTTP-'.$suffix)['status']===303,'Corrected PDF proof failed');
    $second=(int)subscription_query($db,'SELECT MAX(submission_id) FROM subscription_payments WHERE user_id=?',[$ids['owner']])->fetchColumn();
    sh_expect(sh_review($clients['admin'],$second,'approved','Actual receipt verified in fictional test',true)['status']===303,'Approval failed');
    $expires=subscription_state($db,$ids['owner'])['expires_at'];sh_expect(sh_review($clients['admin'],$second,'approved','Actual receipt verified in fictional test',true)['status']===303 && subscription_state($db,$ids['owner'])['expires_at']===$expires,'Approval replay added paid time');
    foreach(['dashboard','sales','expenses','customers','products','payments','reports']as$route){sh_expect(sh_request($clients['owner'],'user/'.$route.'.php')['status']===200,'Approved owner could not open '.$route);}
    sh_snapshot('active',sh_request($clients['owner'],'user/subscription.php')['body']);
    echo "PASS: real multipart PNG/PDF submissions, private downloads, owner isolation, pending/rejected states, corrected proof, verified approval, replay and paid feature access.\n";
    $stage='expiry and concurrent renewal';
    $now=new DateTimeImmutable('now',new DateTimeZone('Asia/Manila'));subscription_query($db,'UPDATE owner_subscriptions SET started_at=?,expires_at=? WHERE user_id=?',[$now->modify('-1 month')->format('Y-m-d H:i:s'),$now->modify('-1 second')->format('Y-m-d H:i:s'),$ids['owner']]);
    foreach($protected as$path){$r=sh_request($clients['owner'],$path);sh_expect($r['status']===303 && str_ends_with($r['headers']['location']??'','/user/subscription.php'),'Expired access bypass: '.$path);}
    sh_expect(sh_request($clients['owner'],'user/settings.php')['status']===200,'Expired account settings blocked');sh_snapshot('expired',sh_request($clients['owner'],'user/subscription.php')['body']);
    sh_expect(sh_upload($clients['owner'],$method,__DIR__.'/../assets/images/logo-icon.jpg','RENEW-HTTP-'.$suffix)['status']===303,'Actual multipart JPG renewal failed');
    $renewal=(int)subscription_query($db,'SELECT MAX(submission_id) FROM subscription_payments WHERE user_id=?',[$ids['owner']])->fetchColumn();
    $multi=curl_multi_init();$multiHeaders=[];
    foreach(['admin','admin2']as$i=>$name){$form=sh_request($clients[$name],'admin/subscriptions.php?view='.$renewal);curl_setopt($clients[$name],CURLOPT_URL,'http://localhost/profitpocket/admin/subscriptions.php?view='.$renewal);curl_setopt($clients[$name],CURLOPT_POST,true);curl_setopt($clients[$name],CURLOPT_POSTFIELDS,http_build_query(['csrf_token'=>sh_value($form['body'],'csrf_token'),'action'=>'review','submission_id'=>$renewal,'decision'=>'approved','review_note'=>'Concurrent test approval','confirm'=>'1','payment_verified'=>'1']));curl_multi_add_handle($multi,$clients[$name]);}
    do{$status=curl_multi_exec($multi,$running);if($running){curl_multi_select($multi,1.0);}}while($running && $status===CURLM_OK);
    $statuses=[];foreach(['admin','admin2']as$name){$statuses[]=curl_getinfo($clients[$name],CURLINFO_HTTP_CODE);curl_multi_remove_handle($multi,$clients[$name]);}curl_multi_close($multi);sort($statuses);
    sh_expect($statuses===[200,303],'Concurrent review did not yield exactly one successful approval');
    sh_expect((int)subscription_query($db,"SELECT COUNT(*) FROM admin_action_logs WHERE target_user_id=? AND action_code='SUBSCRIPTION_APPROVED'",[$ids['owner']])->fetchColumn()===2,'Concurrent approval duplicated the audit/period');
    sh_expect(subscription_state($db,$ids['owner'])['active'] && sh_request($clients['owner'],'user/dashboard.php')['status']===200,'Renewal failed to restore access');
    sh_snapshot('renewed',sh_request($clients['owner'],'user/subscription.php')['body']);sh_snapshot('admin-overview',sh_request($clients['admin'],'admin/subscriptions.php?state=all')['body']);
    echo "PASS: immediate expiry enforcement, preserved account access, multipart JPG renewal and concurrent administrator approval with one period/audit; no real emails or money transferred.\n";
}catch(Throwable $e){if($db->inTransaction()){$db->rollBack();}fwrite(STDERR,'FAIL ['.$stage.']: '.$e->getMessage().PHP_EOL);$failed=true;}
finally{
    if($ids){$placeholders=implode(',',array_fill(0,count($ids),'?'));$values=array_values($ids);
        $proofs=subscription_query($db,'SELECT proof_name FROM subscription_payments WHERE user_id IN ('.$placeholders.')',$values)->fetchAll(PDO::FETCH_COLUMN);
        foreach($proofs as$name){if(preg_match('/\A[a-f0-9]{64}\.bin\z/',$name)){ $file=subscription_storage().'/'.$name;if(is_file($file)){unlink($file);} }}
        $db->beginTransaction();subscription_query($db,'DELETE FROM notification_outbox WHERE user_id IN ('.$placeholders.')',$values);subscription_query($db,'DELETE FROM subscription_payments WHERE user_id IN ('.$placeholders.')',$values);
        foreach($values as$fixtureId){subscription_query($db,'DELETE FROM deployment_runs WHERE run_key=?',['bootstrap-password-required:'.$fixtureId]);}
        if($method){subscription_query($db,'DELETE FROM subscription_payment_methods WHERE method_id=?',[$method]);}
        subscription_query($db,'DELETE FROM admin_action_logs WHERE admin_user_id IN ('.$placeholders.')',$values);
        foreach(['password_reset_tokens','auth_sessions']as$table){subscription_query($db,'DELETE FROM '.$table.' WHERE user_id IN ('.$placeholders.')',$values);}
        subscription_query($db,'DELETE FROM activity_logs WHERE actor_user_id IN ('.$placeholders.')',$values);subscription_query($db,'DELETE FROM businesses WHERE owner_user_id IN ('.$placeholders.')',$values);subscription_query($db,'DELETE FROM users WHERE user_id IN ('.$placeholders.')',$values);$db->commit();
    }
    if(is_file($temporary)){unlink($temporary);}foreach($clients as$client){curl_close($client);}
}
if($failed){exit(1);}
