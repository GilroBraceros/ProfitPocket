'use strict';
// Menus live outside scrolling tables while open; Bootstrap still positions them.
const recordActionMenus = new Map();
const recordActionFocus = new Map();
document.addEventListener('click', event => {
    const link=event.target.closest('.record-actions-menu a');
    if (!link) return;
    const menu=link.closest('.record-actions-menu');
    const toggle=recordActionMenus.get(menu)?.toggle || menu.parentElement.querySelector('.record-action-more');
    const id=link.hasAttribute('data-admin-modal')?'admin-action-modal':link.hasAttribute('data-sale-modal')?'sales-action-modal':link.hasAttribute('data-expense-modal')?'expense-action-modal':link.hasAttribute('data-utang-modal')?'utang-action-modal':link.hasAttribute('data-product-modal')?'product-action-modal':'customer-action-modal';
    if (toggle) recordActionFocus.set(id,toggle);
},true);
document.addEventListener('hidden.bs.modal', event => {
    const toggle=recordActionFocus.get(event.target.id);
    recordActionFocus.delete(event.target.id);
    if (toggle?.isConnected) toggle.focus({preventScroll:true});
});
document.addEventListener('show.bs.dropdown', event => {
    const toggle=event.target;
    if (!toggle.matches('.record-action-more')) return;
    const menu=toggle.parentElement.querySelector('.record-actions-menu');
    const marker=document.createComment('record action menu');
    menu.before(marker);
    recordActionMenus.set(menu,{toggle,marker});
    document.body.append(menu);
    menu.classList.add('record-actions-menu-floating');
});
document.addEventListener('hidden.bs.dropdown', event => {
    for (const [menu,state] of recordActionMenus) {
        if (state.toggle!==event.target) continue;
        state.marker.replaceWith(menu);
        menu.classList.remove('record-actions-menu-floating');
        recordActionMenus.delete(menu);
    }
});
// Bootstrap's delegated keyboard lookup expects a sibling toggle. Restore that
// behavior explicitly for portaled menus, including Escape focus restoration.
document.addEventListener('keydown', event => {
    const menu=event.target.closest('.record-actions-menu-floating');
    const state=recordActionMenus.get(menu);
    if (!state) return;
    if (event.key==='Tab') { bootstrap.Dropdown.getInstance(state.toggle)?.hide(); state.toggle.focus(); return; }
    if (!['ArrowUp','ArrowDown','Home','End','Escape'].includes(event.key)) return;
    event.preventDefault();event.stopImmediatePropagation();
    if (event.key==='Escape') { bootstrap.Dropdown.getInstance(state.toggle)?.hide();state.toggle.focus();return; }
    const items=[...menu.querySelectorAll('.dropdown-item:not(.disabled)')];
    const index=items.indexOf(document.activeElement);
    const next=event.key==='Home'?0:event.key==='End'?items.length-1:event.key==='ArrowDown'?Math.min(index+1,items.length-1):Math.max(index-1,0);
    items[next]?.focus();
},true);
