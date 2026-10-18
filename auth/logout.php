<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Sign out using the button in your account.');
}
if (!valid_csrf()) {
    http_response_code(403);
    exit('This form has expired. Return to your account and try again.');
}
if (!empty($_SESSION['auth_token'])) {
    revoke_session(db(), $_SESSION['auth_token']);
}
$_SESSION = [];
$cookie = session_get_cookie_params();
setcookie(session_name(), '', [
    'expires' => time() - 3600, 'path' => $cookie['path'], 'domain' => $cookie['domain'],
    'secure' => $cookie['secure'], 'httponly' => true, 'samesite' => 'Lax',
]);
session_destroy();
redirect_to('auth/login.php');
