'use strict';
// Exact integer-centavo preview; the backend independently validates prices.
function initializeProductForm(root) {
    const cost=root.querySelector('[name=default_unit_cost]'), price=root.querySelector('[name=default_selling_price]'), profit=root.querySelector('[data-product-profit]');
    if (!cost || !price || !profit) return;
    const cents=value=> {
        if (!/^[0-9]{1,11}(?:\.[0-9]{1,2})?$/.test(value)) return null;
        const [whole,part='']=value.split('.'); return BigInt(whole)*100n+BigInt(part.padEnd(2,'0'));
    };
    const update=()=> {
        const buy=cents(cost.value), sell=cents(price.value), warning=root.querySelector('.product-price-warning');
        if (buy===null || sell===null) { profit.textContent='Enter valid prices'; warning.classList.add('d-none'); return; }
        const value=sell-buy, absolute=value<0n?-value:value;
        profit.textContent=(value<0n?'\u2212':'')+'\u20b1'+(absolute/100n).toLocaleString('en-PH')+'.'+(absolute%100n).toString().padStart(2,'0');
        profit.classList.toggle('product-negative',value<0n); warning.classList.toggle('d-none',value>=0n);
    };
    cost.addEventListener('input',update); price.addEventListener('input',update); update();
}
initializeProductForm(document);
const productModalElement=document.getElementById('product-action-modal');
if (productModalElement && typeof bootstrap!=='undefined') {
    const modal=bootstrap.Modal.getOrCreateInstance(productModalElement), dialog=productModalElement.querySelector('.modal-dialog'), content=productModalElement.querySelector('.modal-content');
    let pendingLoad=null, busy=false, dirty=false, opener=null, discardContinuation=null, errorNumber=0;
    const focusContent=()=> {
        const target=content.querySelector('input:not([type=hidden]):not([disabled]),select:not([disabled]),textarea:not([disabled])') || content.querySelector('.modal-title');
        if (target) { if (target.matches('.modal-title')) target.tabIndex=-1; target.focus({preventScroll:true}); }
    };
    const confirmDiscard=continuation=> {
        discardContinuation=continuation; const prompt=content.querySelector('.product-discard-prompt');
        prompt.classList.remove('d-none'); prompt.scrollIntoView({block:'nearest'}); prompt.querySelector('[data-keep-product]').focus();
    };
    const showError=(form,message)=> { const box=form.querySelector('.product-submit-error'); box.textContent=message; box.classList.remove('d-none'); box.focus(); };
    const clearFieldErrors=form=> {
        form.querySelectorAll('.product-field-error').forEach(element=>element.remove());
        form.querySelectorAll('.is-invalid').forEach(field=> {
            field.classList.remove('is-invalid'); field.removeAttribute('aria-invalid');
            const ids=(field.getAttribute('aria-describedby')||'').split(' ').filter(id=>id && !id.startsWith('product-error-'));
            if (ids.length) field.setAttribute('aria-describedby',ids.join(' ')); else field.removeAttribute('aria-describedby');
        });
    };
    const fieldError=(field,message)=> {
        if (!field || field.type==='hidden' || field.disabled) return;
        const details=field.closest('details'); if (details) details.open=true;
        const feedback=document.createElement('div'); feedback.id=`product-error-${++errorNumber}`; feedback.className='invalid-feedback d-block product-field-error'; feedback.textContent=message;
        field.insertAdjacentElement('afterend',feedback); field.classList.add('is-invalid'); field.setAttribute('aria-invalid','true'); field.setAttribute('aria-describedby',((field.getAttribute('aria-describedby')||'')+' '+feedback.id).trim());
    };
    const refreshProducts=async()=> {
        const response=await fetch(location.href,{credentials:'same-origin',cache:'no-store'}), html=new DOMParser().parseFromString(await response.text(),'text/html'), updated=html.getElementById('dashboard-content');
        if (!response.ok || !updated?.querySelector('.product-directory')) throw new Error('The product list could not refresh.');
        const scroll=window.scrollY; document.getElementById('dashboard-content').replaceWith(updated); window.scrollTo({top:scroll,behavior:'instant'});
    };
    document.addEventListener('click',async event=> {
        const link=event.target.closest('a[data-product-modal]');
        if (!link || event.button!==0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault(); if (busy) return; if (dirty) { confirmDiscard(()=>link.click()); return; }
        pendingLoad?.abort(); pendingLoad=new AbortController(); const currentLoad=pendingLoad; opener=link; dirty=false;
        dialog.classList.toggle('modal-lg',['create','edit','details'].includes(link.dataset.productModal));
        content.innerHTML='<div class="modal-header"><h2 class="modal-title" id="product-modal-title">Loading product</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body product-modal-loading" role="status"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Loading…</div>';
        dialog.classList.toggle('modal-fullscreen-sm-down',!['archive','restore'].includes(link.dataset.productModal)); modal.show(); const url=new URL(link.href); url.searchParams.set('modal','1'); url.searchParams.set('action',link.dataset.productModal);
        try {
            const response=await fetch(url,{credentials:'same-origin',cache:'no-store',signal:currentLoad.signal}), html=await response.text();
            if (currentLoad.signal.aborted) return;
            const fragment=new DOMParser().parseFromString(html,'text/html');
            if (response.redirected || !fragment.getElementById('product-modal-title')) throw new Error('Your session may have expired. Sign in again, then reopen the product.');
            content.replaceChildren(...fragment.body.childNodes); initializeProductForm(content); focusContent();
        } catch (error) {
            if (error.name==='AbortError') return;
            content.innerHTML='<div class="modal-header"><h2 class="modal-title" id="product-modal-title">Unable to open product</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><div class="alert alert-danger" role="alert"></div></div><div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Close</button></div>';
            content.querySelector('.alert').textContent=error.message; focusContent();
        }
    });
    productModalElement.addEventListener('input',event=> { if (event.target.closest('form')) dirty=true; });
    productModalElement.addEventListener('change',event=> { if (event.target.closest('form')) dirty=true; });
    productModalElement.addEventListener('click',event=> {
        if (event.target.closest('[data-keep-product]')) { content.querySelector('.product-discard-prompt').classList.add('d-none'); discardContinuation=null; focusContent(); }
        if (event.target.closest('[data-discard-product]')) { const continuation=discardContinuation; discardContinuation=null; dirty=false; content.querySelector('.product-discard-prompt').classList.add('d-none'); continuation?.(); }
    });
    productModalElement.addEventListener('hide.bs.modal',event=> { if (busy) { event.preventDefault(); return; } if (dirty) { event.preventDefault(); confirmDiscard(()=>modal.hide()); return; } pendingLoad?.abort(); });
    productModalElement.addEventListener('hidden.bs.modal',()=> { content.replaceChildren(); (opener?.isConnected?opener:document.querySelector('[data-product-modal]'))?.focus({preventScroll:true}); });
    window.addEventListener('beforeunload',event=> { if (dirty || busy) { event.preventDefault(); event.returnValue=''; } });
    productModalElement.addEventListener('submit',async event=> {
        const form=event.target.closest('form[data-product-async]'); if (!form) return; event.preventDefault(); if (busy) return;
        clearFieldErrors(form); const invalid=[...form.elements].filter(field=>field.willValidate && !field.validity.valid);
        invalid.forEach(field=>fieldError(field,field.validationMessage)); if (invalid.length) { invalid[0].focus(); return; }
        form.querySelector('.product-submit-error').classList.add('d-none');
        const payload=new FormData(form), controls=[...form.querySelectorAll('input,select,textarea,button')].map(element=>[element,element.disabled]), button=form.querySelector('button[type=submit]'), label=button.textContent;
        busy=true; form.setAttribute('aria-busy','true'); controls.forEach(([element])=>{element.disabled=true;}); content.querySelectorAll('[data-bs-dismiss]').forEach(element=>{element.disabled=true;});
        button.innerHTML='<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Saving…';
        try {
            const response=await fetch(form.action,{method:'POST',body:payload,credentials:'same-origin',headers:{Accept:'application/json'}});
            if (!response.headers.get('content-type')?.includes('application/json')) throw new Error('The server could not confirm the save. Keep this form open and retry, or sign in again if your session expired.');
            const result=await response.json();
            if (!response.ok || !result.success) {
                for (const [name,message] of Object.entries(result.fields||{})) { const field=form.elements.namedItem(name), state=controls.find(([element])=>element===field); if (state) field.disabled=state[1]; fieldError(field,message); }
                showError(form,result.message || 'Please check the product details.'); return;
            }
            dirty=false; let refreshed=true; try { await refreshProducts(); } catch (_) { refreshed=false; }
            busy=false; modal.hide(); const feedback=document.getElementById('product-feedback');
            feedback.className=`alert ${refreshed?'alert-success':'alert-warning'}`; feedback.textContent=result.message+(refreshed?'':' The change was saved, but the list could not refresh. Reload to see updated figures.'); feedback.focus({preventScroll:true});
        } catch (error) { showError(form,error.message+' Your entered information has been kept.'); }
        finally { busy=false; form.removeAttribute('aria-busy'); controls.forEach(([element,disabled])=>{element.disabled=disabled;}); content.querySelectorAll('[data-bs-dismiss]').forEach(element=>{element.disabled=false;}); button.textContent=label; }
    });
}

if (new URLSearchParams(location.search).get('new')==='1') {
    document.querySelector('a[data-product-modal="create"]')?.click();
}