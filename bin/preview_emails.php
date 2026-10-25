<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(403); exit; }
date_default_timezone_set('Asia/Manila'); require __DIR__.'/../includes/email_templates.php';
$directory=$argv[1]??__DIR__.'/../tests/http-results/email-previews';
if (!is_dir($directory)) { mkdir($directory,0700,true); }
$config=['PROFITPOCKET_BASE_URL'=>'https://profitpocket.example.test','BREVO_REPLY_TO_EMAIL'=>'support@example.invalid','BREVO_SENDER_EMAIL'=>'notifications@example.invalid'];
$data=['name'=>'Carlo <Sample>','customer_name'=>'Sample Customer','business_name'=>'Sample Sari-Sari Store','amount'=>'1250.00','previous'=>'2000.00','remaining'=>'750.00','total_paid'=>'2000.00','paid_at'=>'2026-10-25 09:30:00','changed_at'=>'2026-10-25 09:30:00','due_date'=>'2026-10-28','reference_number'=>null,'reset_token'=>str_repeat('e',64),'submitted_at'=>'2026-10-25 09:30:00','period_start'=>'2026-10-25 09:30:00','period_end'=>'2026-11-25 09:30:00','reason'=>'Sample test reason: the reference could not be verified.','subscription_required'=>true,'owner_name'=>'Sample Owner','submission_id'=>1];
foreach (EMAIL_TYPES as $type) { $sample=$data; if (str_starts_with($type,'subscription_')) { $sample['amount']='100.00'; } $rendered=email_render($type,$sample,$config); file_put_contents($directory.'/'.$type.'.html',$rendered['html']); }
echo 'Rendered '.count(EMAIL_TYPES).' fabricated-data previews. No network transport was used: '.realpath($directory).PHP_EOL;
