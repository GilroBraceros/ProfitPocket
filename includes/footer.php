<?php if (empty($hide_default_footer)): ?><footer class="<?= !empty($dashboard_layout) ? 'dashboard-footer' : 'container py-4 text-center' ?> small text-secondary">ProfitPocket<?= !empty($dashboard_layout) ? ' · ' . e($page_title ?? 'Business overview') : ' &middot; Made for your everyday business' ?></footer><?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<?php if (!empty($dashboard_layout) && in_array($active_page ?? 'dashboard',['dashboard','reports'],true)): ?><script src="<?= e(app_url('assets/js/chart.umd.min.js')) ?>"></script><?php endif; ?>
<script src="<?= e(app_url('assets/js/script.js')) ?>" defer></script>
<?php if (!empty($dashboard_layout) && in_array($active_page??'dashboard',['dashboard','support'],true)): ?><script src="<?= e(app_url('assets/js/support.js')) ?>" defer></script><?php endif; ?>
<?php if (in_array($active_page??'',['sales','expenses','customers','utang','payments','products','admin'],true)): ?><script src="<?= e(app_url('assets/js/record-actions.js')) ?>" defer></script><?php endif; ?>
<?php if (in_array($active_page??'',['sales','utang','payments'],true)): ?><script src="<?= e(app_url('assets/js/payment-methods.js')) ?>" defer></script><?php endif; ?>
<?php if (($active_page ?? '') === 'admin'): ?><script src="<?= e(app_url('assets/js/admin.js')) ?>" defer></script><?php endif; ?>
<?php if (($active_page ?? '') === 'sales'): ?><script src="<?= e(app_url('assets/js/sales.js')) ?>" defer></script><?php endif; ?>
<?php if (($active_page ?? '') === 'expenses'): ?><script src="<?= e(app_url('assets/js/expenses.js')) ?>" defer></script><?php endif; ?>
<?php if (($active_page ?? '') === 'customers'): ?><script src="<?= e(app_url('assets/js/customers.js')) ?>" defer></script><?php endif; ?>
<?php if (in_array($active_page??'',['utang','payments'],true)): ?><script src="<?= e(app_url('assets/js/utang.js')) ?>" defer></script><?php endif; ?>
<?php if (($active_page ?? '') === 'products'): ?><script src="<?= e(app_url('assets/js/products.js')) ?>" defer></script><?php endif; ?>
<?php if (($active_page ?? '') === 'reports'): ?><script src="<?= e(app_url('assets/js/reports.js')) ?>" defer></script><?php endif; ?>
</body>
</html>
