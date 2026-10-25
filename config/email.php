<?php
declare(strict_types=1);
// Values come only from the server environment or a file OUTSIDE the web root.
function email_config_location(): array
{
    $project=realpath(__DIR__.'/..'); $public=realpath($_SERVER['DOCUMENT_ROOT']??'')?:$project;
    for ($dir=$project; $dir!==dirname($dir); $dir=dirname($dir)) {
        if (in_array(strtolower(basename($dir)),['public_html','htdocs','www'],true)) { $public=$dir; break; }
    }
    return [$public,getenv('PROFITPOCKET_EMAIL_CONFIG')?:dirname($public).'/profitpocket-private/email.php'];
}
function email_config(): array
{
    [$public,$file]=email_config_location(); $private=[];
    if (is_file($file)) {
        $resolved=realpath($file); $prefix=strtolower(str_replace('\\','/',$public)).'/';
        if (!$resolved || str_starts_with(strtolower(str_replace('\\','/',$resolved)),$prefix) || preg_match('~(?:^|/)(?:public_html|htdocs|www)(?:/|$)~i',str_replace('\\','/',$resolved))) { throw new RuntimeException('Email configuration must be outside the web root.'); }
        ob_start(); try { $private=require $resolved; } finally { ob_end_clean(); }
        if (!is_array($private)) { throw new RuntimeException('Invalid private email configuration.'); }
    }
    $defaults=['BREVO_API_KEY'=>'','BREVO_SENDER_EMAIL'=>'','BREVO_SENDER_NAME'=>'ProfitPocket','BREVO_REPLY_TO_EMAIL'=>'','BREVO_EMAIL_ENABLED'=>'false','PROFITPOCKET_BASE_URL'=>'','PROFITPOCKET_NOTIFICATION_SECRET'=>''];
    foreach ($defaults as $key=>$value) { $env=getenv($key); $defaults[$key]=$env!==false?$env:($private[$key]??$value); }
    $defaults['BREVO_EMAIL_ENABLED']=filter_var($defaults['BREVO_EMAIL_ENABLED'],FILTER_VALIDATE_BOOLEAN);
    return $defaults;
}
function email_ready(array $c): bool
{
    $base=parse_url((string)$c['PROFITPOCKET_BASE_URL']);
    return $c['BREVO_EMAIL_ENABLED'] && extension_loaded('curl') && strlen((string)$c['BREVO_API_KEY'])>=20 &&
        !preg_match('/[\r\n]/',(string)$c['BREVO_API_KEY']) && filter_var($c['BREVO_SENDER_EMAIL'],FILTER_VALIDATE_EMAIL) &&
        filter_var($c['BREVO_REPLY_TO_EMAIL']?:$c['BREVO_SENDER_EMAIL'],FILTER_VALIDATE_EMAIL) &&
        filter_var($c['PROFITPOCKET_BASE_URL'],FILTER_VALIDATE_URL) && ($base['scheme']??'')==='https' && !empty($base['host']) && !isset($base['user']) && !isset($base['pass']) && !isset($base['query']) && !isset($base['fragment']);
}
