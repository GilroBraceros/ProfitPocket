<?php
return ['subject'=>'Subscription Payment Proof Submitted','title'=>'Your payment proof is awaiting review',
'paragraphs'=>["Hi $first,",'Your ₱100 monthly subscription payment proof was submitted successfully and is awaiting administrator verification. Uploading proof does not confirm receipt of money or activate access.'],
'details'=>['Plan'=>'ProfitPocket Monthly','Amount'=>email_money($data['amount']),'Submitted'=>email_date($data['submitted_at'])],
'cta'=>['View submission',$url('user/subscription.php')]];
