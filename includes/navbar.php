<?php if (!empty($dashboard_layout)): ?>
<a class="visually-hidden-focusable dashboard-skip" href="#dashboard-content">Skip to dashboard</a>
<aside class="offcanvas-lg offcanvas-start business-sidebar" tabindex="-1" id="business-sidebar" aria-labelledby="sidebar-label">
    <div class="offcanvas-header d-lg-none"><span id="sidebar-label" class="fw-semibold">Business navigation</span><button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#business-sidebar" aria-label="Close navigation"></button></div>
    <div class="sidebar-brand"><a href="<?= e(app_url('user/dashboard.php')) ?>" aria-label="ProfitPocket dashboard">
        <img class="sidebar-logo-full" src="<?= e(app_url('assets/images/logo-full.jpg')) ?>" alt="ProfitPocket" width="2752" height="1536">
        <img class="sidebar-logo-icon" src="<?= e(app_url('assets/images/logo-icon.jpg')) ?>" alt="ProfitPocket" width="2048" height="2048">
    </a></div>
    <nav class="business-navigation" aria-label="Business navigation">
        <p class="sidebar-section-label">WORKSPACE</p>
        <?php $current_navigation = $active_page ?? 'dashboard'; ?>
        <?php foreach (['dashboard' => ['Dashboard','grid-1x2'], 'sales' => ['Sales','receipt'], 'expenses'=>['Expenses','arrow-up-right-square'], 'customers'=>['Customers','people'], 'payments'=>['Payment Management','journal-text'], 'products'=>['Products','box-seam'], 'reports'=>['Reports','bar-chart']] as $route => [$label,$icon]): ?>
        <a class="sidebar-link <?= $current_navigation === $route ? 'active' : '' ?>" href="<?= e(app_url('user/' . $route . '.php')) ?>" <?= $current_navigation === $route ? 'aria-current="page"' : '' ?> title="<?= e($label) ?>"><?= dashboard_icon($icon) ?><span class="sidebar-text"><?= e($label) ?></span></a>
        <?php endforeach; ?>
        <div class="sidebar-divider"></div>
        <a class="sidebar-link <?= $current_navigation==='support'?'active':'' ?>" href="<?= e(app_url('user/support.php')) ?>" <?= $current_navigation==='support'?'aria-current="page"':'' ?> title="Help & Support"><?= dashboard_icon('journal-text') ?><span class="sidebar-text">Help & Support</span></a>
        <span class="sidebar-link upcoming" aria-disabled="true" title="Settings — upcoming"><?= dashboard_icon('gear') ?><span class="sidebar-text">Settings</span><span class="sidebar-upcoming">Soon</span></span>
    </nav>
    <div class="sidebar-bottom"><form action="<?= e(app_url('auth/logout.php')) ?>" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><button class="sidebar-link sidebar-logout" type="submit" title="Sign out"><?= dashboard_icon('box-arrow-left') ?><span class="sidebar-text">Sign out</span></button></form></div>
</aside>
<header class="business-topbar">
    <div class="d-flex align-items-center gap-2 min-width-0">
        <button class="icon-button d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#business-sidebar" aria-controls="business-sidebar" aria-label="Open navigation"><?= dashboard_icon('list') ?></button>
        <img class="mobile-brand d-lg-none" src="<?= e(app_url('assets/images/logo-icon.jpg')) ?>" alt="ProfitPocket" width="2048" height="2048">
        <button class="icon-button d-none d-lg-flex" type="button" id="sidebar-collapse" aria-controls="business-sidebar" aria-expanded="true" aria-label="Collapse navigation"><?= dashboard_icon('layout-sidebar') ?></button>
        <span class="topbar-title"><?= e($page_title) ?></span><span class="topbar-separator"></span><span class="topbar-business"><?= e($user['business_name'] ?? 'No business linked') ?></span>
    </div>
    <div class="dropdown"><button type="button" class="profile-button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Open account menu for <?= e($user['full_name']) ?>"><span class="profile-initial"><?= e(mb_strtoupper(mb_substr($user['full_name'], 0, 1))) ?></span><span class="profile-name"><?= e($user['full_name']) ?></span><?= dashboard_icon('chevron-down') ?></button>
        <div class="dropdown-menu dropdown-menu-end account-menu"><div class="px-3 py-2"><strong><?= e($user['full_name']) ?></strong><div class="small text-secondary"><?= e($user['email']) ?></div><div class="small text-secondary">Business owner · <?= e($user['username']) ?></div></div><hr class="dropdown-divider"><form action="<?= e(app_url('auth/logout.php')) ?>" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><button class="dropdown-item" type="submit"><?= dashboard_icon('box-arrow-left') ?> Sign out</button></form></div>
    </div>
</header>
<?php else: ?>
<nav class="navbar navbar-dark app-navbar">
    <div class="container gap-3">
        <a class="navbar-brand fw-bold" href="<?= e(app_url(home_path($user))) ?>">ProfitPocket</a>
        <div class="d-flex align-items-center flex-wrap gap-3">
            <span class="text-white small"><?= e($user['full_name']) ?></span>
            <form action="<?= e(app_url('auth/logout.php')) ?>" method="post" class="m-0">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <button class="btn btn-outline-light btn-sm" type="submit">Sign out</button>
            </form>
        </div>
    </div>
</nav>
<?php endif; ?>
