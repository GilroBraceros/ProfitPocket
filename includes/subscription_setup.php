<?php
declare(strict_types=1);
require_once __DIR__.'/deployment.php';
require_once __DIR__.'/admin.php';
require_once __DIR__.'/subscription_methods.php';

// Fixed, reviewed files only. No SQL, filenames or credentials come from a form.
function subscription_setup_files(): array
{
    return ['database/email-notifications-proposal.sql',
        'database/migrations/20261025_subscriptions.sql',
        'database/migrations/20261025_registration_payments.sql'];
}
function subscription_setup_statements(string $file): array
{
    if (!in_array($file,subscription_setup_files(),true)) {throw new LogicException('Unknown setup migration.');}
    $path=__DIR__.'/../'.$file;
    if (!is_readable($path)) {throw new InvalidArgumentException('A reviewed migration file is missing. Wait for the complete deployment before setup.');}
    $sql=preg_replace('/^--.*$/m','',file_get_contents($path));
    $result=[];
    foreach (explode(';',$sql) as $statement) {
        $statement=trim($statement);
        if ($statement==='') {continue;}
        if (preg_match('/\ACREATE TABLE(?: IF NOT EXISTS)? ([a-z_]+)\s*\((.*)\)\s*ENGINE=/is',$statement,$match)) {
            preg_match_all('/^\s*([a-z_]+)\s+(?:BIGINT|VARCHAR|CHAR|TINYINT|SMALLINT|INT|DATETIME|DECIMAL|LONGTEXT|ENUM)\b/mi',$match[2],$columns);
            $result[]=['table'=>$match[1],'columns'=>$columns[1],'column'=>null,'sql'=>$statement];
        } elseif (preg_match('/\AALTER TABLE ([a-z_]+) ADD COLUMN ([a-z_]+) /i',$statement,$match)) {
            $result[]=['table'=>$match[1],'columns'=>[],'column'=>$match[2],'sql'=>$statement];
        } else {throw new LogicException('Unexpected statement in reviewed setup migration.');}
    }
    return $result;
}
function subscription_setup_columns(PDO $db,string $table): array
{
    return subscription_query($db,'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table])->fetchAll(PDO::FETCH_COLUMN);
}
function subscription_setup_plan(PDO $db): array
{
    $changes=[];
    foreach (subscription_setup_files() as $file) {
        foreach (subscription_setup_statements($file) as $item) {
            $existing=subscription_setup_columns($db,$item['table']);
            if ($item['column']!==null) {
                if (!in_array($item['column'],$existing,true)) {$changes[]='Add '.$item['table'].'.'.$item['column'];}
            } elseif (!$existing) {
                $changes[]='Create '.$item['table'];
            } elseif (array_diff($item['columns'],$existing)) {
                throw new InvalidArgumentException('Existing '.$item['table'].' has an incompatible structure. A hosting administrator must review it before setup.');
            }
        }
    }
    return $changes;
}
function subscription_setup_apply(PDO $db,int $actor,array $input,bool $csrf,string $expectedKey): array
{
    if (!$csrf || $expectedKey==='' || !is_string($input['request_key']??null) || !hash_equals($expectedKey,$input['request_key'])) {
        throw new InvalidArgumentException('This setup form expired. Reload the page and review the proposal again.');
    }
    if (($input['approve_schema']??'')!=='1' || ($input['owner_policy']??'')!=='pending-payment') {
        throw new InvalidArgumentException('Explicitly approve the listed database additions and Pending Payment policy before applying setup.');
    }
    admin_authorize($db,$actor,'settings.manage');
    admin_authorize($db,$actor,'users.manage');
    if ($db->inTransaction()) {throw new LogicException('Setup cannot run inside another transaction.');}
    if (!deployment_release_ready()) {throw new InvalidArgumentException('The complete release has not finished deploying. Wait for deployment, then reload before applying setup.');}
    $lock='pp-upgrade:'.substr(hash('sha256',$db->query('SELECT DATABASE()')->fetchColumn()),0,32);
    if (!subscription_query($db,'SELECT GET_LOCK(?,0)',[$lock])->fetchColumn()) {
        throw new InvalidArgumentException('Another setup job is active. Wait for it to finish, then reload.');
    }
    try {
        $changes=subscription_setup_plan($db);
        if (!is_string($input['plan_hash']??null) || !hash_equals(hash('sha256',json_encode($changes,JSON_THROW_ON_ERROR)),$input['plan_hash'])) {
            throw new InvalidArgumentException('The setup proposal changed. Reload and review the current additions before confirming.');
        }
        // Check core dependencies before the first DDL statement.
        foreach (['users'=>'user_id,role_id,account_status','roles'=>'role_id,role_type',
            'customers'=>'customer_id,business_id','activity_logs'=>'actor_user_id,action_code,entity_type,details'] as $table=>$columns) {
            subscription_query($db,'SELECT '.$columns.' FROM '.$table.' WHERE 1=0');
        }
        foreach (subscription_setup_statements(subscription_setup_files()[0]) as $item) {
            admin_authorize($db,$actor,'settings.manage');
            admin_authorize($db,$actor,'users.manage');
            if (!subscription_setup_columns($db,$item['table'])) {$db->exec($item['sql']);}
        }
        admin_authorize($db,$actor,'settings.manage');
        admin_authorize($db,$actor,'users.manage');
        $base=deployment_migrate($db,['PP_DEPLOY_MIGRATIONS_APPROVED'=>SUBSCRIPTION_VERSION,'PP_EXISTING_OWNER_POLICY'=>'pending-payment']);
        admin_authorize($db,$actor,'settings.manage');
        admin_authorize($db,$actor,'users.manage');
        $registration=deployment_migrate($db,['PP_DEPLOY_MIGRATIONS_APPROVED'=>REGISTRATION_PAYMENT_VERSION]);
        subscription_query($db,"INSERT INTO activity_logs(actor_user_id,action_code,entity_type,details) VALUES(?,'SUBSCRIPTION_SCHEMA_SETUP','platform',?)",[$actor,'Reviewed additive subscription setup: '.$base.'; registration: '.$registration]);
        return ['subscriptions'=>$base,'registration_payments'=>$registration,'changes'=>$changes];
    } finally {
        subscription_query($db,'SELECT RELEASE_LOCK(?)',[$lock]);
    }
}
