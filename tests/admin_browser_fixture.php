<?php
if (PHP_SAPI!=='cli') { http_response_code(403); exit; }
require __DIR__.'/../config/database.php'; $db=db(); $mode=$argv[1]??'';
if ($mode==='create') {
    $suffix=bin2hex(random_bytes(8)); $name='admin_browser_'.$suffix; $password='Browser-admin-'.bin2hex(random_bytes(16)); $roles=array_column($db->query('SELECT role_id,role_code FROM roles')->fetchAll(),'role_id','role_code');
    $db->beginTransaction();
    $db->prepare('INSERT INTO users (role_id,full_name,username,email,password_hash) VALUES (?,?,?,?,?)')->execute([$roles['platform_admin'],'Temporary browser administrator',$name,$name.'@example.invalid',password_hash($password,PASSWORD_DEFAULT)]); $admin=(int)$db->lastInsertId();
    $ownerName='admin_browser_owner_'.$suffix; $db->prepare('INSERT INTO users (role_id,full_name,username,email,password_hash) VALUES (?,?,?,?,?)')->execute([$roles['business_owner'],'Temporary browser owner',$ownerName,$ownerName.'@example.invalid',password_hash($password,PASSWORD_DEFAULT)]); $owner=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO businesses (owner_user_id,business_category_id,business_name) VALUES (?,?,?)')->execute([$owner,$db->query('SELECT business_category_id FROM business_categories LIMIT 1')->fetchColumn(),'Temporary admin browser business']); $business=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO support_tickets (submitted_by,subject,description) VALUES (?,?,?)')->execute([$owner,'Browser support fixture','Original concern']); $ticket=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO user_feedback (submitted_by,message) VALUES (?,?)')->execute([$owner,'Browser feedback fixture']); $feedback=(int)$db->lastInsertId();
    $db->commit(); echo json_encode(compact('name','password','admin','owner','ownerName','business','ticket','feedback'));
} elseif ($mode==='cleanup') {
    $name=$argv[2]??''; if (!preg_match('/\Aadmin_browser_[a-f0-9]{16}\z/',$name)) { exit(1); } $suffix=substr($name,14);
    $s=$db->prepare("SELECT user_id,username FROM users WHERE username IN (?,?) AND email LIKE '%@example.invalid'"); $s->execute([$name,'admin_browser_owner_'.$suffix]); $users=$s->fetchAll();
    $db->beginTransaction();
    foreach ($users as $u) {
        $id=$u['user_id']; $s=$db->prepare('SELECT business_id FROM businesses WHERE owner_user_id=?'); $s->execute([$id]); $biz=$s->fetchColumn();
        if ($biz) { foreach (['sales','expenses','products','customers'] as $t) { $s=$db->prepare('SELECT COUNT(*) FROM '.$t.' WHERE business_id=?'); $s->execute([$biz]); if ($s->fetchColumn()) { throw new RuntimeException('Cleanup stopped: business data found.'); } } }
        $s=$db->prepare('SELECT ticket_id FROM support_tickets WHERE submitted_by=?'); $s->execute([$id]);
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $ticket) { foreach (['ticket_messages','ticket_status_history','support_tickets'] as $t) { $db->prepare('DELETE FROM '.$t.' WHERE ticket_id=?')->execute([$ticket]); } }
        $db->prepare('DELETE FROM user_feedback WHERE submitted_by=?')->execute([$id]); $db->prepare('DELETE FROM announcements WHERE author_admin_id=?')->execute([$id]); $db->prepare('DELETE FROM admin_action_logs WHERE admin_user_id=? OR target_user_id=?')->execute([$id,$id]); $db->prepare('DELETE FROM auth_sessions WHERE user_id=?')->execute([$id]); $db->prepare('DELETE FROM activity_logs WHERE actor_user_id=?')->execute([$id]); $db->prepare('DELETE FROM businesses WHERE owner_user_id=?')->execute([$id]);
    }
    foreach ($users as $u) { $db->prepare('DELETE FROM users WHERE user_id=?')->execute([$u['user_id']]); }
    $db->commit(); echo 'PASS: guarded administrator browser fixtures cleaned.'.PHP_EOL;
} else { exit(1); }
