param(
    [Parameter(Mandatory = $true)][string]$Username,
    [Parameter(Mandatory = $true)][string]$Password,
    [switch]$Sales,
    [switch]$Expenses,
    [switch]$Customers,
    [switch]$Utang,
    [switch]$Products,
    [switch]$Reports
)
$ErrorActionPreference = 'Stop'
$projectDirectory = Split-Path -Parent $PSScriptRoot
$resultDirectory = Join-Path $PSScriptRoot 'http-results'
New-Item -ItemType Directory -Path $resultDirectory -Force | Out-Null
$profileDirectory = Join-Path $resultDirectory ('browser-' + [Guid]::NewGuid().ToString('N'))
$browserPath = 'C:\Program Files\Google\Chrome\Application\chrome.exe'
if (!(Test-Path -LiteralPath $browserPath)) { throw 'Chrome is not installed at the expected location.' }
$port = 9227
$browserProcess = Start-Process -FilePath $browserPath -ArgumentList @('--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check', "--remote-debugging-port=$port", "--user-data-dir=$profileDirectory", 'about:blank') -WindowStyle Hidden -PassThru
$script:socket = $null
$script:commandId = 0
$script:browserErrors = [System.Collections.Generic.List[string]]::new()
$script:browserDialogs = [System.Collections.Generic.List[string]]::new()
$script:acceptDialogs = $true
$browserFixture = $null

function Invoke-Cdp([string]$Method, [hashtable]$Arguments = @{}) {
    $script:commandId++
    $requestId = $script:commandId
    $command = @{ id = $script:commandId; method = $Method; params = $Arguments } | ConvertTo-Json -Depth 20 -Compress
    $bytes = [Text.Encoding]::UTF8.GetBytes($command)
    $sendSegment = [ArraySegment[byte]]::new($bytes)
    $cancellation = [Threading.CancellationTokenSource]::new(10000)
    try {
        $script:socket.SendAsync($sendSegment, [Net.WebSockets.WebSocketMessageType]::Text, $true, $cancellation.Token).GetAwaiter().GetResult()
        while ($true) {
            $buffer = New-Object byte[] 65536
            $stream = [IO.MemoryStream]::new()
            do {
                $received = $script:socket.ReceiveAsync([ArraySegment[byte]]::new($buffer), $cancellation.Token).GetAwaiter().GetResult()
                if ($received.MessageType -eq [Net.WebSockets.WebSocketMessageType]::Close) { throw 'Browser connection closed.' }
                $stream.Write($buffer, 0, $received.Count)
            } while (!$received.EndOfMessage)
            $message = [Text.Encoding]::UTF8.GetString($stream.ToArray()) | ConvertFrom-Json
            $stream.Dispose()
            if ($message.method -eq 'Runtime.exceptionThrown') { $script:browserErrors.Add(($message.params.exceptionDetails | ConvertTo-Json -Depth 5 -Compress)) }
            if ($message.method -eq 'Page.javascriptDialogOpening') {
                $script:browserDialogs.Add($message.params.message)
                $script:commandId++
                $reply = @{ id = $script:commandId; method = 'Page.handleJavaScriptDialog'; params = @{ accept = $script:acceptDialogs } } | ConvertTo-Json -Compress -Depth 5
                $replyBytes = [Text.Encoding]::UTF8.GetBytes($reply)
                $script:socket.SendAsync([ArraySegment[byte]]::new($replyBytes), [Net.WebSockets.WebSocketMessageType]::Text, $true, $cancellation.Token).GetAwaiter().GetResult()
            }
            if ($message.id -eq $requestId) {
                if ($message.error) { throw ($message.error | ConvertTo-Json -Compress) }
                return $message.result
            }
        }
    } finally { $cancellation.Dispose() }
}

function Invoke-BrowserScript([string]$Expression) {
    $result = Invoke-Cdp 'Runtime.evaluate' @{ expression = $Expression; returnByValue = $true; awaitPromise = $true }
    if ($result.exceptionDetails) { throw ($result.exceptionDetails | ConvertTo-Json -Depth 5 -Compress) }
    return $result.result.value
}

