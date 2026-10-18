# Run from browser_dashboard_test.ps1 after login. Actual financial writes are
# tested in rollback transactions; this browser suite blocks every valid POST.
Invoke-BrowserScript 'document.querySelector(".action-buttons a[href*=expenses]").click(); true' | Out-Null
Wait-ForBrowser 'location.pathname.endsWith("/user/expenses.php") && !!document.querySelector("#expense-action-modal form")'
if (!(Invoke-BrowserScript 'document.getElementById("expense-action-modal").classList.contains("show")')) { throw 'Dashboard Add Expense shortcut failed.' }
Invoke-BrowserScript 'document.querySelector("#expense-action-modal .btn-close").click(); true' | Out-Null
Wait-ForBrowser '!document.getElementById("expense-action-modal").classList.contains("show")'
Invoke-Cdp 'Page.navigate' @{ url = 'http://localhost/profitpocket/user/expenses.php?q=PP-no-matching-expense-6fd132' } | Out-Null
Wait-ForBrowser 'document.readyState==="complete" && !!document.querySelector(".expense-history .empty-state")'
if (!(Invoke-BrowserScript 'document.querySelector(".expense-summary .summary-amount").textContent.endsWith("0.00") && document.querySelector(".expense-top-category").textContent==="No expenses yet"')) { throw 'Expense empty state/selected totals failed.' }
Invoke-Cdp 'Page.navigate' @{ url = 'http://localhost/profitpocket/user/expenses.php' } | Out-Null
Wait-ForBrowser 'document.readyState==="complete" && !!document.querySelector(".expense-history") && !!window.bootstrap'
Invoke-BrowserScript @'
(() => {
    const original=window.fetch;
    window.expenseWriteCount=0;
    window.fetch=async(input,options={})=>{
        if(options.method==='POST' && new URL(input,location.href).pathname.endsWith('/expense_action.php')) {
            if(!(options.body instanceof FormData) || options.body.get('csrf_token')!=='forged') throw new Error('Browser test blocked an unintended expense write');
            window.expenseWriteCount++;
            await new Promise(resolve=>setTimeout(resolve,250));
        }
        return original(input,options);
    };
    return true;
})()
'@ | Out-Null
$expenseLayouts = @()
foreach ($width in @(1440, 768, 390, 320)) {
    Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{ width = $width; height = 900; deviceScaleFactor = 1; mobile = ($width -lt 1000) } | Out-Null
    Start-Sleep -Milliseconds 200
    $layout = Invoke-BrowserScript '({page:"expenses",viewport:innerWidth,pageWidth:document.documentElement.scrollWidth,summaryCards:document.querySelectorAll(".expense-summary .summary-card").length,active:document.querySelector(".sidebar-link.active").href.endsWith("/user/expenses.php")})'
    if ($layout.pageWidth -gt $layout.viewport -or $layout.summaryCards -ne 3 -or !$layout.active) { throw "Expense layout/navigation failed at $width pixels." }
    $expenseLayouts += $layout
    Save-BrowserScreenshot "expenses-$width.png"
}
if (Invoke-BrowserScript '!!document.querySelector(".expense-actions .dropdown-toggle")') {
    Assert-RecordActionsMenu '.expense-actions .record-action-more'
    Invoke-BrowserScript 'document.querySelector(".expense-actions .dropdown-toggle").click(); true' | Out-Null
    if (!(Invoke-BrowserScript '!!document.querySelector(".record-actions-menu-floating.show")')) { throw 'Expense dropdown inaccessible on mobile.' }
    Save-BrowserScreenshot 'expenses-mobile-actions.png'
    Invoke-BrowserScript 'document.querySelector(".expense-actions .dropdown-toggle").click(); true' | Out-Null
}
Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{ width = 1440; height = 1100; deviceScaleFactor = 1; mobile = $false } | Out-Null
Invoke-BrowserScript 'document.querySelector("a[data-expense-modal=create]").click(); true' | Out-Null
Wait-ForBrowser '!!document.querySelector("#expense-action-modal form")'
Save-BrowserScreenshot 'expense-form-desktop.png'
foreach ($width in @(768, 390, 320)) {
    Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{ width = $width; height = 900; deviceScaleFactor = 1; mobile = $true } | Out-Null
    Start-Sleep -Milliseconds 200
    $layout = Invoke-BrowserScript '({page:"expense modal",viewport:innerWidth,pageWidth:document.documentElement.scrollWidth,bodyWidth:document.querySelector("#expense-action-modal .modal-body").clientWidth,bodyScrollWidth:document.querySelector("#expense-action-modal .modal-body").scrollWidth,footerBottom:document.querySelector("#expense-action-modal .modal-footer").getBoundingClientRect().bottom})'
    if ($layout.pageWidth -gt $layout.viewport -or $layout.bodyScrollWidth -gt $layout.bodyWidth -or $layout.footerBottom -gt 901) { throw "Expense modal overflow/footer failed at $width pixels." }
    $expenseLayouts += $layout
    Save-BrowserScreenshot "expense-form-$width.png"
}
$expenseValidation = Invoke-BrowserScript @'
(() => {
    const form=document.querySelector('#expense-action-modal form');
    const change=(name,value)=>{const field=form.elements.namedItem(name);field.value=value;field.dispatchEvent(new Event('input',{bubbles:true}));field.dispatchEvent(new Event('change',{bubbles:true}));};
    for(const name of ['expense_category_id','payment_method_id']) change(name,[...form.elements.namedItem(name).options].find(option=>option.value).value);
    change('description','Browser form check');change('amount','-1');change('notes','Keep entered expense notes');
    form.requestSubmit();
    const adjacent=form.elements.namedItem('amount').classList.contains('is-invalid') && !!form.elements.namedItem('amount').getAttribute('aria-describedby');
    change('amount','125.50');
    const file=form.elements.namedItem('receipt');
    const transfer=new DataTransfer();transfer.items.add(new File([new Uint8Array(2097153)],'too-large.png',{type:'image/png'}));file.files=transfer.files;file.dispatchEvent(new Event('change',{bubbles:true}));
    form.requestSubmit();
    const invalidReceipt=file.classList.contains('is-invalid') && window.expenseWriteCount===0;
    file.value='';file.dispatchEvent(new Event('change',{bubbles:true}));
    form.querySelector('[name=csrf_token]').value='forged';
    window.expenseTestKey=form.querySelector('[name=request_key]').value;
    form.requestSubmit();form.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));
    return {adjacent,invalidReceipt,loading:form.getAttribute('aria-busy')==='true',disabled:form.querySelector('button[type=submit]').disabled};
})()
'@
if (!$expenseValidation.adjacent -or !$expenseValidation.invalidReceipt -or !$expenseValidation.loading -or !$expenseValidation.disabled) { throw 'Expense adjacent validation/upload limit/loading failed.' }
Wait-ForBrowser '!document.querySelector("#expense-action-modal form").hasAttribute("aria-busy") && !document.querySelector("#expense-action-modal .expense-submit-error").classList.contains("d-none")'
if (!(Invoke-BrowserScript 'window.expenseWriteCount===1 && document.getElementById("notes").value==="Keep entered expense notes" && document.getElementById("amount").value==="125.50" && document.querySelector("#expense-action-modal [name=request_key]").value===window.expenseTestKey')) { throw 'Expense failed save lost input/key or submitted twice.' }
$script:acceptDialogs = $false
Invoke-BrowserScript 'document.querySelector("#expense-action-modal .btn-close").click(); true' | Out-Null
if (!(Invoke-BrowserScript 'document.getElementById("expense-action-modal").classList.contains("show") && document.getElementById("notes").value==="Keep entered expense notes"')) { throw 'Expense discard guard lost form.' }
$script:acceptDialogs = $true
Invoke-BrowserScript 'document.querySelector("#expense-action-modal .btn-close").click(); true' | Out-Null
Wait-ForBrowser '!document.getElementById("expense-action-modal").classList.contains("show")'
if (!(Invoke-BrowserScript 'document.activeElement.matches("a[data-expense-modal=create]")')) { throw 'Expense opener focus not restored.' }
foreach ($action in @('details','edit','void','category','schedule')) {
    if (!(Invoke-BrowserScript "!!document.querySelector('a[data-expense-modal=$action]')")) { continue }
    if ($action -eq 'schedule') { Invoke-BrowserScript 'document.querySelector(".expense-schedules").open=true; true' | Out-Null }
    Invoke-BrowserScript "document.querySelector('a[data-expense-modal=$action]').click(); true" | Out-Null
    Wait-ForBrowser '!!document.querySelector("#expense-action-modal #expense-modal-title") && document.querySelector("#expense-action-modal #expense-modal-title").textContent!=="Loading expense"'
    if ($action -eq 'details' -and !(Invoke-BrowserScript '!document.querySelector("#expense-action-modal form") && !!document.querySelector(".expense-detail-amount")')) { throw 'Expense details are not read-only.' }
    if ($action -eq 'edit' -and !(Invoke-BrowserScript '!document.querySelector("#expense-action-modal input[name=amount]") && !!document.getElementById("description").value && !!document.getElementById("notes")')) { throw 'Expense edit lost fields or permits immutable financial changes.' }
    if ($action -eq 'void') {
        if (!(Invoke-BrowserScript 'document.querySelector("#expense-action-modal .modal-dialog").getBoundingClientRect().width<=500 && document.getElementById("void_reason").required')) { throw 'Expense void confirmation is not compact/reason required.' }
        Invoke-BrowserScript 'document.querySelector("#expense-action-modal form").requestSubmit(); true' | Out-Null
        if (!(Invoke-BrowserScript 'document.getElementById("void_reason").classList.contains("is-invalid") && window.expenseWriteCount===1')) { throw 'Empty void reason submitted.' }
    }
    Save-BrowserScreenshot "expense-$action-mobile.png"
    Invoke-BrowserScript 'document.querySelector("#expense-action-modal .btn-close").click(); true' | Out-Null
    Wait-ForBrowser '!document.getElementById("expense-action-modal").classList.contains("show")'
}
$expenseReport = @{ responsive=$expenseLayouts; dashboardShortcut=$true; emptyState=$true; nativeAndReceiptValidation=$true; serverErrorPreservesInput=$true; duplicateSubmitBlocked=$true; unsavedChangesGuard=$true; focusRestored=$true; readOnlyFinancialFields=$true; expensePosts='one rejected CSRF request; no financial writes' }
$script:browserDialogs.Clear()
