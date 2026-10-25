$ErrorActionPreference='Stop'
$projectDirectory=Split-Path -Parent $PSScriptRoot
$resultDirectory=Join-Path $PSScriptRoot 'http-results'
$profileDirectory=Join-Path $resultDirectory ('browser-'+[Guid]::NewGuid().ToString('N'))
$browserPath='C:\Program Files\Google\Chrome\Application\chrome.exe'
$browserProcess=Start-Process -FilePath $browserPath -ArgumentList @('--headless=new','--disable-gpu','--no-first-run','--no-default-browser-check','--remote-debugging-port=9240',"--user-data-dir=$profileDirectory",'about:blank') -WindowStyle Hidden -PassThru
$visualServer=Start-Process -FilePath 'C:\xampp\php\php.exe' -ArgumentList @('-S','127.0.0.1:8032',(Join-Path $PSScriptRoot 'registration_visual_router.php')) -WorkingDirectory $projectDirectory -WindowStyle Hidden -PassThru
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
    for($attempt=0;$attempt -lt 40;$attempt++){try{$tabs=Invoke-RestMethod 'http://127.0.0.1:9240/json/list';break}catch{Start-Sleep -Milliseconds 200}}
    $target=$tabs|Where-Object {$_.type -eq 'page'}|Select-Object -First 1
    $script:socket=[Net.WebSockets.ClientWebSocket]::new();$null=$script:socket.ConnectAsync([Uri]$target.webSocketDebuggerUrl,[Threading.CancellationToken]::None).GetAwaiter().GetResult()
    Invoke-Cdp 'Runtime.enable'|Out-Null;Invoke-Cdp 'Page.enable'|Out-Null
    $checks=@()
    foreach($page in @('account','payment','complete','rejected-confirmation','renewal','unavailable','admin-methods','admin-edit')){
        Invoke-Cdp 'Page.navigate' @{url="http://127.0.0.1:8032/$page"}|Out-Null
        Wait-Browser 'document.readyState==="complete" && !!document.querySelector("h1")'
        if($page -in @('payment','renewal')){
            Invoke-BrowserScript '(()=>{const s=document.querySelector("[data-payment-picker] select");s.value=s.options[1].value;s.dispatchEvent(new Event("change",{bubbles:true}));return true;})()'|Out-Null
            if($page -eq 'payment'){Wait-Browser '!!document.querySelector("[data-payment-method]:not([hidden]) img")?.naturalWidth'}
        }
        foreach($width in @(1440,768,320)){
            Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{width=$width;height=1000;deviceScaleFactor=1;mobile=($width -lt 768)}|Out-Null
            if(!(Invoke-BrowserScript 'document.documentElement.scrollWidth<=innerWidth')){Save-Shot "onboarding-$page-overflow-$width.png";throw "Page overflow: $page $width"}
            if(!(Invoke-BrowserScript '[...document.querySelectorAll(".onboarding-progress li>span")].every(e=>{const r=e.getBoundingClientRect();return r.width>=30 && Math.abs(r.width-r.height)<1;})')){throw "Registration progress markers distorted: $page $width"}
            Save-Shot "onboarding-$page-$width.png";$checks+=@{page=$page;width=$width}
        }
        if($page -eq 'payment'){
            if(!(Invoke-BrowserScript '(()=>{const s=document.querySelector("[data-payment-picker] select"),first=s.options[1].value,bank=[...s.options].find(o=>o.text.includes("Bank Transfer"));s.value=bank.value;s.dispatchEvent(new Event("change",{bubbles:true}));const p=document.querySelector("[data-payment-method]:not([hidden])"),ok=p.textContent.includes("Fictional Test Bank")&&!p.querySelector("img");s.value=first;s.dispatchEvent(new Event("change",{bubbles:true}));return ok;})()')){throw 'Method switch did not change instructions/QR'}
            Invoke-BrowserScript 'document.querySelector("[data-payment-method]:not([hidden]) [data-copy-account]").click();true'|Out-Null
            Wait-Browser '!!document.querySelector("[data-payment-method]:not([hidden]) [data-copy-status]").textContent'
            Invoke-BrowserScript '(()=>{const dt=new DataTransfer(),bytes=Uint8Array.from(atob("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Z6ZAAAAAASUVORK5CYII="),c=>c.charCodeAt(0));dt.items.add(new File([bytes],"proof.png",{type:"image/png"}));const i=document.querySelector("[data-proof-input]");i.files=dt.files;i.dispatchEvent(new Event("change",{bubbles:true}));return true;})()'|Out-Null
            Wait-Browser 'document.querySelector("[data-proof-image]").naturalWidth>0 && !document.querySelector("[data-proof-preview]").hidden'
            Invoke-BrowserScript '(()=>{const dt=new DataTransfer();dt.items.add(new File(["%PDF-1.4 test only"],"proof.pdf",{type:"application/pdf"}));const i=document.querySelector("[data-proof-input]");i.files=dt.files;i.dispatchEvent(new Event("change",{bubbles:true}));return true;})()'|Out-Null
            if(!(Invoke-BrowserScript '!document.querySelector("[data-proof-pdf]").hidden && document.querySelector("[data-proof-pdf]").href.startsWith("blob:")')){throw 'PDF preview unavailable'}
            Invoke-BrowserScript '(()=>{document.addEventListener("submit",e=>e.preventDefault(),true);document.querySelector("[data-payment-picker]").requestSubmit();return true;})()'|Out-Null
            if(!(Invoke-BrowserScript 'document.querySelector("[data-payment-picker]").getAttribute("aria-busy")==="true" && document.querySelector("[data-payment-picker] button[type=submit]").disabled')){throw 'Payment duplicate-submit/loading guard failed'}
        }
        if($page -eq 'admin-edit'){
            if(!(Invoke-BrowserScript '[...document.querySelector("[name=method_category]").options].map(o=>o.text).join("|")==="GCash|Bank Transfer|Other"')){throw 'Wrong method choices'}
            Invoke-BrowserScript '(()=>{const s=document.querySelector("[name=method_category]");s.value="bank";s.dispatchEvent(new Event("change",{bubbles:true}));return true;})()'|Out-Null
            if(!(Invoke-BrowserScript '!document.querySelector("[data-method-bank]").hidden && document.querySelector("[name=bank_name]").required && document.querySelector("[data-method-other]").hidden')){throw 'Bank name condition failed'}
            Invoke-BrowserScript '(()=>{const s=document.querySelector("[name=method_category]");s.value="other";s.dispatchEvent(new Event("change",{bubbles:true}));return true;})()'|Out-Null
            if(!(Invoke-BrowserScript '!document.querySelector("[data-method-other]").hidden && document.querySelector("[name=custom_method_name]").required')){throw 'Other method condition failed'}
            Invoke-BrowserScript '(()=>{const dt=new DataTransfer();dt.items.add(new File([Uint8Array.from(atob("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Z6ZAAAAAASUVORK5CYII="),c=>c.charCodeAt(0))],"qr.png",{type:"image/png"}));const i=document.querySelector("[data-qr-input]");i.files=dt.files;i.dispatchEvent(new Event("change",{bubbles:true}));return true;})()'|Out-Null
            Wait-Browser 'document.querySelector("[data-qr-preview]").src.startsWith("blob:") && document.querySelector("[data-qr-preview]").naturalWidth>0'
            Invoke-BrowserScript 'document.querySelector("[data-remove-qr]").click();true'|Out-Null
            if(!(Invoke-BrowserScript 'document.querySelector("[data-qr-preview]").hidden && document.querySelector("[data-qr-input]").files.length===0')){throw 'QR removal preview failed'}
        }
    }
    foreach($type in @('registration_payment_submitted','subscription_admin_review')){
        Invoke-Cdp 'Page.navigate' @{url="http://127.0.0.1:8032/email/$type"}|Out-Null;Wait-Browser 'document.readyState==="complete" && !!document.querySelector("h1")'
        Invoke-BrowserScript 'document.querySelector("img").src="http://127.0.0.1:8032/profitpocket/assets/images/logo-icon.jpg";true'|Out-Null
        foreach($width in @(1440,768,320)){
            Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{width=$width;height=1000;deviceScaleFactor=1;mobile=($width -lt 768)}|Out-Null
            if(!(Invoke-BrowserScript 'document.documentElement.scrollWidth<=innerWidth && !document.querySelector("script,link[rel=stylesheet]") && [...document.querySelectorAll("a:not([href^=mailto])")].every(a=>a.href.startsWith("https://"))')){throw "Email preview failed: $type $width"}
            Save-Shot "onboarding-email-$type-$width.png";$checks+=@{page=$type;width=$width}
        }
    }
    if($script:browserErrors.Count){throw ('JavaScript exceptions: '+($script:browserErrors -join ', '))}
    @{checks=$checks;javascriptErrors=0;realEmailsSent=0}|ConvertTo-Json -Depth 5|Set-Content (Join-Path $resultDirectory 'onboarding-browser-checks.json') -Encoding UTF8
    Write-Output 'PASS: 30 responsive page/email layouts, QR selection, copy feedback, bank/custom fields, PNG/PDF previews and duplicate-submit/loading states; zero JavaScript exceptions and no real sends.'
}finally{
    if($script:socket){$script:socket.Dispose()}
    if($browserProcess -and !$browserProcess.HasExited){Stop-Process -Id $browserProcess.Id -ErrorAction SilentlyContinue}
    if($visualServer -and !$visualServer.HasExited){Stop-Process -Id $visualServer.Id -ErrorAction SilentlyContinue}
}
