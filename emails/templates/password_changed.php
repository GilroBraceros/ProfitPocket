<?php
return ['subject'=>'Your ProfitPocket Password Has Been Changed','title'=>'Your password has been changed',
'paragraphs'=>["Hi $first,",'Your ProfitPocket account password was successfully changed.','If you made this change, no further action is required. If you did not make this change, please contact our support team immediately.'],
'details'=>['Change date'=>email_date($data['changed_at'])],'cta'=>['Contact Support',$url('auth/contact_support.php')],'closing'=>'The ProfitPocket Security Team'];
