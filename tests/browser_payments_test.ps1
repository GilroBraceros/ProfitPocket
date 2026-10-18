param([Parameter(Mandatory=$true)][string]$Username,[Parameter(Mandatory=$true)][string]$Password)
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
$visualServer=Start-Process -FilePath 'C:\xampp\php\php.exe' -ArgumentList @('-S','127.0.0.1:8027',(Join-Path $PSScriptRoot 'payment_visual_router.php')) -WorkingDirectory $projectDirectory -WindowStyle Hidden -PassThru

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
    $capture = Invoke-Cdp 'Page.captureScreenshot' @{ format = 'png'; captureBeyondViewport = !(Invoke-BrowserScript '!!document.querySelector(".modal.show")') }
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

function Navigate([string]$Path) {
    Invoke-Cdp 'Page.navigate' @{url="http://localhost/profitpocket/$Path"} | Out-Null
    Wait-ForBrowser 'document.readyState==="complete" && !!document.getElementById("dashboard-content") && !!window.bootstrap'
}
function Close-Payment {
    Wait-ForBrowser 'bootstrap.Modal.getInstance(document.getElementById("utang-action-modal"))._isTransitioning===false'
    Invoke-BrowserScript 'document.querySelector("#utang-action-modal [data-bs-dismiss]").click();true' | Out-Null
    Wait-ForBrowser '!document.querySelector("#utang-action-modal.show") && !document.querySelector(".modal-backdrop")'
}
try {
    for($attempt=0;$attempt -lt 40;$attempt++){try{$tabs=Invoke-RestMethod "http://127.0.0.1:$port/json/list";break}catch{Start-Sleep -Milliseconds 200}}
    $target=$tabs|Where-Object {$_.type -eq 'page'}|Select-Object -First 1
    $script:socket=[Net.WebSockets.ClientWebSocket]::new()
    $null=$script:socket.ConnectAsync([Uri]$target.webSocketDebuggerUrl,[Threading.CancellationToken]::None).GetAwaiter().GetResult()
    Invoke-Cdp 'Runtime.enable' | Out-Null
    Invoke-Cdp 'Page.enable' | Out-Null
    Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{width=1440;height=1000;deviceScaleFactor=1;mobile=$false} | Out-Null
    Invoke-Cdp 'Page.navigate' @{url='http://localhost/profitpocket/auth/login.php'} | Out-Null
    Wait-ForBrowser 'document.readyState==="complete" && !!document.getElementById("identifier")'
    $name=ConvertTo-Json -InputObject $Username -Compress;$secret=ConvertTo-Json -InputObject $Password -Compress
    Invoke-BrowserScript "document.getElementById('identifier').value=$name;document.getElementById('password').value=$secret;document.querySelector('form').requestSubmit();true" | Out-Null
    Wait-ForBrowser 'document.readyState==="complete" && location.pathname.endsWith("/user/dashboard.php") && !!window.bootstrap'
    $layouts=@();$forms=@()
    foreach($tab in @('history','balances')){
        Navigate "user/payments.php?tab=$tab"
        foreach($width in @(1440,1024,768,390,320)){
            Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{width=$width;height=900;deviceScaleFactor=1;mobile=($width -lt 768)} | Out-Null
            Start-Sleep -Milliseconds 150
            $info=Invoke-BrowserScript '({width:innerWidth,page:document.documentElement.scrollWidth,client:document.documentElement.clientWidth,cards:document.querySelectorAll(".utang-summary .summary-card").length,active:document.querySelector(".sidebar-link.active")?.textContent.trim(),error:!!document.querySelector("#dashboard-content>.alert-danger")})'
            if($info.page -gt $info.client+1 -or $info.cards -ne 4 -or $info.active -ne 'Payment Management' -or $info.error){
                $info|ConvertTo-Json -Compress|Write-Output
                Invoke-BrowserScript '[...document.querySelectorAll("#dashboard-content *")].filter(e=>e.getBoundingClientRect().right>document.documentElement.clientWidth+1).slice(0,12).map(e=>({tag:e.tagName,cls:e.className,right:e.getBoundingClientRect().right,width:getComputedStyle(e).width,min:getComputedStyle(e).minWidth}))'|ConvertTo-Json -Depth 4|Write-Output
                Save-BrowserScreenshot 'payments-overflow-failure.png'
                throw "Payment page layout failed: $tab $width"
            }
            $layouts+=@{tab=$tab;width=$width}
            Save-BrowserScreenshot "payments-$tab-$width.png"
        }
    }
    Invoke-BrowserScript 'document.querySelector("button[data-bs-toggle=offcanvas]").click();true' | Out-Null
    Wait-ForBrowser 'document.getElementById("business-sidebar").classList.contains("show")'
    Invoke-BrowserScript 'document.querySelector(".offcanvas-header .btn-close").click();true' | Out-Null
    Wait-ForBrowser '!document.getElementById("business-sidebar").classList.contains("show")'
    if(!(Invoke-BrowserScript '!!document.querySelector("[data-utang-modal=payment]")')){throw 'Read-only browser tests need an existing unpaid sale in the supplied account'}
    Assert-RecordActionsMenu '.payments-table .record-action-more'
    $saleUrl=Invoke-BrowserScript 'document.querySelector("[data-utang-modal=payment]").href'
    foreach($width in @(1440,768,320)){
        Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{width=$width;height=800;deviceScaleFactor=1;mobile=($width -lt 768)} | Out-Null
        $url=ConvertTo-Json -InputObject $saleUrl -Compress
        Invoke-BrowserScript "(()=>{const a=document.querySelector('[data-utang-modal=payment]');a.href=$url;a.click();return true;})()" | Out-Null
        Wait-ForBrowser '!!document.getElementById("amount") && !!document.querySelector("[data-payment-method-fields][data-initialized]")'
        Wait-ForBrowser 'bootstrap.Modal.getInstance(document.getElementById("utang-action-modal"))._isTransitioning===false'
        Invoke-BrowserScript 'document.getElementById("amount").value="1e2";document.getElementById("amount").dispatchEvent(new Event("input",{bubbles:true}));true'|Out-Null
        if(!(Invoke-BrowserScript '!document.getElementById("amount").checkValidity() && document.querySelector("[data-preview-payment]").textContent.includes("valid amount")')){throw 'Nondecimal frontend amount accepted'}
        Invoke-BrowserScript 'document.getElementById("amount").value=(Number(document.getElementById("amount").max)+1).toFixed(2);document.getElementById("amount").dispatchEvent(new Event("input",{bubbles:true}));true'|Out-Null
        if(!(Invoke-BrowserScript '!document.getElementById("amount").checkValidity() && document.querySelector("[data-preview-balance]").textContent.includes("Exceeds")')){throw 'Frontend overpayment accepted'}
        Invoke-BrowserScript 'document.getElementById("amount").value="0.01";document.getElementById("amount").dispatchEvent(new Event("input",{bubbles:true}));true'|Out-Null
        foreach($category in @('cash','wallet','bank')){
            $cat=ConvertTo-Json -InputObject $category -Compress
            Invoke-BrowserScript "document.getElementById('payment_category').value=$cat;document.getElementById('payment_category').dispatchEvent(new Event('change',{bubbles:true}));true" | Out-Null
            $state=Invoke-BrowserScript '({category:document.getElementById("payment_category").value,wallet:!document.querySelector("[data-wallet-field]").hidden,provider:!document.querySelector("[data-provider-field]").hidden,reference:!document.querySelector("[data-payment-reference]").hidden})'
            if(($category -eq 'cash' -and ($state.wallet -or $state.provider -or $state.reference)) -or ($category -eq 'wallet' -and (!$state.wallet -or $state.provider -or !$state.reference)) -or ($category -eq 'bank' -and ($state.wallet -or !$state.provider -or !$state.reference))){throw 'Payment method fields did not switch'}
            if($category -eq 'wallet'){
                Invoke-BrowserScript 'document.getElementById("wallet_method_id").value=document.querySelector("#wallet_method_id option[data-code=wallet_other]").value;document.getElementById("wallet_method_id").dispatchEvent(new Event("change",{bubbles:true}));true' | Out-Null
                if(!(Invoke-BrowserScript '!document.querySelector("[data-provider-field]").hidden && document.getElementById("provider_name").required')){throw 'Other wallet name not required'}
            }
            if(!(Invoke-BrowserScript '(()=>{const b=document.querySelector("#utang-action-modal .modal-body"),f=document.querySelector("#utang-action-modal .modal-footer").getBoundingClientRect();return document.documentElement.scrollWidth<=innerWidth&&b.scrollWidth<=b.clientWidth+1&&f.bottom<=innerHeight+1;})()')){throw "Payment form overflow $category $width"}
            $forms+=@{category=$category;width=$width}
            Save-BrowserScreenshot "payments-record-$category-$width.png"
        }
        Invoke-BrowserScript 'document.getElementById("amount").value="0.01";document.getElementById("amount").dispatchEvent(new Event("input",{bubbles:true}));document.querySelector("#utang-action-modal [data-bs-dismiss]").click();true' | Out-Null
        Wait-ForBrowser '!!document.querySelector(".utang-discard-prompt:not(.d-none)")'
        Invoke-BrowserScript 'document.querySelector("[data-discard-utang]").click();true' | Out-Null
        Wait-ForBrowser '!document.querySelector("#utang-action-modal.show") && !document.querySelector(".modal-backdrop")'
    }
    Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{width=1440;height=900;deviceScaleFactor=1;mobile=$false} | Out-Null
    Navigate 'user/payments.php'
    Write-Output 'PASS: live payment pages, method switching, previews and unsaved-change controls.'
    Invoke-Cdp 'Page.navigate' @{url='http://127.0.0.1:8027/history'}|Out-Null
    Wait-ForBrowser 'document.readyState==="complete" && !!document.querySelector("[data-utang-modal=payment_details]") && !!window.bootstrap'
    foreach($width in @(1440,1024,768,390,320)){
        Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{width=$width;height=900;deviceScaleFactor=1;mobile=($width -lt 768)}|Out-Null
        Start-Sleep -Milliseconds 150
        if(!(Invoke-BrowserScript 'document.documentElement.scrollWidth<=document.documentElement.clientWidth+1 && document.querySelectorAll(".payments-table tbody tr").length===6')){throw "Populated payment history layout failed $width"}
        Save-BrowserScreenshot "payments-history-populated-$width.png"
    }
    $fragment=ConvertTo-Json -InputObject ([IO.File]::ReadAllText((Join-Path $resultDirectory 'payments-details-fixture.html'),[Text.Encoding]::UTF8)) -Compress
    Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{width=1440;height=900;deviceScaleFactor=1;mobile=$false}|Out-Null
    Invoke-BrowserScript "document.querySelector('#utang-action-modal .modal-dialog').classList.remove('modal-lg');document.querySelector('#utang-action-modal .modal-content').innerHTML=$fragment;bootstrap.Modal.getOrCreateInstance(document.getElementById('utang-action-modal')).show();true"|Out-Null
    Wait-ForBrowser 'document.querySelector("#utang-action-modal .modal-title")?.textContent==="Payment details"'
    Wait-ForBrowser '!!document.querySelector("#utang-action-modal.show") && bootstrap.Modal.getInstance(document.getElementById("utang-action-modal"))._isTransitioning===false'
    if(!(Invoke-BrowserScript 'document.querySelector("#utang-action-modal").textContent.includes("Recorded by") && document.querySelector("#utang-action-modal").textContent.includes("Transaction reference")')){throw 'Payment details incomplete'}
    Save-BrowserScreenshot 'payments-details-1440.png'
    foreach($width in @(768,320)){
        Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{width=$width;height=900;deviceScaleFactor=1;mobile=($width -lt 768)}|Out-Null
        if(!(Invoke-BrowserScript '(()=>{const b=document.querySelector("#utang-action-modal .modal-body");return b.scrollWidth<=b.clientWidth+1&&document.documentElement.scrollWidth<=innerWidth;})()')){throw "Payment details overflow $width"}
        Save-BrowserScreenshot "payments-details-$width.png"
    }
    Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{width=1440;height=900;deviceScaleFactor=1;mobile=$false}|Out-Null
    $fragment=ConvertTo-Json -InputObject ([IO.File]::ReadAllText((Join-Path $resultDirectory 'payments-history-modal-fixture.html'),[Text.Encoding]::UTF8)) -Compress
    Invoke-BrowserScript "document.querySelector('#utang-action-modal .modal-dialog').classList.add('modal-lg');document.querySelector('#utang-action-modal .modal-content').innerHTML=$fragment;true"|Out-Null
    Wait-ForBrowser '!!document.querySelector("#utang-action-modal .utang-history")'
    if(!(Invoke-BrowserScript 'document.querySelectorAll(".modal-backdrop").length===1')){throw 'Nested payment history modal'}
    Save-BrowserScreenshot 'payments-history-modal-1440.png'
    Close-Payment
    Navigate 'user/utang.php?state=overdue'
    if(!(Invoke-BrowserScript 'location.pathname.endsWith("/user/payments.php") && location.search.includes("state=overdue")')){throw 'Old URL did not redirect safely'}
    Navigate 'user/sale_form.php'
    Invoke-BrowserScript 'document.querySelector("[data-field=product_id]").selectedIndex=1;document.querySelector("[data-field=product_id]").dispatchEvent(new Event("change",{bubbles:true}));true' | Out-Null
    foreach($width in @(1440,768,320)){
        Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{width=$width;height=900;deviceScaleFactor=1;mobile=($width -lt 768)} | Out-Null
        foreach($category in @('cash','wallet','bank')){
            $cat=ConvertTo-Json -InputObject $category -Compress
            Invoke-BrowserScript "document.getElementById('payment_category').value=$cat;document.getElementById('payment_category').dispatchEvent(new Event('change',{bubbles:true}));true" | Out-Null
            if(!(Invoke-BrowserScript 'document.documentElement.scrollWidth<=document.documentElement.clientWidth+1')){throw "Add Sale overflow $width"}
        }
        Save-BrowserScreenshot "payments-add-sale-$width.png"
    }
    foreach($module in @('sales','expenses','customers','products','reports')){
        Navigate "user/$module.php"
        if(!(Invoke-BrowserScript '!document.querySelector("#dashboard-content>.alert-danger")')){throw "Connected page error: $module"}
    }
    if($script:browserErrors.Count){throw ('JavaScript exceptions: '+($script:browserErrors -join ', '))}
    @{layouts=$layouts;paymentForms=$forms;detailsAndHistory=$true;legacyRedirect=$true;mobileMenu=$true;addSaleMethods=$true;javascriptErrors=0;financialWrites=0}|ConvertTo-Json -Depth 6|Set-Content (Join-Path $resultDirectory 'payments-browser-checks.json') -Encoding UTF8
    Write-Output 'PASS: 10 payment page layouts, 9 method/modal layouts, details/history, legacy redirects, mobile navigation and Add Sale controls; zero JavaScript exceptions or financial writes.'
} finally {
    if($visualServer -and !$visualServer.HasExited){Stop-Process -Id $visualServer.Id -ErrorAction SilentlyContinue}
    if($script:socket){$script:socket.Dispose()}
    if($target){try{Invoke-WebRequest "http://127.0.0.1:$port/json/close/$($target.id)" -UseBasicParsing -TimeoutSec 3|Out-Null}catch{}}
    if($browserProcess -and !$browserProcess.HasExited){Stop-Process -Id $browserProcess.Id -ErrorAction SilentlyContinue}
}
