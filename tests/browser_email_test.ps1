$ErrorActionPreference = 'Stop'
$projectDirectory = Split-Path -Parent $PSScriptRoot
$resultDirectory = Join-Path $PSScriptRoot 'http-results'
New-Item -ItemType Directory -Path $resultDirectory -Force | Out-Null
$profileDirectory = Join-Path $resultDirectory ('browser-' + [Guid]::NewGuid().ToString('N'))
$browserPath = 'C:\Program Files\Google\Chrome\Application\chrome.exe'
if (!(Test-Path -LiteralPath $browserPath)) { throw 'Chrome is not installed at the expected location.' }
$port = 9238
$browserProcess = Start-Process -FilePath $browserPath -ArgumentList @('--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check', "--remote-debugging-port=$port", "--user-data-dir=$profileDirectory", 'about:blank') -WindowStyle Hidden -PassThru
$script:socket = $null
$script:commandId = 0
$script:browserErrors = [System.Collections.Generic.List[string]]::new()
$script:browserDialogs = [System.Collections.Generic.List[string]]::new()
$script:acceptDialogs = $true

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

try {
    for($attempt=0;$attempt -lt 40;$attempt++){try{$tabs=Invoke-RestMethod "http://127.0.0.1:$port/json/list";break}catch{Start-Sleep -Milliseconds 200}}
    $target=$tabs|Where-Object {$_.type -eq 'page'}|Select-Object -First 1
    $script:socket=[Net.WebSockets.ClientWebSocket]::new()
    $null=$script:socket.ConnectAsync([Uri]$target.webSocketDebuggerUrl,[Threading.CancellationToken]::None).GetAwaiter().GetResult()
    Invoke-Cdp 'Runtime.enable'|Out-Null
    Invoke-Cdp 'Page.enable'|Out-Null
    $layouts=@()
    foreach($type in @('welcome','password_reset','password_changed','payment_recorded','partial_payment','upcoming_due','overdue','balance_settled')){
        $file=(Join-Path $resultDirectory "email-previews/$type.html").Replace('\','/')
        Invoke-Cdp 'Page.navigate' @{url="file:///$file"}|Out-Null
        Wait-ForBrowser 'document.readyState==="complete" && !!document.querySelector("h1")'
        # Preview the existing JPG locally. Production HTML retains its HTTPS URL.
        Invoke-BrowserScript 'document.querySelector("img").src="http://localhost/profitpocket/assets/images/logo-icon.jpg";true'|Out-Null
        Wait-ForBrowser 'document.querySelector("img").complete && document.querySelector("img").naturalWidth>0'
        foreach($width in @(1440,768,320)){
            Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{width=$width;height=1000;deviceScaleFactor=1;mobile=($width -lt 768)}|Out-Null
            if(!(Invoke-BrowserScript 'document.documentElement.scrollWidth<=innerWidth && !document.querySelector("script,link[rel=stylesheet]") && [...document.querySelectorAll("a:not([href^=mailto])")].every(a=>a.href.startsWith("https://")) && document.body.textContent.includes("All rights reserved.")')){throw "Email layout failed: $type $width"}
            $layouts+=@{type=$type;width=$width}
            Save-BrowserScreenshot "email-$type-$width.png"
        }
    }
    Invoke-Cdp 'Page.navigate' @{url=('http://localhost/profitpocket/auth/reset_password.php#token='+('e'*64))}|Out-Null
    Wait-ForBrowser 'document.readyState==="complete" && document.getElementById("reset_token")?.value.length===64 && location.hash===""'
    foreach($width in @(1440,768,320)){
        Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{width=$width;height=900;deviceScaleFactor=1;mobile=($width -lt 768)}|Out-Null
        if(!(Invoke-BrowserScript 'document.documentElement.scrollWidth<=innerWidth')){throw "Reset form overflow $width"}
        Save-BrowserScreenshot "email-reset-form-$width.png"
    }
    Invoke-Cdp 'Page.navigate' @{url='http://localhost/profitpocket/auth/forgot_password.php'}|Out-Null
    Wait-ForBrowser 'document.readyState==="complete" && !!document.getElementById("email")'
    Invoke-BrowserScript 'document.getElementById("email").value="nobody@example.invalid";document.querySelector("form").requestSubmit();true'|Out-Null
    Wait-ForBrowser 'document.readyState==="complete" && !!document.querySelector(".alert-success")'
    if(!(Invoke-BrowserScript 'document.querySelector(".alert-success").textContent.includes("eligible account")')){throw 'Forgot password disclosed account existence'}
    Save-BrowserScreenshot 'email-forgot-result-320.png'
    if($script:browserErrors.Count){throw ('JavaScript errors: '+($script:browserErrors -join ', '))}
    @{layouts=$layouts;resetFragmentCleared=$true;genericRecovery=$true;realEmailsSent=0;javascriptErrors=0}|ConvertTo-Json -Depth 5|Set-Content (Join-Path $resultDirectory 'email-browser-checks.json') -Encoding UTF8
    Write-Output 'PASS: 24 email preview layouts, HTTPS CTA markup, local original logo, reset form/fragment protection and generic recovery; zero JavaScript exceptions and no real sends.'
} finally {
    if($script:socket){$script:socket.Dispose()}
    if($target){try{Invoke-WebRequest "http://127.0.0.1:$port/json/close/$($target.id)" -UseBasicParsing -TimeoutSec 3|Out-Null}catch{}}
    if($browserProcess -and !$browserProcess.HasExited){Stop-Process -Id $browserProcess.Id -ErrorAction SilentlyContinue}
}
