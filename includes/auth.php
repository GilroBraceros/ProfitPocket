<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
date_default_timezone_set('Asia/Manila');
ini_set('display_errors', '0');
ini_set('log_errors', '1');

define('APP_BASE_PATH', rtrim(getenv('PP_BASE_PATH') ?: '/profitpocket', '/'));
const AUTH_IDLE_SECONDS = 1800;

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('profitpocket_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => APP_BASE_PATH . '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self' https://cdn.jsdelivr.net; script-src 'self' https://cdn.jsdelivr.net; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_url(string $path = ''): string
{
    return APP_BASE_PATH . '/' . ltrim($path, '/');
}

function redirect_to(string $path): void
{
    header('Location: ' . app_url($path), true, 303);
    exit;
}

function post_text(string $key, bool $trim = true): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? ($trim ? trim($value) : $value) : '';
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function valid_csrf(): bool
{
    $token = post_text('csrf_token');
    return $token !== '' && hash_equals(csrf_token(), $token);
}

function flash(string $message): void
{
    $_SESSION['flash'] = $message;
}

function take_flash(): ?string
{
    $message = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $message;
}

// Only a random token is kept in the PHP session; the database stores its hash.
function session_user(PDO $connection, string $token): ?array
{
    $statement = $connection->prepare(
        "SELECT u.user_id, u.full_name, u.username, u.email, u.account_status,
                r.role_id, r.role_code, r.role_name, r.role_type,
                b.business_id, b.business_name, c.category_name
         FROM auth_sessions s
         JOIN users u ON u.user_id = s.user_id
         JOIN roles r ON r.role_id = u.role_id
         LEFT JOIN businesses b ON b.owner_user_id = u.user_id
         LEFT JOIN business_categories c ON c.business_category_id = b.business_category_id
         WHERE s.token_hash = ? AND s.revoked_at IS NULL
           AND s.expires_at > CURRENT_TIMESTAMP AND u.account_status = 'active'
         LIMIT 1"
    );
    $statement->execute([hash('sha256', $token)]);
    return $statement->fetch() ?: null;
}

function revoke_session(PDO $connection, string $token): void
{
    $statement = $connection->prepare(
        'UPDATE auth_sessions SET revoked_at = CURRENT_TIMESTAMP WHERE token_hash = ? AND revoked_at IS NULL'
    );
    $statement->execute([hash('sha256', $token)]);
}

function forget_login(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
}

function current_user(): ?array
{
    if (empty($_SESSION['auth_token'])) {
        return null;
    }
    $token = $_SESSION['auth_token'];
    $idle = time() - ($_SESSION['last_activity'] ?? 0);
    $user = $idle <= AUTH_IDLE_SECONDS ? session_user(db(), $token) : null;
    if (!$user) {
        revoke_session(db(), $token);
        forget_login();
        flash('Your session has ended. Please sign in again.');
        return null;
    }
    $_SESSION['last_activity'] = time();
    return $user;
}

function home_path(array $user): string
{
    require_once __DIR__.'/deployment.php';
    if ($user['role_type']==='admin') { return bootstrap_needs_password_change(db(),(int)$user['user_id'])?'auth/change_password.php':'admin/dashboard.php'; }
    return subscription_has_access(db(),$user)?'user/dashboard.php':'user/subscription.php';
}

function require_guest(): void
{
    $user = current_user();
    if ($user) {
        redirect_to(home_path($user));
    }
}

function can_access_role(array $user, string $role): bool
{
    return $user['role_type'] === $role;
}

function require_role(string $role): array
{
    $user = current_user();
    if (!$user) {
        redirect_to('auth/login.php');
    }
    if (!can_access_role($user, $role)) {
        http_response_code(403);
        $page_title = 'Access denied';
        require __DIR__ . '/header.php';
        echo '<main class="container py-5"><div class="card p-4"><h1>Access denied</h1><p>Your account cannot access this page.</p><a href="' . e(app_url(home_path($user))) . '">Return to your account</a></div></main>';
        require __DIR__ . '/footer.php';
        exit;
    }
    if ($role==='admin') {
        require_once __DIR__.'/deployment.php';
        if (bootstrap_needs_password_change(db(),(int)$user['user_id'])) { redirect_to('auth/change_password.php'); }
    }
    return $user;
}

function require_permission(string $permission): array
{
    $user = require_role('admin');
    $statement = db()->prepare(
        'SELECT 1 FROM role_permissions rp JOIN permissions p ON p.permission_id = rp.permission_id WHERE rp.role_id = ? AND p.permission_code = ?'
    );
    $statement->execute([$user['role_id'], $permission]);
    if (!$statement->fetchColumn()) {
        http_response_code(403);
        $page_title = 'Access denied';
        require __DIR__ . '/header.php';
        echo '<main class="container py-5"><div class="card p-4"><h1>Access denied</h1><p>Your administrator role does not have permission for this module.</p><a href="' . e(app_url('admin/dashboard.php')) . '">Return to your account</a></div></main>';
        require __DIR__ . '/footer.php';
        exit;
    }
    return $user;
}

function require_business(): array
{
    $user = require_role('owner');
    require_once __DIR__.'/subscriptions.php'; subscription_require_access($user);
    if (!$user['business_id']) {
        flash('Your account has no linked business. Please contact the administrator.');
        redirect_to('user/dashboard.php');
    }
    // Future module queries must take business_id from this result, never from a form.
    return $user;
}

function find_login_user(PDO $connection, string $identifier): ?array
{
    // Registration excludes @ from usernames to avoid ambiguity with email addresses.
    $column = str_contains($identifier, '@') ? 'email' : 'username';
    $statement = $connection->prepare(
        "SELECT u.*, r.role_type FROM users u JOIN roles r ON r.role_id = u.role_id WHERE u.{$column} = ? LIMIT 1"
    );
    $statement->execute([$identifier]);
    return $statement->fetch() ?: null;
}

function password_matches(?array $user, string $password): bool
{
    // A fixed dummy hash keeps an unknown account on the password-verification path.
    $hash = $user['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi';
    $matches = password_verify($password, $hash);
    return $user !== null && $matches && $user['account_status'] === 'active';
}

// Called inside a transaction so session creation and login metadata succeed together.
function create_login_session(PDO $connection, array $user, string $password): string
{
    $token = bin2hex(random_bytes(32));
    $packed_ip = @inet_pton($_SERVER['REMOTE_ADDR'] ?? '');
    $statement = $connection->prepare(
        'INSERT INTO auth_sessions (user_id, token_hash, expires_at, ip_address, user_agent)
         VALUES (?, ?, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 8 HOUR), ?, ?)'
    );
    $statement->execute([
        $user['user_id'], hash('sha256', $token), $packed_ip ?: null,
        mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
    ]);
    $hash = password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)
        ? password_hash($password, PASSWORD_DEFAULT) : $user['password_hash'];
    $statement = $connection->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP, password_hash = ? WHERE user_id = ?');
    $statement->execute([$hash, $user['user_id']]);
    if ($user['role_type']==='admin') {
        $statement=$connection->prepare("INSERT INTO activity_logs (actor_user_id,action_code,entity_type,entity_id) VALUES (?,'AUTH_ADMIN_LOGIN','user',?)");
        $statement->execute([$user['user_id'],$user['user_id']]);
    }
    return $token;
}

