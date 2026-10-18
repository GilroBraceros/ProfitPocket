'use strict';
const supportElement=document.getElementById('support-modal');
if(supportElement && window.bootstrap){
    const modal=bootstrap.Modal.getOrCreateInstance(supportElement),content=supportElement.querySelector('.modal-content');
    let opener=null,controller=null,dirty=false,saving=false;
    const message=text=>{
        content.innerHTML='<div class="modal-header"><h2 class="modal-title" id="support-modal-title">Help & Support</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body" role="status"></div>';
        content.querySelector('.modal-body').textContent=text;
    };
    document.addEventListener('click',async event=>{
        const link=event.target.closest('a[data-support-modal]');
        if(!link || event.button!==0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey)return;
        event.preventDefault();
        if(saving || (dirty && !window.confirm('Discard the unsaved changes?')))return;
        if(!supportElement.contains(link))opener=link;
        controller?.abort();const request=new AbortController();controller=request;dirty=false;
        message('Loading…');content.setAttribute('aria-busy','true');modal.show();
        try{
            const url=new URL(link.href,location.href);url.searchParams.set('modal','1');
            const response=await fetch(url,{signal:request.signal,cache:'no-store'});
            if(response.redirected)throw new Error('Your session ended. Reload and sign in.');
            const html=await response.text();if(request.signal.aborted)return;
            if(!response.ok && !html.includes('support-modal-title'))throw new Error('This item is unavailable or is not permitted.');
            content.innerHTML=html;content.querySelector('.btn-close')?.focus();
            const form=content.querySelector('[data-support-async]');
            form?.addEventListener('input',()=>{dirty=true;});
            form?.addEventListener('submit',async event=>{
                event.preventDefault();if(saving || !form.reportValidity())return;
                const body=new FormData(form),error=form.querySelector('.admin-error');
                const controls=[...form.querySelectorAll('input,select,textarea,button')].map(control=>[control,control.disabled]);
                const submit=form.querySelector('button[type=submit]'),label=submit.textContent;
                saving=true;error.classList.add('d-none');form.setAttribute('aria-busy','true');
                controls.forEach(([control])=>control.disabled=true);submit.textContent='Saving…';
                try{
                    const response=await fetch(form.action,{method:'POST',body,cache:'no-store'});
                    if(response.redirected)throw new Error('Your session ended. Reload and sign in.');
                    const result=await response.json();
                    if(!response.ok || !result.success)throw new Error(result.message || 'Your message could not be saved.');
                    dirty=false;location.assign(result.redirect);
                }catch(failure){error.textContent=failure.message;error.classList.remove('d-none');error.focus();}
                finally{saving=false;form.removeAttribute('aria-busy');submit.textContent=label;controls.forEach(([control,disabled])=>control.disabled=disabled);}
            });
        }catch(failure){if(failure.name!=='AbortError')message(failure.message);}
        finally{if(controller===request)content.removeAttribute('aria-busy');}
    });
    supportElement.addEventListener('hide.bs.modal',event=>{
        if(saving || (dirty && !window.confirm('Discard the unsaved changes?'))){event.preventDefault();return;}
        controller?.abort();
    });
    supportElement.addEventListener('hidden.bs.modal',()=>{dirty=false;if(opener?.isConnected)opener.focus({preventScroll:true});});
}
