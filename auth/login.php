<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_guest();
$page_title = 'Sign in';
$errors = [];
$identifier = '';
$notice = take_flash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = post_text('identifier');
    $password = post_text('password', false);
    if (!valid_csrf()) {
        http_response_code(403);
        $errors[] = 'This form has expired. Please try again.';
    } elseif ($identifier === '' || mb_strlen($identifier) > 191 || $password === '' || strlen($password) > 4096 || str_contains($password, "\0")) {
        $errors[] = 'Enter your username or email and password.';
    } else {
        $connection = db();
        try {
            if (login_is_limited($connection, $identifier)) {
                http_response_code(429);
                $errors[] = 'Too many sign-in attempts. Please wait 15 minutes and try again.';
            } else {
                // Lock the user while checking status and creating a session.
                $connection->beginTransaction();
                $column = str_contains($identifier, '@') ? 'email' : 'username';
                $statement = $connection->prepare("SELECT user_id FROM users WHERE {$column} = ? FOR UPDATE");
                $statement->execute([$identifier]);
                $statement->fetchColumn();
                $login_user = find_login_user($connection, $identifier);
                if (!password_matches($login_user, $password)) {
                    $connection->rollBack();
                    log_failed_login($connection, $identifier);
                    $errors[] = 'Unable to sign in with those credentials.';
                } else {
                    $token = create_login_session($connection, $login_user, $password);
                    $connection->commit();
                    remember_login($token);
                    redirect_to(home_path($login_user));
                }
            }
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            error_log('ProfitPocket: sign-in operation failed.');
            http_response_code(503);
            $errors[] = 'Sign in is temporarily unavailable. Please try again later.';
        }
    }
}
require __DIR__ . '/../includes/header.php';
?>
<main class="container auth-shell">
    <section class="auth-intro">
        <a class="brand" href="<?= e(app_url()) ?>">ProfitPocket</a>
        <p class="eyebrow mt-5">YOUR BUSINESS, WITH CLARITY</p>
        <h1>Small business.<br>A clearer tomorrow.</h1>
        <p class="intro-copy">Keep your business records in one place, starting with a secure account.</p>
    </section>
    <section class="card auth-card" aria-labelledby="sign-in-heading">
        <h2 id="sign-in-heading">Welcome back</h2>
        <p class="text-secondary">Sign in to your ProfitPocket account.</p>
        <?php if ($notice): ?><div class="alert alert-success" role="status"><?= e($notice) ?></div><?php endif; ?>
        <?php foreach ($errors as $error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endforeach; ?>
        <form method="post" action="<?= e(app_url('auth/login.php')) ?>">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <div class="mb-3">
                <label for="identifier" class="form-label">Username or email</label>
                <input id="identifier" name="identifier" class="form-control" value="<?= e($identifier) ?>" maxlength="191" autocomplete="username" required>
            </div>
            <div class="mb-4">
                <label for="password" class="form-label">Password</label>
                <div class="input-group">
                    <input id="password" name="password" type="password" class="form-control" autocomplete="current-password" required>
                    <button class="btn btn-outline-secondary" type="button" data-toggle-password="password" aria-label="Show password" aria-pressed="false">Show</button>
                </div>
            </div>
            <button class="btn btn-primary w-100" type="submit">Sign in</button>
        </form>
        <p class="mt-3 mb-0 small text-center">New to ProfitPocket? <a href="<?= e(app_url('auth/register.php')) ?>">Create an account</a></p>
        <a class="small text-center mt-3" href="<?= e(app_url('auth/forgot_password.php')) ?>">Need help signing in?</a>
    </section>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