function Wait-ForBrowser([string]$Expression) {
    for ($attempt = 0; $attempt -lt 40; $attempt++) {
        if (Invoke-BrowserScript $Expression) { return }
        Start-Sleep -Milliseconds 200
    }
    throw "Browser condition timed out: $Expression"
}

function Save-BrowserScreenshot([string]$Name) {
    $capture = Invoke-Cdp 'Page.captureScreenshot' @{ format = 'png'; captureBeyondViewport = $true }
    [IO.File]::WriteAllBytes((Join-Path $resultDirectory $Name), [Convert]::FromBase64String($capture.data))
}
function Assert-RecordActionsMenu([string]$Selector) {
    Invoke-BrowserScript "document.querySelector('$Selector').scrollIntoView({behavior:'instant',block:'nearest',inline:'nearest'});document.querySelector('$Selector').click();true" | Out-Null
    Wait-ForBrowser '!!document.querySelector(".record-actions-menu-floating.show")'
    Start-Sleep -Milliseconds 150
    if (!(Invoke-BrowserScript '(()=>{const menu=document.querySelector(".record-actions-menu-floating.show"),box=menu.getBoundingClientRect();return menu.parentElement===document.body && box.left>=0 && box.right<=innerWidth+1 && box.top>=0 && box.bottom<=innerHeight+1;})()')) {
        Invoke-BrowserScript '(()=>{const menu=document.querySelector(".record-actions-menu-floating.show"),box=menu.getBoundingClientRect();return {url:location.href,viewport:innerWidth,height:innerHeight,scrollY,placement:menu.dataset.popperPlacement,left:box.left,right:box.right,top:box.top,bottom:box.bottom,style:menu.getAttribute("style")};})()' | ConvertTo-Json -Compress | Write-Output
        Save-BrowserScreenshot 'action-menu-failure.png'
        throw 'Action menu is clipped or outside the viewport.'
    }
    Invoke-BrowserScript 'document.querySelector(".record-actions-menu-floating .dropdown-item").focus(); document.activeElement.dispatchEvent(new KeyboardEvent("keydown",{key:"End",bubbles:true}));true' | Out-Null
    if (!(Invoke-BrowserScript 'document.activeElement===document.querySelector(".record-actions-menu-floating li:last-child .dropdown-item")')) { throw 'Action menu keyboard navigation failed.' }
    Invoke-BrowserScript 'document.activeElement.dispatchEvent(new KeyboardEvent("keydown",{key:"Escape",bubbles:true}));true' | Out-Null
    if (!(Invoke-BrowserScript 'document.activeElement.matches(".record-action-more") && !document.querySelector(".record-actions-menu-floating")')) { throw 'Action menu Escape/focus restoration failed.' }
    Invoke-BrowserScript "document.querySelector('$Selector').click();document.querySelector('.record-actions-menu-floating .dropdown-item').focus();true" | Out-Null
    Invoke-Cdp 'Input.dispatchKeyEvent' @{type='keyDown';key='Tab';code='Tab';windowsVirtualKeyCode=9} | Out-Null
    Invoke-Cdp 'Input.dispatchKeyEvent' @{type='keyUp';key='Tab';code='Tab';windowsVirtualKeyCode=9} | Out-Null
    if (!(Invoke-BrowserScript '!document.querySelector(".record-actions-menu-floating") && !document.activeElement.closest(".dropdown-menu")')) { throw 'Action menu Tab kept focus in a hidden menu.' }
}

