<?php
declare(strict_types=1);
require_once __DIR__.'/subscription_email.php';
require_once __DIR__.'/deployment.php';

function email_setup_https(array $server): bool
{
    $https=strtolower((string)($server['HTTPS']??''));
    return (in_array($https,['on','1'],true) || (int)($server['SERVER_PORT']??0)===443)
        && strtolower((string)($server['HTTP_HOST']??''))==='profitpocket.online';
}
function email_setup_require_https(array $server): void
{
    if (!email_setup_https($server)) {throw new InvalidArgumentException('Use the hosted HTTPS ProfitPocket page to configure or test email.');}
}
function email_setup_base_url(string $base): string
{
    if (!preg_match('~\A(?:/[A-Za-z0-9_-]+)*\z~',$base)) {throw new InvalidArgumentException('The application base path is invalid.');}
    return 'https://profitpocket.online'.$base;
}
function email_setup_write_private(array $config): void
{
    [, $file]=email_config_location();
    if (!preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~',$file) || preg_match('~(?:^|[\\\\/])\.\.(?:[\\\\/]|$)~',$file) || is_link($file) || upgrade_public_path($file)) {
        throw new InvalidArgumentException('Email configuration must be a private file outside the public website.');
    }
    $parent=dirname($file);$ancestor=$parent;
    while (!is_dir($ancestor) && dirname($ancestor)!==$ancestor) {$ancestor=dirname($ancestor);}
    if (!realpath($ancestor) || upgrade_public_path(realpath($ancestor))) {throw new InvalidArgumentException('A private email directory could not be verified.');}
    if (!is_dir($parent) && !@mkdir($parent,0700,true) && !is_dir($parent)) {throw new InvalidArgumentException('PHP cannot create the private email directory. The hosting owner must grant access outside public_html.');}
    $parent=realpath($parent);
    if (!$parent || upgrade_public_path($parent) || !is_writable($parent)) {throw new InvalidArgumentException('The private email directory is not writable by PHP. Hosting access is needed to correct its permissions.');}
    $existing=[];
    if (is_file($file)) {
        if (!realpath($file) || upgrade_public_path(realpath($file))) {throw new InvalidArgumentException('The existing email file is not private.');}
        ob_start();try {$existing=require $file;} finally {ob_end_clean();}
        if (!is_array($existing)) {throw new InvalidArgumentException('The existing private email file must return an array.');}
    }
    $values=array_replace($existing,$config);$temporary=$parent.'/email-'.bin2hex(random_bytes(12)).'.tmp';
    try {
        $handle=@fopen($temporary,'x');
        if (!$handle) {throw new InvalidArgumentException('PHP could not save the private email settings.');}
        try {
            if (!@chmod($temporary,0600)) {throw new InvalidArgumentException('Private email file permissions could not be secured.');}
            $content="<?php\nreturn ".var_export($values,true).";\n";
            if (fwrite($handle,$content)!==strlen($content) || !fflush($handle)) {throw new InvalidArgumentException('Private email settings could not be fully saved.');}
        } finally {fclose($handle);}
        if (!@rename($temporary,$file)) {throw new InvalidArgumentException('PHP could not replace the private email configuration.');}
    } finally {if (is_file($temporary)) {@unlink($temporary);}}
}
function email_admin_save(PDO $db,int $actor,array $input,array $server,string $base,?callable $senderTransport=null): void
{
    admin_authorize($db,$actor,'users.manage');admin_authorize($db,$actor,'settings.manage');
    email_setup_require_https($server);
    if (($input['confirm_email_setup']??'')!=='1') {throw new InvalidArgumentException('Confirm that you authorize the sender for ProfitPocket notifications.');}
    if ($db->inTransaction()) {throw new LogicException('Email setup must run outside financial transactions.');}
    if (!deployment_release_ready()) {throw new InvalidArgumentException('Wait for the complete application release to deploy before configuring email.');}
    $lock='pp-email-config:'.substr(hash('sha256',$db->query('SELECT DATABASE()')->fetchColumn()),0,32);
    if (!subscription_query($db,'SELECT GET_LOCK(?,0)',[$lock])->fetchColumn()) {throw new InvalidArgumentException('Another email setup request is active. Try again after it finishes.');}
    try {
        $config=email_config();
        $key=is_string($input['brevo_api_key']??null)?$input['brevo_api_key']:'';
        if ($key!=='') {
            if (strlen($key)<20 || strlen($key)>512 || preg_match('/[^\x21-\x7e]/',$key)) {throw new InvalidArgumentException('Enter a valid Brevo API key without whitespace.');}
            $config['BREVO_API_KEY']=$key;
        }
        foreach (['sender_email'=>'BREVO_SENDER_EMAIL','sender_name'=>'BREVO_SENDER_NAME','reply_to_email'=>'BREVO_REPLY_TO_EMAIL'] as $field=>$setting) {
            if (!is_string($input[$field]??null)) {throw new InvalidArgumentException('Complete the sender details.');}
            $config[$setting]=trim($input[$field]);
        }
        if (strlen($config['BREVO_SENDER_EMAIL'])>191 || strlen($config['BREVO_REPLY_TO_EMAIL'])>191 || !filter_var($config['BREVO_SENDER_EMAIL'],FILTER_VALIDATE_EMAIL) || ($config['BREVO_REPLY_TO_EMAIL']!=='' && !filter_var($config['BREVO_REPLY_TO_EMAIL'],FILTER_VALIDATE_EMAIL))) {throw new InvalidArgumentException('Enter valid sender and optional reply-to email addresses within 191 characters.');}
        if ($config['BREVO_SENDER_NAME']==='' || !mb_check_encoding($config['BREVO_SENDER_NAME'],'UTF-8') || mb_strlen($config['BREVO_SENDER_NAME'])>100 || preg_match('/[\x00-\x1f\x7f]/',$config['BREVO_SENDER_NAME'])) {throw new InvalidArgumentException('Enter a sender name within 100 characters.');}
        $config['PROFITPOCKET_BASE_URL']=email_setup_base_url($base);
        if ($config['PROFITPOCKET_NOTIFICATION_SECRET']==='') {$config['PROFITPOCKET_NOTIFICATION_SECRET']=bin2hex(random_bytes(32));}
        if (!preg_match('/\A[a-f0-9]{64}\z/',$config['PROFITPOCKET_NOTIFICATION_SECRET'])) {throw new InvalidArgumentException('The existing notification secret is invalid. It has been preserved; ask the hosting owner to review it.');}
        $config['BREVO_EMAIL_ENABLED']=true;
        foreach ($config as $setting=>$value) {
            $env=getenv($setting);
            if ($env!==false && ($setting==='BREVO_EMAIL_ENABLED'?!filter_var($env,FILTER_VALIDATE_BOOLEAN):(string)$value!==$env)) {
                throw new InvalidArgumentException('A server environment setting overrides this change. The hosting owner must update the email environment configuration.');
            }
        }
        $ready=email_diagnostic_status($db,$config);
        if (!$ready['configuration_valid'] || !$ready['notification_schema']) {throw new InvalidArgumentException('Complete the API key, sender and approved email queue setup before activating email.');}
        $sender=brevo_sender_status($config,$senderTransport);
        if ($sender!=='active') {
            throw new InvalidArgumentException(match($sender) {
                'credentials_rejected'=>'Brevo rejected the API key. Use a valid replacement key.',
                'sender_not_registered','inactive'=>'Brevo has not confirmed this sender as active. Verify the sender in Brevo first.',
                default=>'The Brevo sender check could not complete. Settings have not been activated; try again later.',
            });
        }
        admin_authorize($db,$actor,'users.manage');admin_authorize($db,$actor,'settings.manage');
        email_setup_write_private($config);
        try {subscription_query($db,"INSERT INTO activity_logs(actor_user_id,action_code,entity_type,details) VALUES(?,'EMAIL_CONFIGURATION_UPDATED','platform','Verified sender and saved private email configuration')",[$actor]);}
        catch (Throwable $e) {error_log('ProfitPocket: email setup audit unavailable.');}
    } finally {subscription_query($db,'SELECT RELEASE_LOCK(?)',[$lock]);}
}
