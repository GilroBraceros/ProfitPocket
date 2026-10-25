<?php
return ['subject'=>'Your ProfitPocket Subscription Has Expired','title'=>'Renew to restore your business tools',
'paragraphs'=>["Hi $first,",'Your subscription has expired. Your existing business records are preserved. You can still sign in, submit renewal proof, manage your password and contact support. Business tools reopen after administrator approval.'],
'details'=>['Expired'=>email_date($data['period_end']),'Renewal amount'=>email_money($data['amount'])],
'cta'=>['Submit renewal proof',$url('user/subscription.php')]];
