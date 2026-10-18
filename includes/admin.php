<?php
declare(strict_types=1);
require_once __DIR__.'/sales.php';
require_once __DIR__.'/platform.php';

class AdminDenied extends RuntimeException {}
class AdminNotFound extends RuntimeException {}
const ADMIN_MODULES=[
    'dashboard'=>['Dashboard','ProfitPocket platform overview','platform_metrics.view','grid-1x2'],
    'users'=>['User Management','Manage registered ProfitPocket accounts.','users.manage','people'],
    'businesses'=>['Business Management','View and manage registered businesses.','users.manage','box-seam'],
    'tickets'=>['Support Tickets','Review and respond to user concerns.','support.manage','journal-text'],
    'feedback'=>['User Feedback','Review suggestions and feedback from ProfitPocket users.','support.manage','journal-text'],
    'announcements'=>['Announcements','Manage important platform announcements.','announcements.manage','journal-plus'],
    'logs'=>['Activity Logs','Review important system and administrative activities.','users.manage','list'],
    'settings'=>['System Settings','Manage your account and authorized platform settings.','','gear'],
];
const ADMIN_ACTIONS=[
    'user_view'=>['users','View account',false], 'suspend'=>['users','Suspend Account',true], 'reactivate'=>['users','Reactivate Account',true],
    'business_view'=>['businesses','Business profile',false], 'ticket_view'=>['tickets','Ticket details',false], 'ticket_update'=>['tickets','Respond / update ticket',true],
    'feedback_view'=>['feedback','Feedback details',false], 'feedback_update'=>['feedback','Review feedback',true],
    'announcement_view'=>['announcements','Announcement details',false], 'announcement_create'=>['announcements','Create Announcement',true],
    'announcement_edit'=>['announcements','Edit Announcement',true], 'publish'=>['announcements','Publish Announcement',true], 'unpublish'=>['announcements','Unpublish Announcement',true],
    'log_view'=>['logs','Activity details',false], 'profile'=>['settings','Update administrator profile',true],
    'setting'=>['settings','Update platform setting',true], 'role'=>['settings','Change administrator role',true],
];
const ADMIN_SETTING_KEYS=PLATFORM_PUBLIC_KEYS;
function admin_action_label(string $code): string
{
    return match($code) {
        'USER_REGISTERED'=>'Owner registered','AUTH_ADMIN_LOGIN'=>'Administrator signed in',
        'OWNER_TICKET_CREATED'=>'Support ticket submitted','OWNER_TICKET_REPLIED'=>'Owner follow-up added',
        'OWNER_FEEDBACK_SUBMITTED'=>'Feedback submitted','ADMIN_SUSPEND'=>'Account suspended',
        'ADMIN_REACTIVATE'=>'Account reactivated','ADMIN_TICKET_UPDATE'=>'Support ticket updated',
        'ADMIN_FEEDBACK_UPDATE'=>'Feedback reviewed','ADMIN_ANNOUNCEMENT_CREATE'=>'Announcement created',
        'ADMIN_ANNOUNCEMENT_EDIT'=>'Announcement edited','ADMIN_PUBLISH'=>'Announcement published',
        'ADMIN_UNPUBLISH'=>'Announcement unpublished','ADMIN_SETTING'=>'Platform setting updated',
        'ADMIN_ROLE'=>'Administrator role changed','ADMIN_PROFILE'=>'Administrator profile updated',
        default=>ucwords(strtolower(str_replace('_',' ',$code))),
    };
}
function admin_query(PDO $db,string $sql,array $values=[]): PDOStatement { $s=$db->prepare($sql); $s->execute($values); return $s; }
function admin_permissions(PDO $db,int $role): array { return admin_query($db,'SELECT p.permission_code FROM role_permissions rp JOIN permissions p ON p.permission_id=rp.permission_id WHERE rp.role_id=?',[$role])->fetchAll(PDO::FETCH_COLUMN); }
function admin_can(array $permissions,string $permission): bool { return $permission==='' || in_array($permission,$permissions,true); }
function admin_action_permission(string $action): string { return match($action) {'setting'=>'settings.manage','role'=>'users.manage',default=>ADMIN_MODULES[ADMIN_ACTIONS[$action][0]][2]}; }
function admin_authorize(PDO $db,int $actor,string $permission='',bool $lock=false): array
{
    if ($lock) {
        if (!$db->inTransaction()) { throw new LogicException('Admin changes require a transaction.'); }
        // All admin writes share these role locks, including concurrent role changes.
        admin_query($db,"SELECT role_id FROM roles WHERE role_type='admin' ORDER BY role_id FOR UPDATE")->fetchAll();
    }
    $u=admin_query($db,"SELECT u.user_id,u.role_id,u.account_status,r.role_type FROM users u JOIN roles r ON r.role_id=u.role_id WHERE u.user_id=?".($lock?' FOR UPDATE':''),[$actor])->fetch();
    if (!$u || $u['role_type']!=='admin' || $u['account_status']!=='active' || !admin_can(admin_permissions($db,(int)$u['role_id']),$permission)) { throw new AdminDenied('You do not have permission for this action.'); }
    return $u;
}
function admin_text(array $input,string $key,int $limit,bool $required=false): string
{
    $text=sale_text($input,$key);
    if (!mb_check_encoding($text,'UTF-8') || mb_strlen($text)>$limit || ($required && $text==='')) { throw new InvalidArgumentException('Enter '.str_replace('_',' ',$key).' within '.$limit.' characters.'); }
    return $text;
}
function admin_filters(string $module,array $input): array
{
    $f=[]; foreach (['q'=>'','state'=>'all','category'=>'','sort'=>'newest','start'=>'','end'=>'','actor'=>'','kind'=>'','page'=>'1'] as $k=>$v) { $f[$k]=sale_text($input,$k,$v); }
    $states=match($module) {'users','businesses'=>['active','inactive','suspended','deactivated'],'tickets'=>['open','in_progress','resolved','closed'],'feedback'=>['new','reviewed','archived'],'announcements'=>['draft','published','archived'],'logs'=>['admin','platform'],default=>[]};
    if (!mb_check_encoding($f['q'],'UTF-8') || mb_strlen($f['q'])>100 || !in_array($f['state'],array_merge(['all'],$states),true) || !in_array($f['sort'],['newest','oldest'],true) || !ctype_digit($f['page']) || strlen($f['page'])>6 || (int)$f['page']<1) { throw new InvalidArgumentException('Choose valid search and filters.'); }
    if ($f['actor']!=='') { $f['actor']=(string)sale_id($f['actor']); }
    if ($f['kind']!=='' && !preg_match('/\A[A-Z0-9_]{1,80}\z/',$f['kind'])) { throw new InvalidArgumentException('Choose a valid action type.'); }
    if ($f['category']!=='' && $module==='businesses') { $f['category']=(string)sale_id($f['category']); }
    if ($f['category']!=='' && $module==='feedback' && !in_array($f['category'],['bug','suggestion','compliment','other'],true)) { throw new InvalidArgumentException('Choose a feedback category.'); }
    if ($f['start']!=='' || $f['end']!=='') { dashboard_period(['period'=>'custom','start'=>$f['start'],'end'=>$f['end']]); }
    $f['page']=(int)$f['page']; return $f;
}
function admin_sources(string $module): array
{
    return match($module) {
        'users'=>['u.user_id AS id,u.full_name,u.username,u.email,u.account_status AS state,u.created_at,u.last_login_at,u.phone,b.business_name', "FROM users u JOIN roles r ON r.role_id=u.role_id LEFT JOIN businesses b ON b.owner_user_id=u.user_id WHERE r.role_type='owner'",'u.created_at','u.user_id',["u.full_name","u.username","u.email"],'u.account_status'],
        'businesses'=>['b.business_id AS id,b.business_name,b.description,b.address,b.contact_number,b.created_at,c.category_name,u.full_name,u.email,u.account_status AS state',"FROM businesses b JOIN business_categories c ON c.business_category_id=b.business_category_id JOIN users u ON u.user_id=b.owner_user_id WHERE 1=1",'b.created_at','b.business_id',['b.business_name','u.full_name'],'u.account_status'],
        'tickets'=>['t.ticket_id AS id,t.subject,t.description,t.priority,t.ticket_status AS state,t.created_at,t.updated_at,t.resolved_at,t.assigned_admin_id,u.full_name,u.email',"FROM support_tickets t JOIN users u ON u.user_id=t.submitted_by WHERE 1=1",'t.created_at','t.ticket_id',['t.subject','u.full_name'],'t.ticket_status'],
        'feedback'=>["f.feedback_id AS id,f.message,f.category,f.status AS state,f.created_at,COALESCE(u.full_name,'Anonymous') AS full_name","FROM user_feedback f LEFT JOIN users u ON u.user_id=f.submitted_by WHERE 1=1",'f.created_at','f.feedback_id',['f.message',"COALESCE(u.full_name,'Anonymous')"],'f.status'],
        'announcements'=>['a.announcement_id AS id,a.title,a.body,a.announcement_status AS state,a.created_at,a.published_at,a.author_admin_id,u.full_name',"FROM announcements a JOIN users u ON u.user_id=a.author_admin_id WHERE 1=1",'a.created_at','a.announcement_id',['a.title'],'a.announcement_status'],
        // Financial event payloads and login failure identifiers never enter this source.
        'logs'=>["l.id,l.source,l.created_at,l.actor,l.target,l.action_code,l.reason,u.full_name,l.old_value,l.new_value,l.entity_type,l.entity_id","FROM (
            SELECT admin_action_id AS id,'admin' AS source,created_at,admin_user_id AS actor,target_user_id AS target,action_code,reason,old_value,new_value,NULL AS entity_type,NULL AS entity_id FROM admin_action_logs
            UNION ALL SELECT activity_id,'platform',occurred_at,actor_user_id,NULL,action_code,'Platform event',NULL,NULL,entity_type,entity_id FROM activity_logs
            WHERE business_id IS NULL AND entity_type IN ('user','platform','support_ticket','announcement','user_feedback') AND action_code IN ('USER_REGISTERED','AUTH_ADMIN_LOGIN','OWNER_TICKET_CREATED','OWNER_TICKET_REPLIED','OWNER_FEEDBACK_SUBMITTED','PLATFORM_UPDATED','ANNOUNCEMENT_PUBLISHED')
            ) l LEFT JOIN users u ON u.user_id=l.actor WHERE 1=1",'l.created_at','l.id',['l.action_code',"COALESCE(u.full_name,'System')",'COALESCE(l.reason,\'\')'],'l.source'],
    };
}
function admin_list(PDO $db,string $module,array $f): array
{
    [$select,$source,$date,$id,$search,$state]=admin_sources($module); $values=[];
    if ($f['q']!=='') { $source.=' AND ('.implode(' OR ',array_map(fn($s)=>'LOCATE(?,'.$s.')>0',$search)).')'; foreach ($search as $unused) { $values[]=$f['q']; } }
    if ($f['state']!=='all') { $source.=' AND '.$state.'=?'; $values[]=$f['state']; }
    if ($module==='businesses' && $f['category']!=='') { $source.=' AND b.business_category_id=?'; $values[]=$f['category']; }
    if ($module==='feedback' && $f['category']!=='') { $source.=' AND f.category=?'; $values[]=$f['category']; }
    if ($module==='logs' && $f['actor']!=='') { $source.=' AND l.actor=?'; $values[]=$f['actor']; }
    if ($module==='logs' && $f['kind']!=='') { $source.=' AND l.action_code=?'; $values[]=$f['kind']; }
    if ($f['start']!=='') { $source.=' AND '.$date.'>=? AND '.$date.'<?'; array_push($values,$f['start'],dashboard_date($f['end'])->modify('+1 day')->format('Y-m-d')); }
    $count=(int)admin_query($db,'SELECT COUNT(*) '.$source,$values)->fetchColumn(); $pages=max(1,(int)ceil($count/15)); $page=min($f['page'],$pages);
    $direction=$f['sort']==='oldest'?'ASC':'DESC';
    $rows=admin_query($db,'SELECT '.$select.' '.$source." ORDER BY $date $direction,$id $direction LIMIT 15 OFFSET ".(($page-1)*15),$values)->fetchAll();
    return compact('rows','count','pages','page');
}
function admin_read(PDO $db,string $module,int $id,string $source='admin'): array
{
    [$select,$from,$unused,$column]=admin_sources($module); $values=[$id]; $from.=' AND '.$column.'=?';
    if ($module==='logs') { if (!in_array($source,['admin','platform'],true)) { throw new AdminNotFound('Activity not found.'); } $from.=' AND l.source=?'; $values[]=$source; }
    $row=admin_query($db,'SELECT '.$select.' '.$from,$values)->fetch();
    if (!$row) { throw new AdminNotFound('Record not found.'); } return $row;
}
function admin_revision(array $row): string { return hash('sha256',json_encode($row,JSON_THROW_ON_ERROR)); }
function admin_check_revision(array $row,array $input): void { if (!hash_equals(admin_revision($row),sale_text($input,'revision'))) { throw new InvalidArgumentException('This record changed. Reopen it before saving.'); } }
function admin_audit(PDO $db,int $actor,?int $target,string $action,array $before,array $after,string $reason,string $key,string $hash): void
{
    $ip=$_SERVER['REMOTE_ADDR']??''; $after['_ip']=filter_var($ip,FILTER_VALIDATE_IP)?$ip:null;
    $after['_request_key']=$key; $after['_payload_hash']=$hash;
    admin_query($db,'INSERT INTO admin_action_logs (admin_user_id,target_user_id,action_code,old_value,new_value,reason) VALUES (?,?,?,?,?,?)',[$actor,$target,$action,json_encode($before,JSON_THROW_ON_ERROR),json_encode($after,JSON_THROW_ON_ERROR),$reason?:null]);
}
function admin_safe_log(?string $json): array
{
    $data=json_decode($json??'',true); if (!is_array($data)) { return []; }
    $allowed=['entity','id','account_status','role_id','full_name','email','phone','status','title','setting_key','setting_value','password_changed'];
    $safe=[]; foreach ($allowed as $key) { if (isset($data[$key]) && is_scalar($data[$key])) { if ($key==='setting_value' && !in_array($data['setting_key']??'',ADMIN_SETTING_KEYS,true)) { continue; } $safe[$key]=(string)$data[$key]; } } return $safe;
}
function admin_log_record(array $row): string
{
    if (!empty($row['target'])) { return 'User #'.(int)$row['target']; }
    $safe=admin_safe_log($row['new_value']??null);
    if (in_array($safe['setting_key']??'',ADMIN_SETTING_KEYS,true)) { return ucwords(str_replace('_',' ',$safe['setting_key'])); }
    $entity=$safe['entity']??$row['entity_type']??''; $id=$safe['id']??$row['entity_id']??null;
    $labels=['users'=>'User','user'=>'User','tickets'=>'Ticket','support_ticket'=>'Ticket','feedback'=>'Feedback','user_feedback'=>'Feedback','announcements'=>'Announcement','announcement'=>'Announcement','platform'=>'Platform'];
    return isset($labels[$entity]) && ctype_digit((string)$id) && (int)$id>0?$labels[$entity].' #'.(int)$id:'Not recorded';
}
function admin_change(PDO $db,array $actor,string $action,int $id,array $input,string $key): array
{
    if (!isset(ADMIN_ACTIONS[$action]) || !ADMIN_ACTIONS[$action][2]) { throw new AdminDenied('This action is read-only.'); }
    $current=admin_authorize($db,(int)$actor['user_id'],admin_action_permission($action),true); $actorId=(int)$current['user_id'];
    if ($action==='role' && !admin_can(admin_permissions($db,(int)$current['role_id']),'settings.manage')) { throw new AdminDenied('Role changes also require settings permission.'); }
    if (!preg_match('/\A[a-f0-9]{64}\z/',$key)) { throw new InvalidArgumentException('Invalid submission. Reopen the form.'); }
    $payload=$input; foreach (['csrf_token','request_key','request_signature','current_password','new_password','confirm_password'] as $field) { unset($payload[$field]); }
    ksort($payload); $hash=hash('sha256',json_encode([$action,$id,$payload],JSON_THROW_ON_ERROR));
    $previous=admin_query($db,"SELECT new_value FROM admin_action_logs WHERE admin_user_id=? AND JSON_UNQUOTE(JSON_EXTRACT(new_value,'$._request_key'))=? FOR UPDATE",[$actorId,$key])->fetchColumn();
    if ($previous) {
        $saved=json_decode($previous,true);
        if ($action==='profile' || !hash_equals($saved['_payload_hash']??'',$hash)) { throw new InvalidArgumentException('This submission was already used. Reopen the form.'); }
        return ['id'=>(int)$saved['id'],'replayed'=>true,'logout'=>false];
    }
    $before=[]; $after=['entity'=>ADMIN_ACTIONS[$action][0],'id'=>$id]; $target=null; $reason=''; $logout=false;
    if (in_array($action,['suspend','reactivate'],true)) {
        admin_query($db,'SELECT user_id FROM users WHERE user_id=? FOR UPDATE',[$id]); $row=admin_read($db,'users',$id); admin_check_revision($row,$input);
        if (sale_text($input,'confirm')!=='1') { throw new InvalidArgumentException('Confirm the account status change.'); }
        $reason=admin_text($input,'reason',255,true);
        if (($action==='suspend' && $row['state']!=='active') || ($action==='reactivate' && $row['state']!=='suspended')) { throw new InvalidArgumentException('Only active accounts can be suspended and suspended accounts reactivated.'); }
        $status=$action==='suspend'?'suspended':'active'; $before=['account_status'=>$row['state']]; $after['account_status']=$status; $target=$id;
        admin_query($db,'UPDATE users SET account_status=? WHERE user_id=?',[$status,$id]);
        admin_query($db,'UPDATE auth_sessions SET revoked_at=CURRENT_TIMESTAMP WHERE user_id=? AND revoked_at IS NULL',[$id]);
    } elseif ($action==='ticket_update') {
        admin_query($db,'SELECT ticket_id FROM support_tickets WHERE ticket_id=? FOR UPDATE',[$id]); $row=admin_read($db,'tickets',$id); admin_check_revision($row,$input);
        $status=sale_text($input,'status'); $message=admin_text($input,'message',4000);
        if (!in_array($status,['open','in_progress','resolved','closed'],true) || ($message==='' && $status===$row['state'])) { throw new InvalidArgumentException('Write a response or choose a different ticket status.'); }
        if ($message!=='') { admin_query($db,'INSERT INTO ticket_messages (ticket_id,sender_user_id,message_body) VALUES (?,?,?)',[$id,$actorId,$message]); }
        if ($status!==$row['state']) { admin_query($db,'INSERT INTO ticket_status_history (ticket_id,changed_by,old_status,new_status) VALUES (?,?,?,?)',[$id,$actorId,$row['state'],$status]); }
        admin_query($db,"UPDATE support_tickets SET ticket_status=?,assigned_admin_id=?,updated_at=CURRENT_TIMESTAMP,resolved_at=CASE WHEN ? IN ('resolved','closed') THEN COALESCE(resolved_at,CURRENT_TIMESTAMP) ELSE NULL END WHERE ticket_id=?",[$status,$actorId,$status,$id]);
        $before=['status'=>$row['state']]; $after['status']=$status; $reason=$message!==''?'Administrator response recorded in the ticket.':'Ticket status updated.';
    } elseif ($action==='feedback_update') {
        admin_query($db,'SELECT feedback_id FROM user_feedback WHERE feedback_id=? FOR UPDATE',[$id]); $row=admin_read($db,'feedback',$id); admin_check_revision($row,$input);
        $status=sale_text($input,'status'); if (!in_array($status,['new','reviewed','archived'],true)) { throw new InvalidArgumentException('Choose a valid feedback status.'); }
        $before=['status'=>$row['state']]; $after['status']=$status; admin_query($db,'UPDATE user_feedback SET status=? WHERE feedback_id=?',[$status,$id]);
    } elseif (in_array($action,['announcement_create','announcement_edit','publish','unpublish'],true)) {
        $row=null; if ($action!=='announcement_create') { admin_query($db,'SELECT announcement_id FROM announcements WHERE announcement_id=? FOR UPDATE',[$id]); $row=admin_read($db,'announcements',$id); admin_check_revision($row,$input); }
        if (in_array($action,['publish','unpublish'],true)) { if (sale_text($input,'confirm')!=='1') { throw new InvalidArgumentException('Confirm the publication change.'); } $title=$row['title']; $body=$row['body']; $status=$action==='publish'?'published':'draft'; }
        else { $title=admin_text($input,'title',200,true); $body=admin_text($input,'body',8000,true); $status=sale_text($input,'status','draft'); }
        if (!in_array($status,['draft','published','archived'],true)) { throw new InvalidArgumentException('Choose a publication status.'); }
        $published=$status==='published'?($row && $row['state']==='published'?$row['published_at']:date('Y-m-d H:i:s')):($row['published_at']??null);
        if (!$row) { admin_query($db,'INSERT INTO announcements (author_admin_id,title,body,announcement_status,published_at) VALUES (?,?,?,?,?)',[$actorId,$title,$body,$status,$published]); $id=(int)$db->lastInsertId(); $after['id']=$id; }
        else { admin_query($db,'UPDATE announcements SET title=?,body=?,announcement_status=?,published_at=? WHERE announcement_id=?',[$title,$body,$status,$published,$id]); $before=['title'=>$row['title'],'status'=>$row['state']]; }
        $after+=['title'=>$title,'status'=>$status]; $reason='Announcement content is retained in its record.';
    } elseif ($action==='setting') {
        $setting=sale_text($input,'setting_key'); if (!in_array($setting,ADMIN_SETTING_KEYS,true)) { throw new AdminDenied('This configuration value cannot be edited here.'); }
        $row=admin_query($db,'SELECT setting_key,setting_value,updated_at FROM platform_settings WHERE setting_key=? FOR UPDATE',[$setting])->fetch(); if (!$row) { throw new AdminNotFound('Setting not configured.'); } admin_check_revision($row,$input);
        $value=admin_text($input,'setting_value',$setting==='platform_description'?2000:191,true);
        if ($setting==='support_email' && !filter_var($value,FILTER_VALIDATE_EMAIL)) { throw new InvalidArgumentException('Enter a valid support email.'); }
        admin_query($db,'UPDATE platform_settings SET setting_value=?,updated_by=? WHERE setting_key=?',[$value,$actorId,$setting]); $before=$row; unset($before['updated_at']); $after+=['setting_key'=>$setting,'setting_value'=>$value];
    } elseif ($action==='role') {
        $targetRow=admin_query($db,"SELECT u.user_id,u.role_id,u.account_status,r.role_type FROM users u JOIN roles r ON r.role_id=u.role_id WHERE u.user_id=? FOR UPDATE",[$id])->fetch();
        if (!$targetRow || $targetRow['role_type']!=='admin') { throw new AdminDenied('Only existing administrator roles can be reassigned.'); } admin_check_revision($targetRow,$input);
        $role=sale_id(sale_text($input,'role_id')); $new=admin_query($db,"SELECT role_id FROM roles WHERE role_id=? AND role_type='admin'",[$role])->fetchColumn();
        if (!$new || array_diff(admin_permissions($db,$role),admin_permissions($db,(int)$current['role_id']))) { throw new AdminDenied('This role cannot be assigned by your account.'); }
        if (sale_text($input,'confirm')!=='1') { throw new InvalidArgumentException('Confirm the role change.'); } $reason=admin_text($input,'reason',255,true);
        $newPermissions=admin_permissions($db,$role);
        if ($targetRow['account_status']==='active' && (!in_array('users.manage',$newPermissions,true) || !in_array('settings.manage',$newPermissions,true))) {
            $others=(int)admin_query($db,"SELECT COUNT(*) FROM users u JOIN roles r ON r.role_id=u.role_id WHERE r.role_type='admin' AND u.account_status='active' AND u.user_id<>?
                AND EXISTS(SELECT 1 FROM role_permissions rp JOIN permissions p ON p.permission_id=rp.permission_id WHERE rp.role_id=u.role_id AND p.permission_code='users.manage')
                AND EXISTS(SELECT 1 FROM role_permissions rp JOIN permissions p ON p.permission_id=rp.permission_id WHERE rp.role_id=u.role_id AND p.permission_code='settings.manage') FOR UPDATE",[$id])->fetchColumn();
            if (!$others) { throw new InvalidArgumentException('Keep at least one active administrator with user and settings permissions.'); }
        }
        $before=['role_id'=>$targetRow['role_id']]; $after['role_id']=$role; $target=$id;
        admin_query($db,'UPDATE users SET role_id=? WHERE user_id=?',[$role,$id]); admin_query($db,'UPDATE auth_sessions SET revoked_at=CURRENT_TIMESTAMP WHERE user_id=? AND revoked_at IS NULL',[$id]); $logout=$id===$actorId;
    } elseif ($action==='profile') {
        if ($id!==$actorId) { throw new AdminDenied('You may only update your own profile.'); }
        $row=admin_query($db,'SELECT full_name,email,phone,password_hash FROM users WHERE user_id=? FOR UPDATE',[$actorId])->fetch();
        $password=$input['current_password']??null;
        if (!is_string($password) || strlen($password)>72 || !password_verify($password,$row['password_hash'])) { throw new InvalidArgumentException('Enter your current password to confirm these changes.'); }
        $name=admin_text($input,'full_name',150,true); $email=admin_text($input,'email',191,true); $phone=admin_text($input,'phone',30);
        if (!filter_var($email,FILTER_VALIDATE_EMAIL)) { throw new InvalidArgumentException('Enter a valid email.'); }
        $new=$input['new_password']??''; if (!is_string($new) || ($new!=='' && (strlen($new)<12 || strlen($new)>72 || str_contains($new,"\0") || $new!==($input['confirm_password']??'')))) { throw new InvalidArgumentException('New passwords must match and contain 12–72 bytes.'); }
        $hashPassword=$new!==''?password_hash($new,PASSWORD_DEFAULT):$row['password_hash'];
        admin_query($db,'UPDATE users SET full_name=?,email=?,phone=?,password_hash=? WHERE user_id=?',[$name,$email,$phone?:null,$hashPassword,$actorId]); unset($row['password_hash']); $before=$row; $after+=['full_name'=>$name,'email'=>$email,'phone'=>$phone,'password_changed'=>$new!=='']; $target=$actorId;
        admin_query($db,'UPDATE auth_sessions SET revoked_at=CURRENT_TIMESTAMP WHERE user_id=? AND revoked_at IS NULL',[$actorId]); $logout=true;
    }
    admin_audit($db,$actorId,$target,'ADMIN_'.strtoupper($action),$before,$after,$reason,$key,$hash);
    return ['id'=>$id,'replayed'=>false,'logout'=>$logout];
}
function admin_dashboard(PDO $db,array $permissions,int $actor): array
{
    $counts=admin_query($db,"SELECT COUNT(*) AS total,COALESCE(SUM(account_status='active'),0) AS active,COALESCE(SUM(account_status='suspended'),0) AS suspended,COALESCE(SUM(account_status IN ('inactive','deactivated')),0) AS inactive FROM users u JOIN roles r ON r.role_id=u.role_id WHERE r.role_type='owner'")->fetch();
    $counts['businesses']=(int)$db->query('SELECT COUNT(*) FROM businesses')->fetchColumn();
    $counts['tickets']=(int)$db->query("SELECT COUNT(*) FROM support_tickets WHERE ticket_status IN ('open','in_progress')")->fetchColumn();
    $recent=admin_can($permissions,'users.manage')?admin_query($db,"SELECT u.user_id,u.full_name,u.username,b.business_name,u.created_at,u.account_status FROM users u JOIN roles r ON r.role_id=u.role_id LEFT JOIN businesses b ON b.owner_user_id=u.user_id WHERE r.role_type='owner' ORDER BY u.created_at DESC,u.user_id DESC LIMIT 5")->fetchAll():[];
    [, $source]=admin_sources('logs');
    $activity=admin_query($db,'SELECT l.created_at,l.action_code,l.reason,u.full_name '.$source.(admin_can($permissions,'users.manage')?'':' AND l.actor=?').' ORDER BY l.created_at DESC,l.id DESC LIMIT 5',admin_can($permissions,'users.manage')?[]:[$actor])->fetchAll();
    $support=admin_can($permissions,'support.manage')?admin_query($db,"SELECT t.ticket_id,t.subject,t.ticket_status,t.created_at,u.full_name FROM support_tickets t JOIN users u ON u.user_id=t.submitted_by WHERE t.ticket_status IN ('open','in_progress') ORDER BY t.created_at,t.ticket_id LIMIT 3")->fetchAll():[];
    return compact('counts','recent','activity','support');
}
// Integration point: owners can retrieve published messages without changing their dashboard.
function admin_published_announcements(PDO $db,int $limit=5): array { return platform_announcements($db,$limit); }
