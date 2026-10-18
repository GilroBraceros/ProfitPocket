<?php
http_response_code(501);
require __DIR__ . '/header.php';
require __DIR__ . '/navbar.php';
?>
<main class="container py-5"><section class="card p-4">
    <h1 class="h2"><?= e($page_title) ?></h1>
    <p>This module is scheduled for a later development step.</p>
    <a href="<?= e(app_url(home_path($user))) ?>">Return to your account</a>
</section></main>
<?php require __DIR__ . '/footer.php'; ?>