try {
    for ($attempt = 0; $attempt -lt 40; $attempt++) {
        try { $tabs = Invoke-RestMethod -Uri "http://127.0.0.1:$port/json/list"; break } catch { Start-Sleep -Milliseconds 200 }
    }
    $target = $tabs | Where-Object { $_.type -eq 'page' } | Select-Object -First 1
    if (!$target) { throw 'No headless browser tab was found.' }
    $script:socket = [Net.WebSockets.ClientWebSocket]::new()
    $null = $script:socket.ConnectAsync([Uri]$target.webSocketDebuggerUrl, [Threading.CancellationToken]::None).GetAwaiter().GetResult()
    Invoke-Cdp 'Runtime.enable' | Out-Null
    Invoke-Cdp 'Page.enable' | Out-Null
    Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{ width = 1440; height = 1100; deviceScaleFactor = 1; mobile = $false } | Out-Null
    Invoke-Cdp 'Page.navigate' @{ url = 'http://localhost/profitpocket/auth/login.php' } | Out-Null
    Wait-ForBrowser "document.readyState === 'complete' && !!document.getElementById('identifier')"
    $encodedUsername = ConvertTo-Json -InputObject $Username -Compress
    $encodedPassword = ConvertTo-Json -InputObject $Password -Compress
    Invoke-BrowserScript "document.getElementById('identifier').value=$encodedUsername; document.getElementById('password').value=$encodedPassword; document.querySelector('form').requestSubmit(); true" | Out-Null
    Wait-ForBrowser "location.pathname.endsWith('/user/dashboard.php') && document.readyState === 'complete' && !!window.Chart && !!window.bootstrap && !!Chart.getChart('financial-chart')"

    $desktop = Invoke-BrowserScript @'
(() => {
    const images = [...document.images].filter(image => image.getBoundingClientRect().width > 0);
    const sidebar = document.getElementById('business-sidebar');
    return {
        viewport: innerWidth, pageWidth: document.documentElement.scrollWidth,
        sidebarWidth: Math.round(sidebar.getBoundingClientRect().width),
        gridColumns: getComputedStyle(document.querySelector('.summary-grid')).gridTemplateColumns,
        chartWidth: Chart.getChart('financial-chart').width,
        imagesLoaded: images.every(image => image.complete && image.naturalWidth > 0),
        activeNavigation: !!document.querySelector('.sidebar-link.active[aria-current="page"]'),
        quickActionsDisabled: [...document.querySelectorAll('.action-buttons button')].every(button => button.disabled),
        noInlineScripts: !document.querySelector('script:not([src])')
    };
})()
'@
    if (!$desktop.imagesLoaded -or !$desktop.activeNavigation -or !$desktop.quickActionsDisabled -or $desktop.pageWidth -gt $desktop.viewport) { throw 'Desktop layout or assets failed checks.' }
    Save-BrowserScreenshot 'dashboard-desktop.png'
    Invoke-BrowserScript "document.getElementById('sidebar-collapse').click(); true" | Out-Null
    $collapsed = Invoke-BrowserScript "document.body.classList.contains('sidebar-collapsed') && getComputedStyle(document.querySelector('.sidebar-logo-icon')).display !== 'none' && Math.round(document.getElementById('business-sidebar').getBoundingClientRect().width) === 76"
    if (!$collapsed) { throw 'Desktop sidebar collapse failed.' }
    Save-BrowserScreenshot 'dashboard-collapsed.png'
    Invoke-BrowserScript "document.getElementById('sidebar-collapse').click(); document.getElementById('period').value='custom'; document.getElementById('period').dispatchEvent(new Event('change')); true" | Out-Null
    if (!(Invoke-BrowserScript "!document.getElementById('custom-dates').hidden && document.getElementById('start').required")) { throw 'Custom date controls failed.' }
    Invoke-BrowserScript "document.getElementById('period').value='month'; document.getElementById('period').dispatchEvent(new Event('change')); document.querySelector('.profile-button').click(); true" | Out-Null
    if (!(Invoke-BrowserScript "document.querySelector('.account-menu').classList.contains('show')")) { throw 'Account dropdown failed.' }
    Invoke-BrowserScript "document.querySelector('.profile-button').click(); true" | Out-Null

    $mobileResults = @()
    foreach ($width in @(768, 390, 320)) {
        Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{ width = $width; height = 900; deviceScaleFactor = 1; mobile = $true } | Out-Null
        Start-Sleep -Milliseconds 250
        $layout = Invoke-BrowserScript "({viewport:innerWidth,pageWidth:document.documentElement.scrollWidth,chartWidth:Chart.getChart('financial-chart').width,gridColumns:getComputedStyle(document.querySelector('.summary-grid')).gridTemplateColumns})"
        if ($layout.pageWidth -gt $layout.viewport -or $layout.chartWidth -gt $layout.viewport) { throw "Page or chart overflow at $width pixels." }
        $mobileResults += $layout
        Save-BrowserScreenshot "dashboard-$width.png"
    }
    Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{ width = 390; height = 900; deviceScaleFactor = 1; mobile = $true } | Out-Null
    Invoke-BrowserScript 'document.querySelector("button[data-bs-toggle=offcanvas]").click(); true' | Out-Null
    Wait-ForBrowser "document.getElementById('business-sidebar').classList.contains('show')"
    Save-BrowserScreenshot 'dashboard-mobile-menu.png'
    Invoke-BrowserScript 'document.querySelector(".offcanvas-header .btn-close").click(); true' | Out-Null
    Wait-ForBrowser "!document.getElementById('business-sidebar').classList.contains('show')"
    $utangReport = $null
    $productReport = $null
    $financialReport = $null
    if ($Reports) { . (Join-Path $PSScriptRoot 'browser_report_checks.ps1') }
    if ($Products) { . (Join-Path $PSScriptRoot 'browser_product_checks.ps1') }
    if ($Utang) { . (Join-Path $PSScriptRoot 'browser_utang_checks.ps1') }
    $customerReport = $null
    if ($Customers) { . (Join-Path $PSScriptRoot 'browser_customer_checks.ps1') }
    $expenseReport = $null
    if ($Expenses) { . (Join-Path $PSScriptRoot 'browser_expenses_checks.ps1') }
    $salesReport = $null
    if ($Sales) {
        Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{ width = 1440; height = 1100; deviceScaleFactor = 1; mobile = $false } | Out-Null
        Invoke-Cdp 'Page.navigate' @{ url = 'http://localhost/profitpocket/user/sales.php' } | Out-Null
        Wait-ForBrowser "location.pathname.endsWith('/user/sales.php') && document.readyState === 'complete' && !!document.querySelector('.sales-history')"
        $saleDetailsUrl = Invoke-BrowserScript "document.querySelector('.transaction-reference a')?.href || ''"
        if (!(Invoke-BrowserScript "document.querySelector('.sidebar-link.active').href.endsWith('/user/sales.php') && document.documentElement.scrollWidth <= innerWidth")) { throw 'Sales navigation/layout failed.' }
        Save-BrowserScreenshot 'sales-desktop.png'
        $salesLayouts = @()
        foreach ($width in @(768, 390, 320)) {
            Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{ width = $width; height = 900; deviceScaleFactor = 1; mobile = $true } | Out-Null
            Start-Sleep -Milliseconds 200
            $layout = Invoke-BrowserScript '({page:"sales",viewport:innerWidth,pageWidth:document.documentElement.scrollWidth,tableScroll:document.querySelector(".table-responsive")?.scrollWidth})'
            if ($layout.pageWidth -gt $layout.viewport) { throw "Sales page overflow at $width pixels." }
            $salesLayouts += $layout
            Save-BrowserScreenshot "sales-$width.png"
        }
        Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{ width = 1440; height = 1100; deviceScaleFactor = 1; mobile = $false } | Out-Null
        if (Invoke-BrowserScript '!!document.querySelector(".sale-actions .record-action-more")') { Assert-RecordActionsMenu '.sale-actions .record-action-more' }
        Invoke-BrowserScript 'document.querySelector("a[data-sale-modal=create]").click(); true' | Out-Null
        Wait-ForBrowser "location.pathname.endsWith('/user/sales.php') && document.getElementById('sales-action-modal').classList.contains('show') && !!document.getElementById('sale-entry-form') && !!document.querySelector('#sale-entry-form[data-initialized]')"
        $calculation = Invoke-BrowserScript @'
(() => {
    const change = (field, value) => { field.value=value; field.dispatchEvent(new Event('change',{bubbles:true})); field.dispatchEvent(new Event('input',{bubbles:true})); };
    const row = document.querySelector('.sale-item-row');
    const product = row.querySelector('[data-field=product_id]');
    const selected = [...product.options].find(option => option.value);
    if (!selected) throw new Error('No real products available for browser check');
    change(product,selected.value);
    change(row.querySelector('[data-field=quantity]'),'1.234');
    change(row.querySelector('[data-field=unit_price]'),'10.01');
    if (document.getElementById('add-sale-item').disabled) throw new Error('Add item disabled with products available');
    document.getElementById('add-sale-item').click();
    const second=document.querySelectorAll('.sale-item-row')[1];
    change(second.querySelector('[data-field=product_id]'),selected.value);
    change(second.querySelector('[data-field=quantity]'),'0.100');
    change(second.querySelector('[data-field=unit_price]'),'0.05');
    change(document.getElementById('discount_type'),'percent');
    change(document.getElementById('discount_value'),'12.50');
    change(document.getElementById('payment_category'),'cash');
    const total=document.getElementById('preview-total').textContent;
    const subtotal=document.getElementById('preview-subtotal').textContent;
    if (!total.endsWith('10.82') || !subtotal.endsWith('12.36') || document.getElementById('initial_payment').value!=='10.82') throw new Error('Live decimal calculation/full payment failed');
    change(document.getElementById('payment_plan'),'partial');
    change(document.getElementById('initial_payment'),'5');
    const customer=[...document.getElementById('customer_id').options].find(option=>option.value);
    if (!customer) throw new Error('No real customer available for credit check');
    change(document.getElementById('customer_id'),customer.value);
    if (!document.getElementById('preview-balance').textContent.endsWith('5.82') || !document.getElementById('customer_id').required) throw new Error('Partial balance/customer validation failed');
    change(document.getElementById('discount_type'),'fixed'); change(document.getElementById('discount_value'),'1.00');
    change(document.getElementById('payment_plan'),'unpaid');
    if (!document.getElementById('preview-total').textContent.endsWith('11.36') || document.getElementById('initial_payment').value!=='0.00') throw new Error('Fixed discount/unpaid failed');
    change(document.getElementById('save_mode'),'draft');
    if (document.getElementById('initial_payment').value!=='0.00' || document.getElementById('save-sale').textContent!=='Save Sale') throw new Error('Draft form behavior failed');
    change(document.getElementById('save_mode'),'posted'); change(document.getElementById('payment_plan'),'full');
    return {subtotal,total,partialBalance:'5.82',fixedTotal:'11.36',items:document.querySelectorAll('.sale-item-row').length,formValid:document.getElementById('sale-entry-form').checkValidity()};
})()
'@
        if (!$calculation.formValid -or $calculation.items -ne 2) { throw 'Complete multiple-item form invalid.' }
        Save-BrowserScreenshot 'sale-form-desktop.png'
        foreach ($width in @(768, 390, 320)) {
            Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{ width = $width; height = 900; deviceScaleFactor = 1; mobile = $true } | Out-Null
            Start-Sleep -Milliseconds 200
            $layout = Invoke-BrowserScript '({page:"sale modal",viewport:innerWidth,pageWidth:document.documentElement.scrollWidth,bodyWidth:document.querySelector("#sales-action-modal .modal-body").clientWidth,bodyScrollWidth:document.querySelector("#sales-action-modal .modal-body").scrollWidth,footerBottom:document.querySelector("#sales-action-modal .modal-footer").getBoundingClientRect().bottom,bodyScrollable:document.querySelector("#sales-action-modal .modal-body").scrollHeight>document.querySelector("#sales-action-modal .modal-body").clientHeight})'
            if ($layout.pageWidth -gt $layout.viewport -or $layout.bodyScrollWidth -gt $layout.bodyWidth -or $layout.footerBottom -gt 901 -or !$layout.bodyScrollable) { throw "Sale modal overflow or inaccessible footer at $width pixels." }
            $salesLayouts += $layout
            Save-BrowserScreenshot "sale-form-$width.png"
        }
        if (!(Invoke-BrowserScript "document.querySelectorAll('.sale-item-row')[1].querySelector('[data-remove-item]').click(); document.querySelectorAll('.sale-item-row').length===1 && document.querySelector('[data-remove-item]').disabled")) { throw 'Remove item/reindex failed.' }
        $script:acceptDialogs = $false
        Invoke-BrowserScript 'document.querySelector("#sales-action-modal .btn-close").click(); true' | Out-Null
        if (!(Invoke-BrowserScript 'document.getElementById("sales-action-modal").classList.contains("show") && document.querySelectorAll(".sale-item-row").length===1')) { throw 'Rejecting discard did not retain form.' }
        if ($script:browserDialogs.Count -ne 1) { throw 'Unsaved changes confirmation did not appear.' }
        $nativeValidation = Invoke-BrowserScript @'
(() => {
    const quantity=document.querySelector('[data-field=quantity]');
    quantity.value='0';quantity.dispatchEvent(new Event('input',{bubbles:true}));
    document.getElementById('sale-entry-form').requestSubmit();
    const passed=quantity.classList.contains('is-invalid') && !!quantity.getAttribute('aria-describedby');
    quantity.value='1.234';quantity.dispatchEvent(new Event('input',{bubbles:true}));
    document.querySelector('#sale-entry-form [name=csrf_token]').value='forged';
    document.getElementById('notes').value='kept modal notes';
    document.getElementById('notes').dispatchEvent(new Event('input',{bubbles:true}));
    // Block every browser write unless it is explicitly the rejected-CSRF test.
    // This guard protects real financial data even if a DOM selector regresses.
    const originalFetch=window.fetch;
    window.fetch=(input,options={})=>{
        if (options.method==='POST' && (!(options.body instanceof FormData) || options.body.get('csrf_token')!=='forged')) return Promise.reject(new Error('Browser test blocked an unintended write'));
        return originalFetch(input,options);
    };
    const key=document.querySelector('#sale-entry-form [name=request_key]').value;
    window.modalTestSaveKey=key;
    document.getElementById('sale-entry-form').requestSubmit();
    document.getElementById('sale-entry-form').dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));
    return {adjacentValidation:passed,saveDisabled:document.getElementById('save-sale').disabled,loading:document.getElementById('save-sale').textContent.includes('Saving')};
})()
'@
        if (!$nativeValidation.adjacentValidation -or !$nativeValidation.saveDisabled -or !$nativeValidation.loading) { throw 'Field validation or loading/duplicate-submit protection failed.' }
        Wait-ForBrowser "!document.getElementById('save-sale').disabled && !document.querySelector('.sale-submit-error').classList.contains('d-none')"
        if (!(Invoke-BrowserScript 'document.getElementById("notes").value==="kept modal notes" && document.querySelector("[name=request_key]").value===window.modalTestSaveKey && document.getElementById("sales-action-modal").classList.contains("show")')) { throw 'Server error discarded entered values or idempotency key.' }
        Save-BrowserScreenshot 'sale-modal-validation.png'
        $script:acceptDialogs = $true
        Invoke-BrowserScript 'document.querySelector("#sales-action-modal .btn-close").click(); true' | Out-Null
        Wait-ForBrowser '!document.getElementById("sales-action-modal").classList.contains("show")'
        if ($saleDetailsUrl) {
            Invoke-BrowserScript 'document.querySelector("a[data-sale-modal=details]").click(); true' | Out-Null
            Wait-ForBrowser "document.getElementById('sales-action-modal').classList.contains('show') && !!document.querySelector('.sale-details-body')"
            if (!(Invoke-BrowserScript 'document.documentElement.scrollWidth <= innerWidth')) { throw 'Sale details overflow.' }
            if (!(Invoke-BrowserScript '!document.querySelector("#sales-action-modal form") && document.querySelector("#sales-action-modal .modal-body").scrollWidth<=document.querySelector("#sales-action-modal .modal-body").clientWidth')) { throw 'Details modal was not read-only or overflowed.' }
            Save-BrowserScreenshot 'sale-details-320.png'
            Invoke-BrowserScript 'document.querySelector("#sales-action-modal .btn-close").click(); document.querySelector("a[data-sale-modal=edit]").click(); true' | Out-Null
            Wait-ForBrowser '!!document.getElementById("sale-notes-form")'
            if (!(Invoke-BrowserScript 'document.getElementById("sales-modal-title").textContent.includes("notes") && !document.querySelector("#sales-action-modal [name=initial_payment]")')) { throw 'Posted edit exposed financial fields.' }
            Save-BrowserScreenshot 'sale-edit-notes-modal.png'
            Invoke-BrowserScript 'document.querySelector("#sales-action-modal .btn-close").click(); document.querySelector("a[data-sale-modal=void]").click(); true' | Out-Null
            Wait-ForBrowser '!!document.querySelector("#sales-action-modal [name=void_reason]")'
            if (!(Invoke-BrowserScript 'document.querySelectorAll(".modal.show").length===1 && document.querySelector("#sales-action-modal .modal-footer").textContent.includes("Confirm Void")')) { throw 'Void confirmation missing or nested.' }
            Save-BrowserScreenshot 'sale-void-confirmation.png'
            Invoke-BrowserScript 'document.querySelector("#sales-action-modal .btn-close").click(); true' | Out-Null
            Wait-ForBrowser '!document.getElementById("sales-action-modal").classList.contains("show")'
            if (!(Invoke-BrowserScript 'document.activeElement.matches(".record-action-more")')) { throw 'Focus did not return to opening action control.' }
        }
        $salesReport = @{ calculations = $calculation; responsive = $salesLayouts; multipleItems = $true; removeItem = $true; voidConfirmation = !!$saleDetailsUrl; financialSubmissions = 0; serverErrorPreservesInput = $true; unsavedChangesGuard = $true; adjacentValidation = $true; loadingAndDuplicateProtection = $true; readonlyDetailsAndPostedEdits = $true; focusRestored = $true }
        # Verify successful asynchronous saving in a disposable business. Only
        # immediate drafts without payments/credits may be submitted or removed.
        $fixtureOutput = & C:\xampp\php\php.exe (Join-Path $PSScriptRoot 'browser_sales_fixture.php') create
        if ($LASTEXITCODE -ne 0) { throw 'Browser fixture creation failed.' }
        $browserFixture = $fixtureOutput | ConvertFrom-Json
        Invoke-BrowserScript "document.querySelector('.account-menu form').requestSubmit(); true" | Out-Null
        Wait-ForBrowser "location.pathname.endsWith('/auth/login.php') && !!document.getElementById('identifier')"
        $fixtureUsername = ConvertTo-Json -InputObject $browserFixture.username -Compress
        $fixturePassword = ConvertTo-Json -InputObject $browserFixture.password -Compress
        Invoke-BrowserScript "document.getElementById('identifier').value=$fixtureUsername; document.getElementById('password').value=$fixturePassword; document.querySelector('form').requestSubmit(); true" | Out-Null
        Wait-ForBrowser "location.pathname.endsWith('/user/dashboard.php') && document.readyState === 'complete'"
        Invoke-Cdp 'Page.navigate' @{ url = 'http://localhost/profitpocket/user/sales.php?state=draft&period=today&q=SL-' } | Out-Null
        Wait-ForBrowser "location.pathname.endsWith('/user/sales.php') && document.readyState === 'complete'"
        Invoke-BrowserScript 'document.querySelector("a[data-sale-modal=create]").click(); true' | Out-Null
        Wait-ForBrowser '!!document.querySelector("#sale-entry-form[data-initialized]")'
        Invoke-BrowserScript @'
