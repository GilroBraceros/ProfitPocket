<?php
return ['subject'=>'Welcome to ProfitPocket — Payment Verification Pending','title'=>'Registration completed!',
'paragraphs'=>["Hi $first,",'Your ProfitPocket account has been created, and your ₱100 subscription payment proof has been submitted for verification.','Please wait for a System Administrator to review your payment. You will receive an email once your account has been activated.','Your account will become fully accessible once your subscription payment has been approved.'],
'details'=>['Business'=>$data['business_name'],'Amount submitted'=>email_money($data['amount']),'Submitted'=>email_date($data['submitted_at']),'Status'=>'Pending Verification'],
'cta'=>['Go to Sign In',$url('auth/login.php')]];
