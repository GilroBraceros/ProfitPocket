<?php
// Runs inside the authenticated disposable owner's existing HTTP test session.
foreach (['sales','expenses','profit','utang'] as $tab) {
    $reply=request_http('user/reports.php?tab='.$tab);
    expect_http($reply['status']===200 && str_contains($reply['body'],'Financial Reports') && !str_contains($reply['body'],'temporarily unavailable'),'Report page unavailable: '.$tab);
    expect_http(substr_count($reply['body'],'data-report-amount=')===4,'Report summary cards missing');
    $csv=request_http('user/reports.php?tab='.$tab.'&export=csv');
    expect_http($csv['status']===200 && str_contains($csv['headers']['content-type'][0]??'','text/csv') && str_contains($csv['headers']['content-disposition'][0]??'','attachment; filename="profitpocket_'.($tab==='utang'?'receivables':$tab).'_'),'CSV download headers wrong');
    expect_http(str_contains($csv['body'],$tab==='utang'?'Customer Receivables':ucfirst($tab).' report') && str_contains($csv['body'],'Asia/Manila') && !str_contains($csv['body'],'<!doctype'),'CSV content wrong');
    $print=request_http('user/reports.php?tab='.$tab.'&print=1');
    expect_http($print['status']===200 && str_contains($print['body'],'report-print-view') && str_contains($print['body'],'Print report') && !str_contains($print['body'],'data-report-chart='),'Print view unavailable');
}
foreach (['today','week','month','year'] as $period) { expect_http(request_http('user/reports.php?period='.$period)['status']===200,'Period failed: '.$period); }
expect_http(request_http('user/reports.php?period=custom&start=2026-10-01&end=2026-10-09')['status']===200,'Custom report unavailable');
foreach (['period=bad','period[]=month','period=custom&start=2026-02-30&end=2026-03-01','period=custom&start=2026-10-10&end=2026-10-09','period=custom&start=2024-01-01&end=2026-10-09','q[]=bad','status=overdue','page=0','tab=bad','export=pdf','print[]=1','category=-1'] as $query) {
    $reply=request_http('user/reports.php?'.$query); expect_http($reply['status']===400 && !str_contains($reply['body'],'SQLSTATE'),'Malformed filter not rejected: '.$query);
}
expect_http(request_http('user/reports.php',['tab'=>'sales'])['status']===405,'Reports accepted POST');
$reply=request_http('user/reports.php?q=%3Cscript%3Ealert(1)%3C%2Fscript%3E');
expect_http($reply['status']===200 && str_contains($reply['body'],'&lt;script&gt;') && !str_contains($reply['body'],'<script>alert(1)</script>'),'Report search XSS');
$foreignRef=$connection->query('SELECT sale_number FROM sales WHERE business_id<>'.(int)$owner['business_id'].' LIMIT 1')->fetchColumn();
foreach (['sales','expenses','utang'] as $tab) {
    $csv=request_http('user/reports.php?tab='.$tab.'&export=csv&business_id='.$other_business);
    expect_http($csv['status']===200 && (!$foreignRef || !str_contains($csv['body'],$foreignRef)),'CSV foreign business exposure');
}
$checks[]='PASS: Reports HTTP tabs, empty states, all periods, CSV headers/content, print views, malformed filters/XSS, read-only requests and foreign-business export isolation';
