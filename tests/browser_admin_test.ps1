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
    $modalVisible = Invoke-BrowserScript '!!document.querySelector(".modal.show")'
    $capture = Invoke-Cdp 'Page.captureScreenshot' @{ format = 'png'; captureBeyondViewport = !$modalVisible }
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

$fixture=$null
function Open-AdminModal([string]$Url) {
    $encoded=ConvertTo-Json -InputObject $Url -Compress
    Invoke-BrowserScript "(()=>{const a=document.createElement('a');a.href=$encoded;a.dataset.adminModal='';document.querySelector('.admin-main').append(a);a.click();return true;})()" | Out-Null
    Wait-ForBrowser '!!document.querySelector("#admin-action-modal.show .modal-title") && !document.querySelector("#admin-action-modal .modal-content").hasAttribute("aria-busy")'
    Wait-ForBrowser 'bootstrap.Modal.getInstance(document.getElementById("admin-action-modal"))._isTransitioning===false'
}
function Close-AdminModal {
    Invoke-BrowserScript 'document.querySelector("#admin-action-modal [data-bs-dismiss=modal]").click();true' | Out-Null
    Wait-ForBrowser '!document.querySelector("#admin-action-modal.show") && !document.querySelector(".modal-backdrop")'
}
function Save-AdminModal {
    Invoke-BrowserScript 'document.querySelector("#admin-action-modal form").requestSubmit();true' | Out-Null
    Wait-ForBrowser 'document.readyState==="complete" && !document.querySelector("#admin-action-modal.show") && !!document.querySelector(".admin-main")'
}
try {
    $fixture=& C:\xampp\php\php.exe (Join-Path $PSScriptRoot 'admin_browser_fixture.php') create | ConvertFrom-Json
    if($LASTEXITCODE -ne 0){throw 'Could not create isolated admin browser fixture'}
    for($attempt=0;$attempt -lt 40;$attempt++){
        try{$tabs=Invoke-RestMethod -Uri "http://127.0.0.1:$port/json/list";break}catch{Start-Sleep -Milliseconds 200}
    }
    $target=$tabs | Where-Object {$_.type -eq 'page'} | Select-Object -First 1
    $script:socket=[Net.WebSockets.ClientWebSocket]::new()
    $null=$script:socket.ConnectAsync([Uri]$target.webSocketDebuggerUrl,[Threading.CancellationToken]::None).GetAwaiter().GetResult()
    Invoke-Cdp 'Runtime.enable' | Out-Null
    Invoke-Cdp 'Page.enable' | Out-Null
    Invoke-Cdp 'Page.navigate' @{url='http://localhost/profitpocket/auth/login.php'} | Out-Null
    Wait-ForBrowser 'document.readyState==="complete" && !!document.getElementById("identifier")'
    $name=ConvertTo-Json -InputObject $fixture.name -Compress
    $password=ConvertTo-Json -InputObject $fixture.password -Compress
    Invoke-BrowserScript "document.getElementById('identifier').value=$name;document.getElementById('password').value=$password;document.querySelector('form').requestSubmit();true" | Out-Null
    Wait-ForBrowser 'document.readyState==="complete" && location.pathname.endsWith("/admin/dashboard.php") && !!document.querySelector("[data-admin-count]") && !!window.bootstrap'
    Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{width=1440;height=1000;deviceScaleFactor=1;mobile=$false} | Out-Null
    Invoke-BrowserScript 'document.getElementById("sidebar-collapse").click();true' | Out-Null
    if(!(Invoke-BrowserScript 'document.body.classList.contains("sidebar-collapsed") && Math.round(document.getElementById("business-sidebar").getBoundingClientRect().width)===76 && getComputedStyle(document.querySelector(".sidebar-logo-icon")).display!=="none"')){throw 'Administrator sidebar collapse failed'}
    Invoke-BrowserScript 'document.getElementById("sidebar-collapse").click();true' | Out-Null
    $layouts=[System.Collections.Generic.List[object]]::new()
    foreach($module in @('dashboard','users','businesses','tickets','feedback','announcements','logs','settings')){
        foreach($width in @(1440,1024,768,390,320)){
            Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{width=$width;height=1000;deviceScaleFactor=1;mobile=$false} | Out-Null
            Invoke-Cdp 'Page.navigate' @{url="http://localhost/profitpocket/admin/$module.php"} | Out-Null
            Wait-ForBrowser "document.readyState==='complete' && location.pathname.endsWith('/admin/$module.php') && !!document.querySelector('.admin-main') && !!window.bootstrap"
            $layout=Invoke-BrowserScript '({width:innerWidth,client:document.documentElement.clientWidth,page:document.documentElement.scrollWidth,active:!!document.querySelector(".sidebar-link.active"),errors:!!document.querySelector(".admin-main>.alert-danger"),logos:[...document.querySelectorAll(".sidebar-brand img")].every(i=>i.complete&&i.naturalWidth>0)})'
            if($layout.page -gt ($layout.client+1) -or !$layout.active -or $layout.errors -or !$layout.logos){throw "Admin layout failed: $module $width (page $($layout.page), client $($layout.client))"}
            $alignment=Invoke-BrowserScript '(()=>{const actions=[...document.querySelectorAll(".admin-table td:last-child .record-actions")];return {bodyFont:parseFloat(getComputedStyle(document.body).fontSize),aligned:actions.every(a=>{const b=a.getBoundingClientRect(),c=a.closest("td").getBoundingClientRect();return b.left>=c.left-1&&b.right<=c.right+1;}),columns:[...document.querySelectorAll(".admin-table:first-child col")].map(c=>({style:c.getAttribute("style"),width:c.getBoundingClientRect().width,display:getComputedStyle(c).display})),headers:[...document.querySelectorAll(".admin-table:first-child th")].map(c=>Math.round(c.getBoundingClientRect().width))};})()'
            if(!$alignment.aligned -or $alignment.bodyFont -lt 14){ $alignment | ConvertTo-Json -Depth 5 -Compress | Write-Output; throw "Unreadable text or overlapping table actions: $module $width" }
            if($width -eq 1440){$alignment | ConvertTo-Json -Depth 5 | Set-Content (Join-Path $resultDirectory "admin-$module-alignment.json") -Encoding UTF8}
            $layouts.Add(@{module=$module;width=$width;pageWidth=$layout.page;clientWidth=$layout.client})
            if($width -in @(1440,768,320)){Save-BrowserScreenshot "admin-$module-$width.png"}
        }
    }
    Invoke-BrowserScript 'document.querySelector("button[data-bs-toggle=offcanvas]").click();true' | Out-Null
    Wait-ForBrowser 'document.getElementById("business-sidebar").classList.contains("show")'
    Invoke-BrowserScript 'document.querySelector(".offcanvas-header .btn-close").click();true' | Out-Null
    Wait-ForBrowser '!document.getElementById("business-sidebar").classList.contains("show")'
    $formLayouts=[System.Collections.Generic.List[object]]::new()
    $formCases=@(
        @{action='ticket_update';id=$fixture.ticket},@{action='ticket_view';id=$fixture.ticket},
        @{action='user_view';id=$fixture.owner},@{action='business_view';id=$fixture.business},
        @{action='suspend';id=$fixture.owner},@{action='feedback_update';id=$fixture.feedback},
        @{action='announcement_create';id=0},@{action='profile';id=$fixture.admin},
        @{action='role';id=$fixture.admin},@{action='setting';id=0;extra='&setting_key=support_email'}
    )
    foreach($case in $formCases){
        foreach($width in @(1440,768,320)){
            Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{width=$width;height=1000;deviceScaleFactor=1;mobile=$false} | Out-Null
            Open-AdminModal "http://localhost/profitpocket/admin/action.php?action=$($case.action)&id=$($case.id)$($case.extra)"
            if(!(Invoke-BrowserScript '(()=>{const body=document.querySelector("#admin-action-modal .modal-body"),foot=document.querySelector("#admin-action-modal .modal-footer").getBoundingClientRect();return !document.querySelector("#admin-action-modal .admin-error:not(.d-none)") && document.documentElement.scrollWidth<=innerWidth && body.scrollWidth<=body.clientWidth+1 && foot.bottom<=innerHeight+1;})()')){throw "Admin form layout failed: $($case.action) $width"}
            $formLayouts.Add(@{action=$case.action;width=$width})
            Save-BrowserScreenshot "admin-$($case.action)-form-$width.png"
            Close-AdminModal
        }
    }
    Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{width=1440;height=1000;deviceScaleFactor=1;mobile=$false} | Out-Null
    Invoke-Cdp 'Page.navigate' @{url='http://localhost/profitpocket/admin/settings.php'} | Out-Null
    Wait-ForBrowser '!!document.querySelector("[data-admin-settings-nav]") && !!window.bootstrap'
    Invoke-BrowserScript 'document.querySelector("[data-admin-settings-nav] a[href=\"#role-settings\"]").click();true' | Out-Null
    Wait-ForBrowser 'document.querySelector("[data-admin-settings-nav] a[aria-current]").getAttribute("href")==="#role-settings"'
    Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{width=1440;height=1000;deviceScaleFactor=1;mobile=$false} | Out-Null
    Invoke-Cdp 'Page.navigate' @{url='http://localhost/profitpocket/admin/announcements.php?q=Browser+announcement'} | Out-Null
    Wait-ForBrowser 'document.readyState==="complete" && location.pathname.endsWith("/admin/announcements.php") && !!document.querySelector("[data-admin-modal]")'
    Invoke-BrowserScript 'document.querySelector(".dashboard-heading [data-admin-modal]").click();true' | Out-Null
    Wait-ForBrowser '!!document.getElementById("admin-title")'
    Invoke-BrowserScript 'document.getElementById("admin-title").value="Browser announcement <script>";document.getElementById("admin-body").value="Browser content <b>safe</b>";sessionStorage.setItem("admin_posts","0");const original=window.fetch;window.fetch=(url,options)=>{if(options?.method==="POST")sessionStorage.setItem("admin_posts",String(Number(sessionStorage.getItem("admin_posts"))+1));return original(url,options);};document.querySelector("#admin-action-modal form").requestSubmit();document.querySelector("#admin-action-modal form").requestSubmit();true' | Out-Null
    Wait-ForBrowser 'document.readyState==="complete" && !document.querySelector("#admin-action-modal.show") && document.querySelector(".admin-table")?.textContent.includes("Browser announcement")'
    if(!(Invoke-BrowserScript 'sessionStorage.getItem("admin_posts")==="1" && location.search.includes("q=Browser") && document.querySelectorAll(".admin-table tbody tr").length===1 && !document.querySelector(".admin-table script")')){throw 'Announcement duplicate/escaping/filter preservation failed'}
    Assert-RecordActionsMenu '.admin-table .record-action-more'
    $announcementId=Invoke-BrowserScript 'new URL(document.querySelector(".admin-table [data-admin-modal=details]").href).searchParams.get("id")'
    foreach($action in @('announcement_edit','publish','unpublish')){
        Open-AdminModal "http://localhost/profitpocket/admin/action.php?action=$action&id=$announcementId"
        if($action -eq 'announcement_edit'){Invoke-BrowserScript 'document.getElementById("admin-title").value="Browser announcement edited";true' | Out-Null}
        else{Invoke-BrowserScript 'document.querySelector("#admin-action-modal input[name=confirm]").checked=true;true' | Out-Null}
        Save-AdminModal
    }
    Open-AdminModal "http://localhost/profitpocket/admin/action.php?action=announcement_edit&id=$announcementId"
    Invoke-BrowserScript 'document.getElementById("admin-body").value="Unsaved";document.getElementById("admin-body").dispatchEvent(new Event("input",{bubbles:true}));true' | Out-Null
    $script:acceptDialogs=$false
    Invoke-BrowserScript 'document.querySelector("#admin-action-modal .btn-close").click();true' | Out-Null
    if(!(Invoke-BrowserScript '!!document.querySelector("#admin-action-modal.show")')){throw 'Dirty confirmation discarded without approval'}
    $script:acceptDialogs=$true
    Close-AdminModal
    Open-AdminModal "http://localhost/profitpocket/admin/action.php?action=ticket_update&id=$($fixture.ticket)"
    Invoke-BrowserScript 'document.getElementById("admin-message").value="Browser response <b>safe</b>";document.getElementById("admin-status").value="resolved";true' | Out-Null
    Save-AdminModal
    Open-AdminModal "http://localhost/profitpocket/admin/action.php?action=ticket_view&id=$($fixture.ticket)"
    if(!(Invoke-BrowserScript 'document.querySelector(".admin-message").textContent.includes("Browser response <b>safe</b>") && !document.querySelector(".admin-message b")')){throw 'Ticket response/history escaping failed'}
    Invoke-BrowserScript 'document.querySelector("#admin-action-modal [data-admin-modal]").click();true' | Out-Null
    Wait-ForBrowser '!!document.getElementById("admin-message") && !document.querySelector("#admin-action-modal .modal-content").hasAttribute("aria-busy")'
    if(!(Invoke-BrowserScript 'document.querySelectorAll(".modal-backdrop").length===1 && document.querySelector("#admin-action-modal").classList.contains("admin-ticket-modal")')){throw 'Ticket View to Reply modal navigation failed'}
    Close-AdminModal
    foreach($action in @('suspend','reactivate')){
        Open-AdminModal "http://localhost/profitpocket/admin/action.php?action=$action&id=$($fixture.owner)"
        Invoke-BrowserScript 'document.getElementById("admin-reason").value="Browser fixture status test";document.querySelector("#admin-action-modal input[name=confirm]").checked=true;true' | Out-Null
        Save-AdminModal
    }
    Open-AdminModal "http://localhost/profitpocket/admin/action.php?action=feedback_update&id=$($fixture.feedback)"
    Invoke-BrowserScript 'document.getElementById("admin-status").value="reviewed";true' | Out-Null
    Save-AdminModal
    Open-AdminModal "http://localhost/profitpocket/admin/action.php?action=profile&id=$($fixture.admin)"
    $currentPassword=ConvertTo-Json -InputObject $fixture.password -Compress
    Invoke-BrowserScript "document.getElementById('admin-full_name').value='Browser profile updated';document.getElementById('admin-current_password').value=$currentPassword;true" | Out-Null
    Invoke-BrowserScript 'document.querySelector("#admin-action-modal form").requestSubmit();true' | Out-Null
    Wait-ForBrowser 'location.pathname.endsWith("/auth/login.php") && !!document.getElementById("identifier")'
    Invoke-BrowserScript "document.getElementById('identifier').value=$name;document.getElementById('password').value=$password;document.querySelector('form').requestSubmit();true" | Out-Null
    Wait-ForBrowser 'location.pathname.endsWith("/admin/dashboard.php") && document.querySelector(".profile-name")?.textContent==="Browser profile updated"'
    if($script:browserErrors.Count){throw ('Admin JavaScript errors: '+($script:browserErrors -join ', '))}
    @{layouts=$layouts;formLayouts=$formLayouts;settingsNavigation=$true;profileSaveAndFreshLogin=$true;announcementCrud=$true;ticketReplyHistory=$true;accountStatus=$true;feedbackReview=$true;duplicateClickBlocked=$true;filtersPreserved=$true;escapedOutput=$true;dirtyGuard=$true;keyboardMenus=$true;mobileNavigation=$true;javascriptErrors=$script:browserErrors.Count} | ConvertTo-Json -Depth 6 | Set-Content (Join-Path $resultDirectory 'admin-browser-checks.json') -Encoding UTF8
    Write-Output ('PASS: '+$layouts.Count+' admin layouts, responsive forms, actual announcement/ticket/feedback/account actions, keyboard/dirty/duplicate controls and zero JavaScript errors.')
} finally {
    if($fixture){& C:\xampp\php\php.exe (Join-Path $PSScriptRoot 'admin_browser_fixture.php') cleanup $fixture.name;if($LASTEXITCODE -ne 0){throw 'Admin browser fixture cleanup needs attention'}}
    if($script:socket){$script:socket.Dispose()}
    if($target){try{Invoke-WebRequest -Uri "http://127.0.0.1:$port/json/close/$($target.id)" -UseBasicParsing | Out-Null}catch{}}
    if($browserProcess -and !$browserProcess.HasExited){Stop-Process -Id $browserProcess.Id -ErrorAction SilentlyContinue}
}
