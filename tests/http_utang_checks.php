<?php
// Included by the HTTP runner while its disposable owner is signed in.
if (PHP_SAPI!=='cli') { http_response_code(403); exit; }
$page=request_http('user/payments.php');
expect_http($page['status']===200 && str_contains($page['body'],'No matching payments') && str_contains($page['body'],'Payment History') && str_contains($page['body'],'Outstanding Balances'),'Empty Payment Management page failed');
expect_http(substr_count($page['body'],'class="summary-card"')===4,'Payment summary cards missing');
expect_http(request_http('user/payments.php',['q'=>'read-only'])['status']===405,'Payment list allowed POST');
$legacy=request_http('user/utang.php?state=overdue');
expect_http($legacy['status']===303 && str_contains($legacy['headers']['location'][0]??'','payments.php?') && str_contains($legacy['headers']['location'][0]??'','tab=balances'),'Legacy credit redirect failed');
foreach (['state=bad&tab=balances','period=custom&start=2026-02-30&end=2026-03-01','method=invalid','type=invalid','tab=invalid'] as $query) { expect_http(request_http('user/payments.php?'.$query)['status']===400,'Invalid payment filter accepted'); }
$foreignSale=(int)$connection->query("SELECT sale_id FROM sales WHERE business_id<>".(int)$owner['business_id']." AND sale_status='posted' LIMIT 1")->fetchColumn();
if ($foreignSale) {
    foreach (['details','history','payment','due'] as $action) {
        $reply=request_http('user/payments_action.php?action='.$action.'&id='.$foreignSale.'&modal=1');
        expect_http($reply['status']===404 && !str_contains($reply['body'],'data-utang-async'),'Foreign payment transaction exposed');
    }
}
$foreignPayment=(int)$connection->query('SELECT payment_id FROM sale_payments WHERE business_id<>'.(int)$owner['business_id'].' LIMIT 1')->fetchColumn();
if ($foreignPayment) { expect_http(request_http('user/payments_action.php?action=payment_details&id='.$foreignPayment.'&modal=1')['status']===404,'Foreign payment details exposed'); }
$forged=['sale_id'=>(string)($foreignSale?:1),'csrf_token'=>'forged','request_key'=>str_repeat('a',64),'request_signature'=>'forged','amount'=>'1'];
foreach (['payment','due'] as $action) { expect_http(request_http('user/payments_action.php?action='.$action.'&modal=1',$forged)['status']===403,'Forged payment mutation accepted'); }
expect_http(request_http('user/payments_action.php?action=history&modal=1',$forged)['status']===405,'Payment history allowed mutations');
$checks[]='PASS: Payment Management tabs/cards/filters, legacy redirect, foreign sale/payment isolation, CSRF and read-only history; no financial writes';