function remember_login(string $token): void
{
    session_regenerate_id(true);
    $_SESSION = ['auth_token' => $token, 'last_activity' => time()];
}

function auth_ip_key(): string
{
    return hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

function login_is_limited(PDO $connection, string $identifier): bool
{
    $statement = $connection->prepare(
        "SELECT COUNT(*) FROM activity_logs WHERE action_code = 'AUTH_LOGIN_FAILED'
         AND occurred_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 15 MINUTE)
         AND (details = ? OR JSON_UNQUOTE(JSON_EXTRACT(after_data, '$.identifier_hash')) = ?)"
    );
    $statement->execute([auth_ip_key(), hash('sha256', mb_strtolower($identifier))]);
    return (int) $statement->fetchColumn() >= 10;
}

function log_failed_login(PDO $connection, string $identifier): void
{
    $statement = $connection->prepare(
        "INSERT INTO activity_logs (action_code, entity_type, details, after_data) VALUES ('AUTH_LOGIN_FAILED', 'authentication', ?, ?)"
    );
    $statement->execute([auth_ip_key(), json_encode(['identifier_hash' => hash('sha256', mb_strtolower($identifier))])]);
}

function registration_errors(array $values, string $password, string $confirmation): array
{
    $errors = [];
    foreach (['full_name' => 'Full name', 'business_name' => 'Business name'] as $field => $label) {
        if ($values[$field] === '' || mb_strlen($values[$field]) > 150) {
            $errors[] = $label . ' must contain 1 to 150 characters.';
        }
    }
    if (!preg_match('/\A[A-Za-z0-9_.-]{3,60}\z/', $values['username'])) {
        $errors[] = 'Username must contain 3 to 60 letters, numbers, dots, underscores, or hyphens.';
    }
    if (strlen($values['email']) > 191 || !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address of at most 191 characters.';
    }
    if (strlen($password) < 12 || strlen($password) > 72) {
        $errors[] = 'Password must contain 12 to 72 bytes (usually characters).';
    }
    if (str_contains($password, "\0")) {
        $errors[] = 'Password contains an unsupported character.';
    }
    if ($password !== $confirmation) {
        $errors[] = 'Passwords do not match.';
    }
    if (!ctype_digit($values['business_category_id'])) {
        $errors[] = 'Select a business category.';
    }
    return $errors;
}

