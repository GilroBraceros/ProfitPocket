<?php
$details=['Transaction number'=>$data['sale_number'],'Purchase date'=>email_date($data['sold_at']),'Total purchase amount'=>email_money($data['total']),'Total amount paid'=>email_money($data['amount_paid']),'Remaining balance'=>email_money($data['remaining'])];
if (!empty($data['due_date'])) {$details['Due date']=email_date($data['due_date'],false);}
$paragraphs=["Hi $name,","This is a friendly reminder regarding your purchase from $business. According to the business's records, your transaction currently has an outstanding balance."];
if (!empty($data['owner_message'])) {$paragraphs[]="Message from $business:\n".$data['owner_message'];}
$paragraphs[]="Please contact $business regarding your payment. If you already made a payment that has not been recorded, kindly coordinate with the business so its records can be updated.";
return ['subject'=>'Friendly Payment Reminder — '.$business,'title'=>'Friendly payment reminder','paragraphs'=>$paragraphs,'details'=>$details,'closing'=>'Thank you! Payment reminder powered by ProfitPocket.'];
