'use strict';
document.querySelectorAll('[data-payment-picker]').forEach(form=>{
    const select=form.querySelector('[name=method_id]'),panels=[...form.querySelectorAll('[data-payment-method]')],empty=form.querySelector('[data-payment-empty]');
    const update=()=>{panels.forEach(panel=>{panel.hidden=panel.dataset.paymentMethod!==select.value;});if(empty){empty.hidden=!!select.value;}};
    select.addEventListener('change',update);update();
    form.querySelectorAll('[data-copy-account]').forEach(button=>{button.hidden=false;button.addEventListener('click',async()=>{const input=document.getElementById(button.dataset.copyAccount),status=button.closest('[data-payment-method]').querySelector('[data-copy-status]');try{await navigator.clipboard.writeText(input.value);status.textContent='Account number copied.';}catch{input.focus();input.select();status.textContent='Select and copy this account number manually.';}});});
});
document.querySelectorAll('[data-method-editor]').forEach(form=>{
    const category=form.querySelector('[name=method_category]');
    const update=()=>{[['[data-method-bank]','bank'],['[data-method-other]','other']].forEach(([selector,value])=>{const block=form.querySelector(selector),input=block.querySelector('input');block.hidden=category.value!==value;input.required=category.value===value;input.disabled=category.value!==value;});};
    category.addEventListener('change',update);update();
});
function paymentFilePreview(input,options){
    let url=null;const error=input.closest('form').querySelector(options.error),image=input.closest('form').querySelector(options.image),container=options.container?input.closest('form').querySelector(options.container):null,pdf=options.pdf?input.closest('form').querySelector(options.pdf):null,name=options.name?input.closest('form').querySelector(options.name):null;
    const original=image?.getAttribute('src')||'';
    const clear=()=>{if(url){URL.revokeObjectURL(url);url=null;}if(image){image.hidden=true;image.removeAttribute('src');}if(pdf){pdf.hidden=true;pdf.removeAttribute('href');}if(container){container.hidden=true;}};
    input.addEventListener('change',()=>{clear();error.textContent='';input.setCustomValidity('');const file=input.files[0];if(!file){if(original&&image){image.src=original;image.hidden=false;}return;}
        if(file.size>options.max||!options.types.includes(file.type)){error.textContent=options.message;input.setCustomValidity(options.message);return;}
        url=URL.createObjectURL(file);if(container){container.hidden=false;}if(name){name.textContent=file.name+' · '+Math.ceil(file.size/1024)+' KB';}
        if(file.type==='application/pdf'&&pdf){pdf.href=url;pdf.hidden=false;}else if(image){image.src=url;image.hidden=false;}
        const remove=input.closest('form').querySelector('[data-remove-qr]');if(remove){remove.checked=false;}
        const caption=input.closest('form').querySelector('[data-qr-caption]');if(caption){caption.textContent='New QR preview — save to apply';}
    });
    const remove=input.closest('form').querySelector('[data-remove-qr]');if(remove){remove.addEventListener('change',()=>{if(remove.checked){input.value='';input.setCustomValidity('');clear();error.textContent='';}else if(original&&image){image.src=original;image.hidden=false;}});}
    window.addEventListener('pagehide',()=>{if(url){URL.revokeObjectURL(url);}});
}
document.querySelectorAll('[data-proof-input]').forEach(input=>paymentFilePreview(input,{max:5242880,types:['image/jpeg','image/png','application/pdf'],message:'Choose a JPG, PNG or PDF up to 5 MB.',error:'[data-proof-error]',image:'[data-proof-image]',container:'[data-proof-preview]',pdf:'[data-proof-pdf]',name:'[data-proof-name]'}));
document.querySelectorAll('[data-qr-input]').forEach(input=>paymentFilePreview(input,{max:2097152,types:['image/jpeg','image/png','image/webp'],message:'Choose a JPG, PNG or WebP QR image up to 2 MB.',error:'[data-qr-error]',image:'[data-qr-preview]'}));
document.querySelectorAll('[data-subscription-form]').forEach(form=>{
    let submitted=false;
    form.addEventListener('submit',event=>{
        if(submitted){event.preventDefault();return;}
        if(!form.checkValidity()){return;}
        submitted=true;
        if(event.submitter?.name){
            const choice=document.createElement('input');
            choice.type='hidden';choice.name=event.submitter.name;choice.value=event.submitter.value;form.append(choice);
        }
        form.querySelectorAll('button[type="submit"]').forEach(button=>{button.disabled=true;button.textContent='Submitting…';});
        form.setAttribute('aria-busy','true');
        const status=form.querySelector('[data-submit-status]');
        if(status){status.textContent='Please wait while your submission is saved.';}
    });
});
