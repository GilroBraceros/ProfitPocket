<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/passwords.php';
require_guest(); $page_title='Reset password'; $submitted=false; $error=null;
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!valid_csrf()) { http_response_code(403); $error='This form expired. Reload and try again.'; }
    else {
        try { password_request_reset(db(),post_text('email')); } catch (Throwable $e) { error_log('ProfitPocket email: reset_request_unavailable'); }
        $submitted=true;
    }
}
require __DIR__.'/../includes/header.php'; ?>
<main class="container py-5"><section class="card auth-card help-card mx-auto"><a class="brand mb-4" href="<?= e(app_url()) ?>">ProfitPocket</a><h1 class="h2">Forgot your password?</h1><p class="text-secondary">Enter your account email to request a private, single-use reset link.</p>
<?php if ($submitted): ?><div class="alert alert-success" role="status">If an eligible account matches and email recovery is configured, you will receive reset instructions. Check your spam folder or contact support if needed.</div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
<form method="post" action="<?= e(app_url('auth/forgot_password.php')) ?>"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><label class="form-label" for="email">Account email</label><input class="form-control mb-3" type="email" id="email" name="email" maxlength="191" autocomplete="email" required><button class="btn btn-primary w-100" type="submit">Request reset link</button></form><a class="mt-3" href="<?= e(app_url('auth/login.php')) ?>">Return to sign in</a><a class="mt-2" href="<?= e(app_url('auth/contact_support.php')) ?>">Contact support</a></section></main><?php require __DIR__.'/../includes/footer.php'; ?>
