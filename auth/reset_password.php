<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/passwords.php';
require_guest(); $page_title='Reset password'; $error=null;
header('Referrer-Policy: no-referrer'); header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        if (!valid_csrf()) { http_response_code(403); throw new InvalidArgumentException('This form expired. Reload and try again.'); }
        $db=db(); $db->beginTransaction();
        password_complete_reset($db,post_text('reset_token'),post_text('password',false),post_text('password_confirmation',false)); $db->commit();
        flash('Your password has been changed. Please sign in.'); redirect_to('auth/login.php');
    } catch (InvalidArgumentException $e) { $error=$e->getMessage(); }
    catch (Throwable $e) { $error='Password recovery is temporarily unavailable. Please try again.'; }
    if (isset($db) && $db->inTransaction()) { $db->rollBack(); }
}
require __DIR__.'/../includes/header.php'; ?>
<main class="container py-5"><section class="card auth-card help-card mx-auto"><a class="brand mb-4" href="<?= e(app_url()) ?>">ProfitPocket</a><h1 class="h2">Create a new password</h1><p class="text-secondary">Your private reset link expires after 30 minutes and can be used once.</p>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
<form method="post" action="<?= e(app_url('auth/reset_password.php')) ?>"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
<div class="mb-3"><label class="form-label" for="reset_token">Reset code</label><input class="form-control" id="reset_token" name="reset_token" type="password" autocomplete="off" minlength="64" maxlength="64" required><p class="form-text">Filled from your private email link. With JavaScript disabled, paste the code following “#token=” in that link.</p></div>
<div class="mb-3"><label class="form-label" for="password">New password</label><input class="form-control" id="password" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="72" required></div><div class="mb-3"><label class="form-label" for="password_confirmation">Confirm new password</label><input class="form-control" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" maxlength="72" required></div><button class="btn btn-primary w-100" type="submit">Save new password</button></form><a class="mt-3" href="<?= e(app_url('auth/forgot_password.php')) ?>">Request another link</a></section></main>
<script src="<?= e(app_url('assets/js/reset-password.js')) ?>" defer></script><?php require __DIR__.'/../includes/footer.php'; ?>
