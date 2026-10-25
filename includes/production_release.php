<?php
declare(strict_types=1);

// Explicitly approved by the project owner for this temporary deployment.
// This is an authorization flag, not a credential or a public installer.
const PRODUCTION_RELEASE_ADMINS_ENABLED=true;

function production_release_location(string $root,string $os): bool
{
    return $os==='Linux' && preg_match('~\A/home/[A-Za-z0-9._-]+/domains/profitpocket\.online/public_html(?:/profitpocket)?\z~',$root)===1;
}

function production_release_database_matches(array $settings,array $identity): bool
{
    // Reuse only the app's already-configured Hostinger connection. Local defaults
    // and another hosting account's database are never eligible.
    $name=(string)($settings['PP_DB_NAME']??''); $user=(string)($settings['PP_DB_USER']??'');
    if (!preg_match('/\A(u[0-9]+)_[A-Za-z0-9_]+\z/',$name,$dbMatch) ||
        !preg_match('/\A(u[0-9]+)_[A-Za-z0-9_]+\z/',$user,$userMatch) || $dbMatch[1]!==$userMatch[1]) { return false; }
    return hash_equals($name,(string)($identity['database_name']??'')) &&
        hash_equals($user,explode('@',(string)($identity['database_account']??''),2)[0]);
}

function production_release_provision(): string
{
    if (!PRODUCTION_RELEASE_ADMINS_ENABLED || !production_release_location(realpath(__DIR__.'/..'),PHP_OS_FAMILY)) { return 'not_applicable'; }
    $settings=database_config();
    if (!defined('PP_LEGACY_DATABASE_FILE') && !production_release_database_matches($settings,['database_name'=>$settings['PP_DB_NAME'],'database_account'=>$settings['PP_DB_USER']])) { throw new RuntimeException('The existing connection is not an eligible hosted database configuration.'); }
    $db=db();
    $identity=$db->query('SELECT DATABASE() AS database_name, CURRENT_USER() AS database_account')->fetch();
    if (defined('PP_LEGACY_DATABASE_FILE')) {
        if (realpath((new ReflectionFunction('db'))->getFileName())!==PP_LEGACY_DATABASE_FILE) { throw new RuntimeException('The existing application connection changed.'); }
        $settings['PP_DB_NAME']=(string)($identity['database_name']??'');
        $settings['PP_DB_USER']=explode('@',(string)($identity['database_account']??''),2)[0];
    }
    if (!production_release_database_matches($settings,$identity?:[])) { throw new RuntimeException('The connected database differs from the existing application configuration.'); }
    // These existing tables/columns distinguish this application before any write.
    foreach (['users'=>['user_id','role_id','password_hash','account_status'],
        'roles'=>['role_id','role_code','role_type'],'role_permissions'=>['role_id','permission_id'],
        'permissions'=>['permission_id','permission_code'],'businesses'=>['owner_user_id'],
        'activity_logs'=>['activity_id','actor_user_id','action_code','details','after_data'],
        'auth_sessions'=>['user_id','token_hash','revoked_at']] as $table=>$required) {
        $columns=subscription_query($db,'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table])->fetchAll(PDO::FETCH_COLUMN);
        if (array_diff($required,$columns)) { throw new RuntimeException('The connected database does not have the existing ProfitPocket schema.'); }
    }
    if (bootstrap_completed($db,PRODUCTION_ADMIN_RUN,true)) { return 'already_completed'; }
    if (!deployment_release_ready()) { throw new RuntimeException('The complete reviewed release must be deployed first.'); }
    $file=__DIR__.'/production_test_accounts.php';
    if (!is_file($file)) { return 'temporary_accounts_removed'; }
    $config=['PP_BOOTSTRAP_AUTHORIZED'=>true,'PP_BOOTSTRAP_TOKEN'=>hash('sha256','source-approved:'.PRODUCTION_ADMIN_RUN)];
    return deployment_bootstrap($db,$config,require $file,PRODUCTION_ADMIN_RUN,true);
}
