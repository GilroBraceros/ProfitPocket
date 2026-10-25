<?php
declare(strict_types=1);
require_once __DIR__.'/notifications.php';

function email_diagnostic_status(PDO $db,array $config): array
{
    $missing=[];
    foreach (['BREVO_API_KEY','BREVO_SENDER_EMAIL','PROFITPOCKET_BASE_URL','PROFITPOCKET_NOTIFICATION_SECRET'] as $key) { if (trim((string)$config[$key])==='') { $missing[]=$key; } }
    $candidate=$config; $candidate['BREVO_EMAIL_ENABLED']=true;
    return ['sending_enabled'=>$config['BREVO_EMAIL_ENABLED'],'configuration_valid'=>email_ready($candidate),
        'valid_settings'=>['BREVO_API_KEY'=>strlen((string)$config['BREVO_API_KEY'])>=20 && !preg_match('/[\r\n]/',(string)$config['BREVO_API_KEY']),
            'BREVO_SENDER_EMAIL'=>(bool)filter_var($config['BREVO_SENDER_EMAIL'],FILTER_VALIDATE_EMAIL),
            'PROFITPOCKET_BASE_URL'=>(bool)filter_var($config['PROFITPOCKET_BASE_URL'],FILTER_VALIDATE_URL) && parse_url($config['PROFITPOCKET_BASE_URL'],PHP_URL_SCHEME)==='https'],
        'missing_settings'=>$missing,'curl'=>extension_loaded('curl'),
        'reset_secret_ready'=>(bool)preg_match('/\A[a-f0-9]{64}\z/',(string)$config['PROFITPOCKET_NOTIFICATION_SECRET']),
        'notification_schema'=>notification_schema($db),'provider_sender_status'=>'not_checked','inbox_delivery'=>'not_verified'];
}
function brevo_sender_status(array $config,?callable $transport=null): string
{
    if (!extension_loaded('curl') || strlen((string)$config['BREVO_API_KEY'])<20 || preg_match('/[\r\n]/',(string)$config['BREVO_API_KEY']) || !filter_var($config['BREVO_SENDER_EMAIL'],FILTER_VALIDATE_EMAIL)) { return 'configuration_missing'; }
    try {
        if ($transport) { $response=$transport(); }
        else {
            $curl=curl_init('https://api.brevo.com/v3/senders');
            curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_HTTPHEADER=>['Accept: application/json','api-key: '.$config['BREVO_API_KEY']]]);
            $body=curl_exec($curl); $error=curl_errno($curl); $http=curl_getinfo($curl,CURLINFO_HTTP_CODE); curl_close($curl); $response=['body'=>$body?:'','error'=>$error,'http'=>$http];
        }
        if (!empty($response['error'])) { return 'connection_unavailable'; }
        if (($response['http']??0)!==200) { return in_array($response['http']??0,[401,403],true)?'credentials_rejected':'provider_unavailable'; }
        $data=json_decode($response['body']??'',true);
        foreach ($data['senders']??[] as $sender) { if (mb_strtolower($sender['email']??'')===mb_strtolower($config['BREVO_SENDER_EMAIL'])) { return !empty($sender['active'])?'active':'inactive'; } }
        return 'sender_not_registered';
    } catch (Throwable $e) { return 'provider_unavailable'; }
}
function email_render_diagnostic(array $config,string $recipient): array
{
    $type='diagnostic'; $url=fn(string $path)=>rtrim($config['PROFITPOCKET_BASE_URL'],'/').'/'.$path;
    $support=$config['BREVO_REPLY_TO_EMAIL']?:$config['BREVO_SENDER_EMAIL'];
    $template=['title'=>'Your ProfitPocket email connection test','paragraphs'=>['An authorized operator requested this single test of the ProfitPocket transactional email connection.','This message confirms a test request only. It does not represent a registration, password change or payment.'],'cta'=>['Open ProfitPocket',$url('')],'closing'=>'The ProfitPocket Team'];
    ob_start(); require __DIR__.'/../emails/layout.php'; $html=ob_get_clean();
    return ['subject'=>'ProfitPocket email delivery test','html'=>$html,'text'=>implode("\n\n",$template['paragraphs'])."\n".$url(''),'to_email'=>$recipient,'to_name'=>'ProfitPocket administrator'];
}

function email_activate(PDO $db,?callable $senderTransport=null): void
{
    $candidate=email_config(); $candidate['BREVO_EMAIL_ENABLED']=true;
    $status=email_diagnostic_status($db,$candidate);
    if (!$status['configuration_valid'] || !$status['reset_secret_ready'] || !$status['notification_schema']) { throw new RuntimeException('Complete private email settings and the approved notification schema first.'); }
    if (brevo_sender_status($candidate,$senderTransport)!=='active') { throw new RuntimeException('Brevo did not confirm an active verified sender.'); }
    $env=getenv('BREVO_EMAIL_ENABLED');
    if ($env!==false) {
        if (!filter_var($env,FILTER_VALIDATE_BOOLEAN)) { throw new RuntimeException('The server environment overrides email sending to false; update it for web PHP and cron.'); }
        return;
    }
    [, $file]=email_config_location();
    if (!is_file($file) || !is_writable($file) || !is_writable(dirname($file))) { throw new RuntimeException('The private email configuration and its directory must be writable by the PHP account.'); }
    ob_start(); try { $values=require $file; } finally { ob_end_clean(); }
    if (!is_array($values)) { throw new RuntimeException('Invalid private email configuration.'); }
    if (($values['BREVO_EMAIL_ENABLED']??false)===true) { return; }
    $values['BREVO_EMAIL_ENABLED']=true;
    $temporary=dirname($file).'/email-'.bin2hex(random_bytes(12)).'.tmp';
    try {
        $handle=fopen($temporary,'x');
        if (!$handle) { throw new RuntimeException('Private email settings could not be saved.'); }
        try {
            if (!chmod($temporary,0600)) { throw new RuntimeException('Private file permissions could not be set.'); }
            $content="<?php\nreturn ".var_export($values,true).";\n";
            if (fwrite($handle,$content)!==strlen($content) || !fflush($handle)) { throw new RuntimeException('Private email settings could not be saved.'); }
        } finally { fclose($handle); }
        if (!rename($temporary,$file)) { throw new RuntimeException('Private email settings could not be replaced.'); }
    } finally { if (is_file($temporary)) { unlink($temporary); } }
}

