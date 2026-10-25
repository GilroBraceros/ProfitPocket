<?php
declare(strict_types=1);
require_once __DIR__.'/deployment.php';

const PRODUCTION_ADMIN_RUN='bootstrap:hostinger-test-admins:v1';

function production_check_location(array $config,string $root,string $os): void
{
    // No request Host/header/parameter can authorize provisioning.
    if ($os!=='Linux' || $config['PP_ENVIRONMENT']!=='production' ||
        !is_string($config['PP_PRODUCTION_APP_ROOT']) || $config['PP_PRODUCTION_APP_ROOT']!==$root ||
        preg_match('~(?:^|/)(?:xampp|htdocs)(?:/|$)~i',$root) ||
        $config['PP_PRODUCTION_BASE_URL']!=='https://profitpocket.online/profitpocket' ||
        !preg_match('/\A[a-f0-9]{64}\z/',(string)$config['PP_PROVISION_TOKEN'])) {
        throw new RuntimeException('Production authorization or installation identity is missing.');
    }
    if (!str_starts_with($root,'/home/') || !str_contains($root,'/domains/profitpocket.online/public_html/')) { throw new RuntimeException('This is not the designated Hostinger application directory.'); }
    foreach (['PP_PRODUCTION_DB_NAME','PP_PRODUCTION_DB_USER'] as $key) {
        if (!is_string($config[$key]) || $config[$key]==='' || in_array(strtolower($config[$key]),['root','profitpocket_db'],true) || !preg_match('/\A[A-Za-z0-9_]+\z/',$config[$key])) { throw new RuntimeException('Explicit hosted database identity is required.'); }
    }
}

function production_database_matches(array $config,array $identity): bool
{
    return hash_equals($config['PP_PRODUCTION_DB_NAME'],(string)($identity['database_name']??'')) &&
        hash_equals($config['PP_PRODUCTION_DB_USER'],explode('@',(string)($identity['database_account']??''),2)[0]);
}

function production_require_environment(PDO $db,array $config): void
{
    production_check_location($config,realpath(__DIR__.'/..'),PHP_OS_FAMILY);
    $identity=$db->query('SELECT DATABASE() AS database_name, CURRENT_USER() AS database_account')->fetch();
    if (!production_database_matches($config,$identity?:[])) { throw new RuntimeException('Connected database does not match the privately designated production database.'); }
    if (!subscription_schema($db)) { throw new RuntimeException('The existing reviewed subscription migration is required; bootstrap never changes schema.'); }
}

function production_email_config(array $config): array
{
    require_once __DIR__.'/../config/email.php';
    $email=email_config();
    if (rtrim((string)$email['PROFITPOCKET_BASE_URL'],'/')!==$config['PP_PRODUCTION_BASE_URL']) { throw new RuntimeException('Private email links do not match the designated production HTTPS URL.'); }
    return $email;
}

function production_provision(PDO $db,array $config): string
{
    production_require_environment($db,$config);
    if (!$config['PP_PROVISION_TEST_ADMINS']) { return 'not_authorized'; }
    if (subscription_query($db,'SELECT 1 FROM deployment_runs WHERE run_key=?',[PRODUCTION_ADMIN_RUN])->fetchColumn()) { return 'already_completed'; }
    if (!deployment_release_ready()) { throw new RuntimeException('The deployed release is incomplete or differs from its reviewed manifest.'); }
    $file=__DIR__.'/production_test_accounts.php';
    if (!is_file($file)) { throw new RuntimeException('Temporary test-account hashes are unavailable.'); }
    $accounts=require $file;
    $bootstrap=$config; $bootstrap['PP_BOOTSTRAP_AUTHORIZED']=true; $bootstrap['PP_BOOTSTRAP_TOKEN']=$config['PP_PROVISION_TOKEN'];
    return deployment_bootstrap($db,$bootstrap,$accounts,PRODUCTION_ADMIN_RUN);
}

function production_initialize(): void
{
    if (PHP_SAPI==='cli' || PHP_OS_FAMILY!=='Linux') { return; }
    try {
        $config=upgrade_config();
        if ($config['PP_ENVIRONMENT']!=='production' || !$config['PP_PROVISION_TEST_ADMINS']) { return; }
        // The completion record permanently disables inserts on future requests.
        production_provision(db(),$config);
    } catch (Throwable $e) {
        // Initialization failure never exposes credentials or breaks normal login.
        error_log('ProfitPocket production bootstrap: unavailable; inspect private setup using the CLI check.');
    }
}

function production_admin_status(PDO $db): array
{
    $details=subscription_query($db,'SELECT details FROM deployment_runs WHERE run_key=?',[PRODUCTION_ADMIN_RUN])->fetchColumn();
    $ids=$details?(json_decode($details,true)['user_ids']??[]):[];
    $result=[];
    foreach ($ids as $id) {
        $user=subscription_query($db,'SELECT u.user_id,u.account_status,r.role_type,r.role_code,r.role_id FROM users u JOIN roles r ON r.role_id=u.role_id WHERE u.user_id=?',[$id])->fetch();
        if (!$user) { $result[]=['exists'=>false]; continue; }
        require_once __DIR__.'/admin.php';
        $permissions=admin_permissions($db,(int)$user['role_id']); $complete=in_array('settings.manage',$permissions,true);
        foreach (ADMIN_MODULES as $module) { if ($module[2]!=='' && !in_array($module[2],$permissions,true)) { $complete=false; } }
        $result[]=['exists'=>true,'active'=>$user['account_status']==='active','platform_administrator'=>$user['role_code']==='platform_admin' && $user['role_type']==='admin',
            'module_permissions_complete'=>$complete,'subscription_exempt'=>$user['role_type']==='admin' && subscription_has_access($db,$user),
            'password_change_required'=>bootstrap_needs_password_change($db,(int)$id)];
    }
    return ['provisioning_completed'=>(bool)$details,'accounts'=>$result];
}
