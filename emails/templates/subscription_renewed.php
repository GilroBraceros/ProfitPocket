<?php
return ['subject'=>'Your ProfitPocket Subscription Has Been Renewed','title'=>'Your subscription is renewed',
'paragraphs'=>["Hi $first,",'An administrator verified your renewal payment. Your subscription period has been extended using your actual approval and expiry dates. No automatic recurring charge was created.'],
'details'=>['Verified payment'=>email_money($data['amount']),'New expiry'=>email_date($data['period_end'])],
'cta'=>['Open dashboard',$url('user/dashboard.php')]];