function email_diagnostic_key(string $recipient,bool $production=false): string
{
    return 'email-test:'.($production?'hostinger:':'').SUBSCRIPTION_VERSION.':'.substr(hash('sha256',mb_strtolower($recipient)),0,32);
}

function email_send_diagnostic(PDO $db,array $config,array $upgrade,bool $production=false,?callable $transport=null,?callable $senderTransport=null): array
{
    $recipient=(string)$upgrade['PP_EMAIL_TEST_ADDRESS'];
    if (!$upgrade['PP_EMAIL_TEST_AUTHORIZED'] || !email_recipient_valid($recipient)) { throw new RuntimeException('Privately authorize one administrator-controlled test inbox first.'); }
    if (!email_ready($config) || !subscription_schema($db) || !preg_match('/\A[a-f0-9]{64}\z/',(string)$config['PROFITPOCKET_NOTIFICATION_SECRET'])) { throw new RuntimeException('Complete private email settings and approved schema first.'); }
    if ($db->inTransaction()) { throw new RuntimeException('The connection test must run outside an application transaction.'); }
    $key=email_diagnostic_key($recipient,$production);
    $lock='pp-email-test:'.substr(hash('sha256',$db->query('SELECT DATABASE()')->fetchColumn().':'.$key),0,40);
    if (!subscription_query($db,'SELECT GET_LOCK(?,0)',[$lock])->fetchColumn()) { return ['status'=>'another_test_running']; }
    try {
        $saved=subscription_query($db,'SELECT details FROM deployment_runs WHERE run_key=?',[$key])->fetchColumn();
        if ($saved) { return (json_decode($saved,true)?:['status'=>'uncertain'])+['replayed'=>true,'inbox_delivery'=>'not_verified']; }
        if (brevo_sender_status($config,$senderTransport)!=='active') { throw new RuntimeException('Brevo did not confirm the configured sender is active.'); }
        // Persist BEFORE the API request. A crash or timeout never resends blindly.
        subscription_query($db,'INSERT INTO deployment_runs (run_key,details) VALUES (?,?)',[$key,'{"status":"uncertain","code":"attempt_started"}']);
        $result=brevo_send($config,email_render_diagnostic($config,$recipient),hash('sha256',$key),$transport);
        subscription_query($db,'UPDATE deployment_runs SET details=? WHERE run_key=?',[json_encode($result,JSON_THROW_ON_ERROR),$key]);
        return $result+['replayed'=>false,'inbox_delivery'=>'not_verified'];
    } finally { subscription_query($db,'SELECT RELEASE_LOCK(?)',[$lock]); }
}

function email_diagnostic_delivery(PDO $db,array $config,array $upgrade,bool $production=false,?callable $transport=null): array
{
    $recipient=(string)$upgrade['PP_EMAIL_TEST_ADDRESS'];
    if (!$upgrade['PP_EMAIL_TEST_AUTHORIZED'] || !email_recipient_valid($recipient)) { throw new RuntimeException('Authorize the controlled test inbox privately first.'); }
    $saved=subscription_query($db,'SELECT details FROM deployment_runs WHERE run_key=?',[email_diagnostic_key($recipient,$production)])->fetchColumn();
    $result=$saved?json_decode($saved,true):[]; $id=$result['message_id']??'';
    if (!is_string($id) || $id==='') { return ['provider_delivery'=>'not_verifiable','inbox_delivery'=>'not_verified']; }
    $unknown=['provider_delivery'=>'not_verified','inbox_delivery'=>'not_verified'];
    if (!email_ready($config)) { return $unknown; }
    try {
        if ($transport) { $response=$transport(); }
        else {
            $query=http_build_query(['messageId'=>$id,'email'=>$recipient,'limit'=>100,'sort'=>'desc']);
            $curl=curl_init('https://api.brevo.com/v3/smtp/statistics/events?'.$query);
            curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_HTTPHEADER=>['Accept: application/json','api-key: '.$config['BREVO_API_KEY']]]);
            $body=curl_exec($curl); $error=curl_errno($curl); $http=curl_getinfo($curl,CURLINFO_HTTP_CODE); curl_close($curl); $response=['http'=>$http,'body'=>$body?:'','error'=>$error];
        }
        if (!empty($response['error']) || ($response['http']??0)!==200) { return $unknown; }
        $data=json_decode($response['body']??'',true); $events=[];
        foreach ($data['events']??[] as $event) {
            if (($event['messageId']??'')!==$id || mb_strtolower($event['email']??'')!==mb_strtolower($recipient)) { continue; }
            $kind=(string)($event['event']??'');
            if (in_array($kind,['delivered','hard_bounce','soft_bounce','blocked','deferred','invalid','request','error'],true)) { $events[]=$kind; }
        }
        return ['provider_delivery'=>in_array('delivered',$events,true)?'delivered':($events[0]??'not_yet_reported'),'inbox_delivery'=>'not_verified'];
    } catch (Throwable $e) { return $unknown; }
}
