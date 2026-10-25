<?php
declare(strict_types=1);
const EMAIL_TYPES=['welcome','password_reset','password_changed','payment_recorded','partial_payment','upcoming_due','overdue','balance_settled','subscription_submitted','subscription_approved','subscription_rejected','subscription_expiring','subscription_expired','subscription_renewed','registration_payment_submitted','subscription_admin_review'];
function email_escape($value): string { return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function email_date(string $value,bool $time=true): string { return (new DateTimeImmutable($value,new DateTimeZone('Asia/Manila')))->setTimezone(new DateTimeZone('Asia/Manila'))->format($time?'j F Y, g:i a \P\H\T':'j F Y'); }
function email_money(string $value): string
{
    if (!preg_match('/\A\d+(?:\.\d{1,2})?\z/',$value)) { throw new InvalidArgumentException('Invalid email amount.'); }
    [$whole,$part]=array_pad(explode('.',$value),2,''); return '₱'.preg_replace('/\B(?=(\d{3})+(?!\d))/',',',$whole).str_pad('.'.$part,3,'0');
}
function email_render(string $type,array $data,array $config): array
{
    if (!in_array($type,EMAIL_TYPES,true)) { throw new InvalidArgumentException('Unsupported notification type.'); }
    $base=rtrim($config['PROFITPOCKET_BASE_URL'],'/');
    if (!filter_var($base,FILTER_VALIDATE_URL) || parse_url($base,PHP_URL_SCHEME)!=='https') { throw new InvalidArgumentException('Email links require an HTTPS base URL.'); }
    $url=fn(string $route)=>$base.'/'.$route;
    $first=explode(' ',trim($data['name']??''))[0]?:'there'; $name=$data['customer_name']??'Customer'; $business=$data['business_name']??'';
    $template=require __DIR__.'/../emails/templates/'.$type.'.php';
    $subject=trim(preg_replace('/[\r\n]+/u',' ', $template['subject']));
    $support=$config['BREVO_REPLY_TO_EMAIL']?:$config['BREVO_SENDER_EMAIL'];
    ob_start(); require __DIR__.'/../emails/layout.php'; $html=ob_get_clean();
    $text=$template['title']."\n\n".implode("\n\n",$template['paragraphs']);
    foreach ($template['details']??[] as $label=>$value) { $text.="\n".$label.': '.$value; }
    if (isset($template['cta'])) { $text.="\n\n".$template['cta'][0].': '.$template['cta'][1]; }
    $text.="\n\nProfitPocket\nManage smarter. Track better.\nThis is an automated notification from ProfitPocket.\nFor assistance, contact ".$support.".\n© ProfitPocket. All rights reserved.";
    return ['subject'=>$subject,'html'=>$html,'text'=>$text];
}
