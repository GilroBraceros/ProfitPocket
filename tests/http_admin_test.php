<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(403); exit; }
require __DIR__.'/../config/database.php';
function ah_expect(bool $ok,string $m): void { if (!$ok) { throw new RuntimeException($m); } }
function ah_client() { $c=curl_init(); curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_COOKIEFILE=>'',CURLOPT_TIMEOUT=>15]); return $c; }
function ah_request($c,string $path,?array $post=null): array { curl_setopt($c,CURLOPT_URL,'http://localhost/profitpocket/'.$path); curl_setopt($c,CURLOPT_POST,$post!==null); if ($post!==null) { curl_setopt($c,CURLOPT_POSTFIELDS,http_build_query($post)); } $body=curl_exec($c); if ($body===false) { throw new RuntimeException(curl_error($c)); } return ['status'=>curl_getinfo($c,CURLINFO_RESPONSE_CODE),'body'=>$body]; }
function ah_field(array $r,string $key): string { ah_expect((bool)preg_match('/name="'.preg_quote($key,'/').'" value="([^"]*)"/',$r['body'],$m),'Missing field '.$key); return html_entity_decode($m[1],ENT_QUOTES,'UTF-8'); }
function ah_form(array $r): array { $a=[]; foreach (['csrf_token','id','revision','request_key','request_signature'] as $k) { $a[$k]=ah_field($r,$k); } return $a; }
function ah_login($c,string $name,string $password): void { $r=ah_request($c,'auth/login.php'); $r=ah_request($c,'auth/login.php',['csrf_token'=>ah_field($r,'csrf_token'),'identifier'=>$name,'password'=>$password]); ah_expect($r['status']===303,'Login failed'); }
function ah_save($c,string $action,array $post): array { $r=ah_request($c,'admin/action.php?action='.$action.'&modal=1',$post); ah_expect($r['status']===200,'Admin POST failed: '.$action.' '.$r['body']); $data=json_decode($r['body'],true,512,JSON_THROW_ON_ERROR); ah_expect($data['success'],'Admin save rejected: '.$action); return $data; }
$db=db(); $suffix=bin2hex(random_bytes(6)); $password='Admin-http-secret-'.$suffix; $ids=[]; $business=0; $ticket=0; $feedback=0; $announcement=0; $admin=ah_client(); $support=ah_client(); $ownerClient=ah_client();
try {
    foreach (['dashboard','users','businesses','tickets','feedback','announcements','logs','settings'] as $module) { ah_expect(ah_request($admin,'admin/'.$module.'.php')['status']===303,'Guest entered '.$module); }
    $roles=array_column($db->query('SELECT role_id,role_code FROM roles')->fetchAll(),'role_id','role_code');
    foreach (['platform_admin','support_admin','business_owner'] as $role) { $name='admin_http_'.$role.'_'.$suffix; $s=$db->prepare('INSERT INTO users (role_id,full_name,username,email,password_hash) VALUES (?,?,?,?,?)'); $s->execute([$roles[$role],'<Test '.$role.'>',$name,$name.'@example.invalid',password_hash($password,PASSWORD_DEFAULT)]); $ids[$role]=(int)$db->lastInsertId(); }
    $s=$db->prepare('INSERT INTO businesses (owner_user_id,business_category_id,business_name) VALUES (?,?,?)'); $s->execute([$ids['business_owner'],$db->query('SELECT business_category_id FROM business_categories LIMIT 1')->fetchColumn(),'Admin HTTP fixture']); $business=(int)$db->lastInsertId();
    $s=$db->prepare('INSERT INTO support_tickets (submitted_by,subject,description) VALUES (?,?,?)'); $s->execute([$ids['business_owner'],'HTTP support <script>','Initial <concern>']); $ticket=(int)$db->lastInsertId();
    $s=$db->prepare('INSERT INTO user_feedback (submitted_by,message) VALUES (?,?)'); $s->execute([$ids['business_owner'],'HTTP feedback']); $feedback=(int)$db->lastInsertId();
    ah_login($ownerClient,'admin_http_business_owner_'.$suffix,$password);
    foreach (['dashboard','users','businesses','tickets','feedback','announcements','logs','settings','action?action=announcement_create'] as $module) { $path=str_contains($module,'?')?str_replace('action?','action.php?',$module):$module.'.php'; ah_expect(ah_request($ownerClient,'admin/'.$path)['status']===403,'Owner entered '.$module); }
    ah_login($admin,'admin_http_platform_admin_'.$suffix,$password); ah_login($support,'admin_http_support_admin_'.$suffix,$password);
    foreach (['dashboard','users','businesses','tickets','feedback','announcements','logs','settings'] as $module) {
        $r=ah_request($admin,'admin/'.$module.'.php'); ah_expect($r['status']===200 && !str_contains($r['body'],'temporarily unavailable'),'Platform page failed: '.$module); ah_expect(!str_contains($r['body'],'password_hash') && !str_contains($r['body'],'token_hash'),'Secret in page '.$module);
        $allowed=in_array($module,['dashboard','tickets','feedback','settings'],true); ah_expect(ah_request($support,'admin/'.$module.'.php')['status']===($allowed?200:403),'Support permission failed '.$module);
    }
    foreach (['q[]=bad','state=invalid','page=0','start=2026-02-30&end=2026-03-01'] as $q) { ah_expect(ah_request($admin,'admin/users.php?'.$q)['status']===400,'Invalid filter accepted'); }
    $r=ah_request($admin,'admin/users.php?q=%3Cscript%3E'); ah_expect(!str_contains($r['body'],'<script>') && str_contains($r['body'],'&lt;script&gt;'),'Unescaped search');
    $id=$ids['business_owner']; $url='admin/action.php?action=suspend&id='.$id.'&modal=1'; $form=ah_form(ah_request($admin,$url)); $input=$form+['confirm'=>'1','reason'=>'HTTP fixture suspension'];
    ah_expect(ah_request($admin,'admin/action.php?action=suspend&modal=1',array_replace($input,['csrf_token'=>'bad']))['status']===403,'CSRF accepted');
    ah_expect(ah_request($support,'admin/action.php?action=suspend&modal=1',$input)['status']===403,'Unauthorized admin POST');
    ah_expect(ah_request($admin,'admin/action.php?action=suspend&modal=1',array_replace($input,['id'=>$ids['platform_admin']]))['status']===403,'ID/signature not bound');
    ah_save($admin,'suspend',$input); ah_expect(ah_save($admin,'suspend',$input)['success'],'Suspension retry failed'); ah_expect(ah_request($ownerClient,'user/dashboard.php')['status']===303,'Suspended session survived');
    $form=ah_form(ah_request($admin,'admin/action.php?action=reactivate&id='.$id.'&modal=1')); ah_save($admin,'reactivate',$form+['confirm'=>'1','reason'=>'Restore fixture']);
    ah_login($ownerClient,'admin_http_business_owner_'.$suffix,$password); ah_expect(ah_request($ownerClient,'user/dashboard.php')['status']===200,'Reactivated login failed');
    $form=ah_form(ah_request($support,'admin/action.php?action=ticket_update&id='.$ticket.'&modal=1')); $input=$form+['message'=>'Actual HTTP reply <b>safe</b>','status'=>'in_progress']; ah_save($support,'ticket_update',$input); ah_save($support,'ticket_update',$input);
    $r=ah_request($admin,'admin/action.php?action=ticket_view&id='.$ticket.'&modal=1'); ah_expect(str_contains($r['body'],'&lt;b&gt;safe&lt;/b&gt;') && !str_contains($r['body'],'<b>safe</b>'),'Ticket escaped/history missing');
    $s=$db->prepare('SELECT COUNT(*) FROM ticket_messages WHERE ticket_id=?'); $s->execute([$ticket]); ah_expect((int)$s->fetchColumn()===1,'Duplicate ticket reply');
    $form=ah_form(ah_request($admin,'admin/action.php?action=feedback_update&id='.$feedback.'&modal=1')); ah_save($admin,'feedback_update',$form+['status'=>'reviewed']);
    $form=ah_form(ah_request($admin,'admin/action.php?action=announcement_create&modal=1')); $input=$form+['title'=>'HTTP announcement <script>','body'=>'Actual <b>content</b>','status'=>'draft']; $save=ah_save($admin,'announcement_create',$input); $announcement=$save['id']; ah_expect(ah_save($admin,'announcement_create',$input)['id']===$announcement,'Announcement retry duplicate');
    foreach (['announcement_edit','publish','unpublish'] as $action) { $form=ah_form(ah_request($admin,'admin/action.php?action='.$action.'&id='.$announcement.'&modal=1')); $post=$form+($action==='announcement_edit'?['title'=>'HTTP edited <script>','body'=>'Updated <b>content</b>','status'=>'draft']:['confirm'=>'1']); ah_save($admin,$action,$post); }
    $r=ah_request($admin,'admin/action.php?action=announcement_view&id='.$announcement.'&modal=1'); ah_expect(str_contains($r['body'],'&lt;script&gt;') && !str_contains($r['body'],'<b>content</b>'),'Announcement XSS');
    ah_expect(ah_request($support,'admin/action.php?action=announcement_create&modal=1',$input)['status']===403,'Support published announcement');
    ah_expect(ah_request($admin,'admin/action.php?action=user_view&modal=1',['id'=>$id])['status']===405,'Read-only detail POST accepted');
    ah_expect(ah_request($admin,'admin/action.php?action=role&id='.$id.'&modal=1')['status']===404,'Owner role assignment exposed');
    $s=$db->prepare('SELECT admin_action_id FROM admin_action_logs WHERE admin_user_id=? ORDER BY admin_action_id DESC LIMIT 1'); $s->execute([$ids['platform_admin']]); $log=$s->fetchColumn();
    $r=ah_request($admin,'admin/action.php?action=log_view&id='.$log.'&modal=1'); ah_expect($r['status']===200 && !str_contains($r['body'],'_request_key') && !str_contains($r['body'],'_payload_hash'),'Internal audit key exposed');
    foreach (['platform_admin','support_admin'] as $assignedRole) {
        $form=ah_form(ah_request($admin,'admin/action.php?action=role&id='.$ids['support_admin'].'&modal=1'));
        ah_save($admin,'role',$form+['role_id'=>(string)$roles[$assignedRole],'confirm'=>'1','reason'=>'Controlled fixture role assignment']);
        ah_expect(ah_request($support,'admin/dashboard.php')['status']===303,'Changed role retained old session');
        ah_login($support,'admin_http_support_admin_'.$suffix,$password);
        ah_expect(ah_request($support,'admin/users.php')['status']===($assignedRole==='platform_admin'?200:403),'Reassigned role permissions not applied on fresh login');
    }
    $form=ah_form(ah_request($admin,'admin/action.php?action=profile&id='.$ids['platform_admin'].'&modal=1'));
    ah_expect(ah_request($admin,'admin/action.php?action=profile&modal=1',$form+['full_name'=>'New','email'=>'new_'.$suffix.'@example.invalid','phone'=>'','current_password'=>'wrong'])['status']===400,'Profile ignored current password');
    ah_save($admin,'profile',$form+['full_name'=>'Changed fixture','email'=>'new_'.$suffix.'@example.invalid','phone'=>'','current_password'=>$password,'new_password'=>'Updated-http-secret-'.$suffix,'confirm_password'=>'Updated-http-secret-'.$suffix]);
    ah_expect(ah_request($admin,'admin/dashboard.php')['status']===303,'Profile sessions retained');
    ah_login($admin,'admin_http_platform_admin_'.$suffix,'Updated-http-secret-'.$suffix);
    echo 'PASS: actual admin/owner logins, permissions, CSRF/ID binding, suspension/session loss/reactivation, ticket reply/history/replay, feedback, announcement CRUD/publication/escaping, private audit, controlled role reassignment/session loss and profile password/session changes'.PHP_EOL;
} catch (Throwable $e) { fwrite(STDERR,'FAIL: '.$e->getMessage().PHP_EOL); $failed=true;
} finally {
    $db->beginTransaction();
    if ($business) { $s=$db->prepare('SELECT COUNT(*) FROM sales WHERE business_id=?'); $s->execute([$business]); if ((int)$s->fetchColumn()!==0) { throw new RuntimeException('Fixture cleanup stopped: financial history found.'); } }
    if ($ticket) { foreach (['ticket_messages','ticket_status_history','support_tickets'] as $t) { $db->prepare('DELETE FROM '.$t.' WHERE ticket_id=?')->execute([$ticket]); } }
    if ($feedback) { $db->prepare('DELETE FROM user_feedback WHERE feedback_id=?')->execute([$feedback]); }
    if (isset($ids['platform_admin'])) { $db->prepare('DELETE FROM announcements WHERE author_admin_id=?')->execute([$ids['platform_admin']]); }
    foreach ($ids as $id) { $db->prepare('DELETE FROM admin_action_logs WHERE admin_user_id=? OR target_user_id=?')->execute([$id,$id]); $db->prepare('DELETE FROM auth_sessions WHERE user_id=?')->execute([$id]); }
    if ($business) { $db->prepare('DELETE FROM businesses WHERE business_id=?')->execute([$business]); }
    foreach ($ids as $id) { $db->prepare('DELETE FROM activity_logs WHERE actor_user_id=?')->execute([$id]); $db->prepare('DELETE FROM users WHERE user_id=?')->execute([$id]); }
    $db->commit(); echo 'PASS: only this run’s disposable admin/support/owner and nonfinancial records removed'.PHP_EOL;
    curl_close($admin); curl_close($support); curl_close($ownerClient);
}
if ($failed??false) { exit(1); }
