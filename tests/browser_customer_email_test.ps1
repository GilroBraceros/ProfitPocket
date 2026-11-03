$ErrorActionPreference='Stop'
$projectDirectory=Split-Path -Parent $PSScriptRoot
$results=Join-Path $PSScriptRoot 'http-results/browser-customer-email'
$profile=Join-Path $results ('profile-'+[Guid]::NewGuid().ToString('N'))
$browserProcess=$null;$visualServer=$null;$script:socket=$null;$script:commandId=0
$script:browserErrors=[Collections.Generic.List[string]]::new()
function Invoke-Cdp([string]$Method,[hashtable]$Arguments=@{}) {
    $script:commandId++;$id=$script:commandId
    $bytes=[Text.Encoding]::UTF8.GetBytes((@{id=$id;method=$Method;params=$Arguments}|ConvertTo-Json -Depth 20 -Compress))
    $cancel=[Threading.CancellationTokenSource]::new(15000)
    try {
        $script:socket.SendAsync([ArraySegment[byte]]::new($bytes),[Net.WebSockets.WebSocketMessageType]::Text,$true,$cancel.Token).GetAwaiter().GetResult()
        while($true) {
            $stream=[IO.MemoryStream]::new()
            do {$buffer=New-Object byte[] 65536;$received=$script:socket.ReceiveAsync([ArraySegment[byte]]::new($buffer),$cancel.Token).GetAwaiter().GetResult();$stream.Write($buffer,0,$received.Count)} while(!$received.EndOfMessage)
            $message=[Text.Encoding]::UTF8.GetString($stream.ToArray())|ConvertFrom-Json;$stream.Dispose()
            if($message.method -eq 'Runtime.exceptionThrown') {$script:browserErrors.Add(($message.params.exceptionDetails|ConvertTo-Json -Depth 5 -Compress))}
            if($message.id -eq $id) {if($message.error){throw($message.error|ConvertTo-Json -Compress)};return $message.result}
        }
    } finally {$cancel.Dispose()}
}
function Invoke-Script([string]$Expression) {
    $result=Invoke-Cdp 'Runtime.evaluate' @{expression=$Expression;returnByValue=$true;awaitPromise=$true}
    if($result.exceptionDetails){throw($result.exceptionDetails|ConvertTo-Json -Depth 5 -Compress)}
    return $result.result.value
}
function Wait-Browser([string]$Expression) {
    for($attempt=0;$attempt -lt 70;$attempt++){if(Invoke-Script $Expression){return};Start-Sleep -Milliseconds 100}
    throw "Browser condition timed out: $Expression"
}
function Save-Shot([string]$Name) {
    $capture=Invoke-Cdp 'Page.captureScreenshot' @{format='png';captureBeyondViewport=$true}
    [IO.File]::WriteAllBytes((Join-Path $results $Name),[Convert]::FromBase64String($capture.data))
}
try {
    $browserProcess=Start-Process -FilePath 'C:\Program Files\Google\Chrome\Application\chrome.exe' -ArgumentList @('--headless=new','--disable-gpu','--no-first-run','--no-default-browser-check','--remote-debugging-port=9245',"--user-data-dir=$profile",'about:blank') -WindowStyle Hidden -PassThru
    $visualServer=Start-Process -FilePath 'C:\xampp\php\php.exe' -ArgumentList @('-S','127.0.0.1:8037',(Join-Path $PSScriptRoot 'customer_email_visual_router.php')) -WorkingDirectory $projectDirectory -WindowStyle Hidden -PassThru
    for($attempt=0;$attempt -lt 40;$attempt++){try{$tabs=Invoke-RestMethod 'http://127.0.0.1:9245/json/list';break}catch{Start-Sleep -Milliseconds 150}}
    $target=$tabs|Where-Object {$_.type -eq 'page'}|Select-Object -First 1
    $script:socket=[Net.WebSockets.ClientWebSocket]::new();$null=$script:socket.ConnectAsync([Uri]$target.webSocketDebuggerUrl,[Threading.CancellationToken]::None).GetAwaiter().GetResult()
    Invoke-Cdp 'Runtime.enable'|Out-Null;Invoke-Cdp 'Page.enable'|Out-Null;$checks=@()
    foreach($page in @('email/purchase_paid','email/purchase_unpaid','email/purchase_installment','email/manual_reminder','reminder')) {
        Invoke-Cdp 'Page.navigate' @{url="http://127.0.0.1:8037/$page"}|Out-Null
        Wait-Browser 'document.readyState==="complete" && !!document.querySelector("h1,h2")'
        if($page -eq 'reminder'){Invoke-Script 'bootstrap.Modal.getOrCreateInstance(document.getElementById("sales-action-modal")).show();true'|Out-Null;Wait-Browser 'getComputedStyle(document.querySelector(".modal")).display==="block"';Start-Sleep -Milliseconds 600}
        if($page -ne 'reminder'){Wait-Browser '[...document.images].every(i=>i.complete && i.naturalWidth>0)'}
        foreach($width in @(1440,768,320)) {
            Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{width=$width;height=1000;deviceScaleFactor=1;mobile=($width -lt 768)}|Out-Null
            Invoke-Script 'new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))).then(()=>true)'|Out-Null
            if(!(Invoke-Script 'document.documentElement.scrollWidth<=innerWidth')) {Save-Shot 'overflow.png';throw "Horizontal overflow: $page $width"}
            if($page -eq 'reminder' -and !(Invoke-Script '[...document.querySelectorAll(".modal-header,.modal-body,.modal-footer")].every(e=>e.scrollWidth<=e.clientWidth)')) {throw "Clipped reminder content: $width"}
            Save-Shot (($page.Replace('/','-'))+"-$width.png");$checks+=@{page=$page;width=$width}
        }
    }
    Invoke-Script 'document.querySelector("#owner_message").value="Visual reminder <safe>";document.querySelector("[data-email-preview]").click();true'|Out-Null
    Wait-Browser 'document.querySelector("[data-email-preview-status]").textContent.includes("No email was sent")'
    if(!(Invoke-Script 'document.querySelector("iframe").srcdoc.includes("Visual reminder &lt;safe&gt;") && document.querySelector("iframe").getAttribute("sandbox")===""')) {throw 'Preview escaping or sandbox failed'}
    Invoke-Script 'document.querySelector("[name=confirm_recipient]").checked=true;document.querySelector("[name=confirm_recipient]").focus();true'|Out-Null
    if(!(Invoke-Script 'document.activeElement.name==="confirm_recipient"')) {throw 'Recipient keyboard focus unavailable'}
    Save-Shot 'reminder-focus-320.png'
    Invoke-Script 'document.querySelector("form[data-customer-reminder]").requestSubmit();true'|Out-Null
    if(!(Invoke-Script 'document.querySelector("form[data-customer-reminder]").getAttribute("aria-busy")==="true" && document.querySelector("button[type=submit]").disabled')) {throw 'Reminder loading/disabled state missing'}
    Wait-Browser '!document.querySelector("button[type=submit]").disabled && document.querySelector(".sale-submit-error").textContent.includes("Read-only visual test")'
    if($script:browserErrors.Count){throw('JavaScript exceptions: '+($script:browserErrors -join ', '))}
    @{layouts=$checks;javascriptErrors=0;realEmailsSent=0;source='Actual rollback-fixture HTML; loopback read-only renderer'}|ConvertTo-Json -Depth 5|Set-Content (Join-Path $results 'checks.json') -Encoding UTF8
    Write-Output 'PASS: 15 desktop/tablet/mobile layouts, original logo rendering, reminder preview escaping/sandbox, recipient focus, loading/disabled state and failure recovery; no JavaScript exceptions or real emails.'
} finally {
    if($script:socket){$script:socket.Dispose()}
    if($browserProcess -and !$browserProcess.HasExited){Stop-Process -Id $browserProcess.Id -ErrorAction SilentlyContinue}
    if($visualServer -and !$visualServer.HasExited){Stop-Process -Id $visualServer.Id -ErrorAction SilentlyContinue}
}
