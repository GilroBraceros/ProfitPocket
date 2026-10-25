<?php
require_once __DIR__.'/../includes/auth.php'; require_once __DIR__.'/../config/email.php';
$page_title='Contact support'; $support=''; try { $c=email_config(); $support=$c['BREVO_REPLY_TO_EMAIL']?:$c['BREVO_SENDER_EMAIL']; } catch (Throwable $e) {}
require __DIR__.'/../includes/header.php'; ?>
<main class="container py-5"><section class="card auth-card help-card mx-auto"><a class="brand mb-4" href="<?= e(app_url()) ?>">ProfitPocket</a><h1 class="h2">Contact support</h1><p>For account or security concerns, contact the ProfitPocket support team.</p><?php if (filter_var($support,FILTER_VALIDATE_EMAIL)): ?><a href="mailto:<?= e($support) ?>"><?= e($support) ?></a><?php else: ?><p class="text-secondary">Contact your ProfitPocket administrator for account assistance.</p><?php endif; ?><a class="btn btn-primary mt-4" href="<?= e(app_url('auth/login.php')) ?>">Return to sign in</a></section></main><?php require __DIR__.'/../includes/footer.php'; ?>
