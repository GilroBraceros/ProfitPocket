<?php
declare(strict_types=1);
const EMAIL_TYPES=['welcome','password_reset','password_changed','payment_recorded','partial_payment','upcoming_due','overdue','balance_settled','subscription_submitted','subscription_approved','subscription_rejected','subscription_expiring','subscription_expired','subscription_renewed','registration_payment_submitted','subscription_admin_review','purchase_paid','purchase_unpaid','purchase_installment','manual_reminder'];
function email_purchase_template(string $type,array $data): array
{
    $business=$data['business_name'];$name=$data['customer_name'];
    $subject=match($type) {'purchase_paid'=>'Purchase Confirmation — ','purchase_unpaid'=>'Purchase Recorded — Payment Pending | ',default=>'Installment Purchase Confirmation — '};
    $status=match($type) {'purchase_paid'=>'Fully Paid','purchase_unpaid'=>'Unpaid',default=>'Partially Paid'};
    $details=['Transaction number'=>$data['sale_number'],'Purchase date'=>email_date($data['sold_at'])];
    $discounted=!empty($data['discount']) && bccomp($data['discount'],'0',2)>0;
    if ($discounted) {$details+=['Subtotal'=>email_money($data['subtotal']),'Discount'=>email_money($data['discount'])];}
    $details+=['Total purchase amount'=>email_money($data['total']),($type==='purchase_installment'?'Initial payment / down payment':'Amount paid at purchase')=>email_money($data['amount_paid']),'Remaining balance'=>email_money($data['remaining']),'Payment status'=>$status];
    if (!empty($data['due_date'])) {$details['Due date']=email_date($data['due_date'],false);}
    return ['subject'=>$subject.$business,'title'=>$type==='purchase_paid'?'Thank you for your purchase':'Your purchase has been recorded',
        'paragraphs'=>["Hi $name,",($type==='purchase_installment'?"Your installment purchase from $business has been successfully recorded.":"Your purchase from $business has been successfully recorded."),$type==='purchase_paid'?'Thank you for your purchase!':"Please coordinate with $business regarding the remaining balance and your payment arrangement."],
        'details'=>$details,'items'=>$data['items'],'items_label'=>$discounted?'Purchased items (before discount)':'Purchased items','closing'=>"For inquiries, please contact $business. This purchase confirmation was generated through ProfitPocket."];
}
// Preview only: keep the application's strict CSP. Real emails retain inline CSS.
function email_browser_preview(string $html,string $base): string
{
    if (!preg_match('~\A(?:/[A-Za-z0-9_-]+)*\z~',$base)) {throw new InvalidArgumentException('Invalid preview asset path.');}
    $html=preg_replace('/\sstyle="[^"]*"/','',$html);
    $html=str_replace('</head>','<link rel="stylesheet" href="'.email_escape($base.'/assets/css/email-preview.css').'"></head>',$html);
    return preg_replace('/(<img\b[^>]*\bsrc=)"[^"]*"/','$1"'.email_escape($base.'/assets/branding.php?logo=icon').'"',$html,1);
}
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
    if (!empty($template['items'])) {$text.="\n\nPurchased items";foreach ($template['items'] as $item) {$text.="\n".$item['quantity'].' × '.$item['name'].' — '.email_money($item['total']);}}
    if (isset($template['cta'])) { $text.="\n\n".$template['cta'][0].': '.$template['cta'][1]; }
    $text.="\n\nProfitPocket\nManage smarter. Track better.\nThis is an automated notification from ProfitPocket.\nFor assistance, contact ".$support.".\n© ProfitPocket. All rights reserved.";
    return ['subject'=>$subject,'html'=>$html,'text'=>$text];
}
