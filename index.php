<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
$user = current_user();
redirect_to($user ? home_path($user) : 'auth/login.php');
