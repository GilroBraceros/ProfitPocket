<?php
if (!defined('APP_BASE_PATH')) {
    http_response_code(403);
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($page_title ?? 'Your business') ?> | ProfitPocket</title>
    <link rel="icon" type="image/jpeg" href="<?= e(app_url('assets/images/logo-icon.jpg')) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="<?= e(app_url('assets/css/style.css')) ?>" rel="stylesheet">
    <?php if (in_array($active_page??'',['payments','utang'],true)): ?><link href="<?= e(app_url('assets/css/payments.css')) ?>?v=<?= filemtime(__DIR__.'/../assets/css/payments.css') ?>" rel="stylesheet"><?php endif; ?>
    <?php if (($active_page??'')==='admin'): ?><link href="<?= e(app_url('assets/css/admin.css')) ?>?v=<?= filemtime(__DIR__.'/../assets/css/admin.css') ?>" rel="stylesheet"><?php endif; ?>
</head>
<body<?= !empty($dashboard_layout) ? ' class="dashboard-page'.(($active_page??'')==='reports'?' reports-layout':'').(($active_page??'')==='admin'?' admin-layout':'').'"' : '' ?>>
