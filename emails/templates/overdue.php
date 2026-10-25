<?php
return ['subject'=>'Payment Follow-Up — '.$business,'title'=>'A friendly payment follow-up',
'paragraphs'=>["Hi $name,","Our records indicate that you have an outstanding payment with $business.",'Please contact the business to confirm your payment status or discuss the remaining balance.',"If you've already paid, kindly disregard this reminder and notify the business. Thank you for your attention."],
'details'=>['Business'=>$business,'Outstanding balance'=>email_money($data['remaining']),'Original due date'=>email_date($data['due_date'],false)],'closing'=>'Powered by ProfitPocket.'];
