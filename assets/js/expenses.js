'use strict';
function initializeExpenseForm(root) {
    const file = root.querySelector('input[type=file]');
    if (file) file.addEventListener('change', () => {
        const receipt=file.files[0];
        file.setCustomValidity(receipt && (receipt.size>2097152 || !['image/jpeg','image/png','image/webp'].includes(receipt.type)) ? 'Choose a JPG, PNG or WebP image up to 2 MB.' : '');
    });
    const end=root.querySelector('#end_date'), start=root.querySelector('#start_date');
    if (end && start) {
        const update=()=>{end.min=start.value;}; start.addEventListener('change',update); update();
    }
}
// One shared Bootstrap modal keeps focus, state and confirmation dialogs simple.
const expenseModalElement = document.getElementById('expense-action-modal');
if (expenseModalElement && typeof bootstrap !== 'undefined') {
    const modal = bootstrap.Modal.getOrCreateInstance(expenseModalElement);
    const dialog = expenseModalElement.querySelector('.modal-dialog');
    const content = expenseModalElement.querySelector('.modal-content');
    let pendingLoad = null;
    let busy = false;
    let dirty = false;
    let opener = null;
    let feedbackNumber = 0;

    const focusContent = () => {
        const target = content.querySelector('input:not([type=hidden]):not([disabled]), textarea:not([disabled]), select:not([disabled])') || content.querySelector('.modal-title');
        if (target) { if (target.matches('.modal-title')) target.tabIndex = -1; target.focus({preventScroll:true}); }
    };
    const showFeedback = (message, success) => {
        const feedback = document.getElementById('expense-feedback');
        feedback.className = `alert ${success ? 'alert-success' : 'alert-warning'}`;
        feedback.textContent = message;
        feedback.focus({preventScroll:true});
    };
    const showError = (form, message) => {
        const box = form.querySelector('.expense-submit-error');
        box.textContent = message;
        box.classList.remove('d-none');
        box.focus();
    };
    const fieldError = (field, message) => {
        if (!field || field.type === 'hidden' || field.disabled) return;
        const details = field.closest('details');
        if (details) details.open = true;
        for (const id of (field.getAttribute('aria-describedby')||'').split(' ')) {
            if (id.startsWith('expense-field-error-')) document.getElementById(id)?.remove();
        }
        const feedback = document.createElement('div');
        feedback.id = `expense-field-error-${++feedbackNumber}`;
        feedback.className = 'invalid-feedback d-block expense-field-error';
        feedback.textContent = message;
        field.insertAdjacentElement('afterend', feedback);
        field.classList.add('is-invalid');
        field.setAttribute('aria-invalid', 'true');
        const descriptions=(field.getAttribute('aria-describedby')||'').split(' ').filter(id=>id && !id.startsWith('expense-field-error-'));
        field.setAttribute('aria-describedby', [...descriptions, feedback.id].join(' '));
    };
    const clearFieldErrors = (form) => {
        form.querySelectorAll('.expense-field-error').forEach(element => element.remove());
        form.querySelectorAll('.is-invalid').forEach(field => {
            field.classList.remove('is-invalid'); field.removeAttribute('aria-invalid');
            const descriptions=(field.getAttribute('aria-describedby')||'').split(' ').filter(id=>id && !id.startsWith('expense-field-error-'));
            if (descriptions.length) field.setAttribute('aria-describedby', descriptions.join(' ')); else field.removeAttribute('aria-describedby');
        });
    };
    const refreshExpenses = async () => {
        const response = await fetch(location.href, {credentials:'same-origin',cache:'no-store'});
        const html = new DOMParser().parseFromString(await response.text(), 'text/html');
        const updated = html.getElementById('dashboard-content');
        if (!response.ok || !updated?.querySelector('.expense-history')) throw new Error('The expense list could not be refreshed.');
        const scroll = window.scrollY;
        // Replace only the list region; keep the page's modal and navigation.
        document.getElementById('dashboard-content').replaceWith(updated);
        const period = updated.querySelector('#period');
        const dates = updated.querySelector('#custom-dates');
        const updateDates = () => {
            const custom = period.value === 'custom'; dates.hidden = !custom;
            dates.querySelectorAll('input').forEach(field=>{field.disabled=!custom;field.required=custom;});
        };
        period.addEventListener('change',updateDates); updateDates();
        window.scrollTo({top:scroll,behavior:'instant'});
        return updated;
    };
    document.addEventListener('click', async (event) => {
        const link = event.target.closest('a[data-expense-modal]');
        if (!link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        if (busy || (dirty && !window.confirm('Discard your unsaved changes?'))) return;
        pendingLoad?.abort();
        pendingLoad = new AbortController();
        const currentLoad = pendingLoad;
        opener = link; dirty = false;
        dialog.classList.remove('modal-xl');
        dialog.classList.toggle('modal-lg', ['create','edit','details','schedule','confirm'].includes(link.dataset.expenseModal));
        dialog.classList.toggle('modal-fullscreen-sm-down', !['void','toggle','category'].includes(link.dataset.expenseModal));
        content.innerHTML = '<div class="modal-header"><h2 class="modal-title" id="expense-modal-title">Loading expense</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body expense-modal-loading" role="status"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Loading…</div>';
        modal.show();
        const url = new URL(link.href); url.hash = ''; url.searchParams.set('modal','1');
        url.searchParams.set('action',link.dataset.expenseModal);
        try {
            const response = await fetch(url,{credentials:'same-origin',cache:'no-store',signal:currentLoad.signal});
            const html = await response.text();
            if (currentLoad.signal.aborted) return;
            const fragment = new DOMParser().parseFromString(html,'text/html');
            if (response.redirected || !fragment.getElementById('expense-modal-title')) throw new Error('Your session may have expired. Sign in again, then reopen the expense.');
            content.replaceChildren(...fragment.body.childNodes);
            initializeExpenseForm(content);
            focusContent();
        } catch (error) {
            if (error.name === 'AbortError') return;
            content.innerHTML = '<div class="modal-header"><h2 class="modal-title" id="expense-modal-title">Unable to open expense</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><div class="alert alert-danger" role="alert"></div></div><div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Close</button></div>';
            content.querySelector('.alert').textContent = error.message;
            focusContent();
        }
    });
    expenseModalElement.addEventListener('input', event => {
        if (event.target.closest('form')) dirty = true;
        const field = event.target;
        if (field.classList.contains('is-invalid')) {
            const descriptions=(field.getAttribute('aria-describedby')||'').split(' ').filter(id=>{
                if (id.startsWith('expense-field-error-')) { document.getElementById(id)?.remove(); return false; }
                return !!id;
            });
            field.classList.remove('is-invalid'); field.removeAttribute('aria-invalid');
            if (descriptions.length) field.setAttribute('aria-describedby',descriptions.join(' ')); else field.removeAttribute('aria-describedby');
        }
    });
    expenseModalElement.addEventListener('change', event => { if (event.target.closest('form')) dirty = true; });
    expenseModalElement.addEventListener('hide.bs.modal', event => {
        if (busy || (dirty && !window.confirm('Discard your unsaved changes?'))) { event.preventDefault(); return; }
        pendingLoad?.abort(); dirty = false;
    });
    expenseModalElement.addEventListener('hidden.bs.modal', () => {
        content.replaceChildren();
        const target = opener?.isConnected ? opener : document.querySelector('[data-expense-modal="create"]');
        target?.focus({preventScroll:true});
    });
    window.addEventListener('beforeunload', event => {
        if (dirty || busy) { event.preventDefault(); event.returnValue = ''; }
    });
    expenseModalElement.addEventListener('submit', async event => {
        const form = event.target.closest('form[data-expense-async]');
        if (!form) return;
        event.preventDefault();
        if (busy) return;
        clearFieldErrors(form);
        const invalid = [...form.elements].filter(field=>field.willValidate && !field.validity.valid);
        invalid.forEach(field => fieldError(field,field.validationMessage));
        if (invalid.length) {
            invalid[0].focus();
            return;
        }
        form.querySelector('.expense-submit-error').classList.add('d-none');
        const payload = new FormData(form);
        const controls = [...form.querySelectorAll('input,select,textarea,button')].map(element=>[element,element.disabled]);
        const button = form.querySelector('button[type=submit]');
        const label = button.dataset.submitLabel || button.textContent;
        busy = true; form.setAttribute('aria-busy','true');
        controls.forEach(([element])=>{element.disabled=true;});
        content.querySelectorAll('[data-bs-dismiss]').forEach(element=>{element.disabled=true;});
        button.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Saving…';
        try {
            const response = await fetch(form.action,{method:'POST',body:payload,credentials:'same-origin',headers:{Accept:'application/json'}});
            if (!response.headers.get('content-type')?.includes('application/json')) throw new Error('The server could not confirm the save. Keep this form open and retry, or sign in again if your session expired.');
            const result = await response.json();
            if (!response.ok || !result.success) {
                for (const [name,message] of Object.entries(result.fields||{})) {
                    // Controls were disabled only for submission; restore before marking errors.
                    const field = form.elements.namedItem(name);
                    const state = controls.find(([element])=>element===field);
                    if (state) field.disabled=state[1];
                    fieldError(field,message);
                }
                showError(form,result.message || 'Please check the expense details.');
                return;
            }
            dirty = false;
            let refreshed = true;
            try { await refreshExpenses(); } catch (_) { refreshed = false; }
            busy = false; modal.hide();
            showFeedback(result.message + (refreshed ? '' : ' The expense was saved, but the list could not refresh. Reload the page to see updated figures.'),refreshed);
        } catch (error) {
            showError(form,error.message + ' Your entered information has been kept.');
        } finally {
            busy = false; form.removeAttribute('aria-busy');
            controls.forEach(([element,disabled])=>{element.disabled=disabled;});
            content.querySelectorAll('[data-bs-dismiss]').forEach(element=>{element.disabled=false;});
            button.textContent = label;
        }
    });
    if (new URLSearchParams(location.search).get('new') === '1') {
        document.querySelector('a[data-expense-modal="create"]')?.click();
    }
}
