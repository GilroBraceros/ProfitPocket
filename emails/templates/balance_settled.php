<?php
return ['subject'=>'Your Outstanding Balance Has Been Settled!','title'=>'Your outstanding balance is settled',
'paragraphs'=>["Hi $name,",'Good news!',"Your outstanding balance with $business has been fully settled according to the business's records.",'Thank you for completing your payment!'],
'details'=>['Business'=>$business,'Transaction number'=>$data['sale_number']??'','Previous balance'=>email_money($data['previous']),'Final payment'=>email_money($data['amount']),'Total amount settled'=>email_money($data['total_paid']),'Settlement date'=>email_date($data['paid_at']),'Remaining balance'=>'₱0.00']+(!empty($data['reference_number'])?['Transaction reference'=>$data['reference_number']]:[]),
'closing'=>'Payment confirmation powered by ProfitPocket. This reflects the business’s payment record.'];
