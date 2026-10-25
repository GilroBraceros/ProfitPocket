<?php
return ['subject'=>'Your ProfitPocket Subscription Expires Soon','title'=>'Keep your business tools available',
'paragraphs'=>["Hi $first,",'Your ProfitPocket Monthly subscription expires soon. To renew, manually pay ₱100 using the configured instructions and submit proof for administrator review. No automatic charge will be made.'],
'details'=>['Current expiry'=>email_date($data['period_end']),'Monthly amount'=>email_money($data['amount'])],
'cta'=>['Renew subscription',$url('user/subscription.php')]];
