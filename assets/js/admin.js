'use strict';
const adminModalElement=document.getElementById('admin-action-modal');
if(adminModalElement && window.bootstrap){
    const modal=bootstrap.Modal.getOrCreateInstance(adminModalElement),content=adminModalElement.querySelector('.modal-content');
    let opener=null,controller=null,dirty=false,saving=false;
    const showMessage=text=>{
        content.innerHTML='<div class="modal-header"><h2 class="modal-title" id="admin-modal-title">Administration</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body" role="status"></div>';
        content.querySelector('.modal-body').textContent=text;
    };
    document.addEventListener('click',async event=>{
        const link=event.target.closest('a[data-admin-modal]');if(!link || event.button!==0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey)return;
        event.preventDefault();if(saving || (dirty && !window.confirm('Discard the unsaved changes?')))return;if(!adminModalElement.contains(link))opener=link;controller?.abort();const request=new AbortController();controller=request;dirty=false;showMessage('Loading…');content.setAttribute('aria-busy','true');modal.show();
        try{
            const url=new URL(link.href,location.href);url.searchParams.set('modal','1');
            adminModalElement.classList.toggle('admin-ticket-modal',url.searchParams.get('action')?.startsWith('ticket_'));
            const response=await fetch(url,{signal:request.signal,cache:'no-store'});if(response.redirected)throw new Error('Your session ended. Reload and sign in.');
            const html=await response.text();if(request.signal.aborted)return;
            if(!response.ok && !html.includes('admin-modal-title'))throw new Error('This action is unavailable or is not permitted.');
            content.innerHTML=html;content.querySelector('.btn-close')?.focus();
            const form=content.querySelector('[data-admin-async]');
            form?.addEventListener('input',()=>{dirty=true;});
            form?.addEventListener('submit',async event=>{
                event.preventDefault();if(saving || !form.reportValidity())return;
                const body=new FormData(form),error=form.querySelector('.admin-error'),controls=[...form.querySelectorAll('input,select,textarea,button')].map(control=>[control,control.disabled]);saving=true;error.classList.add('d-none');form.setAttribute('aria-busy','true');
                const submit=form.querySelector('button[type=submit]'),label=submit.textContent;submit.textContent='Saving…';controls.forEach(([control])=>control.disabled=true);
                try{
                    const response=await fetch(form.action,{method:'POST',body,headers:{'Accept':'application/json'},cache:'no-store'});
                    if(response.redirected)throw new Error('Your session ended. Reload and sign in.');
                    const result=await response.json();if(!response.ok || !result.success)throw new Error(result.message || 'The change could not be saved.');
                    dirty=false;location.assign(result.logout?result.redirect:location.href);
                }catch(failure){error.textContent=failure.message;error.classList.remove('d-none');error.focus();}
                finally{saving=false;form.removeAttribute('aria-busy');submit.textContent=label;controls.forEach(([control,disabled])=>control.disabled=disabled);}
            });
        }catch(error){if(error.name!=='AbortError')showMessage(error.message);}
        finally{if(controller===request)content.removeAttribute('aria-busy');}
    });
    adminModalElement.addEventListener('hide.bs.modal',event=>{if(saving || (dirty && !window.confirm('Discard the unsaved changes?'))){event.preventDefault();return;}controller?.abort();});
    adminModalElement.addEventListener('hidden.bs.modal',()=>{dirty=false;if(opener?.isConnected)opener.focus({preventScroll:true});});
}

// Settings uses ordinary anchors; highlight the selected section without hiding it.
const adminSettingsNav=document.querySelector('[data-admin-settings-nav]');
if(adminSettingsNav){
    const updateSection=()=>{
        const target=location.hash || '#profile';
        adminSettingsNav.querySelectorAll('a').forEach(link=>{
            if(link.getAttribute('href')===target)link.setAttribute('aria-current','location');
            else link.removeAttribute('aria-current');
        });
    };
    updateSection();
    window.addEventListener('hashchange',updateSection);
}
