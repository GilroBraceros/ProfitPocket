'use strict';
const reportChartWrap=document.querySelector('[data-report-chart]');
if(reportChartWrap){
    try{
        if(typeof Chart==='undefined')throw new Error('Chart unavailable');
        const data=JSON.parse(reportChartWrap.dataset.reportChart);
        const pesos=new Intl.NumberFormat('en-PH',{style:'currency',currency:'PHP',minimumFractionDigits:2});
        new Chart(document.getElementById('report-chart'),{type:'bar',data:{labels:data.labels,datasets:[
            {label:'Sales',data:data.sales,backgroundColor:'#269967',borderRadius:3,maxBarThickness:24},
            {label:'Operating expenses',data:data.expenses,backgroundColor:'#9caaa3',borderRadius:3,maxBarThickness:24},
            {type:'line',label:'Estimated net profit',data:data.profit,borderColor:'#18543d',backgroundColor:'#18543d',borderWidth:2,pointRadius:2,tension:0}
        ]},options:{
            responsive:true,maintainAspectRatio:false,animation:false,
            interaction:{mode:'index',intersect:false},
            plugins:{
                legend:{display:false},
                tooltip:{callbacks:{label:c=>c.dataset.label+': '+pesos.format(c.parsed.y)}}
            },
            scales:{
                x:{grid:{display:false},ticks:{maxRotation:0,maxTicksLimit:8,font:{size:10}}},
                y:{beginAtZero:true,grid:{color:'#eff3f0'},ticks:{maxTicksLimit:5,font:{size:10},callback:v=>'₱'+Number(v).toLocaleString('en-PH')}}
            }
        }});
    }catch(_){document.getElementById('report-chart').hidden=true;document.getElementById('report-chart-fallback').hidden=false;document.getElementById('report-chart-data').open=true;}
}
document.querySelectorAll('[data-report-loading]').forEach(button=>button.form.addEventListener('submit',()=>{button.disabled=true;button.textContent='Loading…';button.form.setAttribute('aria-busy','true');}));
document.querySelector('[data-report-print]')?.addEventListener('click',()=>window.print());
window.addEventListener('beforeprint',()=>document.querySelectorAll('.report-distribution').forEach(details=>{details.open=true;}));

const reportModalElement=document.getElementById('report-detail-modal');
if(reportModalElement && window.bootstrap){
    const modal=bootstrap.Modal.getOrCreateInstance(reportModalElement),content=reportModalElement.querySelector('.modal-content');
    let controller=null,opener=null;
    const message=(text)=>{content.replaceChildren();const header=document.createElement('div');header.className='modal-header';const title=document.createElement('h2');title.id='report-detail-title';title.className='modal-title';title.textContent='Transaction details';const close=document.createElement('button');close.className='btn-close';close.type='button';close.dataset.bsDismiss='modal';close.setAttribute('aria-label','Close');header.append(title,close);const body=document.createElement('div');body.className='modal-body';body.setAttribute('role','status');body.textContent=text;content.append(header,body);};
    document.addEventListener('click',async event=>{
        const link=event.target.closest('a[data-report-detail]');if(!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button!==0)return;
        event.preventDefault();controller?.abort();const request=new AbortController();controller=request;opener=link;message('Loading details…');content.setAttribute('aria-busy','true');modal.show();
        try{
            const url=new URL(link.href,location.href);url.searchParams.set('modal','1');
            const response=await fetch(url,{signal:request.signal,headers:{'X-Requested-With':'XMLHttpRequest'},cache:'no-store'});
            if(response.redirected)throw new Error('Your session expired. Reload Reports to sign in.');
            if(!response.ok)throw new Error('Details are unavailable. Close this window and try again.');
            const html=await response.text();if(request.signal.aborted)return;
            content.innerHTML=html;const title=content.querySelector('.modal-title');if(title)title.id='report-detail-title';
            if(!content.querySelector('.modal-body'))throw new Error('Details could not be loaded.');
            content.querySelector('.btn-close')?.focus();
        }catch(error){if(error.name!=='AbortError')message(error.message);}
        finally{if(controller===request)content.removeAttribute('aria-busy');}
    });
    reportModalElement.addEventListener('hidden.bs.modal',()=>{controller?.abort();content.removeAttribute('aria-busy');if(opener?.isConnected)opener.focus();});
}
