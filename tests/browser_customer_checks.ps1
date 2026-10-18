# Real account checks are read-only. Customer mutations use a disposable business
# with no posted sales, payments, credits or expenses; fixture cleanup verifies it.
Invoke-BrowserScript 'document.querySelector(".action-buttons a[href*=customers]").click();true' | Out-Null
Wait-ForBrowser 'location.pathname.endsWith("/user/customers.php") && !!document.querySelector("#customer-action-modal form")'
Invoke-BrowserScript 'document.querySelector("#customer-action-modal .btn-close").click();true' | Out-Null
Wait-ForBrowser '!document.getElementById("customer-action-modal").classList.contains("show")'
Invoke-Cdp 'Page.navigate' @{url='http://localhost/profitpocket/user/customers.php'} | Out-Null
Wait-ForBrowser 'document.readyState==="complete" && !!document.querySelector(".customer-directory")'
$customerLayouts=@()
foreach ($width in @(1440,768,390,320)) {
    Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{width=$width;height=900;deviceScaleFactor=1;mobile=($width -lt 1000)} | Out-Null
    Start-Sleep -Milliseconds 150
    $layout=Invoke-BrowserScript '({page:"customers",viewport:innerWidth,pageWidth:document.documentElement.scrollWidth,cards:document.querySelectorAll(".customer-summary .summary-card").length})'
    if ($layout.pageWidth -gt $layout.viewport -or $layout.cards -ne 3) { throw "Customer page overflow or incorrect summary at $width pixels." }
    $customerLayouts+=$layout
    Save-BrowserScreenshot "customers-$width.png"
}
if (Invoke-BrowserScript '!!document.querySelector(".customer-actions .record-action-more")') {
    Assert-RecordActionsMenu '.customer-actions .record-action-more'
    Invoke-BrowserScript 'document.querySelector(".customer-actions .record-action-view").click();true' | Out-Null
    Wait-ForBrowser '!!document.querySelector(".customer-detail-section")'
    if (!(Invoke-BrowserScript '!document.querySelector("#customer-action-modal form") && document.querySelector("#customer-action-modal").textContent.includes("Purchase summary") && document.querySelector("#customer-action-modal").textContent.includes("Recent payments") && document.querySelector("#customer-action-modal .modal-body").scrollWidth<=document.querySelector("#customer-action-modal .modal-body").clientWidth')) { throw 'Customer details missing sections or overflowed.' }
    Save-BrowserScreenshot 'customer-details-320.png'
    Invoke-BrowserScript 'document.querySelector("#customer-action-modal .btn-close").click();true' | Out-Null
    Wait-ForBrowser '!document.getElementById("customer-action-modal").classList.contains("show")'
}
$fixtureOutput=& C:\xampp\php\php.exe (Join-Path $PSScriptRoot 'browser_sales_fixture.php') create
if ($LASTEXITCODE -ne 0) { throw 'Customer browser fixture creation failed.' }
$browserFixture=$fixtureOutput | ConvertFrom-Json
Invoke-BrowserScript 'document.querySelector(".account-menu form").requestSubmit();true' | Out-Null
Wait-ForBrowser 'location.pathname.endsWith("/auth/login.php") && !!document.getElementById("identifier")'
$customerFixtureUsername=ConvertTo-Json -InputObject $browserFixture.username -Compress
$customerFixturePassword=ConvertTo-Json -InputObject $browserFixture.password -Compress
Invoke-BrowserScript "document.getElementById('identifier').value=$customerFixtureUsername;document.getElementById('password').value=$customerFixturePassword;document.querySelector('form').requestSubmit();true" | Out-Null
Wait-ForBrowser 'location.pathname.endsWith("/user/dashboard.php") && document.readyState==="complete"'
Invoke-Cdp 'Page.navigate' @{url='http://localhost/profitpocket/user/customers.php?q=Browser&sort=recent'} | Out-Null
Wait-ForBrowser 'document.readyState==="complete" && !!document.querySelector(".customer-directory .empty-state")'
Invoke-BrowserScript @'
(() => {
    if(!document.querySelector('.account-menu').textContent.includes('modal_browser_')) throw new Error('Not a disposable customer account');
    const original=window.fetch;window.customerTestPosts=0;
    window.fetch=async(input,options={})=>{
        if(options.method==='POST') {
            if(!new URL(input,location.href).pathname.endsWith('/customer_action.php') || !(options.body instanceof FormData)) throw new Error('Blocked an unintended browser write');
            window.customerTestPosts++;
            await new Promise(resolve=>setTimeout(resolve,200));
        }
        return original(input,options);
    };
    document.querySelector('a[data-customer-modal=create]').click();return true;
})()
'@ | Out-Null
Wait-ForBrowser '!!document.querySelector("#customer-action-modal form")'
foreach ($width in @(1440,768,390,320)) {
    Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{width=$width;height=900;deviceScaleFactor=1;mobile=($width -lt 1000)} | Out-Null
    $layout=Invoke-BrowserScript '({page:"customer form",viewport:innerWidth,pageWidth:document.documentElement.scrollWidth,bodyWidth:document.querySelector("#customer-action-modal .modal-body").clientWidth,bodyScroll:document.querySelector("#customer-action-modal .modal-body").scrollWidth,footerBottom:document.querySelector("#customer-action-modal .modal-footer").getBoundingClientRect().bottom})'
    if ($layout.pageWidth -gt $layout.viewport -or $layout.bodyScroll -gt $layout.bodyWidth -or $layout.footerBottom -gt 901) { throw "Customer form overflow at $width pixels." }
    $customerLayouts+=$layout
    Save-BrowserScreenshot "customer-form-$width.png"
}
Invoke-BrowserScript @'
(() => {
    const form=document.querySelector('#customer-action-modal form');form.requestSubmit();
    if(!form.elements.customer_name.classList.contains('is-invalid') || window.customerTestPosts!==0) throw new Error('Missing name passed browser validation');
    const change=(name,value)=>{form.elements.namedItem(name).value=value;form.elements.namedItem(name).dispatchEvent(new Event('input',{bubbles:true}));};
    change('customer_name','Browser customer <b>');change('phone','invalid phone');change('email','browser@example.invalid');change('notes','Preserve browser notes');
    form.requestSubmit();return true;
})()
'@ | Out-Null
Wait-ForBrowser '!document.querySelector("#customer-action-modal form").hasAttribute("aria-busy") && !!document.querySelector("#phone.is-invalid")'
if (!(Invoke-BrowserScript 'document.getElementById("notes").value==="Preserve browser notes" && document.getElementById("customer_name").value==="Browser customer <b>"')) { throw 'Customer server error lost input.' }
Invoke-BrowserScript 'document.querySelector("#customer-action-modal .btn-close").click();true' | Out-Null
if (!(Invoke-BrowserScript '!document.querySelector(".customer-discard-prompt").classList.contains("d-none") && document.getElementById("customer-action-modal").classList.contains("show")')) { throw 'Customer unsaved changes prompt missing.' }
Invoke-BrowserScript 'document.querySelector("[data-keep-customer]").click();document.getElementById("phone").value="+63 917 555 0102";document.getElementById("phone").dispatchEvent(new Event("input",{bubbles:true}));window.customerTestUrl=location.href;document.querySelector("#customer-action-modal form").requestSubmit();document.querySelector("#customer-action-modal form").dispatchEvent(new Event("submit",{bubbles:true,cancelable:true}));true' | Out-Null
if (!(Invoke-BrowserScript 'document.querySelector("#customer-action-modal form").getAttribute("aria-busy")==="true"')) { throw 'Customer saving lock missing.' }
Wait-ForBrowser '!document.getElementById("customer-action-modal").classList.contains("show") && document.getElementById("customer-feedback").classList.contains("alert-success")'
if (!(Invoke-BrowserScript 'window.customerTestPosts===2 && location.href===window.customerTestUrl && document.getElementById("q").value==="Browser" && document.getElementById("sort").value==="recent" && document.querySelector(".customer-directory").textContent.includes("Browser customer <b>") && !document.querySelector(".customer-name b") && document.querySelector(".customer-summary .summary-amount").textContent==="1"')) { throw 'Customer save lost filters, count or escaping; duplicate submission occurred.' }
Invoke-BrowserScript @'
(async()=>{
    const id=new URL(document.querySelector('.customer-actions .record-action-view').href).searchParams.get('id');window.customerTestId=id;
    const response=await fetch('/profitpocket/user/sale_form.php?modal=1');const html=new DOMParser().parseFromString(await response.text(),'text/html');
    if(!html.querySelector('#customer_id option[value="'+id+'"]')) throw new Error('Customer missing from actual Sales form');
    return true;
})()
'@ | Out-Null
Assert-RecordActionsMenu '.customer-actions .record-action-more'
Invoke-BrowserScript 'document.querySelector(".customer-actions .record-action-more").click();document.querySelector("a[data-customer-modal=edit]").click();true' | Out-Null
Wait-ForBrowser '!!document.querySelector("#customer-action-modal #customer_name")'
Invoke-BrowserScript 'document.getElementById("customer_name").value="Browser customer edited";document.getElementById("customer_name").dispatchEvent(new Event("input",{bubbles:true}));document.querySelector("#customer-action-modal form").requestSubmit();true' | Out-Null
Wait-ForBrowser '!document.getElementById("customer-action-modal").classList.contains("show") && document.querySelector(".customer-name").textContent.includes("edited")'
Invoke-BrowserScript 'document.querySelector(".customer-actions .record-action-more").click();document.querySelector("a[data-customer-modal=archive]").click();true' | Out-Null
Wait-ForBrowser '!!document.querySelector("#customer-action-modal [name=confirm_customer_action]")'
if (!(Invoke-BrowserScript 'document.querySelectorAll(".modal.show").length===1 && document.querySelector("#customer-action-modal .modal-body").textContent.includes("history will be kept")')) { throw 'Archive confirmation missing or nested.' }
Save-BrowserScreenshot 'customer-archive-320.png'
Invoke-BrowserScript 'document.querySelector("#customer-action-modal form").requestSubmit();true' | Out-Null
Wait-ForBrowser '!document.getElementById("customer-action-modal").classList.contains("show") && !!document.querySelector(".customer-directory .empty-state")'
Invoke-Cdp 'Page.navigate' @{url='http://localhost/profitpocket/user/customers.php?state=archived&q=Browser'} | Out-Null
Wait-ForBrowser 'document.readyState==="complete" && !!document.querySelector("a[data-customer-modal=restore]")'
Invoke-BrowserScript 'document.querySelector(".customer-actions .record-action-more").click();document.querySelector("a[data-customer-modal=restore]").click();true' | Out-Null
Wait-ForBrowser '!!document.querySelector("#customer-action-modal [name=confirm_customer_action]")'
Invoke-BrowserScript 'document.querySelector("#customer-action-modal form").requestSubmit();true' | Out-Null
Wait-ForBrowser '!document.getElementById("customer-action-modal").classList.contains("show") && !!document.querySelector(".customer-directory .empty-state")'
Invoke-BrowserScript 'document.querySelector(".account-menu form").requestSubmit();true' | Out-Null
Wait-ForBrowser 'location.pathname.endsWith("/auth/login.php")'
& C:\xampp\php\php.exe (Join-Path $PSScriptRoot 'browser_sales_fixture.php') cleanup $browserFixture.username
if ($LASTEXITCODE -ne 0) { throw 'Customer browser fixture cleanup failed.' }
$browserFixture=$null
Invoke-BrowserScript "document.getElementById('identifier').value=$encodedUsername;document.getElementById('password').value=$encodedPassword;document.querySelector('form').requestSubmit();true" | Out-Null
Wait-ForBrowser 'location.pathname.endsWith("/user/dashboard.php") && document.readyState==="complete" && !!Chart.getChart("financial-chart")'
$customerReport=@{responsive=$customerLayouts;realCreateEditArchiveRestore=$true;emptyStates=$true;salesSelection=$true;errorInputRetention=$true;dirtyFormConfirmation=$true;duplicateSubmitProtection=$true;keyboardMenuAndFocus=$true;filtersPreserved=$true;fixturesCleaned=$true}
