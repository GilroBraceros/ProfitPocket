<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/dashboard.php';
date_default_timezone_set('Asia/Manila');

function assert_dashboard(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}
function fixture_insert(PDO $connection, string $sql, array $values): int
{
    $connection->prepare($sql)->execute($values);
    return (int) $connection->lastInsertId();
}

$connection = db();
$checks = [];
$today = new DateTimeImmutable('2026-10-09', new DateTimeZone('Asia/Manila'));
try {
    $period = dashboard_period([], $today);
    assert_dashboard($period['start']->format('Y-m-d') === '2026-10-01' && $period['end']->format('Y-m-d') === '2026-10-09', 'Month-to-date bounds wrong');
    assert_dashboard(dashboard_period(['period' => 'week'], $today)['start']->format('Y-m-d') === '2026-10-05', 'Monday week boundary wrong');
    assert_dashboard(dashboard_period(['period' => 'today'], $today)['days'] === 1, 'Today period wrong');
    assert_dashboard(dashboard_date("2026-10-09\0") === null, 'Malformed date accepted');
    foreach ([['period' => 'invalid'], ['period' => 'custom','start' => '2026-02-30','end' => '2026-03-03'], ['period' => 'custom','start' => '2026-10-10','end' => '2026-10-09'], ['period' => 'custom','start' => ['bad'],'end' => '2026-10-09'], ['period' => 'custom','start' => '2024-01-01','end' => '2026-01-01']] as $invalid) {
        try { dashboard_period($invalid, $today); throw new RuntimeException('Invalid date filter accepted'); }
        catch (InvalidArgumentException $expected) { }
    }
    assert_dashboard(dashboard_cents('1234.05') === 123405 && dashboard_cents('-0.05') === -5 && dashboard_money(-123405) === '−₱1,234.05', 'Centavo arithmetic or currency formatting wrong');
    assert_dashboard(dashboard_chart([], dashboard_period(['period' => 'custom','start' => '2026-01-01','end' => '2026-03-01'], $today))['grouping'] === 'weekly', 'Weekly grouping failed');
    assert_dashboard(dashboard_chart([], dashboard_period(['period' => 'custom','start' => '2026-01-01','end' => '2026-12-31'], $today))['grouping'] === 'monthly', 'Monthly grouping failed');
    $checks[] = 'PASS: date validation, month/week/today, comparisons, centavos, weekly/monthly chart grouping';

    // Fixtures live only inside this transaction, including trigger-generated logs.
    $connection->beginTransaction();
    $suffix = bin2hex(random_bytes(8));
    $role = $connection->query("SELECT role_id FROM roles WHERE role_code='business_owner'")->fetchColumn();
    $category = $connection->query('SELECT business_category_id FROM business_categories LIMIT 1')->fetchColumn();
    $method = $connection->query('SELECT payment_method_id FROM payment_methods WHERE is_active=1 LIMIT 1')->fetchColumn();
    $owner_id = fixture_insert($connection, 'INSERT INTO users (role_id,full_name,username,email,password_hash) VALUES (?,?,?,?,?)', [$role,'Dashboard test','dash_' . $suffix,$suffix . '@example.invalid',password_hash('Dashboard-test-only-' . $suffix, PASSWORD_DEFAULT)]);
    $business_id = fixture_insert($connection, 'INSERT INTO businesses (owner_user_id,business_category_id,business_name) VALUES (?,?,?)', [$owner_id,$category,'Dashboard test']);
    $empty = dashboard_data($connection, $business_id, $period);
    assert_dashboard($empty['totals']['sales'] === 0 && $empty['totals']['profit'] === 0 && $empty['outstanding'] === 0 && !$empty['chart']['has_activity'] && $empty['transactions'] === [], 'Empty business state wrong');
    $customer_id = fixture_insert($connection, 'INSERT INTO customers (business_id,customer_name) VALUES (?,?)', [$business_id,'Dashboard customer']);
    $product_id = fixture_insert($connection, 'INSERT INTO products (business_id,product_name,default_unit_cost) VALUES (?,?,?)', [$business_id,'Dashboard product','999.00']);
    $expense_category_id = fixture_insert($connection, 'INSERT INTO expense_categories (business_id,category_name) VALUES (?,?)', [$business_id,'Dashboard category']);
    $timestamp = '2026-10-09 10:00:00';

    $sale_insert = 'INSERT INTO sales (business_id,customer_id,created_by,sale_number,sale_type,sold_at) VALUES (?,?,?,?,?,?)';
    $item_insert = 'INSERT INTO sale_items (sale_id,business_id,product_id,product_name_snapshot,quantity,unit_price,unit_cost) VALUES (?,?,?,?,?,?,?)';
    $payment_insert = 'INSERT INTO sale_payments (sale_id,business_id,payment_method_id,entered_by,amount,paid_at) VALUES (?,?,?,?,?,?)';
    $immediate = fixture_insert($connection, $sale_insert, [$business_id,null,$owner_id,'TEST-PAID-' . $suffix,'immediate',$timestamp]);
    fixture_insert($connection, $item_insert, [$immediate,$business_id,$product_id,'Snapshot product',1,'1000.00','600.00']);
    fixture_insert($connection, $payment_insert, [$immediate,$business_id,$method,$owner_id,'1000.00',$timestamp]);
    $connection->prepare("UPDATE sales SET sale_status='posted' WHERE sale_id=?")->execute([$immediate]);
    $credit = fixture_insert($connection, $sale_insert, [$business_id,$customer_id,$owner_id,'TEST-CREDIT-' . $suffix,'credit',$timestamp]);
    fixture_insert($connection, $item_insert, [$credit,$business_id,$product_id,'Snapshot product',1,'1000.00','200.00']);
    $connection->prepare('INSERT INTO customer_credits (sale_id) VALUES (?)')->execute([$credit]);
    fixture_insert($connection, $payment_insert, [$credit,$business_id,$method,$owner_id,'300.00',$timestamp]);
    $connection->prepare("UPDATE sales SET sale_status='posted' WHERE sale_id=?")->execute([$credit]);
    $expense_insert = 'INSERT INTO expenses (business_id,expense_category_id,payment_method_id,entered_by,description,amount,expense_date,expense_treatment) VALUES (?,?,?,?,?,?,?,?)';
    fixture_insert($connection, $expense_insert, [$business_id,$expense_category_id,$method,$owner_id,'Operating','100.00','2026-10-09','operating']);
    fixture_insert($connection, $expense_insert, [$business_id,$expense_category_id,$method,$owner_id,'Inventory','2500.00','2026-10-09','inventory_purchase']);
    $void_expense = fixture_insert($connection, $expense_insert, [$business_id,$expense_category_id,$method,$owner_id,'Voided','50.00','2026-10-09','operating']);
    $connection->prepare("UPDATE expenses SET expense_status='void',void_reason='Test',voided_by=?,voided_at=CURRENT_TIMESTAMP WHERE expense_id=?")->execute([$owner_id,$void_expense]);

    $data = dashboard_data($connection, $business_id, $period);
    assert_dashboard($data['totals'] === ['sales' => 200000,'expenses' => 10000,'cogs' => 80000,'profit' => 110000] && $data['outstanding'] === 70000, 'Fully/partially paid figures or cost snapshots wrong');
    assert_dashboard(in_array('Partial', array_column($data['transactions'], 'status'), true) && in_array('Paid', array_column($data['transactions'], 'status'), true), 'Payment status wrong');
    fixture_insert($connection, $payment_insert, [$credit,$business_id,$method,$owner_id,'200.00','2026-10-09 11:00:00']);
    $repaid = dashboard_data($connection, $business_id, $period);
    assert_dashboard($repaid['outstanding'] === 50000 && $repaid['totals'] === $data['totals'], 'Repayment double-counted as revenue or balance wrong');
    assert_dashboard(array_sum($repaid['chart']['sales']) === 2000 && array_sum($repaid['chart']['expenses']) === 100, 'Chart totals differ from cards');
    fixture_insert($connection, $payment_insert, [$credit,$business_id,$method,$owner_id,'500.00','2026-10-09 12:00:00']);
    $fully_paid = dashboard_data($connection, $business_id, $period);
    assert_dashboard($fully_paid['outstanding'] === 0 && $fully_paid['totals']['sales'] === 200000, 'Full settlement changed revenue');
    $checks[] = 'PASS: fully paid sale, partial payments, repayments, historical COGS, profit, and expense treatment';

    $draft = fixture_insert($connection, $sale_insert, [$business_id,$customer_id,$owner_id,'TEST-DRAFT-' . $suffix,'credit',$timestamp]);
    fixture_insert($connection, $item_insert, [$draft,$business_id,$product_id,'Draft product',1,'750.00','0.00']);
    $connection->prepare('INSERT INTO customer_credits (sale_id) VALUES (?)')->execute([$draft]);
    $void_sale = fixture_insert($connection, $sale_insert, [$business_id,$customer_id,$owner_id,'TEST-VOID-' . $suffix,'credit',$timestamp]);
    fixture_insert($connection, $item_insert, [$void_sale,$business_id,$product_id,'Voided product',1,'500.00','300.00']);
    $connection->prepare('INSERT INTO customer_credits (sale_id) VALUES (?)')->execute([$void_sale]);
    $collection = fixture_insert($connection, $payment_insert, [$void_sale,$business_id,$method,$owner_id,'500.00',$timestamp]);
    $connection->prepare("UPDATE sales SET sale_status='posted' WHERE sale_id=?")->execute([$void_sale]);
    fixture_insert($connection, "INSERT INTO sale_payments (sale_id,business_id,payment_method_id,entered_by,payment_kind,amount,reverses_payment_id,paid_at) VALUES (?,?,?,?,'refund',?,?,?)", [$void_sale,$business_id,$method,$owner_id,'500.00',$collection,'2026-10-09 13:00:00']);
    $connection->prepare("UPDATE sales SET sale_status='void',void_reason='Test',voided_by=?,voided_at=CURRENT_TIMESTAMP WHERE sale_id=?")->execute([$owner_id,$void_sale]);
    $after_void = dashboard_data($connection, $business_id, $period);
    assert_dashboard($after_void['totals'] === $fully_paid['totals'] && $after_void['outstanding'] === 0, 'Draft or void sale included');
    $outside = dashboard_data($connection, $business_id, dashboard_period(['period' => 'custom','start' => '2026-10-01','end' => '2026-10-08'], $today));
    assert_dashboard($outside['totals']['sales'] === 0 && $outside['transactions'] === [], 'Date filter leaked transactions');
    $foreign = dashboard_data($connection, 0, $period);
    assert_dashboard($foreign['totals']['sales'] === 0 && $foreign['transactions'] === [], 'Business isolation failed');
    $checks[] = 'PASS: drafts/voids excluded, refunds represented, date bounds, business isolation, empty state';

    foreach (['percent' => '10.00', 'fixed' => '15.00'] as $discount_type => $discount_value) {
        $discounted = fixture_insert($connection, $sale_insert, [$business_id,$customer_id,$owner_id,'TEST-' . $discount_type . '-' . $suffix,'credit',$timestamp]);
        fixture_insert($connection, $item_insert, [$discounted,$business_id,$product_id,'Discount product',1,'100.00','10.00']);
        $connection->prepare('UPDATE sales SET discount_type=?,discount_value=? WHERE sale_id=?')->execute([$discount_type,$discount_value,$discounted]);
        $connection->prepare('INSERT INTO customer_credits (sale_id) VALUES (?)')->execute([$discounted]);
        $connection->prepare("UPDATE sales SET sale_status='posted' WHERE sale_id=?")->execute([$discounted]);
    }
    $discount_data = dashboard_data($connection, $business_id, $period);
    assert_dashboard($discount_data['totals']['sales'] === 217500 && $discount_data['totals']['profit'] === 125500 && $discount_data['outstanding'] === 17500, 'Fixed/percent discounts are inconsistent');
    fixture_insert($connection, $expense_insert, [$business_id,$expense_category_id,$method,$owner_id,'Loss-day expense','50000.00','2026-10-08','operating']);
    $loss_day = dashboard_data($connection, $business_id, dashboard_period(['period' => 'custom','start' => '2026-10-08','end' => '2026-10-08'], $today));
    assert_dashboard($loss_day['totals']['profit'] === -5000000 && $loss_day['outstanding'] === 17500, 'Negative profit or current balance across date filters wrong');
    $checks[] = 'PASS: fixed/percent discounts, negative profit, and current credit independent of period';
    $connection->rollBack();
    $checks[] = 'PASS: all financial fixtures and generated audit logs rolled back';
    echo implode(PHP_EOL, $checks) . PHP_EOL;
} catch (Throwable $exception) {
    if ($connection->inTransaction()) { $connection->rollBack(); }
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
