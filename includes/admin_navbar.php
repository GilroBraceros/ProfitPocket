<a class="visually-hidden-focusable dashboard-skip" href="#dashboard-content">Skip to administration</a>
<aside class="offcanvas-lg offcanvas-start business-sidebar admin-sidebar" tabindex="-1" id="business-sidebar" aria-label="Administration navigation">
    <div class="offcanvas-header d-lg-none"><span>Administration</span><button class="btn-close" type="button" data-bs-dismiss="offcanvas" data-bs-target="#business-sidebar" aria-label="Close navigation"></button></div>
    <div class="sidebar-brand">
        <a href="<?= e(app_url('admin/dashboard.php')) ?>" aria-label="ProfitPocket administration">
            <img class="sidebar-logo-full" src="<?= e(app_url('assets/images/logo-full.jpg')) ?>" alt="ProfitPocket">
            <img class="sidebar-logo-icon" src="<?= e(app_url('assets/images/logo-icon.jpg')) ?>" alt="ProfitPocket">
        </a>
    </div>
    <nav class="business-navigation" aria-label="Administration">
        <?php
        $groups=[
            'Overview'=>['dashboard'=>'Dashboard'],
            'Management'=>['users'=>'Users','businesses'=>'Businesses'],
            'Support'=>['tickets'=>'Support Tickets','feedback'=>'Feedback'],
            'Platform'=>['announcements'=>'Announcements','logs'=>'Activity Logs','settings'=>'Settings'],
        ];
        foreach ($groups as $group=>$routes):
            $routes=array_filter($routes,fn($label,$route)=>admin_can($permissions,ADMIN_MODULES[$route][2]),ARRAY_FILTER_USE_BOTH);
            if (!$routes) { continue; }
        ?>
        <div class="admin-nav-group">
            <p class="sidebar-section-label"><?= e($group) ?></p>
            <?php foreach ($routes as $route=>$label): ?>
            <a class="sidebar-link <?= $module===$route?'active':'' ?>" href="<?= e(app_url('admin/'.$route.'.php')) ?>" <?= $module===$route?'aria-current="page"':'' ?> title="<?= e($label) ?>">
                <?= dashboard_icon(ADMIN_MODULES[$route][3]) ?><span class="sidebar-text"><?= e($label) ?></span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar-bottom">
        <a class="sidebar-link" href="<?= e(app_url('admin/settings.php#profile')) ?>" title="Administrator profile"><?= dashboard_icon('people') ?><span class="sidebar-text">Administrator profile</span></a>
        <form method="post" action="<?= e(app_url('auth/logout.php')) ?>">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <button class="sidebar-link sidebar-logout" type="submit" title="Logout"><?= dashboard_icon('box-arrow-left') ?><span class="sidebar-text">Logout</span></button>
        </form>
    </div>
</aside>
<header class="business-topbar admin-topbar">
    <div class="d-flex align-items-center gap-3 min-width-0">
        <button class="icon-button d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#business-sidebar" aria-controls="business-sidebar" aria-label="Open navigation"><?= dashboard_icon('list') ?></button>
        <button class="icon-button d-none d-lg-flex" id="sidebar-collapse" type="button" aria-expanded="true" aria-controls="business-sidebar" aria-label="Collapse navigation"><?= dashboard_icon('layout-sidebar') ?></button>
        <nav class="admin-breadcrumb" aria-label="Breadcrumb"><a href="<?= e(app_url('admin/dashboard.php')) ?>">Administration</a><?= dashboard_icon('chevron-down') ?><span aria-current="page"><?= e($page_title) ?></span></nav>
    </div>
    <div class="admin-account dropdown">
        <button class="profile-button" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Open administrator account menu">
            <span class="profile-initial"><?= e(mb_strtoupper(mb_substr($user['full_name'],0,1))) ?></span><span class="profile-name"><?= e($user['full_name']) ?></span><?= dashboard_icon('chevron-down') ?>
        </button>
        <div class="dropdown-menu dropdown-menu-end account-menu">
            <div class="px-3 py-2"><strong><?= e($user['full_name']) ?></strong><div class="small text-secondary"><?= e($user['email']) ?></div><div class="small text-secondary"><?= e($user['role_name']) ?></div></div>
            <hr class="dropdown-divider">
            <a class="dropdown-item" href="<?= e(app_url('admin/settings.php#profile')) ?>">My profile</a>
            <form method="post" action="<?= e(app_url('auth/logout.php')) ?>"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><button class="dropdown-item" type="submit">Logout</button></form>
        </div>
    </div>
</header>
