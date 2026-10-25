<?php
declare(strict_types=1);

function upgrade_private_root(): string
{
    $root=realpath(__DIR__.'/..');
    for ($dir=$root; $dir!==dirname($dir); $dir=dirname($dir)) {
        if (in_array(strtolower(basename($dir)),['public_html','htdocs','www'],true)) { return dirname($dir).'/profitpocket-private'; }
    }
    return dirname($root).'/profitpocket-private';
}
function upgrade_public_path(string $path): bool
{
    $path=strtolower(str_replace('\\','/',$path));
    $project=strtolower(str_replace('\\','/',realpath(__DIR__.'/..')));
    $document=strtolower(str_replace('\\','/',realpath($_SERVER['DOCUMENT_ROOT']??'')?:$project));
    return preg_match('~(?:^|/)(?:public_html|htdocs|www)(?:/|$)~',$path)===1 ||
        $path===$project || str_starts_with($path,$project.'/') || $path===$document || str_starts_with($path,$document.'/');
}
function upgrade_config(): array
{
    $file=getenv('PROFITPOCKET_UPGRADE_CONFIG')?:upgrade_private_root().'/upgrade.php'; $private=[];
    if (is_file($file)) {
        $resolved=realpath($file);
        if (!$resolved || upgrade_public_path($resolved)) { throw new RuntimeException('Upgrade configuration must be outside the document root.'); }
        ob_start(); try { $private=require $resolved; } finally { ob_end_clean(); }
        if (!is_array($private)) { throw new RuntimeException('Invalid private upgrade configuration.'); }
    }
    $values=['PP_SUBSCRIPTIONS_ENABLED'=>false,'PP_SUBSCRIPTION_STORAGE'=>upgrade_private_root().'/subscription-proofs',
        'PP_DEPLOY_MIGRATIONS_APPROVED'=>'','PP_EXISTING_OWNER_POLICY'=>'',
        'PP_BOOTSTRAP_AUTHORIZED'=>false,'PP_BOOTSTRAP_TOKEN'=>'',
        'PP_BOOTSTRAP_ADMIN1_EMAIL'=>'','PP_BOOTSTRAP_ADMIN1_PASSWORD'=>'','PP_BOOTSTRAP_ADMIN1_NAME'=>'System Administrator 1',
        'PP_BOOTSTRAP_ADMIN2_EMAIL'=>'','PP_BOOTSTRAP_ADMIN2_PASSWORD'=>'','PP_BOOTSTRAP_ADMIN2_NAME'=>'System Administrator 2',
        'PP_EMAIL_TEST_ADDRESS'=>'','PP_EMAIL_TEST_AUTHORIZED'=>false,
        'PP_ENVIRONMENT'=>'development','PP_PRODUCTION_APP_ROOT'=>'','PP_PRODUCTION_DB_NAME'=>'',
        'PP_PRODUCTION_DB_USER'=>'','PP_PRODUCTION_BASE_URL'=>'',
        'PP_PROVISION_TEST_ADMINS'=>false,'PP_PROVISION_TOKEN'=>'','PP_ACTIVATE_EMAIL'=>false];
    foreach ($values as $key=>$default) { $env=getenv($key); $values[$key]=$env!==false?$env:($private[$key]??$default); }
    foreach (['PP_SUBSCRIPTIONS_ENABLED','PP_BOOTSTRAP_AUTHORIZED','PP_EMAIL_TEST_AUTHORIZED','PP_PROVISION_TEST_ADMINS','PP_ACTIVATE_EMAIL'] as $key) { $values[$key]=filter_var($values[$key],FILTER_VALIDATE_BOOLEAN); }
    return $values;
}
