<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require __DIR__.'/../includes/deployment.php';
function root_expect(bool $ok,string $message):void{if(!$ok){throw new RuntimeException($message);}}
function root_request(CurlHandle $curl,string $path,?array $post=null):array{
    $headers=[];curl_setopt_array($curl,[CURLOPT_URL=>'http://127.0.0.1:8033/'.$path,CURLOPT_POST=>$post!==null,CURLOPT_HEADERFUNCTION=>static function($curl,$line)use(&$headers){$parts=explode(':',$line,2);if(count($parts)===2){$headers[strtolower(trim($parts[0]))]=trim($parts[1]);}return strlen($line);}]);
    if($post!==null){curl_setopt($curl,CURLOPT_POSTFIELDS,http_build_query($post));}$body=curl_exec($curl);if($body===false){throw new RuntimeException('Root test HTTP server unavailable.');}return ['status'=>curl_getinfo($curl,CURLINFO_HTTP_CODE),'body'=>$body,'headers'=>$headers];
}
function root_field(array $response,string $name):string{if(!preg_match('/name="'.preg_quote($name,'/').'" value="([^"]*)"/',$response['body'],$m)){throw new RuntimeException('Root form field missing.');}return html_entity_decode($m[1],ENT_QUOTES,'UTF-8');}
$db=db();$user=null;$server=null;$curl=null;$failed=false;$root=sys_get_temp_dir().'/pp-root-http-'.bin2hex(random_bytes(8));mkdir($root,0700);
try{
    $env=getenv();unset($env['PP_BASE_PATH']);$env['BREVO_EMAIL_ENABLED']='false';
    $server=proc_open([PHP_BINARY,'-d','session.save_path='.$root,'-S','127.0.0.1:8033','-t',realpath(__DIR__.'/..')],[0=>['pipe','r'],1=>['file',$root.'/server.log','a'],2=>['file',$root.'/server.log','a']],$pipes,dirname(__DIR__),$env);root_expect(is_resource($server),'Root HTTP process not started.');fclose($pipes[0]);
    $curl=curl_init();curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_COOKIEFILE=>'',CURLOPT_TIMEOUT=>15]);
    for($i=0;$i<30;$i++){try{$form=root_request($curl,'auth/login.php');if($form['status']===200){break;}}catch(Throwable $e){}usleep(100000);}
    root_expect($form['status']===200 && str_contains($form['body'],'action="/auth/login.php"'),'Root login posts to the old subfolder.');
    root_expect(str_contains($form['headers']['set-cookie']??'','path=/;'),'Root session cookie has the wrong path.');
    $registration=root_request($curl,'auth/register.php');root_expect($registration['status']===200 && str_contains($registration['body'],'action="/auth/register.php"'),'Root registration routing failed.');
    root_expect(root_request($curl,'assets/css/subscriptions.css')['status']===200,'Root asset path failed.');
    $suffix=bin2hex(random_bytes(7));$password='Root-fixture-'.$suffix.'!';$email='root_fixture_'.$suffix.'@example.invalid';
    $role=subscription_query($db,"SELECT role_id FROM roles WHERE role_code='platform_admin' AND role_type='admin'")->fetchColumn();
    subscription_query($db,"INSERT INTO users(role_id,full_name,username,email,password_hash,account_status) VALUES(?,?,?,?,?,'active')",[$role,'Root routing fixture','root_fixture_'.$suffix,$email,password_hash($password,PASSWORD_DEFAULT)]);$user=(int)$db->lastInsertId();
    subscription_query($db,"INSERT INTO activity_logs(actor_user_id,action_code,entity_type,entity_id) VALUES(?,'DEPLOY_ADMIN_PASSWORD_REQUIRED','user',?)",[$user,$user]);
    $form=root_request($curl,'auth/login.php');$login=root_request($curl,'auth/login.php',['csrf_token'=>root_field($form,'csrf_token'),'identifier'=>$email,'password'=>$password]);
    root_expect($login['status']===303 && ($login['headers']['location']??'')==='/auth/change_password.php','Root administrator sign-in failed.');
    $change=root_request($curl,'auth/change_password.php');$next='Changed-root-fixture-'.$suffix.'!';
    $changed=root_request($curl,'auth/change_password.php',['csrf_token'=>root_field($change,'csrf_token'),'request_key'=>root_field($change,'request_key'),'current_password'=>$password,'password'=>$next,'password_confirmation'=>$next]);
    root_expect($changed['status']===303 && !bootstrap_needs_password_change($db,$user),'Root initial password change failed.');
    $form=root_request($curl,'auth/login.php');$login=root_request($curl,'auth/login.php',['csrf_token'=>root_field($form,'csrf_token'),'identifier'=>$email,'password'=>$next]);
    root_expect($login['status']===303 && ($login['headers']['location']??'')==='/admin/dashboard.php' && root_request($curl,'admin/dashboard.php')['status']===200,'Root dashboard access failed.');
    root_expect(root_request($curl,'admin/subscriptions.php')['status']===200,'Root subscription management access failed.');
    echo "PASS: domain-root form/actions/assets, cookie/CSRF, administrator login, mandatory password change, fresh sign-in, dashboard and subscription access; fictional fixture removed and real sending disabled.\n";
}catch(Throwable $e){fwrite(STDERR,'FAIL: '.$e->getMessage().PHP_EOL);$failed=true;}
finally{
    if(is_resource($server)){proc_terminate($server);proc_close($server);}if($curl){curl_close($curl);unset($curl);}
    if($user){$db->beginTransaction();foreach(['notification_outbox','auth_sessions','password_reset_tokens']as$table){subscription_query($db,'DELETE FROM '.$table.' WHERE user_id=?',[$user]);}subscription_query($db,'DELETE FROM activity_logs WHERE actor_user_id=?',[$user]);subscription_query($db,'DELETE FROM users WHERE user_id=?',[$user]);$db->commit();}
    clearstatcache(true);foreach(glob($root.'/*')?:[]as$file){if(is_file($file)){unlink($file);}}rmdir($root);
}
if($failed){exit(1);}
