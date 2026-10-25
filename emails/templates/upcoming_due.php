<?php
return ['subject'=>'Friendly Payment Reminder — '.$business,'title'=>'Your payment is coming due',
'paragraphs'=>["Hi $name,","This is a friendly reminder that you have an upcoming payment with $business.","Please coordinate with the business regarding your payment. If you've already settled this amount, kindly contact the business so its records can be updated.",'Thank you!'],
'details'=>['Business'=>$business,'Outstanding balance'=>email_money($data['remaining']),'Due date'=>email_date($data['due_date'],false)],'closing'=>'Payment reminder powered by ProfitPocket.'];
