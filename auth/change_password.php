<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/passwords.php';
$user=current_user(); if (!$user) { redirect_to('auth/login.php'); }
$page_title='Change password'; $error=null; $_SESSION['password_change_key']??=bin2hex(random_bytes(32));
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        if (!valid_csrf() || !hash_equals($_SESSION['password_change_key'],post_text('request_key'))) { http_response_code(403); throw new InvalidArgumentException('This form expired. Reload and try again.'); }
        $db=db(); $db->beginTransaction(); password_authenticated_change($db,(int)$user['user_id'],post_text('current_password',false),post_text('password',false),post_text('password_confirmation',false),post_text('request_key')); $db->commit();
        forget_login(); flash('Your password has been changed. Please sign in again.'); redirect_to('auth/login.php');
    } catch (InvalidArgumentException $e) { $error=$e->getMessage(); }
    catch (Throwable $e) { $error='Your password could not be changed. Please try again.'; }
    if (isset($db) && $db->inTransaction()) { $db->rollBack(); }
}
require __DIR__.'/../includes/header.php'; ?>
<main class="container py-5"><section class="card auth-card help-card mx-auto"><a class="brand mb-4" href="<?= e(app_url(home_path($user))) ?>">ProfitPocket</a><h1 class="h2">Change password</h1><p class="text-secondary">Saving signs you out of all sessions.</p><?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
<form method="post" action="<?= e(app_url('auth/change_password.php')) ?>"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="request_key" value="<?= e($_SESSION['password_change_key']) ?>">
<?php foreach (['current_password'=>'Current password','password'=>'New password','password_confirmation'=>'Confirm new password'] as $field=>$label): ?><div class="mb-3"><label class="form-label" for="<?= e($field) ?>"><?= e($label) ?></label><input class="form-control" id="<?= e($field) ?>" name="<?= e($field) ?>" type="password" autocomplete="<?= $field==='current_password'?'current-password':'new-password' ?>" maxlength="72" <?= $field!=='current_password'?'minlength="12"':'' ?> required></div><?php endforeach; ?>
<button class="btn btn-primary w-100" type="submit">Save password</button></form><a class="mt-3" href="<?= e(app_url(home_path($user))) ?>">Return to your account</a></section></main><?php require __DIR__.'/../includes/footer.php'; ?>
