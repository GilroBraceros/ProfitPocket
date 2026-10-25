<?php
return ['subject'=>'Your ProfitPocket Account Is Now Active!','title'=>'Your account is activated',
'paragraphs'=>["Hi $first,",'An administrator verified your payment and approved your ProfitPocket Monthly subscription. Your business tools are ready.'],
'details'=>['Verified payment'=>email_money($data['amount']),'Activation date'=>email_date($data['period_start']),'Access until'=>email_date($data['period_end'])],
'cta'=>['Open dashboard',$url('user/dashboard.php')]];
