# Original products are read-only. Real CRUD runs in a disposable business and
# catalog without posted sales, payments, customer credits or expenses.
Invoke-Cdp 'Page.navigate' @{url='http://localhost/profitpocket/user/products.php'} | Out-Null
Wait-ForBrowser 'document.readyState==="complete" && !!document.querySelector(".product-directory") && !!window.bootstrap'
$productLayouts=@()
foreach ($width in @(1440,1024,768,390,320)) {
    Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{width=$width;height=1000;deviceScaleFactor=1;mobile=($width -lt 1000)} | Out-Null
    Start-Sleep -Milliseconds 150
    $layout=Invoke-BrowserScript '({page:"products",viewport:innerWidth,pageWidth:document.documentElement.scrollWidth,cards:document.querySelectorAll(".product-summary .summary-card").length})'
    if ($layout.pageWidth -gt $layout.viewport -or $layout.cards -ne 2) { throw "Product page overflow or wrong cards at $width pixels." }
    $productLayouts+=$layout; Save-BrowserScreenshot "products-$width.png"
}
if (Invoke-BrowserScript '!!document.querySelector(".product-actions .record-action-more")') {
    Assert-RecordActionsMenu '.product-actions .record-action-more'
    Invoke-BrowserScript 'document.querySelector(".product-actions .record-action-view").click();true' | Out-Null
    Wait-ForBrowser '!!document.querySelector("#product-action-modal .customer-detail-section")'
    if (!(Invoke-BrowserScript '!document.querySelector("#product-action-modal form") && document.querySelector("#product-action-modal .modal-body").scrollWidth<=document.querySelector("#product-action-modal .modal-body").clientWidth+1')) { throw 'Product details are not read-only or overflow.' }
    Save-BrowserScreenshot 'product-details-320.png'
    Invoke-BrowserScript 'document.querySelector("#product-action-modal .btn-close").click();true' | Out-Null
    Wait-ForBrowser '!document.getElementById("product-action-modal").classList.contains("show")'
}
$fixtureOutput=& C:\xampp\php\php.exe (Join-Path $PSScriptRoot 'browser_sales_fixture.php') create
if ($LASTEXITCODE -ne 0) { throw 'Product browser fixture creation failed.' }
$browserFixture=$fixtureOutput | ConvertFrom-Json
Invoke-BrowserScript 'document.querySelector(".account-menu form").requestSubmit();true' | Out-Null
Wait-ForBrowser 'location.pathname.endsWith("/auth/login.php") && !!document.getElementById("identifier")'
$productFixtureUsername=ConvertTo-Json -InputObject $browserFixture.username -Compress
$productFixturePassword=ConvertTo-Json -InputObject $browserFixture.password -Compress
Invoke-BrowserScript "document.getElementById('identifier').value=$productFixtureUsername;document.getElementById('password').value=$productFixturePassword;document.querySelector('form').requestSubmit();true" | Out-Null
Wait-ForBrowser 'location.pathname.endsWith("/user/dashboard.php") && document.readyState==="complete"'
Invoke-Cdp 'Page.navigate' @{url='http://localhost/profitpocket/user/products.php?q=Catalog&sort=price_high'} | Out-Null
Wait-ForBrowser 'document.readyState==="complete" && !!document.querySelector(".product-directory .empty-state")'
Invoke-BrowserScript @'
(() => {
    if(!document.querySelector('.account-menu').textContent.includes('modal_browser_')) throw new Error('Not a disposable product account');
    const original=window.fetch;window.productTestPosts=0;window.productTestUrl=location.href;
    window.fetch=async(input,options={})=>{
        if(options.method==='POST') {
            if(!new URL(input,location.href).pathname.endsWith('/product_action.php') || !(options.body instanceof FormData)) throw new Error('Blocked unintended browser write');
            window.productTestPosts++;await new Promise(resolve=>setTimeout(resolve,200));
        }
        return original(input,options);
    };
    document.querySelector('a[data-product-modal=create]').click();return true;
})()
'@ | Out-Null
Wait-ForBrowser '!!document.querySelector("#product-action-modal #product_name")'
foreach ($width in @(1440,768,390,320)) {
    Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{width=$width;height=900;deviceScaleFactor=1;mobile=($width -lt 1000)} | Out-Null
    Start-Sleep -Milliseconds 150
    if (!(Invoke-BrowserScript '(()=>{const body=document.querySelector("#product-action-modal .modal-body"),footer=document.querySelector("#product-action-modal .modal-footer").getBoundingClientRect();return body.scrollWidth<=body.clientWidth+1 && footer.bottom<=innerHeight+1 && document.documentElement.scrollWidth<=innerWidth+1;})()')) { throw "Product form overflow/footer inaccessible at $width." }
    Save-BrowserScreenshot "product-form-$width.png"
}
Invoke-BrowserScript @'
(() => {
    const form=document.querySelector('#product-action-modal form');form.requestSubmit();
    if(!form.elements.product_name.classList.contains('is-invalid')) throw new Error('Empty product name passed');
    form.elements.product_name.value='Browser catalog <b>';form.elements.sku.value='BROWSER-PRODUCT';form.elements.description.value='<script>safe description</script>';
    form.elements.default_unit_cost.value='80.01';form.elements.default_selling_price.value='70.00';
    form.elements.default_selling_price.dispatchEvent(new Event('input',{bubbles:true}));
    if(document.querySelector('[data-product-profit]').textContent!=='\u2212\u20b110.01' || document.querySelector('.product-price-warning').classList.contains('d-none')) throw new Error('Negative profit warning is wrong');
    form.elements.default_selling_price.value='100.10';form.elements.default_selling_price.dispatchEvent(new Event('input',{bubbles:true}));
    if(document.querySelector('[data-product-profit]').textContent!=='\u20b120.09' || !document.querySelector('.product-price-warning').classList.contains('d-none')) throw new Error('Exact profit preview is wrong');
    document.querySelector('#product-action-modal .btn-close').click();return true;
})()
'@ | Out-Null
Wait-ForBrowser '!document.querySelector(".product-discard-prompt").classList.contains("d-none")'
Invoke-BrowserScript 'document.querySelector("[data-keep-product]").click();document.querySelector("#product-action-modal form").requestSubmit();document.querySelector("#product-action-modal form").requestSubmit();true' | Out-Null
Wait-ForBrowser '!document.getElementById("product-action-modal").classList.contains("show") && document.getElementById("product-feedback").classList.contains("alert-success")'
if (!(Invoke-BrowserScript 'window.productTestPosts===1 && location.href===window.productTestUrl && document.getElementById("q").value==="Catalog" && document.getElementById("sort").value==="price_high" && document.querySelector(".product-name").textContent.includes("Browser catalog <b>") && !document.querySelector(".product-name b")')) { throw 'Product save lost filters/escaping or duplicated.' }
Invoke-BrowserScript @'
(async()=>{
    window.managedProductId=new URL(document.querySelector('.product-actions .record-action-view').href).searchParams.get('id');
    const response=await fetch('/profitpocket/user/sale_form.php?modal=1'), html=new DOMParser().parseFromString(await response.text(),'text/html');
    const option=html.querySelector('[data-field=product_id] option[value="'+window.managedProductId+'"]');
    if(!option || option.dataset.price!=='100.10' || option.dataset.cost!=='80.01') throw new Error('Created product Sales defaults incorrect');
    return true;
})()
'@ | Out-Null
Assert-RecordActionsMenu '.product-actions .record-action-more'
Invoke-BrowserScript 'document.querySelector(".product-actions .record-action-more").click();document.querySelector(".record-actions-menu-floating [data-product-modal=edit]").click();true' | Out-Null
Wait-ForBrowser '!!document.querySelector("#product-action-modal #product_name")'
Invoke-BrowserScript 'document.getElementById("product_name").value="Browser catalog edited";document.getElementById("default_selling_price").value="100.12";document.getElementById("default_selling_price").dispatchEvent(new Event("input",{bubbles:true}));document.querySelector("#product-action-modal form").requestSubmit();true' | Out-Null
Wait-ForBrowser '!document.getElementById("product-action-modal").classList.contains("show") && document.querySelector(".product-name").textContent.includes("edited")'
$productSalesId=Invoke-BrowserScript 'window.managedProductId'
Invoke-Cdp 'Page.navigate' @{url='http://localhost/profitpocket/user/sales.php'} | Out-Null
Wait-ForBrowser 'document.readyState==="complete" && !!document.querySelector(".sales-history")'
Invoke-BrowserScript 'document.querySelector("[data-sale-modal=create]").click();true' | Out-Null
Wait-ForBrowser '!!document.querySelector("#sales-action-modal [data-field=product_id]")'
Invoke-BrowserScript "const picker=document.querySelector('#sales-action-modal [data-field=product_id]');picker.value='$productSalesId';picker.dispatchEvent(new Event('change',{bubbles:true}));true" | Out-Null
if (!(Invoke-BrowserScript 'document.querySelector("#sales-action-modal [data-field=unit_price]").value==="100.12" && document.querySelector("#sales-action-modal [data-unit-cost]").textContent==="\u20b180.01"')) { throw 'Actual Sales selection did not retrieve edited price and cost.' }
Invoke-BrowserScript 'document.querySelector("#sales-action-modal .btn-close").click();true' | Out-Null
Wait-ForBrowser '!document.getElementById("sales-action-modal").classList.contains("show")'
Invoke-Cdp 'Page.navigate' @{url='http://localhost/profitpocket/user/products.php?q=Catalog&sort=price_high'} | Out-Null
Wait-ForBrowser 'document.readyState==="complete" && !!document.querySelector(".product-actions")'
Invoke-BrowserScript 'document.querySelector(".product-actions .record-action-more").click();document.querySelector(".record-actions-menu-floating [data-product-modal=archive]").click();true' | Out-Null
Wait-ForBrowser '!!document.querySelector("#product-action-modal [name=confirm_product_action]")'
if (!(Invoke-BrowserScript 'document.querySelectorAll(".modal.show").length===1 && !document.querySelector("#product-action-modal .modal-dialog").classList.contains("modal-fullscreen-sm-down")')) { throw 'Archive confirmation is oversized or nested.' }
Save-BrowserScreenshot 'product-archive-320.png'
Invoke-BrowserScript 'document.querySelector("#product-action-modal form").requestSubmit();true' | Out-Null
Wait-ForBrowser '!document.getElementById("product-action-modal").classList.contains("show") && !!document.querySelector(".product-directory .empty-state")'
Invoke-BrowserScript "(async()=>{const r=await fetch('/profitpocket/user/sale_form.php?modal=1'),h=new DOMParser().parseFromString(await r.text(),'text/html');if([...h.querySelectorAll('[data-field=product_id] option')].some(o=>o.value==='$productSalesId'))throw new Error('Archived product still selectable');return true;})()" | Out-Null
Invoke-Cdp 'Page.navigate' @{url='http://localhost/profitpocket/user/products.php?state=archived&q=Catalog'} | Out-Null
Wait-ForBrowser 'document.readyState==="complete" && !!document.querySelector("[data-product-modal=restore]")'
Invoke-BrowserScript 'document.querySelector(".product-actions .record-action-more").click();document.querySelector(".record-actions-menu-floating [data-product-modal=restore]").click();true' | Out-Null
Wait-ForBrowser '!!document.querySelector("#product-action-modal [name=confirm_product_action]")'
Invoke-BrowserScript 'document.querySelector("#product-action-modal form").requestSubmit();true' | Out-Null
Wait-ForBrowser '!document.getElementById("product-action-modal").classList.contains("show") && !!document.querySelector(".product-directory .empty-state")'
Invoke-BrowserScript 'document.querySelector(".account-menu form").requestSubmit();true' | Out-Null
Wait-ForBrowser 'location.pathname.endsWith("/auth/login.php")'
& C:\xampp\php\php.exe (Join-Path $PSScriptRoot 'browser_sales_fixture.php') cleanup $browserFixture.username
if ($LASTEXITCODE -ne 0) { throw 'Product fixture cleanup failed.' }
$browserFixture=$null
Invoke-BrowserScript "document.getElementById('identifier').value=$encodedUsername;document.getElementById('password').value=$encodedPassword;document.querySelector('form').requestSubmit();true" | Out-Null
Wait-ForBrowser 'location.pathname.endsWith("/user/dashboard.php") && document.readyState==="complete" && !!Chart.getChart("financial-chart")'
$productReport=@{responsive=$productLayouts;realCreateEditArchiveRestore=$true;exactProfitPreview=$true;belowCostWarning=$true;filtersPreserved=$true;escapedOutput=$true;duplicateClickBlocked=$true;salesDefaultsAndAutomaticPrice=$true;archivedSalesExclusion=$true;keyboardActions=$true;fixturesCleaned=$true}
