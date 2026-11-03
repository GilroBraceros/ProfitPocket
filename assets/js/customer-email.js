'use strict';
document.addEventListener('click',async event=>{
    const button=event.target.closest('[data-email-preview]');if(!button)return;
    const form=button.closest('form'),status=form.querySelector('[data-email-preview-status]');
    const payload=new FormData(form);payload.set('intent','preview');
    button.disabled=true;status.textContent='Updating preview…';
    try {
        const response=await fetch(form.action,{method:'POST',body:payload,credentials:'same-origin',headers:{Accept:'application/json'}});
        const result=await response.json();if(!response.ok||!result.success)throw new Error(result.message||'Preview unavailable.');
        form.querySelector('iframe').srcdoc=result.html;status.textContent='Preview updated. No email was sent.';
    }catch(error){status.textContent=error.message;}finally{button.disabled=false;}
});
