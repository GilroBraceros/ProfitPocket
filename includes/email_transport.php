<?php
declare(strict_types=1);
require_once __DIR__.'/../config/email.php';

function brevo_uuid(string $event): string
{
    $h=hash('sha256',$event); return substr($h,0,8).'-'.substr($h,8,4).'-4'.substr($h,13,3).'-8'.substr($h,17,3).'-'.substr($h,20,12);
}
function email_recipient_valid(string $email,bool $allowTest=false): bool
{
    if (!filter_var($email,FILTER_VALIDATE_EMAIL)) { return false; }
    $domain=strtolower(substr(strrchr($email,'@'),1));
    return $allowTest || (!preg_match('/(?:^|\.)(?:invalid|test|example|localhost)\z/',$domain) && !in_array($domain,['example.com','example.net','example.org'],true));
}
function brevo_send(array $config,array $email,string $event,?callable $transport=null): array
{
    if (!email_ready($config)) { return ['status'=>'skipped','code'=>'configuration_missing']; }
    if (!email_recipient_valid($email['to_email']??'',$transport!==null)) { return ['status'=>'skipped','code'=>'invalid_recipient']; }
    $payload=['sender'=>['email'=>$config['BREVO_SENDER_EMAIL'],'name'=>$config['BREVO_SENDER_NAME']],
        'replyTo'=>['email'=>$config['BREVO_REPLY_TO_EMAIL']?:$config['BREVO_SENDER_EMAIL'],'name'=>'ProfitPocket'],
        'to'=>[['email'=>$email['to_email'],'name'=>$email['to_name']]],'subject'=>$email['subject'],
        'htmlContent'=>$email['html'],'textContent'=>$email['text'],'headers'=>['idempotencyKey'=>brevo_uuid($event)]];
    try {
        if ($transport) { $response=$transport($payload); }
        else {
            $retry=60; $curl=curl_init('https://api.brevo.com/v3/smtp/email');
            curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,
                CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_HTTPHEADER=>['Accept: application/json','Content-Type: application/json','api-key: '.$config['BREVO_API_KEY']],
                CURLOPT_POSTFIELDS=>json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),
                CURLOPT_HEADERFUNCTION=>static function($curl,$line) use (&$retry) { if (preg_match('/^Retry-After:\s*(\d+)/i',$line,$m)) { $retry=max(30,min(600,(int)$m[1])); } return strlen($line); }]);
            $body=curl_exec($curl); $error=curl_errno($curl); $status=curl_getinfo($curl,CURLINFO_HTTP_CODE); curl_close($curl);
            $response=['http'=>$status,'body'=>$body!==false?$body:'','error'=>$error,'retry'=>$retry];
        }
        if (in_array((int)($response['error']??0),[6,7],true)) { return ['status'=>'retry','code'=>'connection_unavailable','retry'=>60]; }
        if (in_array((int)($response['error']??0),[60,77],true)) { return ['status'=>'failed','code'=>'tls_configuration']; }
        if (!empty($response['error'])) { return ['status'=>'uncertain','code'=>'network_outcome_unknown']; }
        $status=(int)($response['http']??0); $body=json_decode($response['body']??'',true);
        if ($status===201 && is_string($body['messageId']??null)) { return ['status'=>'accepted','message_id'=>mb_substr($body['messageId'],0,255),'code'=>null]; }
        if ($status>=200 && $status<300) { return ['status'=>'uncertain','code'=>'acceptance_id_missing']; }
        if (($body['code']??'')==='duplicate_parameter') { return ['status'=>'uncertain','code'=>'provider_duplicate']; }
        if ($status===429 || $status>=500) { return ['status'=>'retry','code'=>$status===429?'rate_limited':'provider_unavailable','retry'=>$response['retry']??60]; }
        return ['status'=>'failed','code'=>in_array($status,[401,403],true)?'sender_or_credentials_rejected':'provider_rejected'];
    } catch (Throwable $e) { return ['status'=>'uncertain','code'=>'transport_outcome_unknown']; }
}
