<?php
return ['subject'=>'Welcome to ProfitPocket!','title'=>'Welcome to ProfitPocket!',
'paragraphs'=>["Hi $first,",'Welcome to ProfitPocket! Your account has been successfully created.',!empty($data['subscription_required'])?'Activate your ₱100 monthly subscription by following the payment instructions and submitting proof. Business tools open after an administrator verifies and approves your payment.':'Manage your business sales, expenses, products, customers and payments in one convenient platform.','Thank you for choosing ProfitPocket!'],
'cta'=>!empty($data['subscription_required'])?['Activate subscription',$url('user/subscription.php')]:['Go to Dashboard',$url('user/dashboard.php')]];
