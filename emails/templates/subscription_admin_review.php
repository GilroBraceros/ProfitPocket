<?php
return ['subject'=>'New ProfitPocket Subscription Payment for Review','title'=>'An initial payment is ready for review',
'paragraphs'=>["Hi $first,",'A new Business Owner completed registration and submitted initial subscription payment proof. Review it against the actual receiving account before approving access.'],
'details'=>['Owner'=>$data['owner_name'],'Business'=>$data['business_name'],'Amount'=>email_money($data['amount']),'Submitted'=>email_date($data['submitted_at'])],
'cta'=>['Review payment',$url('admin/subscriptions.php?view='.(int)$data['submission_id'])]];
