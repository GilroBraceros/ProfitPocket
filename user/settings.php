<?php
require_once __DIR__.'/../includes/subscriptions.php';
$user=require_role('owner'); $page_title='Account settings'; $active_page='subscription';
require __DIR__.'/../includes/header.php'; ?>
<main class="subscription-shell"><?php require __DIR__.'/../includes/subscription_navigation.php'; ?><div class="subscription-heading"><h1>Account settings</h1><p>Manage your account security, even while your subscription is pending or expired.</p></div><section class="subscription-card"><h2>Your account</h2><dl class="subscription-facts"><div><dt>Name</dt><dd><?= e($user['full_name']) ?></dd></div><div><dt>Email</dt><dd><?= e($user['email']) ?></dd></div><div><dt>Business</dt><dd><?= e($user['business_name']??'No linked business') ?></dd></div></dl><a class="btn btn-primary mt-4" href="<?= e(app_url('auth/change_password.php')) ?>">Change password</a></section></main><?php require __DIR__.'/../includes/footer.php'; ?>
