<?php
return ['subject'=>'Reset Your ProfitPocket Password','title'=>'Reset your password',
'paragraphs'=>["Hi $first,",'We received a request to reset your ProfitPocket account password. Click the button below to create a new password.','This link expires in 30 minutes. If you did not request this change, you can safely ignore this email.'],
'cta'=>['Reset Password',$url('auth/reset_password.php').'#token='.$data['reset_token']], 'closing'=>'The ProfitPocket Security Team'];
