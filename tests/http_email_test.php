<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(403); exit; }
require __DIR__.'/../includes/passwords.php';
function email_http_expect(bool $ok,string $message): void { if (!$ok) { throw new RuntimeException($message); } }
$curl=curl_init(); curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_COOKIEFILE=>'',CURLOPT_TIMEOUT=>10]);
function email_http(string $path,?array $post=null): array {
    global $curl; $headers=[];
    curl_setopt($curl,CURLOPT_URL,'http://localhost/profitpocket/'.$path); curl_setopt($curl,CURLOPT_POST,$post!==null);
    if ($post!==null) { curl_setopt($curl,CURLOPT_POSTFIELDS,http_build_query($post)); }
    curl_setopt($curl,CURLOPT_HEADERFUNCTION,static function($curl,$line)use(&$headers){$p=explode(':',$line,2);if(count($p)===2){$headers[strtolower(trim($p[0]))]=trim($p[1]);}return strlen($line);});
    $body=curl_exec($curl); if ($body===false) { throw new RuntimeException('Local HTTP request failed.'); }
    return ['status'=>curl_getinfo($curl,CURLINFO_HTTP_CODE),'body'=>$body,'headers'=>$headers];
}
function email_form_value(string $html,string $name): string {
    if (!preg_match('/name="'.preg_quote($name,'/').'" value="([^"]+)"/',$html,$m)) { throw new RuntimeException('Form field unavailable: '.$name); } return html_entity_decode($m[1],ENT_QUOTES,'UTF-8');
}
$db=db();$id=null;$business=null;$failed=false;
try {
    foreach (['bin/process_notifications.php','bin/generate_reminders.php','bin/preview_emails.php','bin/email_setup_check.php','emails/templates/welcome.php','config/email.php','includes/notifications.php'] as $path) { email_http_expect(email_http($path)['status']===403,'Private email functionality exposed: '.$path); }
    email_http_expect(email_http('auth/change_password.php')['status']===303,'Guest reached password change');
    $reset=email_http('auth/reset_password.php'); email_http_expect($reset['status']===200 && ($reset['headers']['referrer-policy']??'')==='no-referrer' && str_contains($reset['headers']['cache-control']??'','no-store'),'Reset security headers');
    email_http_expect(email_http('auth/reset_password.php',['csrf_token'=>'invalid','reset_token'=>str_repeat('e',64),'password'=>'New-password-test-123!','password_confirmation'=>'New-password-test-123!'])['status']===403,'Reset CSRF accepted');
    $db->beginTransaction(); $suffix=bin2hex(random_bytes(8)); $name='email_http_'.$suffix; $email=$name.'@example.invalid';
    $id=create_owner($db,['full_name'=>'Temporary email HTTP owner','username'=>$name,'email'=>$email,'business_name'=>'Temporary email HTTP business','business_category_id'=>(string)$db->query('SELECT business_category_id FROM business_categories LIMIT 1')->fetchColumn()],'HTTP-email-old-123!'); $business=(int)$db->query('SELECT business_id FROM businesses WHERE owner_user_id='.(int)$id)->fetchColumn();$db->commit();
    foreach ([$email,'unknown_'.$suffix.'@example.invalid'] as $address) { $form=email_http('auth/forgot_password.php'); $r=email_http('auth/forgot_password.php',['csrf_token'=>email_form_value($form['body'],'csrf_token'),'email'=>$address]); email_http_expect($r['status']===200 && str_contains($r['body'],'If an eligible account matches'),'Recovery account enumeration'); }
    $form=email_http('auth/login.php'); $r=email_http('auth/login.php',['csrf_token'=>email_form_value($form['body'],'csrf_token'),'identifier'=>$name,'password'=>'HTTP-email-old-123!']); email_http_expect($r['status']===303,'Fixture login failed');
    $form=email_http('auth/change_password.php'); email_http_expect($form['status']===200,'Password form unavailable');
    $r=email_http('auth/change_password.php',['csrf_token'=>email_form_value($form['body'],'csrf_token'),'request_key'=>email_form_value($form['body'],'request_key'),'current_password'=>'HTTP-email-old-123!','password'=>'HTTP-email-new-123!','password_confirmation'=>'HTTP-email-new-123!']);
    email_http_expect($r['status']===303 && str_contains($r['headers']['location']??'','auth/login.php'),'Password change did not sign out');
    $s=$db->prepare('SELECT password_hash FROM users WHERE user_id=?');$s->execute([$id]);email_http_expect(password_verify('HTTP-email-new-123!',$s->fetchColumn()),'HTTP password update missing');
    $s=$db->prepare('SELECT COUNT(*) FROM auth_sessions WHERE user_id=? AND revoked_at IS NULL');$s->execute([$id]);email_http_expect((int)$s->fetchColumn()===0,'Existing sessions were not revoked');
    $form=email_http('auth/login.php'); $r=email_http('auth/login.php',['csrf_token'=>email_form_value($form['body'],'csrf_token'),'identifier'=>$name,'password'=>'HTTP-email-new-123!']);email_http_expect($r['status']===303,'Fresh login with new password failed');
    $r=email_http('auth/change_password.php',['csrf_token'=>'invalid','request_key'=>'invalid','current_password'=>'HTTP-email-new-123!','password'=>'Another-password-123!','password_confirmation'=>'Another-password-123!']);email_http_expect($r['status']===403,'Authenticated change CSRF accepted');
    echo "PASS: private email/CLI routes denied, generic reset requests, token/referrer protections, CSRF, authenticated password change, session revocation and fresh login; no real email sent.\n";
} catch (Throwable $e) { if($db->inTransaction()){$db->rollBack();}fwrite(STDERR,'FAIL: '.$e->getMessage().PHP_EOL);$failed=true; }
finally {
    if($id){$db->beginTransaction();foreach(['notification_outbox','password_reset_tokens','auth_sessions']as$table){$db->prepare('DELETE FROM '.$table.' WHERE user_id=?')->execute([$id]);}$db->prepare('DELETE FROM activity_logs WHERE actor_user_id=?')->execute([$id]);$db->prepare('DELETE FROM businesses WHERE owner_user_id=?')->execute([$id]);$db->prepare('DELETE FROM users WHERE user_id=?')->execute([$id]);$db->commit();}
    curl_close($curl);
}
if($failed){exit(1);}
