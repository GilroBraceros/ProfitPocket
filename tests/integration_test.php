<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(403); exit; }
require __DIR__.'/../includes/auth.php';
require __DIR__.'/../includes/admin.php';
require __DIR__.'/../includes/support.php';
function integration_expect(bool $value,string $message): void { if (!$value) { throw new RuntimeException($message); } }
function integration_reject(PDO $db,callable $action): void {
    $db->exec('SAVEPOINT invalid_integration');
    try { $action(); throw new LogicException('Invalid operation accepted.'); }
    catch (InvalidArgumentException|OutOfBoundsException|DomainException|AdminDenied $expected) { $db->exec('ROLLBACK TO SAVEPOINT invalid_integration'); }
}
function integration_counts(PDO $db): array {
    $counts=[];
    foreach (['users','businesses','support_tickets','ticket_messages','ticket_status_history','user_feedback','activity_logs','admin_action_logs','platform_settings','announcements'] as $table) {
        $counts[$table]=$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
    }
    return $counts;
}
$db=db();$baseline=integration_counts($db);
$settingsBefore=$db->query('SELECT * FROM platform_settings ORDER BY setting_key')->fetchAll();
try {
    $db->beginTransaction();
    $roles=array_column($db->query('SELECT role_id,role_code FROM roles')->fetchAll(),'role_id','role_code');
    $suffix=bin2hex(random_bytes(10));
    $make=function(string $role,string $prefix) use ($db,$roles,$suffix): int {
        $name=$prefix.$suffix;
        platform_query($db,'INSERT INTO users (role_id,full_name,username,email,password_hash) VALUES (?,?,?,?,?)',
            [$roles[$role],$prefix,$name,$name.'@example.invalid',password_hash(bin2hex(random_bytes(24)),PASSWORD_DEFAULT)]);
        return (int)$db->lastInsertId();
    };
    $admin=$make('platform_admin','IntegrationAdmin');$owner=$make('business_owner','IntegrationOwner');$other=$make('business_owner','IntegrationOther');
    $values=['platform_name'=>'Integration platform','platform_description'=>'Actual owner help description','support_email'=>'integration@example.invalid','support_phone'=>'+63 900 000 0000'];
    foreach ($values as $key=>$value) {
        // Missing general keys exist only inside this rollback test, never a migration.
        if (!platform_query($db,'SELECT setting_key FROM platform_settings WHERE setting_key=?',[$key])->fetchColumn()) {
            platform_query($db,'INSERT INTO platform_settings (setting_key,setting_value,updated_by) VALUES (?,?,?)',[$key,'Original',$admin]);
        }
        $row=platform_query($db,'SELECT setting_key,setting_value,updated_at FROM platform_settings WHERE setting_key=?',[$key])->fetch();
        admin_change($db,['user_id'=>$admin],'setting',0,['setting_key'=>$key,'setting_value'=>$value,'revision'=>admin_revision($row)],bin2hex(random_bytes(32)));
    }
    integration_expect(platform_information($db)===$values || array_diff_assoc($values,platform_information($db))===[],'Updated settings did not reach owner consumer.');
    integration_expect(count(platform_information($db))===4,'Unexpected private setting exposed.');
    $row=platform_query($db,"SELECT setting_key,setting_value,updated_at FROM platform_settings WHERE setting_key='support_email'")->fetch();
    integration_reject($db,fn()=>admin_change($db,['user_id'=>$admin],'setting',0,['setting_key'=>'support_email','setting_value'=>'invalid address','revision'=>admin_revision($row)],bin2hex(random_bytes(32))));
    echo 'PASS: all supported general setting updates reach the owner consumer; invalid email is rejected'.PHP_EOL;
    $ticketIds=[];
    for ($i=0;$i<12;$i++) {
        $result=support_change($db,$owner,'new_ticket',0,['subject'=>'Ticket '.$i,'description'=>'Real rollback concern','priority'=>'normal'],bin2hex(random_bytes(32)));
        $ticketIds[]=$result['id'];
    }
    $first=support_listing($db,$owner,[]);$second=support_listing($db,$owner,['page'=>'2']);
    integration_expect($first['count']===12 && count($first['rows'])===10 && count($second['rows'])===2,'Ticket pagination lost records.');
    integration_expect(!array_intersect(array_column($first['rows'],'ticket_id'),array_column($second['rows'],'ticket_id')),'Pagination repeated records.');
    integration_expect(support_listing($db,$other,[])['count']===0,'Other owner tickets leaked.');
    integration_reject($db,fn()=>support_ticket($db,$other,$ticketIds[0]));
    integration_reject($db,fn()=>support_change($db,$other,'reply',$ticketIds[0],['message'=>'Unauthorized'],bin2hex(random_bytes(32))));
    integration_reject($db,fn()=>support_listing($db,$owner,['tab'=>'feedback','state'=>'resolved']));
    $key=bin2hex(random_bytes(32));$input=['category'=>'suggestion','message'=>'Keep this actual feedback'];
    $feedback=support_change($db,$owner,'feedback',0,$input,$key);
    integration_expect(support_change($db,$owner,'feedback',0,$input,$key)['replayed'],'Identical retry duplicated feedback.');
    integration_reject($db,fn()=>support_change($db,$owner,'feedback',0,['category'=>'suggestion','message'=>'Different content'],$key));
    integration_expect(support_listing($db,$owner,['tab'=>'feedback'])['count']===1 && support_listing($db,$other,['tab'=>'feedback'])['count']===0,'Feedback replay/scoping failed.');
    platform_query($db,"UPDATE users SET account_status='suspended' WHERE user_id=?",[$owner]);
    integration_reject($db,fn()=>support_change($db,$owner,'feedback',0,$input,bin2hex(random_bytes(32))));
    echo 'PASS: pagination, tenant isolation, tampered retries, duplicate feedback and fresh suspended-account checks'.PHP_EOL;
    $db->rollBack();
    integration_expect(integration_counts($db)===$baseline,'Rollback left integration rows.');
    integration_expect($db->query('SELECT * FROM platform_settings ORDER BY setting_key')->fetchAll()===$settingsBefore,'Existing platform settings changed after rollback.');
    echo 'PASS: fixture rows and settings fully rolled back; no schema changes'.PHP_EOL;
} finally { if ($db->inTransaction()) { $db->rollBack(); } }
