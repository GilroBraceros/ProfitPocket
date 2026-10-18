'use strict';
function initializePaymentMethods(root=document) {
    root.querySelectorAll('[data-payment-method-fields]').forEach(fields=>{
        if(fields.dataset.initialized) return;
        fields.dataset.initialized='true';
        const category=fields.querySelector('[name=payment_category]'), wallet=fields.querySelector('[name=wallet_method_id]'), provider=fields.querySelector('[name=provider_name]'), method=fields.querySelector('[name=payment_method_id]');
        let enabled=true;
        fields.updatePaymentMethod=(active=enabled)=>{
            enabled=active;
            const isWallet=category.value==='wallet', isBank=category.value==='bank', other=isWallet&&wallet.selectedOptions[0]?.dataset.code==='wallet_other';
            category.disabled=!enabled; category.required=enabled;
            method.disabled=!enabled; method.value=isWallet?wallet.value:category.selectedOptions[0]?.dataset.methodId||'';
            fields.querySelector('[data-wallet-field]').hidden=!isWallet;
            wallet.disabled=!enabled||!isWallet; wallet.required=enabled&&isWallet;
            fields.querySelector('[data-provider-field]').hidden=!(isBank||other);
            provider.disabled=!enabled||!(isBank||other); provider.required=enabled&&(isBank||other);
            fields.querySelector('[data-provider-label]').textContent=isBank?'Bank name':'Other wallet name';
            const form=fields.closest('form'), reference=form?.querySelector('[data-payment-reference]');
            if(reference) { reference.hidden=category.value==='cash'||!enabled; reference.querySelector('input').disabled=category.value==='cash'||!enabled; }
        };
        category.addEventListener('change',()=>fields.updatePaymentMethod());
        wallet.addEventListener('change',()=>fields.updatePaymentMethod());
        fields.updatePaymentMethod();
    });
}
initializePaymentMethods();