// The caller owns the transaction. Public registration can only assign the owner role.
function create_owner(PDO $connection, array $values, string $password, bool $sendWelcome=true): int
{
    $statement = $connection->prepare("SELECT role_id FROM roles WHERE role_code = 'business_owner' AND role_type = 'owner'");
    $statement->execute();
    $role_id = $statement->fetchColumn();
    if (!$role_id) {
        throw new RuntimeException('The business owner role is missing.');
    }
    $statement = $connection->prepare('SELECT business_category_id FROM business_categories WHERE business_category_id = ?');
    $statement->execute([$values['business_category_id']]);
    if (!$statement->fetchColumn()) {
        throw new InvalidArgumentException('Select a valid business category.');
    }
    $statement = $connection->prepare(
        'INSERT INTO users (role_id, full_name, username, email, password_hash) VALUES (?, ?, ?, ?, ?)'
    );
    $statement->execute([$role_id, $values['full_name'], $values['username'], $values['email'], password_hash($password, PASSWORD_DEFAULT)]);
    $user_id = (int) $connection->lastInsertId();
    $statement = $connection->prepare(
        'INSERT INTO businesses (owner_user_id, business_category_id, business_name) VALUES (?, ?, ?)'
    );
    $statement->execute([$user_id, $values['business_category_id'], $values['business_name']]);
    require_once __DIR__.'/subscriptions.php'; subscription_initialize_owner($connection,$user_id);
    $statement=$connection->prepare("INSERT INTO activity_logs (actor_user_id,action_code,entity_type,entity_id) VALUES (?,'USER_REGISTERED','user',?)");
    $statement->execute([$user_id,$user_id]);
    require_once __DIR__.'/notifications.php';
    if ($sendWelcome) { notification_enqueue($connection,'welcome','welcome:'.$user_id,['user_id'=>$user_id,'recipient_email'=>$values['email']]); }
    return $user_id;
}

// One-time owner-approved production bootstrap; always disabled locally.
// It grants no session and never performs a schema migration.
if (PHP_SAPI!=='cli' && PHP_OS_FAMILY==='Linux') {
    require_once __DIR__.'/production.php';
    production_initialize();
}
