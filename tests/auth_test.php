<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
// All test data is rolled back; existing accounts are never changed.
if (!is_dir(__DIR__ . '/.sessions')) {
    mkdir(__DIR__ . '/.sessions', 0700, true);
}
session_save_path(__DIR__ . '/.sessions');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'ProfitPocket integration test';
require_once __DIR__ . '/../includes/auth.php';

function check(bool $condition, string $description): void
{
    if (!$condition) {
        throw new RuntimeException($description);
    }
}

$connection = db();
$before = (int) $connection->query('SELECT COUNT(*) FROM users')->fetchColumn();
$suffix = bin2hex(random_bytes(8));
$values = [
    'full_name' => '<Test Owner>', 'username' => 'test_' . $suffix,
    'email' => $suffix . '@example.invalid', 'business_name' => '<Test Business>',
    'business_category_id' => (string) $connection->query('SELECT business_category_id FROM business_categories LIMIT 1')->fetchColumn(),
];
$password = 'Test-only-secret-' . $suffix;
$result = [];

try {
    check(registration_errors($values, $password, $password) === [], 'Valid registration rejected');
    check(count(registration_errors($values, 'short', 'different')) >= 2, 'Weak or mismatched password accepted');
    check(e('<script>') === '&lt;script&gt;', 'HTML escaping failed');
    $_POST = ['csrf_token' => csrf_token()];
    check(valid_csrf(), 'Valid CSRF token rejected');
    $_POST['csrf_token'] = 'invalid';
    check(!valid_csrf(), 'Invalid CSRF token accepted');
    $_POST['csrf_token'] = ['unexpected'];
    check(!valid_csrf(), 'Array CSRF token accepted');
    $result[] = 'PASS: input validation, escaping, and CSRF';

    $connection->beginTransaction();
    $connection->exec('SAVEPOINT registration_failure');
    $bad_values = $values;
    $bad_values['business_name'] = null;
    try {
        create_owner($connection, $bad_values, $password);
        throw new RuntimeException('Expected business insertion failure');
    } catch (PDOException $expected) {
        $connection->exec('ROLLBACK TO SAVEPOINT registration_failure');
    }
    check(find_login_user($connection, $values['username']) === null, 'Failed registration left an orphan user');
    $result[] = 'PASS: failed business insertion rolls back the user';

    $user_id = create_owner($connection, $values + ['role_id' => 3], $password);
    $owner = find_login_user($connection, $values['username']);
    check($owner !== null && (int) $owner['user_id'] === $user_id, 'Registered user missing');
    check($owner['role_type'] === 'owner', 'Public registration created an administrator');
    check(password_matches($owner, $password), 'Correct password rejected');
    check(!password_matches($owner, 'wrong password'), 'Incorrect password accepted');
    check(!password_matches(null, $password), 'Unknown account accepted');
    check(find_login_user($connection, $values['email'])['user_id'] === $owner['user_id'], 'Email sign in failed');
    check(find_login_user($connection, "' OR 1=1 --") === null, 'SQL injection accepted');
    foreach (['inactive', 'suspended', 'deactivated'] as $status) {
        $blocked = $owner;
        $blocked['account_status'] = $status;
        check(!password_matches($blocked, $password), 'Blocked account accepted');
    }
    $result[] = 'PASS: owner registration, hashed passwords, email/username lookup, and blocked accounts';

    foreach (['username', 'email'] as $duplicate_field) {
        $duplicate = $values;
        $duplicate[$duplicate_field === 'email' ? 'username' : 'email'] .= '_unique';
        try {
            create_owner($connection, $duplicate, $password);
            throw new RuntimeException('Duplicate ' . $duplicate_field . ' accepted');
        } catch (PDOException $expected) {
            check(($expected->errorInfo[1] ?? 0) === 1062, 'Unexpected duplicate error');
        }
    }
    $result[] = 'PASS: duplicate username and email rejected';

    $token = create_login_session($connection, $owner, $password);
    $session_owner = session_user($connection, $token);
    check($session_owner !== null && $session_owner['business_name'] === $values['business_name'], 'Owner session has wrong business');
    check(can_access_role($session_owner, 'owner') && !can_access_role($session_owner, 'admin'), 'Owner role boundary failed');
    $statement = $connection->prepare("SELECT u.* FROM users u JOIN roles r ON r.role_id=u.role_id WHERE r.role_type='admin' AND u.account_status='active' LIMIT 1");
    $statement->execute();
    $admin = $statement->fetch();
    if ($admin) {
        // Create only a session fixture, avoiding changes to the existing admin user.
        $admin_token = bin2hex(random_bytes(32));
        $statement = $connection->prepare('INSERT INTO auth_sessions (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 1 HOUR))');
        $statement->execute([$admin['user_id'], hash('sha256', $admin_token)]);
        $session_admin = session_user($connection, $admin_token);
        check($session_admin !== null && can_access_role($session_admin, 'admin') && !can_access_role($session_admin, 'owner'), 'Admin role boundary failed');
        check(home_path($session_admin) === 'admin/dashboard.php', 'Admin redirect failed');
    }
    check(home_path($session_owner) === 'user/dashboard.php', 'Owner redirect failed');
    check(session_user($connection, 'tampered token') === null, 'Tampered session accepted');
    $statement = $connection->prepare("UPDATE users SET account_status='suspended' WHERE user_id=?");
    $statement->execute([$user_id]);
    check(session_user($connection, $token) === null, 'Suspended account retained access');
    $connection->prepare("UPDATE users SET account_status='active' WHERE user_id=?")->execute([$user_id]);
    revoke_session($connection, $token);
    check(session_user($connection, $token) === null, 'Revoked session accepted');
    $expired_token = bin2hex(random_bytes(32));
    $connection->prepare('INSERT INTO auth_sessions (user_id, token_hash, created_at, expires_at) VALUES (?, ?, DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 2 HOUR), DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 HOUR))')->execute([$user_id, hash('sha256', $expired_token)]);
    check(session_user($connection, $expired_token) === null, 'Expired session accepted');
    $result[] = 'PASS: tenant ownership, owner/admin routing, revoked/expired/tampered sessions, and live account status';

    $limit_identifier = 'limit_' . $suffix;
    $_SERVER['REMOTE_ADDR'] = '192.0.2.123';
    for ($attempt = 0; $attempt < 10; $attempt++) {
        log_failed_login($connection, $limit_identifier);
    }
    check(login_is_limited($connection, $limit_identifier), 'Login attempt limit failed');
    check(login_is_limited($connection, 'another account'), 'IP limit failed');
    $_SERVER['REMOTE_ADDR'] = '192.0.2.124';
    check(login_is_limited($connection, $limit_identifier), 'Account limit failed');
    $result[] = 'PASS: login limiting across accounts and PHP sessions';

    $connection->rollBack();
    check((int) $connection->query('SELECT COUNT(*) FROM users')->fetchColumn() === $before, 'Test user count changed');
    check(find_login_user($connection, $values['username']) === null, 'Test account persisted');
    $result[] = 'PASS: all database test records rolled back';
    session_destroy();
    echo implode(PHP_EOL, $result) . PHP_EOL;
} catch (Throwable $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    session_destroy();
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
