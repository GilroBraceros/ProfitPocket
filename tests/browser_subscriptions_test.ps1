$ErrorActionPreference='Stop'
$projectDirectory=Split-Path -Parent $PSScriptRoot
$resultDirectory=Join-Path $PSScriptRoot 'http-results'
$profileDirectory=Join-Path $resultDirectory ('browser-'+[Guid]::NewGuid().ToString('N'))
$browserPath='C:\Program Files\Google\Chrome\Application\chrome.exe'
$browserProcess=Start-Process -FilePath $browserPath -ArgumentList @('--headless=new','--disable-gpu','--no-first-run','--no-default-browser-check','--remote-debugging-port=9239',"--user-data-dir=$profileDirectory",'about:blank') -WindowStyle Hidden -PassThru
$visualServer=Start-Process -FilePath 'C:\xampp\php\php.exe' -ArgumentList @('-S','127.0.0.1:8029',(Join-Path $PSScriptRoot 'subscription_visual_router.php')) -WorkingDirectory $projectDirectory -WindowStyle Hidden -PassThru
$script:socket=$null;$script:commandId=0;$script:browserErrors=[Collections.Generic.List[string]]::new()
function Invoke-Cdp([string]$Method,[hashtable]$Arguments=@{}){
    $script:commandId++;$requestId=$script:commandId
    $command=@{id=$requestId;method=$Method;params=$Arguments}|ConvertTo-Json -Depth 20 -Compress
    $bytes=[Text.Encoding]::UTF8.GetBytes($command);$cancel=[Threading.CancellationTokenSource]::new(10000)
    try{
        $script:socket.SendAsync([ArraySegment[byte]]::new($bytes),[Net.WebSockets.WebSocketMessageType]::Text,$true,$cancel.Token).GetAwaiter().GetResult()
        while($true){
            $stream=[IO.MemoryStream]::new()
            do{$buffer=New-Object byte[] 65536;$received=$script:socket.ReceiveAsync([ArraySegment[byte]]::new($buffer),$cancel.Token).GetAwaiter().GetResult();$stream.Write($buffer,0,$received.Count)}while(!$received.EndOfMessage)
            $message=[Text.Encoding]::UTF8.GetString($stream.ToArray())|ConvertFrom-Json;$stream.Dispose()
            if($message.method -eq 'Runtime.exceptionThrown'){$script:browserErrors.Add(($message.params.exceptionDetails|ConvertTo-Json -Depth 5 -Compress))}
            if($message.id -eq $requestId){if($message.error){throw($message.error|ConvertTo-Json -Compress)};return $message.result}
        }
    }finally{$cancel.Dispose()}
}
function Invoke-BrowserScript([string]$Expression){$result=Invoke-Cdp 'Runtime.evaluate' @{expression=$Expression;returnByValue=$true;awaitPromise=$true};if($result.exceptionDetails){throw($result.exceptionDetails|ConvertTo-Json -Depth 5 -Compress)};return $result.result.value}
function Wait-Browser([string]$Expression){for($attempt=0;$attempt -lt 60;$attempt++){if(Invoke-BrowserScript $Expression){return};Start-Sleep -Milliseconds 150};throw "Browser condition timed out: $Expression"}
function Save-Shot([string]$Name){$capture=Invoke-Cdp 'Page.captureScreenshot' @{format='png';captureBeyondViewport=$true};[IO.File]::WriteAllBytes((Join-Path $resultDirectory $Name),[Convert]::FromBase64String($capture.data))}
try{
    for($attempt=0;$attempt -lt 40;$attempt++){try{$tabs=Invoke-RestMethod 'http://127.0.0.1:9239/json/list';break}catch{Start-Sleep -Milliseconds 200}}
    $target=$tabs|Where-Object {$_.type -eq 'page'}|Select-Object -First 1
    $script:socket=[Net.WebSockets.ClientWebSocket]::new();$null=$script:socket.ConnectAsync([Uri]$target.webSocketDebuggerUrl,[Threading.CancellationToken]::None).GetAwaiter().GetResult()
    Invoke-Cdp 'Runtime.enable'|Out-Null;Invoke-Cdp 'Page.enable'|Out-Null
    $checks=@()
    foreach($page in @('homepage','pending-payment','pending-verification','rejected','active','expired','renewed','admin-review','admin-overview')){
        Invoke-Cdp 'Page.navigate' @{url="http://127.0.0.1:8029/$page"}|Out-Null
        Wait-Browser 'document.readyState==="complete" && !!document.querySelector("h1") && getComputedStyle(document.querySelector("h1")).fontWeight>=600'
        foreach($width in @(1440,768,320)){
            Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{width=$width;height=1000;deviceScaleFactor=1;mobile=($width -lt 768)}|Out-Null
            if(!(Invoke-BrowserScript 'document.documentElement.scrollWidth<=innerWidth')){Save-Shot "subscription-$page-overflow-$width.png";throw "Horizontal page overflow: $page $width"}
            Save-Shot "subscription-$page-$width.png";$checks+=@{page=$page;width=$width}
        }
        if($page -eq 'homepage'){
            Invoke-BrowserScript 'document.querySelector(".navbar-toggler").click();true'|Out-Null
            Wait-Browser 'document.querySelector(".navbar-toggler").getAttribute("aria-expanded")==="true"'
            if(!(Invoke-BrowserScript '[...document.querySelectorAll(".navbar-nav .nav-link")].every(a=>document.querySelector(a.getAttribute("href")))')){throw 'Homepage section links failed'}
            Invoke-BrowserScript 'document.querySelector(".pp-button-row a").focus();true'|Out-Null
            if(!(Invoke-BrowserScript 'parseInt(getComputedStyle(document.activeElement).outlineWidth)>=3')){throw 'Visible keyboard focus missing'}
        }
        if($page -eq 'pending-payment'){
            Invoke-BrowserScript '(()=>{document.addEventListener("submit",e=>e.preventDefault(),true);const form=document.querySelector("[data-subscription-form]"),method=form.querySelector("select[name=method_id]");method.value=method.options[1].value;method.dispatchEvent(new Event("change",{bubbles:true}));const dt=new DataTransfer();dt.items.add(new File(["not sent"],"sample.png",{type:"image/png"}));document.getElementById("proof").files=dt.files;form.requestSubmit();return true;})()'|Out-Null
            if(!(Invoke-BrowserScript 'document.querySelector("[data-subscription-form]").getAttribute("aria-busy")==="true" && document.querySelector("[data-subscription-form] button[type=submit]").disabled')){throw 'Upload loading/duplicate-submit state missing'}
        }
        if($page -eq 'admin-review'){
            Invoke-BrowserScript '(()=>{document.addEventListener("submit",e=>e.preventDefault(),true);const form=document.querySelector("form[data-subscription-form]");form.querySelector("[name=confirm]").checked=true;form.querySelector("[name=payment_verified]").checked=true;form.requestSubmit(form.querySelector("[name=decision][value=approved]"));return true;})()'|Out-Null
            if(!(Invoke-BrowserScript 'document.querySelector("input[type=hidden][name=decision]")?.value==="approved" && document.querySelector("form[data-subscription-form]").getAttribute("aria-busy")==="true"')){throw 'Approval submitter or saving state lost'}
        }
    }
    foreach($type in @('subscription_submitted','subscription_approved','subscription_rejected','subscription_expiring','subscription_expired','subscription_renewed')){
        Invoke-Cdp 'Page.navigate' @{url="http://127.0.0.1:8029/email/$type"}|Out-Null;Wait-Browser 'document.readyState==="complete" && !!document.querySelector("h1")'
        Invoke-BrowserScript 'document.querySelector("img").src="http://127.0.0.1:8029/profitpocket/assets/images/logo-icon.jpg";true'|Out-Null
        foreach($width in @(1440,768,320)){
            Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{width=$width;height=1000;deviceScaleFactor=1;mobile=($width -lt 768)}|Out-Null
            if(!(Invoke-BrowserScript 'document.documentElement.scrollWidth<=innerWidth && !document.querySelector("script,link[rel=stylesheet]") && [...document.querySelectorAll("a:not([href^=mailto])")].every(a=>a.href.startsWith("https://"))')){throw "Subscription email layout failed: $type $width"}
            Save-Shot "subscription-email-$type-$width.png";$checks+=@{page=$type;width=$width}
        }
    }
    if($script:browserErrors.Count){throw('JavaScript exceptions: '+($script:browserErrors -join ', '))}
    @{checks=$checks;javascriptErrors=0;realEmailsSent=0;source='Actual cleaned-up HTTP test snapshots; read-only loopback preview'}|ConvertTo-Json -Depth 5|Set-Content (Join-Path $resultDirectory 'subscription-browser-checks.json') -Encoding UTF8
    Write-Output 'PASS: 45 responsive page/email layouts, navigation, keyboard focus, submitter preservation and loading states; no JavaScript exceptions or live mutations.'
}finally{
    if($script:socket){$script:socket.Dispose()}
    if($browserProcess -and !$browserProcess.HasExited){Stop-Process -Id $browserProcess.Id -ErrorAction SilentlyContinue}
    if($visualServer -and !$visualServer.HasExited){Stop-Process -Id $visualServer.Id -ErrorAction SilentlyContinue}
}
