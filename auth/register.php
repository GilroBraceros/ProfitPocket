<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_guest();
$connection = db();
$page_title = 'Create an account';
$categories = $connection->query('SELECT business_category_id, category_name FROM business_categories ORDER BY category_name')->fetchAll();
$errors = [];
$values = ['full_name' => '', 'username' => '', 'email' => '', 'business_name' => '', 'business_category_id' => ''];
if (!$categories) {
    $errors[] = 'Business categories are not configured. Please contact the administrator.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($values as $key => $value) {
        $values[$key] = post_text($key);
    }
    $values['email'] = strtolower($values['email']);
    $password = post_text('password', false);
    if (!valid_csrf()) {
        http_response_code(403);
        $errors[] = 'This form has expired. Please try again.';
    } else {
        $errors = array_merge($errors, registration_errors($values, $password, post_text('password_confirmation', false)));
    }
    if (!$errors) {
        try {
            $connection->beginTransaction();
            create_owner($connection, $values, $password);
            $connection->commit();
            session_regenerate_id(true);
            unset($_SESSION['csrf_token']);
            flash('Your account and business are ready. Please sign in.');
            redirect_to('auth/login.php');
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            if ($exception instanceof InvalidArgumentException) {
                $errors[] = $exception->getMessage();
            } elseif ($exception instanceof PDOException && ($exception->errorInfo[1] ?? 0) === 1062) {
                $errors[] = 'This username or email is already registered. Try another or sign in.';
            } else {
                error_log('ProfitPocket: account registration failed.');
                http_response_code(503);
                $errors[] = 'Registration is temporarily unavailable. Please try again later.';
            }
        }
    }
}
require __DIR__ . '/../includes/header.php';
?>
<main class="container py-5">
    <div class="registration-wrap mx-auto">
        <a class="brand" href="<?= e(app_url()) ?>">ProfitPocket</a>
        <section class="card auth-card mt-4" aria-labelledby="register-heading">
            <p class="eyebrow">LET'S GET STARTED</p>
            <h1 id="register-heading" class="h2">Create your business account</h1>
            <p class="text-secondary">A secure home for your business records.</p>
            <?php if ($errors): ?>
                <div class="alert alert-danger" role="alert"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
            <?php endif; ?>
            <form method="post" action="<?= e(app_url('auth/register.php')) ?>">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <div class="row g-3">
                    <div class="col-12"><label class="form-label" for="full_name">Full name</label><input class="form-control" id="full_name" name="full_name" value="<?= e($values['full_name']) ?>" maxlength="150" autocomplete="name" required></div>
                    <div class="col-md-6"><label class="form-label" for="username">Username</label><input class="form-control" id="username" name="username" value="<?= e($values['username']) ?>" minlength="3" maxlength="60" pattern="[A-Za-z0-9_.\-]{3,60}" autocomplete="username" aria-describedby="username-help" required><div id="username-help" class="form-text">Letters, numbers, dots, underscores, or hyphens.</div></div>
                    <div class="col-md-6"><label class="form-label" for="email">Email</label><input class="form-control" id="email" name="email" type="email" value="<?= e($values['email']) ?>" maxlength="191" autocomplete="email" required></div>
                    <div class="col-md-6"><label class="form-label" for="business_name">Business name</label><input class="form-control" id="business_name" name="business_name" value="<?= e($values['business_name']) ?>" maxlength="150" autocomplete="organization" required></div>
                    <div class="col-md-6"><label class="form-label" for="business_category_id">Business category</label><select class="form-select" id="business_category_id" name="business_category_id" required><option value="">Choose a category</option><?php foreach ($categories as $category): ?><option value="<?= e($category['business_category_id']) ?>" <?= (string) $category['business_category_id'] === $values['business_category_id'] ? 'selected' : '' ?>><?= e($category['category_name']) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-6"><label class="form-label" for="password">Password</label><div class="input-group"><input class="form-control" type="password" id="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" aria-describedby="password-help" required><button class="btn btn-outline-secondary" type="button" data-toggle-password="password" aria-label="Show password" aria-pressed="false">Show</button></div><div id="password-help" class="form-text">Use a unique password, 12–72 bytes long.</div></div>
                    <div class="col-md-6"><label class="form-label" for="password_confirmation">Confirm password</label><input class="form-control" type="password" id="password_confirmation" name="password_confirmation" minlength="12" maxlength="72" autocomplete="new-password" required></div>
                </div>
                <button class="btn btn-primary w-100 mt-4" type="submit" <?= !$categories ? 'disabled' : '' ?>>Create account</button>
            </form>
            <p class="mt-3 mb-0 text-center small">Already registered? <a href="<?= e(app_url('auth/login.php')) ?>">Sign in</a></p>
        </section>
    </div>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
