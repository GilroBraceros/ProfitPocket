# Read-only browser checks against the signed-in owner's existing reports.
$reportWidths = [System.Collections.Generic.List[object]]::new()
$reportModalChecks = [System.Collections.Generic.List[object]]::new()
foreach ($tab in @('sales','expenses','profit','utang')) {
    foreach ($width in @(1440,1024,768,390,320)) {
        Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{width=$width;height=1100;deviceScaleFactor=1;mobile=$false} | Out-Null
        Invoke-Cdp 'Page.navigate' @{url="http://localhost/profitpocket/user/reports.php?period=year&tab=$tab"} | Out-Null
        Wait-ForBrowser "document.readyState==='complete' && location.search.includes('tab=$tab') && !!document.querySelector('.report-panel') && !!window.bootstrap && !!window.Chart"
        $layout=Invoke-BrowserScript @'
(() => {
  const chart=Chart.getChart('report-chart');
  if(document.querySelector('[data-report-chart]') && !chart) throw new Error('Report chart did not render');
  if(chart && chart.data.datasets.length!==3) throw new Error('Missing report chart series');
  return {tab:new URL(location.href).searchParams.get('tab'),viewport:innerWidth,pageWidth:document.documentElement.scrollWidth,cards:document.querySelectorAll('[data-report-amount]').length,chart:!!chart,activeNavigation:!!document.querySelector('.sidebar-link.active[href$="reports.php"]'),selectedTabs:document.querySelectorAll('.report-tabs .nav-link.active').length};
})()
'@
        if($layout.pageWidth -gt $width -or $layout.cards -ne 4 -or !$layout.activeNavigation -or $layout.selectedTabs -ne 1){throw "Reports layout failed: $tab at $width"}
        $reportWidths.Add($layout)
        if($width -in @(1440,320)){Save-BrowserScreenshot "report-$tab-$width.png"}
    }
    if($tab -ne 'profit') {
        if(Invoke-BrowserScript '!!document.querySelector("[data-report-detail]")') {
            Invoke-BrowserScript 'document.querySelector("[data-report-detail]").scrollIntoView({behavior:"instant",block:"center"});document.querySelector("[data-report-detail]").click();true' | Out-Null
            Wait-ForBrowser '!!document.querySelector("#report-detail-modal.show .modal-body") && !document.querySelector("#report-detail-modal .modal-content").hasAttribute("aria-busy")'
            Wait-ForBrowser 'bootstrap.Modal.getInstance(document.getElementById("report-detail-modal"))._isTransitioning===false'
            if(!(Invoke-BrowserScript '(()=>{const modal=document.querySelector("#report-detail-modal"),box=modal.querySelector(".modal-content").getBoundingClientRect();return !modal.querySelector("form") && box.left>=0 && box.right<=innerWidth+1 && box.height<=innerHeight+1 && !!modal.querySelector("#report-detail-title") && getComputedStyle(modal.querySelector(".modal-footer")).display!=="none";})()')){throw "Report details invalid at phone width: $tab"}
            Save-BrowserScreenshot "report-$tab-details-320.png"
            Invoke-BrowserScript 'document.querySelector("#report-detail-modal [data-bs-dismiss=modal]").click();true' | Out-Null
            Wait-ForBrowser '!document.querySelector("#report-detail-modal.show") && !document.querySelector(".modal-backdrop")'
            if(!(Invoke-BrowserScript 'document.activeElement.matches("[data-report-detail]")')){throw 'Report modal did not restore focus'}
            $reportModalChecks.Add($tab)
        }
    }
}
Invoke-Cdp 'Emulation.setDeviceMetricsOverride' @{width=1440;height=1100;deviceScaleFactor=1;mobile=$false} | Out-Null
Invoke-Cdp 'Page.navigate' @{url='http://localhost/profitpocket/user/reports.php?period=year&tab=profit'} | Out-Null
Wait-ForBrowser 'document.readyState==="complete" && !!document.querySelector(".report-profit-lines")'
if(!(Invoke-BrowserScript @'
(() => {const data=JSON.parse(document.querySelector('[data-report-chart]').dataset.reportChart);const read=k=>Math.round(Number(document.querySelector('[data-report-amount="'+k+'"]').textContent.replace(/\u2212/g,'-').replace(/[\u20b1,\s]/g,''))*100);return ['sales','expenses','profit'].every(k=>Math.round(data[k].reduce((a,b)=>a+b,0)*100)===read(k));})()
'@)){throw 'Report chart/card totals differ'}
Invoke-BrowserScript 'document.getElementById("period").value="custom";document.getElementById("period").dispatchEvent(new Event("change"));true' | Out-Null
if(!(Invoke-BrowserScript '!document.getElementById("custom-dates").hidden && !document.getElementById("start").disabled && document.getElementById("start").required')){throw 'Reports custom date controls failed'}
Invoke-BrowserScript 'document.getElementById("start").value="2026-10-01";document.getElementById("end").value="2026-10-09";document.getElementById("period").form.requestSubmit();true' | Out-Null
Wait-ForBrowser 'document.readyState==="complete" && location.search.includes("period=custom") && location.search.includes("start=2026-10-01") && !!document.querySelector(".report-panel")'
Invoke-Cdp 'Page.navigate' @{url='http://localhost/profitpocket/user/reports.php?period=year&tab=sales&q=NoReportMatch12345'} | Out-Null
Wait-ForBrowser 'document.readyState==="complete" && !!document.getElementById("report-search") && location.search.includes("NoReportMatch12345")'
if(!(Invoke-BrowserScript 'document.querySelector(".report-panel").textContent.includes("No matching transactions") && !document.querySelector(".report-table-wrap")')){throw 'Reports search empty state failed'}
Invoke-Cdp 'Page.navigate' @{url='http://localhost/profitpocket/user/reports.php?period=custom&start=2026-01-01&end=2026-01-02&tab=sales'} | Out-Null
Wait-ForBrowser 'document.readyState==="complete" && location.search.includes("start=2026-01-01") && !!document.querySelector(".report-panel")'
if(!(Invoke-BrowserScript '!!document.querySelector(".chart-empty")')){throw 'Empty Reports chart state failed'}
$fetchChecks=Invoke-BrowserScript @'
(async()=>{
 const csv=await fetch('/profitpocket/user/reports.php?period=year&tab=sales&export=csv');const text=await csv.text();
 const bad=await fetch('/profitpocket/user/reports.php?period=custom&start=2026-02-30&end=2026-03-01');
 return {csv:csv.ok&&csv.headers.get('content-type').includes('text/csv')&&text.includes('Sales report'),invalid:bad.status===400};
})()
'@
if(!$fetchChecks.csv -or !$fetchChecks.invalid){throw 'Browser CSV/date checks failed'}

foreach($tab in @('sales','expenses','profit','utang')) {
    Invoke-Cdp 'Page.navigate' @{url="http://localhost/profitpocket/user/reports.php?period=year&tab=$tab&print=1"} | Out-Null
    Wait-ForBrowser "document.readyState==='complete' && location.search.includes('tab=$tab') && !!document.querySelector('.report-print-view')"
    Invoke-Cdp 'Emulation.setEmulatedMedia' @{media='print'} | Out-Null
    Invoke-BrowserScript 'dispatchEvent(new Event("beforeprint"));true' | Out-Null
    if(!(Invoke-BrowserScript 'getComputedStyle(document.querySelector(".business-sidebar")).display==="none" && getComputedStyle(document.querySelector(".business-topbar")).display==="none" && getComputedStyle(document.querySelector(".report-print-heading")).display!=="none" && getComputedStyle(document.querySelector(".dashboard-main")).marginLeft==="0px" && !document.querySelector(".report-pagination")')){throw 'Print layout failed'}
    if($tab -eq 'profit'){Save-BrowserScreenshot 'report-profit-print.png'}
    $pdf=Invoke-Cdp 'Page.printToPDF' @{printBackground=$true;preferCSSPageSize=$true}
    $pdfBytes=[Convert]::FromBase64String($pdf.data)
    if($pdfBytes.Length -lt 1500){throw 'Report print output is empty'}
    [IO.File]::WriteAllBytes((Join-Path $resultDirectory "report-$tab-print.pdf"),$pdfBytes)
    Invoke-Cdp 'Emulation.setEmulatedMedia' @{media='screen'} | Out-Null
}
$financialReport=@{responsive=$reportWidths;detailModals=$reportModalChecks;chartReconciled=$true;customDates=$true;emptyStates=$true;csvDownload=$true;invalidDateRejected=$true;printAllTabs=$true;readOnly=$true}
