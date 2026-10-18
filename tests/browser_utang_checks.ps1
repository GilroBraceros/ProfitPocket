# The legacy -Utang switch remains a compatibility smoke check for Payments.
# The complete payment/form matrix is in browser_payments_test.ps1.
Invoke-Cdp 'Page.navigate' @{url='http://localhost/profitpocket/user/utang.php'}|Out-Null
Wait-ForBrowser 'document.readyState==="complete" && location.pathname.endsWith("/user/payments.php") && !!document.querySelector(".payments-directory") && !!window.bootstrap'
$paymentLayouts=@()
foreach($width in @(1440,1024,768,390,320)){
    Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{width=$width;height=900;deviceScaleFactor=1;mobile=($width -lt 768)}|Out-Null
    Start-Sleep -Milliseconds 150
    if(!(Invoke-BrowserScript 'document.documentElement.scrollWidth<=document.documentElement.clientWidth+1 && document.querySelectorAll(".utang-summary .summary-card").length===4')){throw "Payment overflow at $width"}
    $paymentLayouts+=@{width=$width}
    Save-BrowserScreenshot "payments-compatibility-$width.png"
}
$utangReport=@{module='Payment Management';legacyRedirect=$true;responsive=$paymentLayouts;financialWrites=0}
Invoke-Cdp 'Page.navigate' @{url='http://localhost/profitpocket/user/dashboard.php'}|Out-Null
Wait-ForBrowser 'document.readyState==="complete" && !!document.querySelector(".action-buttons") && !!window.bootstrap'
