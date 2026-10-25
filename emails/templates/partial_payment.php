<?php
return ['subject'=>'Partial Payment Received — '.$business,'title'=>'Partial payment recorded',
'paragraphs'=>["Hi $name,","Your partial payment has been successfully recorded by $business.",'Thank you for your payment. You may contact the business if you have questions about your remaining balance.'],
'details'=>['Business'=>$business,'Previous balance'=>email_money($data['previous']),'Amount paid'=>email_money($data['amount']),'Remaining balance after payment'=>email_money($data['remaining'])],
'closing'=>'Powered by ProfitPocket. This message confirms the business’s record, not payment processing by ProfitPocket.'];
