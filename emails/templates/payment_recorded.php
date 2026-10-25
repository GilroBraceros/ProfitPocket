<?php
return ['subject'=>'Payment Confirmation — '.$business,'title'=>'Payment recorded',
'paragraphs'=>["Hi $name,","Your payment has been recorded by $business.",'Thank you for your payment! For questions or concerns, please contact the business directly.'],
'details'=>['Business'=>$business,'Payment amount'=>email_money($data['amount']),'Payment date'=>email_date($data['paid_at']),'Reference number'=>$data['reference_number']??'Not provided','Remaining balance after payment'=>email_money($data['remaining'])],
'closing'=>'This notification was generated through ProfitPocket. The business recorded this payment; ProfitPocket did not process or receive your money.'];
