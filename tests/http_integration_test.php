<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(403); exit; }
require __DIR__.'/../config/database.php';
require __DIR__.'/subscription_test_helpers.php';
function integration_expect(bool $ok,string $message): void { if (!$ok) { throw new RuntimeException($message); } }
function integration_client() { $client=curl_init(); curl_setopt_array($client,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_COOKIEFILE=>'',CURLOPT_TIMEOUT=>15]); return $client; }
function integration_request($client,string $path,?array $post=null): array {
    curl_setopt($client,CURLOPT_URL,'http://localhost/profitpocket/'.$path); curl_setopt($client,CURLOPT_POST,$post!==null);
    if ($post!==null) { curl_setopt($client,CURLOPT_POSTFIELDS,http_build_query($post)); }
    $body=curl_exec($client); if ($body===false) { throw new RuntimeException(curl_error($client)); }
    return ['status'=>curl_getinfo($client,CURLINFO_RESPONSE_CODE),'body'=>$body];
}
function integration_field(array $response,string $key): string {
    integration_expect((bool)preg_match('/name="'.preg_quote($key,'/').'" value="([^"]*)"/',$response['body'],$match),'Missing form field '.$key);
    return html_entity_decode($match[1],ENT_QUOTES,'UTF-8');
}
function integration_form(array $response): array {
    integration_expect($response['status']===200,'Form unavailable: '.$response['body']); $form=[];
    foreach (['csrf_token','id','request_key','request_signature'] as $key) { $form[$key]=integration_field($response,$key); }
    if (str_contains($response['body'],'name="revision"')) { $form['revision']=integration_field($response,'revision'); }
    return $form;
}
function integration_login($client,string $username,string $password): void {
    $page=integration_request($client,'auth/login.php');
    $response=integration_request($client,'auth/login.php',['csrf_token'=>integration_field($page,'csrf_token'),'identifier'=>$username,'password'=>$password]);
    integration_expect($response['status']===303,'Fixture login failed');
}
function integration_save($client,string $side,string $action,array $input): array {
    $response=integration_request($client,$side.'/'.($side==='admin'?'action':'support_action').'.php?action='.$action.'&modal=1',$input);
    integration_expect($response['status']===200,'Save failed '.$action.': '.$response['body']);
    $result=json_decode($response['body'],true,512,JSON_THROW_ON_ERROR); integration_expect($result['success'],'Action rejected'); return $result;
}
function integration_count(array $response,string $key): int {
    integration_expect((bool)preg_match('/data-admin-count="'.$key.'">(\d+)/',$response['body'],$match),'Missing dashboard count '.$key); return (int)$match[1];
}
$db=db(); $suffix=bin2hex(random_bytes(6)); $password='Integration-'.bin2hex(random_bytes(16));
$admin=integration_client(); $owner=integration_client(); $other=integration_client(); $guest=integration_client(); $ids=[]; $failed=false;
try {
    $roles=array_column($db->query('SELECT role_code,role_id FROM roles')->fetchAll(),'role_id','role_code');
    $name='integration_admin_'.$suffix;
    $db->prepare('INSERT INTO users (role_id,full_name,username,email,password_hash) VALUES (?,?,?,?,?)')->execute([$roles['platform_admin'],'Integration administrator',$name,$name.'@example.invalid',password_hash($password,PASSWORD_DEFAULT)]);
    $ids[]=(int)$db->lastInsertId(); integration_login($admin,$name,$password);
    $before=integration_request($admin,'admin/dashboard.php');
    foreach (['owner'=>$owner,'other'=>$other] as $label=>$client) {
        $username='integration_'.$label.'_'.$suffix; $page=integration_request($client,'auth/register.php');
        $response=integration_request($client,'auth/register.php',['csrf_token'=>integration_field($page,'csrf_token'),'request_key'=>integration_field($page,'request_key'),'full_name'=>'Integration '.$label.' '.$suffix,'username'=>$username,'email'=>$username.'@example.invalid','business_name'=>'Integration business '.$label.' '.$suffix,'business_category_id'=>(string)$db->query('SELECT business_category_id FROM business_categories LIMIT 1')->fetchColumn(),'password'=>$password,'password_confirmation'=>$password]);
        integration_expect($response['status']===303,'Owner registration failed');
        $statement=$db->prepare('SELECT user_id FROM users WHERE username=?'); $statement->execute([$username]); $ids[]=(int)$statement->fetchColumn();
        test_activate_subscription($db,(int)end($ids));
        integration_login($client,$username,$password);
    }
    $after=integration_request($admin,'admin/dashboard.php');
    integration_expect(integration_count($after,'total')===integration_count($before,'total')+2 && integration_count($after,'businesses')===integration_count($before,'businesses')+2,'Registration did not update platform counts');
    integration_expect(str_contains(integration_request($admin,'admin/businesses.php?q='.$suffix)['body'],'Integration business owner'),'Registered business missing from directory');
    $form=integration_form(integration_request($admin,'admin/action.php?action=announcement_create&modal=1'));
    $announcement=integration_save($admin,'admin','announcement_create',$form+['title'=>'Integration announcement '.$suffix,'body'=>'Read the whole <b>announcement</b> safely.','status'=>'draft'])['id'];
    $url='user/support_action.php?action=announcement&id='.$announcement.'&modal=1';
    integration_expect(!str_contains(integration_request($owner,'user/dashboard.php')['body'],'Integration announcement '.$suffix) && integration_request($owner,$url)['status']===404,'Draft announcement exposed');
    $form=integration_form(integration_request($admin,'admin/action.php?action=publish&id='.$announcement.'&modal=1')); integration_save($admin,'admin','publish',$form+['confirm'=>'1']);
    integration_expect(str_contains(integration_request($owner,'user/dashboard.php')['body'],'Integration announcement '.$suffix),'Published announcement missing from owner dashboard');
    $detail=integration_request($owner,$url);
    integration_expect($detail['status']===200 && str_contains($detail['body'],'&lt;b&gt;announcement&lt;/b&gt;') && !str_contains($detail['body'],'<b>announcement</b>'),'Announcement full details/escaping failed');
    $db->prepare('UPDATE announcements SET published_at=DATE_ADD(CURRENT_TIMESTAMP,INTERVAL 1 DAY) WHERE announcement_id=?')->execute([$announcement]);
    integration_expect(integration_request($owner,$url)['status']===404,'Future publication exposed');
    $db->prepare('UPDATE announcements SET published_at=CURRENT_TIMESTAMP WHERE announcement_id=?')->execute([$announcement]);
    $form=integration_form(integration_request($admin,'admin/action.php?action=unpublish&id='.$announcement.'&modal=1')); integration_save($admin,'admin','unpublish',$form+['confirm'=>'1']);
    integration_expect(!str_contains(integration_request($owner,'user/dashboard.php')['body'],'Integration announcement '.$suffix) && integration_request($owner,$url)['status']===404,'Unpublished announcement remained visible');
    integration_expect(integration_request($guest,$url)['status']===303,'Guest announcement access');
    echo 'PASS: real registration/business counts and complete draft/publish/read/future/unpublish announcement workflow'.PHP_EOL;
    $form=integration_form(integration_request($owner,'user/support_action.php?action=new_ticket&modal=1')); $input=$form+['subject'=>'Owner request '.$suffix,'description'=>'Help with <script> text','priority'=>'high','submitted_by'=>(string)$ids[2]];
    integration_expect(integration_request($owner,'user/support_action.php?action=new_ticket&modal=1',array_replace($input,['csrf_token'=>'bad']))['status']===403,'Support CSRF accepted');
    integration_expect(integration_request($admin,'user/support_action.php?action=new_ticket&modal=1',$input)['status']===403,'Admin submitted owner ticket');
    $ticket=integration_save($owner,'user','new_ticket',$input)['id'];
    integration_expect(integration_save($owner,'user','new_ticket',$input)['id']===$ticket,'Ticket retry duplicated');
    $detailUrl='user/support_action.php?action=ticket&id='.$ticket.'&modal=1';
    integration_expect(integration_request($other,$detailUrl)['status']===404,'Another owner read private support');
    integration_expect(str_contains(integration_request($admin,'admin/tickets.php?q='.$suffix)['body'],'Owner request '.$suffix),'Admin did not see owner ticket');
    $now=integration_request($admin,'admin/dashboard.php');
    integration_expect(integration_count($now,'tickets')===integration_count($after,'tickets')+1,'New ticket count did not update');
    $form=integration_form(integration_request($admin,'admin/action.php?action=ticket_update&id='.$ticket.'&modal=1'));
    integration_save($admin,'admin','ticket_update',$form+['message'=>'Administrator reply <b>safe</b>','status'=>'resolved']);
    $response=integration_request($owner,$detailUrl);
    integration_expect(str_contains($response['body'],'Administrator reply &lt;b&gt;safe&lt;/b&gt;') && str_contains($response['body'],'Resolved'),'Owner did not see admin response/status');
    $form=integration_form(integration_request($owner,'user/support_action.php?action=reply&id='.$ticket.'&modal=1'));
    integration_save($owner,'user','reply',$form+['message'=>'Owner follow-up']); integration_save($owner,'user','reply',$form+['message'=>'Owner follow-up']);
    $response=integration_request($admin,'admin/action.php?action=ticket_view&id='.$ticket.'&modal=1');
    integration_expect(str_contains($response['body'],'Owner follow-up') && str_contains($response['body'],'Administrator reply'),'Conversation not preserved');
    integration_expect(str_contains(integration_request($owner,$detailUrl)['body'],'Open'),'Follow-up did not reopen resolved ticket');
    $form=integration_form(integration_request($admin,'admin/action.php?action=ticket_update&id='.$ticket.'&modal=1'));
    integration_save($admin,'admin','ticket_update',$form+['message'=>'Closing fixture','status'=>'closed']);
    integration_expect(integration_request($owner,'user/support_action.php?action=reply&id='.$ticket.'&modal=1')['status']===400,'Closed ticket offered follow-up');
    echo 'PASS: owner ticket submission, admin reply, owner read/follow-up/reopening, complete history, retries, status/count synchronization and owner isolation'.PHP_EOL;
    $form=integration_form(integration_request($owner,'user/support_action.php?action=feedback&modal=1'));
    $feedback=integration_save($owner,'user','feedback',$form+['category'=>'bug','message'=>'Owner feedback '.$suffix])['id'];
    integration_expect(str_contains(integration_request($admin,'admin/feedback.php?q='.$suffix)['body'],'Owner feedback '.$suffix),'Owner feedback missing for admin');
    $form=integration_form(integration_request($admin,'admin/action.php?action=feedback_update&id='.$feedback.'&modal=1')); integration_save($admin,'admin','feedback_update',$form+['status'=>'reviewed']);
    $response=integration_request($owner,'user/support.php?tab=feedback'); integration_expect(str_contains($response['body'],'Owner feedback '.$suffix) && str_contains($response['body'],'Reviewed'),'Feedback review state missing for owner');
    integration_expect(!str_contains(integration_request($other,'user/support.php?tab=feedback')['body'],'Owner feedback '.$suffix),'Other owner saw feedback');
    $statement=$db->prepare("SELECT COUNT(*) FROM activity_logs WHERE actor_user_id=? AND action_code='USER_REGISTERED'"); $statement->execute([$ids[1]]); integration_expect((int)$statement->fetchColumn()===1,'Registration event missing');
    $statement=$db->prepare("SELECT COUNT(*) FROM activity_logs WHERE actor_user_id=? AND action_code='AUTH_ADMIN_LOGIN'"); $statement->execute([$ids[0]]); integration_expect((int)$statement->fetchColumn()===1,'Admin login event missing');
    $settings=integration_request($admin,'admin/settings.php'); integration_expect($settings['status']===200,'Admin settings unavailable');
    $statement=$db->query("SELECT setting_value FROM platform_settings WHERE setting_key='support_email'"); $contact=$statement->fetchColumn();
    if ($contact && filter_var($contact,FILTER_VALIDATE_EMAIL)) { integration_expect(str_contains(integration_request($owner,'user/support.php')['body'],htmlspecialchars($contact,ENT_QUOTES,'UTF-8')),'Support setting disconnected'); }
    $form=integration_form(integration_request($admin,'admin/action.php?action=suspend&id='.$ids[1].'&modal=1')); integration_save($admin,'admin','suspend',$form+['confirm'=>'1','reason'=>'Isolated integration fixture']);
    foreach (['user/support.php',$detailUrl,'user/dashboard.php','user/sales.php','user/reports.php'] as $path) { integration_expect(integration_request($owner,$path)['status']===303,'Suspended account reached '.$path); }
    $form=integration_form(integration_request($admin,'admin/action.php?action=reactivate&id='.$ids[1].'&modal=1')); integration_save($admin,'admin','reactivate',$form+['confirm'=>'1','reason'=>'Restore integration fixture']);
    integration_expect(integration_request($owner,'user/support.php')['status']===303,'Reactivation revived old session');
    integration_login($owner,'integration_owner_'.$suffix,$password); integration_expect(integration_request($owner,'user/support.php')['status']===200,'Reactivated support access failed');
    echo 'PASS: feedback submission/review/isolation, actual registration/admin-login audit, support contact consumption, CSRF/role rejection and suspension/reactivation on integrated routes'.PHP_EOL;
} catch (Throwable $e) { fwrite(STDERR,'FAIL: '.$e->getMessage().PHP_EOL); $failed=true;
} finally {
    // Discover this run's accounts even if an assertion failed immediately after registration.
    $statement=$db->prepare("SELECT user_id FROM users WHERE username IN (?,?,?)"); $statement->execute(['integration_admin_'.$suffix,'integration_owner_'.$suffix,'integration_other_'.$suffix]); $ids=array_unique(array_merge($ids,$statement->fetchAll(PDO::FETCH_COLUMN)));
    $db->beginTransaction();
    foreach ($ids as $id) {
        $statement=$db->prepare('SELECT business_id FROM businesses WHERE owner_user_id=?'); $statement->execute([$id]); $business=$statement->fetchColumn();
        if ($business) { foreach (['sales','expenses','products','customers'] as $table) { $statement=$db->prepare('SELECT COUNT(*) FROM '.$table.' WHERE business_id=?'); $statement->execute([$business]); integration_expect(!(int)$statement->fetchColumn(),'Cleanup blocked: fixture financial data'); } }
        $statement=$db->prepare('SELECT ticket_id FROM support_tickets WHERE submitted_by=?'); $statement->execute([$id]);
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $ticketId) { foreach (['ticket_messages','ticket_status_history','support_tickets'] as $table) { $db->prepare('DELETE FROM '.$table.' WHERE ticket_id=?')->execute([$ticketId]); } }
        $db->prepare('DELETE FROM user_feedback WHERE submitted_by=?')->execute([$id]);
        $db->prepare('DELETE FROM announcements WHERE author_admin_id=?')->execute([$id]);
        $db->prepare('DELETE FROM admin_action_logs WHERE admin_user_id=? OR target_user_id=?')->execute([$id,$id]);
        $db->prepare('DELETE FROM auth_sessions WHERE user_id=?')->execute([$id]);
        $db->prepare('DELETE FROM activity_logs WHERE actor_user_id=?')->execute([$id]);
        $db->prepare('DELETE FROM businesses WHERE owner_user_id=?')->execute([$id]);
    }
    foreach ($ids as $id) { $db->prepare('DELETE FROM users WHERE user_id=?')->execute([$id]); }
    $db->commit(); foreach ([$admin,$owner,$other,$guest] as $client) { curl_close($client); }
    echo 'PASS: exact nonfinancial integration fixtures removed'.PHP_EOL;
}
if ($failed) { exit(1); }
