<?php
// Included by http_auth_test.php while its disposable owner is signed in.
if (PHP_SAPI!=='cli') { http_response_code(403); exit; }
function customer_http_fields(array $response): array
{
    $fields=[]; foreach (['csrf_token','request_key','request_signature','customer_id'] as $name) { $fields[$name]=named_field($response,$name); } return $fields;
}
function customer_http_json(array $response, int $status=200): array
{
    expect_http($response['status']===$status,'Unexpected customer HTTP status: '.$response['status']);
    return json_decode($response['body'],true,512,JSON_THROW_ON_ERROR);
}
function customer_in_selector(array $response, int $id): bool
{
    expect_http((bool)preg_match('/<select[^>]*id="customer_id"[^>]*>(.*?)<\/select>/s',$response['body'],$matches),'Sales customer selector absent');
    return str_contains($matches[1],'value="'.$id.'"');
}
expect_http(request_http('user/customers.php')['status']===200,'Customer page unavailable');
expect_http(request_http('user/customers.php?balance=invalid')['status']===400,'Invalid customer filter accepted');
$customerForm=request_http('user/customer_action.php?action=create&modal=1');
$customerPayload=customer_http_fields($customerForm)+['customer_name'=>'<HTTP customer>','phone'=>'+63 917 555 0101','email'=>'customer@example.invalid','address'=>'Test address','notes'=>'<script>escaped customer note</script>'];
$invalidCustomer=array_replace($customerPayload,['customer_name'=>'']);
$validation=customer_http_json(request_http('user/customer_action.php?action=create&modal=1',$invalidCustomer),400);
expect_http(isset($validation['fields']['customer_name']),'Missing customer name accepted');
$forgedCustomer=array_replace($customerPayload,['csrf_token'=>'forged']);
expect_http(request_http('user/customer_action.php?action=create&modal=1',$forgedCustomer)['status']===403,'Customer CSRF forgery accepted');
$savedCustomer=customer_http_json(request_http('user/customer_action.php?action=create&modal=1',$customerPayload));
expect_http($savedCustomer['success'],'HTTP customer creation failed'); $customerId=(int)$savedCustomer['id'];
expect_http(customer_http_json(request_http('user/customer_action.php?action=create&modal=1',$customerPayload))['id']===$customerId,'Customer replay duplicated data');
$customerDetails=request_http('user/customer_action.php?action=details&modal=1&id='.$customerId);
expect_http($customerDetails['status']===200 && str_contains($customerDetails['body'],'&lt;HTTP customer&gt;') && str_contains($customerDetails['body'],'&lt;script&gt;') && str_contains($customerDetails['body'],'09175550101') && !str_contains($customerDetails['body'],'<form'),'Customer details escaping/read-only/normalization failed');
expect_http(customer_in_selector(request_http('user/sale_form.php?modal=1'),$customerId),'New customer missing from Sales');
$duplicateForm=request_http('user/customer_action.php?action=create&modal=1');
$duplicatePayload=array_replace($customerPayload,customer_http_fields($duplicateForm),['customer_name'=>'Shared HTTP contact']);
$validation=customer_http_json(request_http('user/customer_action.php?action=create&modal=1',$duplicatePayload),400);
expect_http($validation['duplicate_phone'] && isset($validation['fields']['phone']),'Shared contact warning absent');
$duplicatePayload['allow_shared_phone']='1';
expect_http(customer_http_json(request_http('user/customer_action.php?action=create&modal=1',$duplicatePayload))['success'],'Explicit shared contact rejected');
$editForm=request_http('user/customer_action.php?action=edit&modal=1&id='.$customerId);
$staleForm=request_http('user/customer_action.php?action=edit&modal=1&id='.$customerId);
$editPayload=array_replace($customerPayload,customer_http_fields($editForm),['revision'=>named_field($editForm,'revision'),'customer_name'=>'Updated HTTP customer']);
$invalidPhone=array_replace($editPayload,['phone'=>'123']);
expect_http(isset(customer_http_json(request_http('user/customer_action.php?action=edit&modal=1',$invalidPhone),400)['fields']['phone']),'Invalid PH mobile accepted');
expect_http(customer_http_json(request_http('user/customer_action.php?action=edit&modal=1',$editPayload))['success'],'HTTP customer edit failed');
expect_http(customer_http_json(request_http('user/customer_action.php?action=edit&modal=1',$editPayload))['success'],'HTTP customer edit replay failed');
$stalePayload=array_replace($editPayload,customer_http_fields($staleForm),['revision'=>named_field($staleForm,'revision')]);
expect_http(request_http('user/customer_action.php?action=edit&modal=1',$stalePayload)['status']===400,'Stale customer edit accepted');
$archiveForm=request_http('user/customer_action.php?action=archive&modal=1&id='.$customerId);
$archivePayload=customer_http_fields($archiveForm)+['revision'=>named_field($archiveForm,'revision')];
expect_http(request_http('user/customer_action.php?action=archive&modal=1',$archivePayload)['status']===400,'Archive accepted without confirmation');
$archivePayload['confirm_customer_action']='1';
expect_http(customer_http_json(request_http('user/customer_action.php?action=archive&modal=1',$archivePayload))['success'],'Archive HTTP action failed');
expect_http(customer_http_json(request_http('user/customer_action.php?action=archive&modal=1',$archivePayload))['success'],'Archive replay failed');
expect_http(!customer_in_selector(request_http('user/sale_form.php?modal=1'),$customerId),'Archived customer offered for a new sale');
expect_http(str_contains(request_http('user/customers.php?state=archived')['body'],'Updated HTTP customer'),'Archived directory lost customer');
$restoreForm=request_http('user/customer_action.php?action=restore&modal=1&id='.$customerId);
$restorePayload=customer_http_fields($restoreForm)+['revision'=>named_field($restoreForm,'revision'),'confirm_customer_action'=>'1'];
expect_http(customer_http_json(request_http('user/customer_action.php?action=restore&modal=1',$restorePayload))['success'],'Restore failed');
expect_http(customer_in_selector(request_http('user/sale_form.php?modal=1'),$customerId),'Restored customer missing from Sales');
$foreignCustomer=$connection->query('SELECT customer_id FROM customers WHERE business_id<>'.(int)$owner['business_id'].' LIMIT 1')->fetchColumn();
foreach (['details','edit','archive','restore'] as $action) { expect_http(request_http('user/customer_action.php?action='.$action.'&modal=1&id='.$foreignCustomer)['status']===404,'Foreign customer accessible: '.$action); }
expect_http(request_http('user/customer_action.php?action=details&modal=1',['customer_id'=>(string)$customerId])['status']===405,'Customer details accept mutations');
expect_http(str_contains(request_http('user/customers.php?q=09175550101')['body'],'Updated HTTP customer'),'Customer phone search failed');
$checks[]='PASS: real HTTP customer create/edit/archive/restore and replay, duplicate-phone warning, field errors, escaping, search, read-only details, stale/CSRF/foreign access and Sales selection';
