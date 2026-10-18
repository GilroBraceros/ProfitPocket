'use strict';
// Integer centavos keep the preview exact; PHP checks the final amount.
function initializeUtangForm(root) {
    initializePaymentMethods(root);
    const preview=root.querySelector('.utang-payment-preview'), amount=root.querySelector('[name=amount]');
    if (!preview || !amount) return;
    const cents=value=> {
        if (!/^[0-9]{1,11}(?:\.[0-9]{1,2})?$/.test(value)) return null;
        const [whole,part='']=value.split('.'); return BigInt(whole)*100n+BigInt(part.padEnd(2,'0'));
    };
    const money=value=>'₱'+(value/100n).toLocaleString('en-PH')+'.'+(value%100n).toString().padStart(2,'0');
    const balance=cents(preview.dataset.balance);
    const update=()=> {
        const payment=cents(amount.value || '0');
        amount.setCustomValidity(payment===null?'Enter a valid amount with up to two decimal places.':payment<=0n?'Payment amount must be greater than zero.':payment>balance?'Payment exceeds the remaining balance.':'');
        preview.querySelector('[data-preview-payment]').textContent=payment===null?'Enter a valid amount':money(payment);
        preview.querySelector('[data-preview-balance]').textContent=payment===null?'—':payment>balance?'Exceeds remaining balance':money(balance-payment);
        preview.classList.toggle('utang-preview-invalid',payment===null || payment>balance);
    };
    amount.addEventListener('input',update); update();
}
initializeUtangForm(document);
function initializePaymentFilters(root=document) {
    const period=root.querySelector('.payments-filter [name=period]'), dates=root.querySelector('.payments-custom-dates');
    if(!period||!dates||period.dataset.initialized) return;
    period.dataset.initialized='true';
    const update=()=>{const custom=period.value==='custom';dates.hidden=!custom;dates.querySelectorAll('input').forEach(field=>{field.disabled=!custom;field.required=custom;});};
    period.addEventListener('change',update);update();
}
initializePaymentFilters();
const utangModalElement=document.getElementById('utang-action-modal');
if (utangModalElement && typeof bootstrap!=='undefined') {
    const modal=bootstrap.Modal.getOrCreateInstance(utangModalElement), dialog=utangModalElement.querySelector('.modal-dialog'), content=utangModalElement.querySelector('.modal-content');
    let pendingLoad=null, busy=false, dirty=false, opener=null, discardContinuation=null, errorNumber=0;
    const focusContent=()=> {
        const target=content.querySelector('input:not([type=hidden]):not([disabled]),select:not([disabled]),textarea:not([disabled])') || content.querySelector('.modal-title');
        if (target) { if (target.matches('.modal-title')) target.tabIndex=-1; target.focus({preventScroll:true}); }
    };
    const confirmDiscard=continuation=> {
        discardContinuation=continuation; const prompt=content.querySelector('.utang-discard-prompt');
        prompt.classList.remove('d-none'); prompt.scrollIntoView({block:'nearest'}); prompt.querySelector('[data-keep-utang]').focus();
    };
    const showError=(form,message)=> { const box=form.querySelector('.utang-submit-error'); box.textContent=message; box.classList.remove('d-none'); box.focus(); };
    const clearFieldErrors=form=> {
        form.querySelectorAll('.utang-field-error').forEach(element=>element.remove());
        form.querySelectorAll('.is-invalid').forEach(field=> {
            field.classList.remove('is-invalid'); field.removeAttribute('aria-invalid');
            const ids=(field.getAttribute('aria-describedby')||'').split(' ').filter(id=>id && !id.startsWith('utang-error-'));
            if (ids.length) field.setAttribute('aria-describedby',ids.join(' ')); else field.removeAttribute('aria-describedby');
        });
    };
    const fieldError=(field,message)=> {
        if (!field || field.type==='hidden' || field.disabled) return;
        const details=field.closest('details'); if (details) details.open=true;
        const feedback=document.createElement('div'); feedback.id=`utang-error-${++errorNumber}`; feedback.className='invalid-feedback d-block utang-field-error'; feedback.textContent=message;
        field.insertAdjacentElement('afterend',feedback); field.classList.add('is-invalid'); field.setAttribute('aria-invalid','true'); field.setAttribute('aria-describedby',((field.getAttribute('aria-describedby')||'')+' '+feedback.id).trim());
    };
    const refreshCredits=async()=> {
        const response=await fetch(location.href,{credentials:'same-origin',cache:'no-store'}), html=new DOMParser().parseFromString(await response.text(),'text/html'), updated=html.getElementById('dashboard-content');
        if (!response.ok || !updated?.querySelector('.payments-directory')) throw new Error('The payment list could not refresh.');
        const scroll=window.scrollY; document.getElementById('dashboard-content').replaceWith(updated); initializePaymentFilters(updated); window.scrollTo({top:scroll,behavior:'instant'});
    };
    document.addEventListener('click',async event=> {
        const link=event.target.closest('a[data-utang-modal]');
        if (!link || event.button!==0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault(); if (busy) return; if (dirty) { confirmDiscard(()=>link.click()); return; }
        pendingLoad?.abort(); pendingLoad=new AbortController(); const currentLoad=pendingLoad; opener=link; dirty=false;
        dialog.classList.toggle('modal-lg',['details','history','payment'].includes(link.dataset.utangModal));
        content.innerHTML='<div class="modal-header"><h2 class="modal-title" id="utang-modal-title">Loading payment information</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body utang-modal-loading" role="status"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Loading…</div>';
        modal.show(); const url=new URL(link.href); url.searchParams.set('modal','1'); url.searchParams.set('action',link.dataset.utangModal);
        try {
            const response=await fetch(url,{credentials:'same-origin',cache:'no-store',signal:currentLoad.signal}), html=await response.text();
            if (currentLoad.signal.aborted) return;
            const fragment=new DOMParser().parseFromString(html,'text/html');
            if (response.redirected || !fragment.getElementById('utang-modal-title')) throw new Error('Your session may have expired. Sign in again, then reopen the payment.');
            content.replaceChildren(...fragment.body.childNodes); initializeUtangForm(content); focusContent();
        } catch (error) {
            if (error.name==='AbortError') return;
            content.innerHTML='<div class="modal-header"><h2 class="modal-title" id="utang-modal-title">Unable to open payment</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><div class="alert alert-danger" role="alert"></div></div><div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Close</button></div>';
            content.querySelector('.alert').textContent=error.message; focusContent();
        }
    });
    utangModalElement.addEventListener('input',event=> { if (event.target.closest('form')) dirty=true; });
    utangModalElement.addEventListener('change',event=> { if (event.target.closest('form')) dirty=true; });
    utangModalElement.addEventListener('click',event=> {
        if (event.target.closest('[data-keep-utang]')) { content.querySelector('.utang-discard-prompt').classList.add('d-none'); discardContinuation=null; focusContent(); }
        if (event.target.closest('[data-discard-utang]')) { const continuation=discardContinuation; discardContinuation=null; dirty=false; content.querySelector('.utang-discard-prompt').classList.add('d-none'); continuation?.(); }
    });
    utangModalElement.addEventListener('hide.bs.modal',event=> { if (busy) { event.preventDefault(); return; } if (dirty) { event.preventDefault(); confirmDiscard(()=>modal.hide()); return; } pendingLoad?.abort(); });
    utangModalElement.addEventListener('hidden.bs.modal',()=> { content.replaceChildren(); (opener?.isConnected?opener:document.querySelector('[data-utang-modal]'))?.focus({preventScroll:true}); });
    window.addEventListener('beforeunload',event=> { if (dirty || busy) { event.preventDefault(); event.returnValue=''; } });
    utangModalElement.addEventListener('submit',async event=> {
        const form=event.target.closest('form[data-utang-async]'); if (!form) return; event.preventDefault(); if (busy) return;
        clearFieldErrors(form); const invalid=[...form.elements].filter(field=>field.willValidate && !field.validity.valid);
        invalid.forEach(field=>fieldError(field,field.validationMessage)); if (invalid.length) { invalid[0].focus(); return; }
        form.querySelector('.utang-submit-error').classList.add('d-none');
        const payload=new FormData(form), controls=[...form.querySelectorAll('input,select,textarea,button')].map(element=>[element,element.disabled]), button=form.querySelector('button[type=submit]'), label=button.textContent;
        busy=true; form.setAttribute('aria-busy','true'); controls.forEach(([element])=>{element.disabled=true;}); content.querySelectorAll('[data-bs-dismiss]').forEach(element=>{element.disabled=true;});
        button.innerHTML='<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Saving…';
        try {
            const response=await fetch(form.action,{method:'POST',body:payload,credentials:'same-origin',headers:{Accept:'application/json'}});
            if (!response.headers.get('content-type')?.includes('application/json')) throw new Error('The server could not confirm the save. Keep this form open and retry, or sign in again if your session expired.');
            const result=await response.json();
            if (!response.ok || !result.success) {
                for (const [name,message] of Object.entries(result.fields||{})) { const field=form.elements.namedItem(name), state=controls.find(([element])=>element===field); if (state) field.disabled=state[1]; fieldError(field,message); }
                showError(form,result.message || 'Please check the entered details.'); return;
            }
            dirty=false; let refreshed=true; try { await refreshCredits(); } catch (_) { refreshed=false; }
            busy=false; modal.hide(); const feedback=document.getElementById('utang-feedback');
            feedback.className=`alert ${refreshed?'alert-success':'alert-warning'}`; feedback.textContent=result.message+(refreshed?'':' The change was saved, but the list could not refresh. Reload to see updated figures.'); feedback.focus({preventScroll:true});
        } catch (error) { showError(form,error.message+' Your entered information has been kept.'); }
        finally { busy=false; form.removeAttribute('aria-busy'); controls.forEach(([element,disabled])=>{element.disabled=disabled;}); content.querySelectorAll('[data-bs-dismiss]').forEach(element=>{element.disabled=false;}); button.textContent=label; }
    });
}
