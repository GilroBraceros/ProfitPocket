<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(403); exit; }
require __DIR__.'/../includes/auth.php'; require __DIR__.'/../includes/admin.php';
function admin_expect(bool $ok,string $message): void { if (!$ok) { throw new RuntimeException($message); } }
function admin_insert(PDO $db,string $sql,array $v): int { admin_query($db,$sql,$v); return (int)$db->lastInsertId(); }
function admin_test_counts(PDO $db): array { $r=[]; foreach (['users','businesses','products','sales','sale_items','sale_payments','customer_credits','expenses','auth_sessions','admin_action_logs','support_tickets','ticket_messages','ticket_status_history','user_feedback','announcements','platform_settings'] as $t) { $r[$t]=$db->query('SELECT COUNT(*) FROM '.$t)->fetchColumn(); } return $r; }
function admin_reject(PDO $db,callable $f,string $message): void { $db->exec('SAVEPOINT rejected_admin_action'); try { $f(); throw new LogicException($message); } catch (AdminDenied|AdminNotFound|InvalidArgumentException $expected) { $db->exec('ROLLBACK TO SAVEPOINT rejected_admin_action'); } }
$db=db(); $baseline=admin_test_counts($db); $suffix=bin2hex(random_bytes(6)); $password='Admin-test-secret-'.$suffix;
try {
    $db->beginTransaction(); $roles=array_column($db->query('SELECT role_id,role_code FROM roles')->fetchAll(),'role_id','role_code');
    $makeUser=fn($role,$name)=>admin_insert($db,'INSERT INTO users (role_id,full_name,username,email,password_hash) VALUES (?,?,?,?,?)',[$roles[$role],$name,$name.'_'.$suffix,$name.'_'.$suffix.'@example.invalid',password_hash($password,PASSWORD_DEFAULT)]);
    $platform=$makeUser('platform_admin','PlatformTest'); $support=$makeUser('support_admin','SupportTest'); $owner=$makeUser('business_owner','OwnerTest'); $actor=['user_id'=>$platform]; $supportActor=['user_id'=>$support];
    $cat=$db->query('SELECT business_category_id FROM business_categories LIMIT 1')->fetchColumn();
    $business=admin_insert($db,'INSERT INTO businesses (owner_user_id,business_category_id,business_name) VALUES (?,?,?)',[$owner,$cat,'Admin test business '.$suffix]);
    $product=admin_insert($db,'INSERT INTO products (business_id,product_name,default_selling_price,default_unit_cost) VALUES (?,?,?,?)',[$business,'Historical product','125.00','50.00']);
    $customer=admin_insert($db,'INSERT INTO customers (business_id,customer_name) VALUES (?,?)',[$business,'Admin fixture customer']);
    $sale=admin_insert($db,"INSERT INTO sales (business_id,customer_id,created_by,sale_number,sale_type,sold_at) VALUES (?,?,?,?,'credit',CURRENT_TIMESTAMP)",[$business,$customer,$owner,'ADMIN-TEST-'.$suffix]);
    admin_query($db,'INSERT INTO sale_items (sale_id,business_id,product_id,product_name_snapshot,quantity,unit_price,unit_cost) VALUES (?,?,?,?,?,?,?)',[$sale,$business,$product,'Historical product','1','125','50']); admin_query($db,'INSERT INTO customer_credits (sale_id) VALUES (?)',[$sale]); admin_query($db,"UPDATE sales SET sale_status='posted' WHERE sale_id=?",[$sale]);
    $financial=sale_read($db,$business,$sale);
    $ownerRow=find_login_user($db,'OwnerTest_'.$suffix); $token=create_login_session($db,$ownerRow,$password);
    $row=admin_read($db,'users',$owner); $key=bin2hex(random_bytes(32)); $input=['revision'=>admin_revision($row),'confirm'=>'1','reason'=>'Test suspension'];
    admin_change($db,$actor,'suspend',$owner,$input,$key); $replay=admin_change($db,$actor,'suspend',$owner,$input,$key);
    admin_expect($replay['replayed'] && session_user($db,$token)===null && sale_read($db,$business,$sale)===$financial,'Suspension/replay changed financial history or retained access');
    admin_reject($db,fn()=>admin_change($db,$actor,'suspend',$owner,array_replace($input,['reason'=>'Different']),$key),'Changed replay accepted');
    admin_reject($db,fn()=>admin_change($db,$supportActor,'reactivate',$owner,$input,bin2hex(random_bytes(32))),'Support admin managed owner');
    admin_reject($db,fn()=>admin_change($db,['user_id'=>$owner,'role_type'=>'admin'],'reactivate',$owner,$input,bin2hex(random_bytes(32))),'Role spoof allowed');
    $row=admin_read($db,'users',$owner); $reactivate=['revision'=>admin_revision($row),'confirm'=>'1','reason'=>'Test reactivation'];
    admin_change($db,$actor,'reactivate',$owner,$reactivate,bin2hex(random_bytes(32)));
    admin_expect(session_user($db,$token)===null && find_login_user($db,'OwnerTest_'.$suffix)['account_status']==='active','Old session revived');
    admin_reject($db,fn()=>admin_change($db,$actor,'suspend',$platform,$input,bin2hex(random_bytes(32))),'Admin account suspended through owner directory');
    echo 'PASS: permissions/role spoofing, confirmed suspension/reactivation, durable retries, revoked sessions and preserved posted financial history'.PHP_EOL;
    $ticket=admin_insert($db,'INSERT INTO support_tickets (submitted_by,subject,description) VALUES (?,?,?)',[$owner,'Test support','Original concern']);
    $row=admin_read($db,'tickets',$ticket); $reply=['revision'=>admin_revision($row),'status'=>'in_progress','message'=>'First <response>']; $key=bin2hex(random_bytes(32));
    admin_change($db,$supportActor,'ticket_update',$ticket,$reply,$key); admin_change($db,$supportActor,'ticket_update',$ticket,$reply,$key);
    admin_expect((int)admin_query($db,'SELECT COUNT(*) FROM ticket_messages WHERE ticket_id=?',[$ticket])->fetchColumn()===1 && (int)admin_query($db,'SELECT COUNT(*) FROM ticket_status_history WHERE ticket_id=?',[$ticket])->fetchColumn()===1,'Ticket reply/history duplicated');
    admin_reject($db,fn()=>admin_change($db,$actor,'ticket_update',$ticket,$reply,bin2hex(random_bytes(32))),'Stale ticket edit accepted');
    $row=admin_read($db,'tickets',$ticket); admin_change($db,$actor,'ticket_update',$ticket,['revision'=>admin_revision($row),'status'=>'resolved','message'=>'Second response'],bin2hex(random_bytes(32)));
    admin_expect((int)admin_query($db,'SELECT COUNT(*) FROM ticket_messages WHERE ticket_id=?',[$ticket])->fetchColumn()===2 && admin_read($db,'tickets',$ticket)['resolved_at']!==null,'Conversation lost or resolution missing');
    $feedback=admin_insert($db,'INSERT INTO user_feedback (submitted_by,message) VALUES (?,?)',[$owner,'Useful suggestion']);
    $row=admin_read($db,'feedback',$feedback); admin_change($db,$supportActor,'feedback_update',$feedback,['revision'=>admin_revision($row),'status'=>'reviewed'],bin2hex(random_bytes(32)));
    admin_expect(admin_read($db,'feedback',$feedback)['state']==='reviewed','Feedback update failed');
    echo 'PASS: ticket replies, status history/assignment/resolution, stale rejection, preserved conversation and reviewed feedback'.PHP_EOL;
    $input=['title'=>'<script>Announcement</script>','body'=>'Plain <b>content</b>','status'=>'draft']; $key=bin2hex(random_bytes(32));
    $new=admin_change($db,$actor,'announcement_create',0,$input,$key); $announcement=$new['id'];
    admin_expect(admin_change($db,$actor,'announcement_create',0,$input,$key)['id']===$announcement,'Announcement duplicated');
    admin_reject($db,fn()=>admin_change($db,$supportActor,'announcement_edit',$announcement,$input,bin2hex(random_bytes(32))),'Support admin edited announcement');
    $row=admin_read($db,'announcements',$announcement); admin_change($db,$actor,'announcement_edit',$announcement,['title'=>'Edited announcement','body'=>'Updated content','status'=>'published','revision'=>admin_revision($row)],bin2hex(random_bytes(32)));
    admin_expect(in_array($announcement,array_column(admin_published_announcements($db,20),'announcement_id')) && (int)admin_read($db,'announcements',$announcement)['author_admin_id']===$platform,'Publication/author failed');
    $row=admin_read($db,'announcements',$announcement); admin_change($db,$actor,'unpublish',$announcement,['revision'=>admin_revision($row),'confirm'=>'1'],bin2hex(random_bytes(32)));
    admin_expect(!in_array($announcement,array_column(admin_published_announcements($db,20),'announcement_id')),'Unpublished message still public');
    if (!admin_query($db,'SELECT setting_key FROM platform_settings WHERE setting_key=?',['platform_description'])->fetchColumn()) {
        admin_query($db,'INSERT INTO platform_settings (setting_key,setting_value,updated_by) VALUES (?,?,?)',['platform_description','Original description',$platform]);
    }
    $row=admin_query($db,'SELECT setting_key,setting_value,updated_at FROM platform_settings WHERE setting_key=?',['platform_description'])->fetch();
    admin_change($db,$actor,'setting',0,['setting_key'=>'platform_description','setting_value'=>'New description','revision'=>admin_revision($row)],bin2hex(random_bytes(32)));
    admin_reject($db,fn()=>admin_change($db,$actor,'setting',0,['setting_key'=>'smtp_password','setting_value'=>'secret'],bin2hex(random_bytes(32))),'Internal setting exposed');
    echo 'PASS: announcement create/edit/publish/unpublish/replay/author preservation, owner integration and safe existing setting edits'.PHP_EOL;
    admin_reject($db,fn()=>admin_change($db,$actor,'role',$owner,['role_id'=>(string)$roles['platform_admin']],bin2hex(random_bytes(32))),'Owner promoted');
    admin_reject($db,fn()=>admin_change($db,$supportActor,'role',$support,['role_id'=>(string)$roles['platform_admin']],bin2hex(random_bytes(32))),'Support admin escalated');
    $db->exec('SAVEPOINT sole_admin');
    admin_query($db,"UPDATE users u JOIN roles r ON r.role_id=u.role_id SET u.account_status='inactive' WHERE r.role_type='admin' AND u.user_id<>?",[$platform]);
    $row=admin_query($db,"SELECT u.user_id,u.role_id,u.account_status,r.role_type FROM users u JOIN roles r ON r.role_id=u.role_id WHERE u.user_id=?",[$platform])->fetch();
    admin_reject($db,fn()=>admin_change($db,$actor,'role',$platform,['role_id'=>(string)$roles['support_admin'],'revision'=>admin_revision($row),'reason'=>'Test','confirm'=>'1'],bin2hex(random_bytes(32))),'Last privileged admin removed');
    $db->exec('ROLLBACK TO SAVEPOINT sole_admin');
    $supportRow=admin_query($db,"SELECT u.user_id,u.role_id,u.account_status,r.role_type FROM users u JOIN roles r ON r.role_id=u.role_id WHERE u.user_id=?",[$support])->fetch();
    admin_change($db,$actor,'role',$support,['role_id'=>(string)$roles['platform_admin'],'revision'=>admin_revision($supportRow),'reason'=>'Controlled promotion','confirm'=>'1'],bin2hex(random_bytes(32)));
    admin_expect((int)admin_query($db,'SELECT role_id FROM users WHERE user_id=?',[$support])->fetchColumn()===(int)$roles['platform_admin'],'Permitted role assignment failed');
    $profile=['full_name'=>'Changed admin','email'=>'changed_'.$suffix.'@example.invalid','phone'=>'','current_password'=>$password,'new_password'=>'New-admin-test-secret-'.$suffix,'confirm_password'=>'New-admin-test-secret-'.$suffix];
    admin_reject($db,fn()=>admin_change($db,$actor,'profile',$support,$profile,bin2hex(random_bytes(32))),'Foreign profile updated');
    admin_reject($db,fn()=>admin_change($db,$actor,'profile',$platform,array_replace($profile,['current_password'=>'wrong']),bin2hex(random_bytes(32))),'Wrong current password accepted');
    $result=admin_change($db,$actor,'profile',$platform,$profile,bin2hex(random_bytes(32))); $changed=find_login_user($db,$profile['email']);
    admin_expect($result['logout'] && password_verify($profile['new_password'],$changed['password_hash']),'Profile password update failed');
    $logs=admin_query($db,'SELECT old_value,new_value FROM admin_action_logs WHERE admin_user_id=?',[$platform])->fetchAll();
    admin_expect(!str_contains(json_encode($logs),$password) && !str_contains(json_encode($logs),$profile['new_password']) && !str_contains(json_encode($logs),'password_hash'),'Audit contains secret');
    admin_expect(admin_safe_log(json_encode(['password_hash'=>'secret','token'=>'secret','amount'=>'999','status'=>'reviewed']))===['status'=>'reviewed'],'Unsafe log payload exposed');
    foreach (['users','businesses','tickets','feedback','announcements','logs'] as $module) { admin_list($db,$module,admin_filters($module,[])); }
    admin_expect(admin_list($db,'users',admin_filters('users',['q'=>'OwnerTest_'.$suffix]))['count']===1,'User search failed');
    admin_expect(admin_list($db,'businesses',admin_filters('businesses',['q'=>$suffix]))['count']===1,'Business search failed');
    admin_expect(admin_list($db,'users',admin_filters('users',['q'=>$suffix,'state'=>'suspended']))['count']===0,'Account status filter failed');
    admin_expect(admin_list($db,'businesses',admin_filters('businesses',['q'=>$suffix,'category'=>(string)$cat]))['count']===1,'Business category filter failed');
    // Pagination must retain the same matching set and deterministic sort order.
    $feedbackIds=[];
    for ($i=0;$i<20;$i++) {
        $feedbackIds[]=admin_insert($db,'INSERT INTO user_feedback (submitted_by,category,message,status) VALUES (?,?,?,?)',[$owner,$i%2?'suggestion':'bug','Directory fixture '.$suffix.' '.$i,$i<4?'reviewed':'new']);
    }
    $first=admin_list($db,'feedback',admin_filters('feedback',['q'=>'Directory fixture '.$suffix,'sort'=>'oldest']));
    $second=admin_list($db,'feedback',admin_filters('feedback',['q'=>'Directory fixture '.$suffix,'sort'=>'oldest','page'=>'2']));
    admin_expect($first['count']===20 && $first['pages']===2 && count($first['rows'])===15 && count($second['rows'])===5 && array_map('intval',array_merge(array_column($first['rows'],'id'),array_column($second['rows'],'id')))===$feedbackIds,'Pagination lost or duplicated matching feedback');
    admin_expect(admin_list($db,'feedback',admin_filters('feedback',['q'=>$suffix,'category'=>'suggestion']))['count']===10 && admin_list($db,'feedback',admin_filters('feedback',['q'=>$suffix,'state'=>'reviewed']))['count']===4,'Feedback category/status filters failed');
    $today=date('Y-m-d');
    $audit=admin_list($db,'logs',admin_filters('logs',['state'=>'admin','actor'=>(string)$platform,'kind'=>'ADMIN_SUSPEND','start'=>$today,'end'=>$today]));
    admin_expect($audit['count']===1 && admin_log_record($audit['rows'][0])==='User #'.$owner,'Audit actor/action/date filters or affected record failed');
    $publicEvent=admin_insert($db,"INSERT INTO activity_logs (actor_user_id,action_code,entity_type,entity_id,after_data) VALUES (?,'USER_REGISTERED','user',?,?)",[$owner,$owner,json_encode(['password_hash'=>'hidden-fixture-secret'])]);
    $visible=admin_read($db,'logs',$publicEvent,'platform');
    admin_expect(admin_log_record($visible)==='User #'.$owner && $visible['new_value']===null,'Nonfinancial platform event metadata failed or secret payload exposed');
    $privateEvent=admin_insert($db,"INSERT INTO activity_logs (actor_user_id,action_code,entity_type,entity_id,after_data) VALUES (?,'USER_REGISTERED','sales',?,?)",[$owner,$sale,json_encode(['amount'=>'125.00'])]);
    admin_reject($db,fn()=>admin_read($db,'logs',$privateEvent,'platform'),'Financial entity exposed through platform event allowlist');
    echo 'PASS: account/business/feedback filters, two-page directories, audit actor/action/date filters, affected record labels and platform event privacy'.PHP_EOL;
    admin_expect(sale_read($db,$business,$sale)===$financial,'Admin changes altered sale history');
    $db->rollBack(); admin_expect(admin_test_counts($db)===$baseline,'Admin fixtures retained');
    echo 'PASS: controlled admin roles/last privileged protection, profile password confirmation/hashing, audit redaction, directories/search and full rollback'.PHP_EOL;
} catch (Throwable $e) { if ($db->inTransaction()) { $db->rollBack(); } fwrite(STDERR,'FAIL: '.$e->getMessage().PHP_EOL); exit(1); }
