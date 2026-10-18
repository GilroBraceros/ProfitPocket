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
function Shot([string]$Name) {
    $capture=Invoke-Cdp 'Page.captureScreenshot' @{format='png';captureBeyondViewport=$false}
    [IO.File]::WriteAllBytes((Join-Path $resultDirectory $Name),[Convert]::FromBase64String($capture.data))
}
function Navigate([string]$Path) {
    $encoded=ConvertTo-Json -InputObject $Path -Compress
    Invoke-Cdp 'Page.navigate' @{url="http://localhost/profitpocket/$Path"} | Out-Null
    Wait-ForBrowser "document.readyState==='complete' && location.href.endsWith($encoded) && !!window.bootstrap"
}
function Login([string]$Name,[string]$Destination) {
    Navigate 'auth/login.php'
    $n=ConvertTo-Json -InputObject $Name -Compress
    $p=ConvertTo-Json -InputObject $fixture.password -Compress
    Invoke-BrowserScript "document.getElementById('identifier').value=$n;document.getElementById('password').value=$p;document.querySelector('form').requestSubmit();true" | Out-Null
    Wait-ForBrowser "document.readyState==='complete' && location.pathname.endsWith('/$Destination') && !!window.bootstrap"
}
function Logout {
    Invoke-BrowserScript 'document.querySelector(".account-menu form").requestSubmit();true' | Out-Null
    Wait-ForBrowser 'document.readyState==="complete" && !!document.getElementById("identifier")'
}
function Open-Modal([string]$Kind,[string]$Url) {
    $encoded=ConvertTo-Json -InputObject $Url -Compress
    Invoke-BrowserScript "(()=>{const a=document.createElement('a');a.href=$encoded;a.setAttribute('data-$Kind-modal','');document.querySelector('main').append(a);a.click();return true;})()" | Out-Null
    $modal=if($Kind -eq 'admin'){'admin-action-modal'}else{'support-modal'}
    Wait-ForBrowser "!!document.querySelector('#$modal.show .modal-title') && !document.querySelector('#$modal .modal-content').hasAttribute('aria-busy') && !bootstrap.Modal.getInstance(document.getElementById('$modal'))._isTransitioning"
}
function Close-Modal([string]$Kind) {
    $modal=if($Kind -eq 'admin'){'admin-action-modal'}else{'support-modal'}
    Invoke-BrowserScript "document.querySelector('#$modal .btn-close').click();true" | Out-Null
    Wait-ForBrowser "!document.querySelector('#$modal.show') && !document.querySelector('.modal-backdrop')"
}
function Save-Modal([string]$Kind) {
    $modal=if($Kind -eq 'admin'){'admin-action-modal'}else{'support-modal'}
    Invoke-BrowserScript "(()=>{const f=document.querySelector('#$modal form'),c=f.querySelector('input[name=confirm]');if(c)c.checked=true;f.requestSubmit();return true;})()" | Out-Null
    Wait-ForBrowser "document.readyState==='complete' && !document.querySelector('#$modal.show') && !!document.querySelector('main')"
}
$fixture=$null
try {
    $fixture=& C:\xampp\php\php.exe (Join-Path $PSScriptRoot 'admin_browser_fixture.php') create | ConvertFrom-Json
    if($LASTEXITCODE -ne 0){throw 'Fixture creation failed'}
    for($attempt=0;$attempt -lt 40;$attempt++){try{$tabs=Invoke-RestMethod "http://127.0.0.1:$port/json/list";break}catch{Start-Sleep -Milliseconds 200}}
    $target=$tabs | Where-Object {$_.type -eq 'page'} | Select-Object -First 1
    $script:socket=[Net.WebSockets.ClientWebSocket]::new()
    $null=$script:socket.ConnectAsync([Uri]$target.webSocketDebuggerUrl,[Threading.CancellationToken]::None).GetAwaiter().GetResult()
    Invoke-Cdp 'Runtime.enable' | Out-Null
    Invoke-Cdp 'Page.enable' | Out-Null
    Login $fixture.name 'admin/dashboard.php'
    Navigate 'admin/announcements.php?q=Integrated+browser'
    Open-Modal 'admin' 'http://localhost/profitpocket/admin/action.php?action=announcement_create'
    Invoke-BrowserScript 'document.getElementById("admin-title").value="Integrated browser announcement";document.getElementById("admin-body").value="Important notice <b>plain text</b>";true' | Out-Null
    Save-Modal 'admin'
    $announcement=Invoke-BrowserScript 'new URL(document.querySelector(".admin-table [data-admin-modal=details]").href).searchParams.get("id")'
    Open-Modal 'admin' "http://localhost/profitpocket/admin/action.php?action=publish&id=$announcement"
    Save-Modal 'admin'
    Logout
    Login $fixture.ownerName 'user/dashboard.php'
    $layouts=[System.Collections.Generic.List[object]]::new()
    foreach($path in @('user/dashboard.php','user/support.php','user/support.php?tab=feedback')){
        foreach($width in @(1440,1024,768,390,320)){
            Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{width=$width;height=1000;deviceScaleFactor=1;mobile=$false} | Out-Null
            Navigate $path
            $layout=Invoke-BrowserScript '({page:document.documentElement.scrollWidth,client:document.documentElement.clientWidth,active:!!document.querySelector(".sidebar-link.active"),logos:[...document.querySelectorAll(".sidebar-brand img")].every(i=>i.complete&&i.naturalWidth>0)})'
            if($layout.page -gt $layout.client+1 -or !$layout.active -or !$layout.logos){throw "Owner layout failed $path $width"}
            $layouts.Add(@{path=$path;width=$width;pageWidth=$layout.page;clientWidth=$layout.client})
            if($width -in @(1440,320)){Shot ("integration-"+($path -replace '[/?=.]','-')+"-$width.png")}
        }
    }
    Navigate 'user/dashboard.php'
    if(!(Invoke-BrowserScript 'document.querySelector(".owner-announcements").textContent.includes("Integrated browser announcement")')){throw 'Published announcement missing'}
    Open-Modal 'support' "http://localhost/profitpocket/user/support_action.php?action=announcement&id=$announcement"
    if(!(Invoke-BrowserScript 'document.querySelector("#support-modal .support-text").textContent.includes("<b>plain text</b>") && !document.querySelector("#support-modal .support-text b")')){throw 'Announcement escaping failed'}
    Shot 'owner-announcement-320.png'
    Close-Modal 'support'
    Navigate 'user/support.php'
    foreach($action in @('new_ticket','feedback')){
        foreach($width in @(1440,768,320)){
            Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{width=$width;height=1000;deviceScaleFactor=1;mobile=$false} | Out-Null
            Open-Modal 'support' "http://localhost/profitpocket/user/support_action.php?action=$action"
            if(!(Invoke-BrowserScript '(()=>{const b=document.querySelector("#support-modal .modal-body"),f=document.querySelector("#support-modal .modal-footer").getBoundingClientRect();return document.documentElement.scrollWidth<=innerWidth && b.scrollWidth<=b.clientWidth+1 && f.bottom<=innerHeight+1;})()')){throw "Owner modal overflow $action $width"}
            Shot "owner-$action-$width.png"
            Close-Modal 'support'
        }
    }
    Open-Modal 'support' 'http://localhost/profitpocket/user/support_action.php?action=new_ticket'
    Invoke-BrowserScript 'document.getElementById("support-subject").value="   ";document.getElementById("support-description").value="Integrated concern <b>safe</b>";document.querySelector("#support-modal form").requestSubmit();true' | Out-Null
    Wait-ForBrowser '!!document.querySelector("#support-modal .alert-danger:not(.d-none)") && !document.querySelector("#support-modal button[type=submit]").disabled'
    if(!(Invoke-BrowserScript 'document.getElementById("support-description").value==="Integrated concern <b>safe</b>"')){throw 'Validation lost entered text'}
    Invoke-BrowserScript 'document.getElementById("support-subject").value="Integrated browser ticket";sessionStorage.setItem("support_posts","0");const original=window.fetch;window.fetch=(url,options)=>{if(options?.method==="POST")sessionStorage.setItem("support_posts",String(Number(sessionStorage.getItem("support_posts"))+1));return original(url,options);};document.querySelector("#support-modal form").requestSubmit();document.querySelector("#support-modal form").requestSubmit();true' | Out-Null
    Wait-ForBrowser 'document.readyState==="complete" && !document.querySelector("#support-modal.show") && document.querySelector(".support-records")?.textContent.includes("Integrated browser ticket")'
    if(!(Invoke-BrowserScript 'sessionStorage.getItem("support_posts")==="1" && [...document.querySelectorAll(".support-record h2")].filter(e=>e.textContent==="Integrated browser ticket").length===1 && !!document.querySelector(".alert-success")')){throw 'Duplicate save/flash failed'}
    $ticket=Invoke-BrowserScript 'new URL(document.querySelector(".support-record [data-support-modal]").href).searchParams.get("id")'
    Navigate 'user/support.php?tab=feedback'
    Open-Modal 'support' 'http://localhost/profitpocket/user/support_action.php?action=feedback'
    Invoke-BrowserScript 'document.getElementById("support-message").value="Integrated browser feedback";document.getElementById("support-category").value="bug";true' | Out-Null
    Save-Modal 'support'
    Logout
    Login $fixture.name 'admin/dashboard.php'
    Navigate 'admin/tickets.php?q=Integrated+browser+ticket'
    if(!(Invoke-BrowserScript 'document.querySelector(".admin-table").textContent.includes("Temporary browser owner")')){throw 'Admin ticket submitter mismatch'}
    Open-Modal 'admin' "http://localhost/profitpocket/admin/action.php?action=ticket_update&id=$ticket"
    Invoke-BrowserScript 'document.getElementById("admin-message").value="Integrated reply <b>safe</b>";document.getElementById("admin-status").value="resolved";true' | Out-Null
    Save-Modal 'admin'
    Navigate 'admin/feedback.php?q=Integrated+browser+feedback'
    $feedback=Invoke-BrowserScript 'new URL(document.querySelector(".admin-table [data-admin-modal=details]").href).searchParams.get("id")'
    Open-Modal 'admin' "http://localhost/profitpocket/admin/action.php?action=feedback_update&id=$feedback"
    Invoke-BrowserScript 'document.getElementById("admin-status").value="reviewed";true' | Out-Null
    Save-Modal 'admin'
    Logout
    Login $fixture.ownerName 'user/dashboard.php'
    Navigate 'user/support.php?tab=feedback'
    if(!(Invoke-BrowserScript '[...document.querySelectorAll(".support-record")].some(e=>e.textContent.includes("Integrated browser feedback")&&e.textContent.includes("Reviewed"))')){throw 'Owner review status missing'}
    Navigate 'user/support.php'
    Open-Modal 'support' "http://localhost/profitpocket/user/support_action.php?action=ticket&id=$ticket"
    if(!(Invoke-BrowserScript 'document.querySelector("#support-modal").textContent.includes("Integrated reply <b>safe</b>") && document.querySelector("#support-modal").textContent.includes("Resolved") && !document.querySelector("#support-modal .admin-message b")')){throw 'Owner reply/status/history missing'}
    Invoke-BrowserScript 'document.querySelector("#support-modal [data-support-modal]").click();true' | Out-Null
    Wait-ForBrowser '!!document.getElementById("support-message")'
    if(!(Invoke-BrowserScript 'document.querySelectorAll(".modal-backdrop").length===1')){throw 'Nested follow-up backdrop'}
    Invoke-BrowserScript 'document.getElementById("support-message").value="Integrated follow-up";true' | Out-Null
    Save-Modal 'support'
    Open-Modal 'support' "http://localhost/profitpocket/user/support_action.php?action=ticket&id=$ticket"
    if(!(Invoke-BrowserScript 'document.querySelector("#support-modal").textContent.includes("Integrated follow-up") && document.querySelector("#support-modal .payment-badge").textContent==="Open"')){throw 'Follow-up/reopen missing'}
    Close-Modal 'support'
    Logout
    Login $fixture.name 'admin/dashboard.php'
    Open-Modal 'admin' "http://localhost/profitpocket/admin/action.php?action=unpublish&id=$announcement"
    Save-Modal 'admin'
    Logout
    Login $fixture.ownerName 'user/dashboard.php'
    if(!(Invoke-BrowserScript '!document.querySelector(".owner-announcements").textContent.includes("Integrated browser announcement")')){throw 'Unpublished announcement still visible'}
    if($script:browserErrors.Count){throw ('Browser JS errors: '+($script:browserErrors -join ', '))}
    @{layouts=$layouts;responsiveForms=6;announcementPublishReadUnpublish=$true;ticketConversationReopen=$true;feedbackReview=$true;validationRetained=$true;duplicateBlocked=$true;javascriptErrors=0} | ConvertTo-Json -Depth 6 | Set-Content (Join-Path $resultDirectory 'integration-browser-checks.json') -Encoding UTF8
    Write-Output 'PASS: 15 owner layouts, six responsive forms, actual announcement/support/feedback integration, validation and duplicate controls, zero JavaScript errors.'
} finally {
    if($fixture){& C:\xampp\php\php.exe (Join-Path $PSScriptRoot 'admin_browser_fixture.php') cleanup $fixture.name;if($LASTEXITCODE -ne 0){throw 'Integration browser fixture cleanup needs attention'}}
    if($script:socket){$script:socket.Dispose()}
    if($target){try{Invoke-WebRequest "http://127.0.0.1:$port/json/close/$($target.id)" -UseBasicParsing | Out-Null}catch{}}
    if($browserProcess -and !$browserProcess.HasExited){Stop-Process -Id $browserProcess.Id -ErrorAction SilentlyContinue}
}
