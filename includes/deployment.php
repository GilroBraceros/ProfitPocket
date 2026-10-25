<?php
declare(strict_types=1);
require_once __DIR__.'/subscriptions.php';

function deployment_file_hash(string $file,bool $normalize=false): string
{
    return $normalize?hash('sha256',str_replace("\r\n","\n",file_get_contents($file))):hash_file('sha256',$file);
}

function deployment_release_ready(): bool
{
    $file=__DIR__.'/../bin/upgrade-release.json';
    if (!is_file($file)) { return false; }
    $manifest=json_decode(file_get_contents($file),true);
    if (!is_array($manifest) || ($manifest['version']??'')!==SUBSCRIPTION_VERSION || empty($manifest['files'])) { return false; }
    foreach ($manifest['files'] as $path=>$hash) {
        if (str_contains($path,'..') || !is_file(__DIR__.'/../'.$path) || !hash_equals($hash,deployment_file_hash(__DIR__.'/../'.$path,($manifest['hash_normalization']??'')==='lf'))) { return false; }
    }
    return true;
}
function deployment_migrate(PDO $db,array $config): string
{
    require_once __DIR__.'/subscription_methods.php';
    if ($config['PP_DEPLOY_MIGRATIONS_APPROVED']===REGISTRATION_PAYMENT_VERSION) { return deployment_registration_migrate($db); }
    if ($config['PP_DEPLOY_MIGRATIONS_APPROVED']!==SUBSCRIPTION_VERSION || $config['PP_EXISTING_OWNER_POLICY']!=='pending-payment') { return 'not_authorized'; }
    if ($db->inTransaction()) { throw new LogicException('DDL must run outside application transactions.'); }
    require_once __DIR__.'/notifications.php';
    if (!notification_schema($db)) { throw new RuntimeException('Apply the reviewed email-notifications-proposal.sql prerequisite before the subscription migration.'); }
    $file=__DIR__.'/../database/migrations/'.SUBSCRIPTION_VERSION.'.sql'; $checksum=hash_file('sha256',$file);
    if (subscription_schema($db)) {
        $details=json_decode(subscription_query($db,'SELECT details FROM deployment_runs WHERE run_key=?',['migration:'.SUBSCRIPTION_VERSION])->fetchColumn(),true);
        if (!hash_equals($details['checksum']??'',$checksum)) { throw new RuntimeException('Migration checksum changed after application.'); }
        return 'already_applied';
    }
    $sql=preg_replace('/^--.*$/m','',file_get_contents($file));
    foreach (explode(';',$sql) as $statement) {
        if (trim($statement)==='') { continue; }
        if (str_starts_with(trim($statement),'ALTER TABLE notification_outbox') && subscription_query($db,"SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notification_outbox' AND COLUMN_NAME='subscription_payment_id'")->fetchColumn()) { continue; }
        $db->exec($statement);
    }
    $db->beginTransaction();
    try {
        subscription_query($db,"INSERT IGNORE INTO owner_subscriptions (user_id) SELECT u.user_id FROM users u JOIN roles r ON r.role_id=u.role_id WHERE r.role_type='owner'");
        subscription_query($db,'INSERT INTO deployment_runs (run_key,details) VALUES (?,?)',['migration:'.SUBSCRIPTION_VERSION,json_encode(['checksum'=>$checksum,'existing_owner_policy'=>'pending-payment'],JSON_THROW_ON_ERROR)]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    return 'applied';
}
function deployment_registration_migrate(PDO $db): string
{
    if ($db->inTransaction() || !subscription_schema($db)) { throw new RuntimeException('Apply the existing subscription migration before the registration/payment upgrade.'); }
    $file=__DIR__.'/../database/migrations/'.REGISTRATION_PAYMENT_VERSION.'.sql'; $checksum=hash_file('sha256',$file);
    if (registration_payment_schema($db)) {
        $details=json_decode(subscription_query($db,'SELECT details FROM deployment_runs WHERE run_key=?',['migration:'.REGISTRATION_PAYMENT_VERSION])->fetchColumn(),true);
        if (!hash_equals($details['checksum']??'',$checksum)) { throw new RuntimeException('Registration migration checksum changed after application.'); }
        return 'already_applied';
    }
    $sql=preg_replace('/^--.*$/m','',file_get_contents($file));
    foreach(explode(';',$sql) as $statement) {
        $statement=trim($statement); if($statement===''){continue;}
        if(preg_match('/\AALTER TABLE subscription_payment_methods ADD COLUMN ([a-z_]+)/i',$statement,$match) && subscription_query($db,"SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='subscription_payment_methods' AND COLUMN_NAME=?",[$match[1]])->fetchColumn()){continue;}
        $db->exec($statement);
    }
    subscription_query($db,'INSERT INTO deployment_runs (run_key,details) VALUES (?,?)',['migration:'.REGISTRATION_PAYMENT_VERSION,json_encode(['checksum'=>$checksum],JSON_THROW_ON_ERROR)]);
    return 'applied';
}
function bootstrap_needs_password_change(PDO $db,int $user): bool
{
    if (subscription_schema($db) && subscription_query($db,'SELECT 1 FROM deployment_runs WHERE run_key=?',['bootstrap-password-required:'.$user])->fetchColumn()) { return true; }
    return subscription_query($db,"SELECT action_code FROM activity_logs WHERE actor_user_id=? AND action_code IN ('DEPLOY_ADMIN_PASSWORD_REQUIRED','DEPLOY_ADMIN_PASSWORD_CHANGED') ORDER BY activity_id DESC LIMIT 1",[$user])->fetchColumn()==='DEPLOY_ADMIN_PASSWORD_REQUIRED';
}
function bootstrap_password_changed(PDO $db,int $user): void
{
    if (subscription_schema($db)) { subscription_query($db,'DELETE FROM deployment_runs WHERE run_key=?',['bootstrap-password-required:'.$user]); }
    if (bootstrap_needs_password_change($db,$user)) {
        subscription_query($db,"INSERT INTO activity_logs (actor_user_id,action_code,entity_type,entity_id) VALUES (?,'DEPLOY_ADMIN_PASSWORD_CHANGED','user',?)",[$user,$user]);
    }
}
function bootstrap_completed(PDO $db,string $runKey,bool $baseSchemaOnly=false): bool
{
    if ($baseSchemaOnly) {
        if (subscription_schema($db) && subscription_query($db,'SELECT 1 FROM deployment_runs WHERE run_key=?',[$runKey])->fetchColumn()) { return true; }
        return (bool)subscription_query($db,"SELECT 1 FROM activity_logs WHERE action_code='DEPLOY_BOOTSTRAP_COMPLETED' AND details=? LIMIT 1",[$runKey])->fetchColumn();
    }
    return (bool)subscription_query($db,'SELECT 1 FROM deployment_runs WHERE run_key=?',[$runKey])->fetchColumn();
}
function deployment_bootstrap(PDO $db,array $config,?array $hashedAccounts=null,string $runKey='bootstrap:two-admins:v1',bool $baseSchemaOnly=false): string
{
    if (!$config['PP_BOOTSTRAP_AUTHORIZED'] || !preg_match('/\A[a-f0-9]{64}\z/',$config['PP_BOOTSTRAP_TOKEN'])) { return 'not_authorized'; }
    if ($baseSchemaOnly && $hashedAccounts===null) { throw new RuntimeException('Source-authorized provisioning requires the reviewed account hashes.'); }
    if (!$baseSchemaOnly && !subscription_schema($db)) { throw new RuntimeException('Apply the reviewed subscription migration first.'); }
    if (!preg_match('/\Abootstrap:[a-z0-9:-]{1,85}\z/',$runKey)) { throw new RuntimeException('Invalid bootstrap completion key.'); }
    if (bootstrap_completed($db,$runKey,$baseSchemaOnly)) { return 'already_completed'; }
    $accounts=[];
    if ($hashedAccounts!==null) {
        if (count($hashedAccounts)!==2) { throw new RuntimeException('Exactly two test administrators are required.'); }
        foreach ($hashedAccounts as $account) {
            $email=mb_strtolower(trim((string)($account['email']??''))); $name=trim((string)($account['name']??'')); $hash=(string)($account['password_hash']??'');
            if (!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>191 || mb_strlen($name)<1 || mb_strlen($name)>150 || (password_get_info($hash)['algoName']??'unknown')==='unknown') { throw new RuntimeException('Invalid hashed administrator identity.'); }
            $accounts[]=['email'=>$email,'name'=>$name,'password_hash'=>$hash];
        }
        if ($accounts[0]['email']===$accounts[1]['email'] || hash_equals($accounts[0]['password_hash'],$accounts[1]['password_hash'])) { throw new RuntimeException('Administrator identities must be distinct.'); }
    } else {
    foreach ([1,2] as $number) {
        $email=mb_strtolower(trim((string)$config['PP_BOOTSTRAP_ADMIN'.$number.'_EMAIL']));
        $password=(string)$config['PP_BOOTSTRAP_ADMIN'.$number.'_PASSWORD'];
        $name=trim((string)$config['PP_BOOTSTRAP_ADMIN'.$number.'_NAME']);
        if (!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>191 || strlen($password)<16 || strlen($password)>72 || str_contains($password,"\0") || mb_strlen($name)<1 || mb_strlen($name)>150 || !preg_match('/[A-Za-z]/',$password) || !preg_match('/[^A-Za-z]/',$password)) { throw new RuntimeException('Both private administrator settings must contain valid names, emails and strong passwords.'); }
        $accounts[]=['email'=>$email,'password'=>$password,'name'=>$name];
    }
    if ($accounts[0]['email']===$accounts[1]['email'] || hash_equals($accounts[0]['password'],$accounts[1]['password'])) { throw new RuntimeException('Administrator emails and initial passwords must be distinct.'); }
    }
    $role=subscription_query($db,"SELECT role_id FROM roles WHERE role_code='platform_admin' AND role_type='admin'")->fetchColumn();
    if (!$role) { throw new RuntimeException('The existing platform administrator role is missing.'); }
    require_once __DIR__.'/admin.php';
    $permissions=admin_permissions($db,(int)$role);
    foreach (['users.manage','settings.manage','platform_metrics.view'] as $permission) { if (!in_array($permission,$permissions,true)) { throw new RuntimeException('The existing administrator role lacks required permissions.'); } }
    if ($hashedAccounts!==null) {
        foreach (ADMIN_MODULES as $module) { if ($module[2]!=='' && !in_array($module[2],$permissions,true)) { throw new RuntimeException('The existing platform administrator role lacks a module permission.'); } }
    }
    $owns=!$db->inTransaction(); if ($owns) { $db->beginTransaction(); }
    try {
        // Existing role rows serialize provisioners and administrative role changes.
        subscription_query($db,"SELECT role_id FROM roles WHERE role_type='admin' ORDER BY role_id FOR UPDATE")->fetchAll();
        if (bootstrap_completed($db,$runKey,$baseSchemaOnly)) { if ($owns) {$db->commit();} return 'already_completed'; }
        // Recheck permissions after taking the shared role locks.
        $permissions=admin_permissions($db,(int)$role);
        foreach (['users.manage','settings.manage','platform_metrics.view'] as $permission) { if (!in_array($permission,$permissions,true)) { throw new RuntimeException('The administrator role permissions changed.'); } }
        if ($hashedAccounts!==null) { foreach (ADMIN_MODULES as $module) { if ($module[2]!=='' && !in_array($module[2],$permissions,true)) { throw new RuntimeException('The administrator role permissions changed.'); } } }
        $ids=[];
        foreach ($accounts as $account) {
            $existing=subscription_query($db,'SELECT user_id,role_id,account_status FROM users WHERE email=? FOR UPDATE',[$account['email']])->fetch();
            if ($existing) {
                if ($hashedAccounts!==null) { throw new RuntimeException('A designated test email already exists. No account was changed.'); }
                if ((int)$existing['role_id']!==(int)$role || $existing['account_status']!=='active' || subscription_query($db,'SELECT 1 FROM businesses WHERE owner_user_id=?',[$existing['user_id']])->fetchColumn()) { throw new RuntimeException('A designated email belongs to an incompatible existing account. No account was changed.'); }
                $ids[]=(int)$existing['user_id']; continue;
            }
            subscription_query($db,"INSERT INTO users (role_id,full_name,username,email,password_hash,account_status) VALUES (?,?,?,?,?,'active')",[$role,$account['name'],'pp_admin_'.substr(hash('sha256',$account['email']),0,24),$account['email'],$account['password_hash']??password_hash($account['password'],PASSWORD_DEFAULT)]);
            $id=(int)$db->lastInsertId(); $ids[]=$id;
            if ($baseSchemaOnly) { subscription_query($db,"INSERT INTO activity_logs (actor_user_id,action_code,entity_type,entity_id) VALUES (?,'DEPLOY_ADMIN_PASSWORD_REQUIRED','user',?)",[$id,$id]); }
            else { subscription_query($db,'INSERT INTO deployment_runs (run_key,details) VALUES (?,?)',['bootstrap-password-required:'.$id,'{}']); }
            subscription_query($db,"INSERT INTO activity_logs (actor_user_id,action_code,entity_type,entity_id) VALUES (?,'DEPLOY_ADMIN_PROVISIONED','user',?)",[$id,$id]);
        }
        if ($baseSchemaOnly) { subscription_query($db,"INSERT INTO activity_logs (actor_user_id,action_code,entity_type,details,after_data) VALUES (?,'DEPLOY_BOOTSTRAP_COMPLETED','deployment',?,?)",[$ids[0],$runKey,json_encode(['user_ids'=>$ids],JSON_THROW_ON_ERROR)]); }
        else { subscription_query($db,'INSERT INTO deployment_runs (run_key,details) VALUES (?,?)',[$runKey,json_encode(['user_ids'=>$ids],JSON_THROW_ON_ERROR)]); }
        if ($owns) { $db->commit(); }
    } catch (Throwable $e) { if ($owns && $db->inTransaction()) { $db->rollBack(); } throw $e; }
    return 'completed';
}
