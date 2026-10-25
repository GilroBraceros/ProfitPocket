<?php
return ['subject'=>'Action Required — ProfitPocket Payment Verification','title'=>'Please review your payment submission',
'paragraphs'=>["Hi $first,",'An administrator could not approve your subscription payment proof. Review the reason below and submit corrected proof. Any existing valid subscription remains available until its expiry.'],
'details'=>['Reason'=>$data['reason']],
'cta'=>['Review and resubmit',$url('user/subscription.php')]];
