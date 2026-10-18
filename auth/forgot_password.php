<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_guest();
$page_title = 'Sign-in help';
require __DIR__ . '/../includes/header.php';
?>
<main class="container py-5">
    <section class="card auth-card help-card mx-auto">
        <a class="brand mb-4" href="<?= e(app_url()) ?>">ProfitPocket</a>
        <h1 class="h2">Need help signing in?</h1>
        <p>You can sign in using your username or email address.</p>
        <p class="text-secondary">Self-service password recovery is not available yet. Contact your ProfitPocket administrator for help with your account.</p>
        <a class="btn btn-primary" href="<?= e(app_url('auth/login.php')) ?>">Return to sign in</a>
    </section>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