(() => {
    const change=(field,value)=>{field.value=value;field.dispatchEvent(new Event('change',{bubbles:true}));field.dispatchEvent(new Event('input',{bubbles:true}));};
    const product=document.querySelector('[data-field=product_id]');
    change(product,[...product.options].find(option=>option.value).value);
    change(document.querySelector('[data-field=quantity]'),'2');
    change(document.getElementById('discount_type'),'fixed');change(document.getElementById('discount_value'),'1');
    change(document.getElementById('save_mode'),'draft');
    const original=window.fetch;
    window.fetch=(input,options={})=>{
        if(options.method==='POST') {
            const data=options.body;
            if(!(data instanceof FormData) || data.get('save_mode')!=='draft' || data.get('payment_plan')!=='full' || data.get('initial_payment')!=='0.00' || data.get('customer_id')!=='' || data.get('sale_id')!=='') return Promise.reject(new Error('Browser test blocked a financial write'));
        }
        return original(input,options);
    };
    window.salesTestUrl=location.href;window.salesTestScroll=window.scrollY;
    document.getElementById('sale-entry-form').requestSubmit();
    return true;
})()
'@ | Out-Null
        Wait-ForBrowser '!document.getElementById("sales-action-modal").classList.contains("show") && document.getElementById("sales-feedback").classList.contains("alert-success")'
        if (!(Invoke-BrowserScript 'location.href===window.salesTestUrl && window.scrollY===window.salesTestScroll && document.querySelector(".sales-history").textContent.includes("250.00") && document.getElementById("state").value==="draft" && document.getElementById("q").value==="SL-"')) { throw 'Successful modal save lost list/filter/scroll state.' }
        Invoke-BrowserScript 'document.getElementById("period").value="custom";document.getElementById("period").dispatchEvent(new Event("change")); true' | Out-Null
        if (!(Invoke-BrowserScript '!document.getElementById("custom-dates").hidden && document.getElementById("start").required')) { throw 'Refreshed date filter lost behavior.' }
        $salesReport.realDraftSaveAndListRefresh = $true
        $salesReport.filtersAndScrollPreserved = $true
    }
    if ($script:browserErrors.Count -gt 0) { throw ('Browser JavaScript errors: ' + ($script:browserErrors -join ', ')) }
    $report = @{ desktop = $desktop; mobile = $mobileResults; sidebarCollapse = $true; mobileMenu = $true; customDateControls = $true; accountDropdown = $true; javascriptErrors = $script:browserErrors.Count }
    if ($salesReport) { $report.sales = $salesReport }
    if ($expenseReport) { $report.expenses = $expenseReport }
    if ($customerReport) { $report.customers = $customerReport }
    if ($utangReport) { $report.utang = $utangReport }
    if ($productReport) { $report.products = $productReport }
    if ($financialReport) { $report.reports = $financialReport }
    $report | ConvertTo-Json -Depth 8 | Set-Content -LiteralPath (Join-Path $resultDirectory 'browser-checks.json') -Encoding UTF8
    $report | ConvertTo-Json -Depth 8
    Invoke-BrowserScript "document.querySelector('.account-menu form').requestSubmit(); true" | Out-Null
    Wait-ForBrowser "location.pathname.endsWith('/auth/login.php')"
} finally {
    if ($browserFixture) {
        & C:\xampp\php\php.exe (Join-Path $PSScriptRoot 'browser_sales_fixture.php') cleanup $browserFixture.username
        if ($LASTEXITCODE -ne 0) { Write-Warning ('Browser fixture cleanup needs attention: ' + $browserFixture.username) }
    }
    if ($script:socket) { $script:socket.Dispose() }
    if ($target) {
        try { Invoke-WebRequest -Uri "http://127.0.0.1:$port/json/close/$($target.id)" -UseBasicParsing | Out-Null } catch { }
    }
    if ($browserProcess -and !$browserProcess.HasExited) { Stop-Process -Id $browserProcess.Id -ErrorAction SilentlyContinue }
}
