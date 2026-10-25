<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
// Real HTTP tests: temporary fixture rows are removed in finally, by exact IDs.
// No existing account credentials or profile records are changed.
require_once __DIR__ . '/../config/database.php';
require_once __DIR__.'/subscription_test_helpers.php';
$connection = db();
$base = 'http://localhost/profitpocket';
$handle = curl_init();
curl_setopt_array($handle, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_COOKIEFILE => '', CURLOPT_TIMEOUT => 10,
]);

function expect_http(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function request_http(string $path, ?array $post = null, bool $multipart = false): array
{
    global $handle, $base;
    $headers = [];
    curl_setopt($handle, CURLOPT_URL, $base . '/' . $path);
    curl_setopt($handle, CURLOPT_HEADERFUNCTION, function ($curl, $line) use (&$headers) {
        $parts = explode(':', $line, 2);
        if (count($parts) === 2) {
            $headers[strtolower(trim($parts[0]))][] = trim($parts[1]);
        }
        return strlen($line);
    });
    curl_setopt($handle, CURLOPT_POST, $post !== null);
    if ($post !== null) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, $multipart ? $post : http_build_query($post));
    }
    $body = curl_exec($handle);
    if ($body === false) {
        throw new RuntimeException('HTTP request failed: ' . curl_error($handle));
    }
    return ['status' => curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'body' => $body, 'headers' => $headers];
}

function form_token(array $response): string
{
    expect_http((bool) preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $response['body'], $matches), 'CSRF token absent');
    return $matches[1];
}

function browser_session_id(): string
{
    global $handle;
    foreach (curl_getinfo($handle, CURLINFO_COOKIELIST) as $cookie) {
        $parts = explode("\t", $cookie);
        if (($parts[5] ?? '') === 'profitpocket_session') {
            return $parts[6];
        }
    }
    return '';
}

function named_field(array $response, string $name): string
{
    expect_http((bool)preg_match('/name="'.preg_quote($name,'/').'" value="([^"]*)"/', $response['body'], $matches), 'Form field absent: '.$name);
    return html_entity_decode($matches[1],ENT_QUOTES,'UTF-8');
}

function sales_fields(array $response): array
{
    $fields=[];
    foreach (['csrf_token','request_key','request_signature','sale_id'] as $name) { $fields[$name]=named_field($response,$name); }
    return $fields;
}

$suffix = bin2hex(random_bytes(8));
$username = 'http_test_' . $suffix;
$password = 'HTTP-test-secret-' . $suffix;
$admin_username = 'http_admin_' . $suffix;
$support_username = 'http_support_' . $suffix;
$fixture_ids = [];
$errors = null;
$checks = [];

try {
    $reply = request_http('user/dashboard.php');
    expect_http($reply['status'] === 303, 'Guest reached protected owner page');
    expect_http(request_http('user/utang.php')['status']===303 && request_http('user/payments.php')['status']===303 && request_http('user/payments_action.php?action=payment&id=1&modal=1')['status']===303,'Guest reached payment routes');
    expect_http(request_http('user/products.php')['status']===303 && request_http('user/product_action.php?action=create&modal=1')['status']===303,'Guest reached Products');
    expect_http(request_http('user/reports.php')['status']===303 && request_http('user/reports.php?export=csv')['status']===303,'Guest reached Reports or download');
    expect_http(request_http('admin/logs.php')['status'] === 303, 'Guest reached admin page');
    expect_http(request_http('auth/logout.php')['status'] === 405, 'GET logout accepted');
    foreach (['database/schema-reference.sql', 'config/database.php', 'includes/auth.php', 'tests/auth_test.php', '.vscode/settings.json', '.codex/config.toml'] as $path) {
        expect_http(request_http($path)['status'] === 403, 'Private path exposed: ' . $path);
    }
    $checks[] = 'PASS: protected HTTP routes, POST-only logout, and private files';

    $reply = request_http('auth/register.php');
    expect_http($reply['status'] === 200, 'Registration page unavailable');
    $category = $connection->query('SELECT business_category_id FROM business_categories LIMIT 1')->fetchColumn();
    $values = [
        'csrf_token' => form_token($reply), 'request_key'=>named_field($reply,'request_key'), 'full_name' => '<HTTP Test Owner>',
        'username' => $username, 'email' => $suffix . '@example.invalid',
        'password' => $password, 'password_confirmation' => $password,
        'business_name' => '<HTTP Test Business>', 'business_category_id' => $category,
        'role_id' => 3,
    ];
    $invalid = $values;
    $invalid['csrf_token'] = 'invalid';
    expect_http(request_http('auth/register.php', $invalid)['status'] === 403, 'CSRF forgery accepted');
    $weak = $values;
    $weak['password'] = 'weak';
    $weak['password_confirmation'] = 'weak';
    expect_http(str_contains(request_http('auth/register.php', $weak)['body'], 'Password must'), 'Weak registration accepted');
    $reply = request_http('auth/register.php', $values);
    expect_http($reply['status'] === 303, 'Valid registration failed');
    $query = $connection->prepare('SELECT u.user_id, r.role_type, b.business_id, b.business_name FROM users u JOIN roles r ON r.role_id=u.role_id JOIN businesses b ON b.owner_user_id=u.user_id WHERE u.username=? AND u.email=?');
    $query->execute([$username, $values['email']]);
    $owner = $query->fetch();
    expect_http($owner !== false, 'Registration did not create the owner and business');
    $fixture_ids[] = $owner['user_id'];
    expect_http($owner['role_type'] === 'owner', 'Role injection created an admin');
    test_activate_subscription($connection,(int)$owner['user_id']);
    // The onboarding session is intentionally separate from a normal sign-in.
    curl_setopt($handle,CURLOPT_COOKIELIST,'ALL');
    $duplicate = $values;
    $duplicateForm=request_http('auth/register.php');
    $duplicate['csrf_token'] = form_token($duplicateForm);
    $duplicate['request_key'] = named_field($duplicateForm,'request_key');
    expect_http(str_contains(request_http('auth/register.php', $duplicate)['body'], 'already registered'), 'Duplicate registration accepted');
    $checks[] = 'PASS: real registration, validation, duplicate handling, and owner-only role';

    $reply = request_http('auth/login.php');
    $login = ['csrf_token' => form_token($reply), 'identifier' => $username, 'password' => 'incorrect'];
    expect_http(str_contains(request_http('auth/login.php', $login)['body'], 'Unable to sign in'), 'Wrong password accepted');
    $login['password'] = $password;
    $before_session = browser_session_id();
    $reply = request_http('auth/login.php', $login);
    expect_http($reply['status'] === 303 && str_ends_with($reply['headers']['location'][0], '/user/dashboard.php'), 'Owner login redirect failed');
    expect_http(browser_session_id() !== $before_session, 'Login did not rotate PHP session ID');
    $reply = request_http('user/dashboard.php');
    expect_http($reply['status'] === 200 && str_contains($reply['body'], '&lt;HTTP Test Business&gt;'), 'Owner dashboard or HTML escaping failed');
    $bad_filter = request_http('user/dashboard.php?period=custom&start=2026-02-30&end=2026-03-05');
    expect_http($bad_filter['status'] === 400 && str_contains($bad_filter['body'], 'Enter valid start'), 'Invalid dashboard dates accepted');
    $other_business = $connection->query('SELECT business_id FROM businesses WHERE business_id<>0 ORDER BY business_id LIMIT 1')->fetchColumn();
    $tampered = request_http('user/dashboard.php?business_id=' . $other_business);
    expect_http($tampered['status'] === 200 && str_contains($tampered['body'], '&lt;HTTP Test Business&gt;') && str_contains($tampered['body'], '₱0.00'), 'Dashboard business identity could be overridden');
    expect_http(request_http('admin/dashboard.php')['status'] === 403, 'Owner reached admin dashboard');
    expect_http(request_http('admin/logs.php')['status'] === 403, 'Owner reached admin logs');
    expect_http(request_http('user/sales.php')['status'] === 200, 'Sales module unavailable');
    // Actual HTTP writes are restricted to unpaid, immediate drafts with no
    // financial history, so finally can remove only this run's exact fixtures.
    $product_form=request_http('user/sale_product.php');
    $product_fields=[];
    foreach (['csrf_token','request_key','request_signature'] as $name) { $product_fields[$name]=named_field($product_form,$name); }
    $product_fields['product_name']='<HTTP sales product>';
    $product_fields['default_selling_price']='125.50'; $product_fields['default_unit_cost']='50';
    expect_http(request_http('user/sale_product.php',$product_fields)['status']===303,'Product reference HTTP save failed');
    expect_http(request_http('user/sale_product.php',$product_fields)['status']===303,'Product reference replay failed');
    $query=$connection->prepare('SELECT product_id FROM products WHERE business_id=?'); $query->execute([$owner['business_id']]);
    $products=$query->fetchAll(PDO::FETCH_COLUMN);
    expect_http(count($products)===1,'Product reference replay duplicated a row'); $product_id=$products[0];
    $sale_form=request_http('user/sale_form.php');
    expect_http($sale_form['status']===200 && str_contains($sale_form['body'],'&lt;HTTP sales product&gt;'),'Form catalog or escaping failed');
    $sale_fields=sales_fields($sale_form);
    $sale_fields+=['sold_at'=>date('Y-m-d\TH:i'),'save_mode'=>'draft','payment_plan'=>'full','initial_payment'=>'0','discount_type'=>'fixed','discount_value'=>'1',
        'customer_id'=>'','notes'=>'<HTTP sale note>','items'=>[['product_id'=>(string)$product_id,'quantity'=>'2','unit_price'=>'125.50']]];
    $invalid=$sale_fields; $invalid['csrf_token']='invalid';
    expect_http(request_http('user/sale_form.php',$invalid)['status']===403,'Sales CSRF forgery accepted');
    $invalid=$sale_fields; $invalid['request_signature']='invalid';
    expect_http(request_http('user/sale_form.php',$invalid)['status']===403,'Sales signed token forgery accepted');
    $invalid=$sale_fields; $invalid['initial_payment']='300'; $invalid['save_mode']='posted';
    expect_http(request_http('user/sale_form.php',$invalid)['status']===400,'HTTP overpayment accepted');
    $reply_saved=request_http('user/sale_form.php',$sale_fields);
    expect_http($reply_saved['status']===303 && str_contains($reply_saved['headers']['location'][0],'/sale_view.php?id='),'Draft HTTP save/redirect failed');
    expect_http(request_http('user/sale_form.php',$sale_fields)['headers']['location'][0]===$reply_saved['headers']['location'][0],'Sale replay created another transaction');
    $query=$connection->prepare('SELECT sale_id FROM sales WHERE business_id=?'); $query->execute([$owner['business_id']]); $ids=$query->fetchAll(PDO::FETCH_COLUMN);
    expect_http(count($ids)===1,'Draft replay duplicated a row'); $sale_id=$ids[0];
    $details=request_http('user/sale_view.php?id='.$sale_id);
    expect_http($details['status']===200 && str_contains($details['body'],'&lt;HTTP sale note&gt;') && str_contains($details['body'],'250.00'),'Saved draft details/totals/escaping failed');
    $edit_form=request_http('user/sale_form.php?id='.$sale_id); $edit=sales_fields($edit_form);
    $edit=array_replace($sale_fields,$edit,['revision'=>named_field($edit_form,'revision'),'notes'=>'Changed HTTP note']);
    $query=$connection->prepare('SELECT sale_item_id FROM sale_items WHERE sale_id=? AND business_id=?'); $query->execute([$sale_id,$owner['business_id']]);
    $edit['items'][0]['sale_item_id']=(string)$query->fetchColumn();
    expect_http(request_http('user/sale_form.php',$edit)['status']===303,'Draft HTTP edit failed');
    expect_http(request_http('user/sale_form.php',$edit)['status']===400,'Stale HTTP edit accepted');
    expect_http(str_contains(request_http('user/sales.php?state=draft&q=SL-')['body'],'250.00'),'HTTP draft search failed');
    expect_http(request_http('user/sales.php?period=custom&start=2026-02-30&end=2026-03-01')['status']===400,'Invalid sales dates accepted');
    $foreign_sale=$connection->query('SELECT sale_id FROM sales WHERE business_id<>'.(int)$owner['business_id'].' LIMIT 1')->fetchColumn();
    expect_http(request_http('user/sale_view.php?id='.$foreign_sale)['status']===404 && request_http('user/sale_form.php?id='.$foreign_sale)['status']===404,'Foreign sale visible or editable');
    $void=['csrf_token'=>form_token($details),'sale_id'=>(string)$sale_id,'request_key'=>named_field($details,'request_key'),'request_signature'=>'forged','revision'=>named_field($details,'revision'),'void_reason'=>'forged'];
    expect_http(request_http('user/sale_view.php',$void)['status']===403,'Forged void accepted');
    $modal_form=request_http('user/sale_form.php?modal=1');
    expect_http($modal_form['status']===200 && str_contains($modal_form['body'],'data-sale-async') && !str_contains($modal_form['body'],'<html'),'Modal entry did not return a form fragment');
    $modal_fields=array_replace($sale_fields,sales_fields($modal_form));
    $bad_modal=$modal_fields; $bad_modal['discount_value']='999999';
    $json=request_http('user/sale_form.php?modal=1',$bad_modal); $validation=json_decode($json['body'],true,512,JSON_THROW_ON_ERROR);
    expect_http($json['status']===400 && !$validation['success'] && isset($validation['fields']['discount_value']),'Modal validation lacked adjacent-field error data');
    $bad_modal=$modal_fields; $bad_modal['csrf_token']='forged';
    $json=request_http('user/sale_form.php?modal=1',$bad_modal);
    expect_http($json['status']===403 && !json_decode($json['body'],true,512,JSON_THROW_ON_ERROR)['success'],'Modal CSRF forgery accepted');
    $json=request_http('user/sale_form.php?modal=1',$modal_fields); $saved_modal=json_decode($json['body'],true,512,JSON_THROW_ON_ERROR);
    expect_http($json['status']===200 && $saved_modal['success'] && $saved_modal['sale_id']!=$sale_id,'Modal draft save failed');
    $replay=json_decode(request_http('user/sale_form.php?modal=1',$modal_fields)['body'],true,512,JSON_THROW_ON_ERROR);
    expect_http($replay['success'] && $replay['sale_id']===$saved_modal['sale_id'],'Modal retry duplicated a sale');
    $modal_details=request_http('user/sale_view.php?modal=1&id='.$sale_id);
    expect_http($modal_details['status']===200 && str_contains($modal_details['body'],'Payment history') && !str_contains($modal_details['body'],'<form') && !str_contains($modal_details['body'],'Unit cost'),'Details modal was not a simple read-only fragment');
    $modal_edit=request_http('user/sale_form.php?modal=1&id='.$sale_id);
    $modal_edit_fields=array_replace($edit,sales_fields($modal_edit),['revision'=>named_field($modal_edit,'revision'),'notes'=>'Saved through modal']);
    $query->execute([$sale_id,$owner['business_id']]); $modal_edit_fields['items'][0]['sale_item_id']=(string)$query->fetchColumn();
    $json=request_http('user/sale_form.php?modal=1',$modal_edit_fields);
    expect_http(json_decode($json['body'],true,512,JSON_THROW_ON_ERROR)['success'],'Modal draft edit failed');
    $stale=request_http('user/sale_form.php?modal=1',$modal_edit_fields);
    expect_http($stale['status']===400 && str_contains($stale['body'],'changed in another request'),'Stale modal edit accepted');
    $void_form=request_http('user/sale_view.php?modal=1&action=void&id='.$sale_id);
    expect_http(str_contains($void_form['body'],'Total sale amount') && str_contains($void_form['body'],'Confirm Void') && !str_contains($void_form['body'],'class="modal '),'Void fragment nested another modal');
    $void_fields=sales_fields($void_form); $void_fields['revision']=named_field($void_form,'revision'); $void_fields['void_reason']='';
    $json=request_http('user/sale_view.php?modal=1',$void_fields); $validation=json_decode($json['body'],true,512,JSON_THROW_ON_ERROR);
    expect_http($json['status']===400 && isset($validation['fields']['void_reason']),'Void modal accepted missing reason');
    expect_http(request_http('user/sale_view.php?modal=1&id='.$foreign_sale)['status']===404,'Modal leaked another business sale');
    $checks[]='PASS: modal HTML/JSON, draft save/edit/replay, field errors, read-only details, non-nested void, stale forms and scope';
    // Expense financial records cannot be deleted under the existing schema.
    // HTTP writes here create only disposable categories/schedules, never expenses.
    expect_http(request_http('user/expenses.php')['status']===200,'Expense history unavailable');
    expect_http(request_http('user/expenses.php?category=1')['status']===400,'Foreign expense category accepted');
    expect_http(request_http('user/expenses.php?period=custom&start=2026-02-30&end=2026-03-01')['status']===400,'Invalid expense dates accepted');
    expect_http(request_http('uploads/receipts/blocked.bin')['status']===403,'Receipt storage is public');
    $categoryForm=request_http('user/expense_action.php?action=category&modal=1');
    $expense_tokens=[]; foreach (['csrf_token','request_key','request_signature'] as $name) { $expense_tokens[$name]=named_field($categoryForm,$name); }
    $category_payload=$expense_tokens+['category_name'=>'<HTTP expense category>'];
    $saved=json_decode(request_http('user/expense_action.php?action=category&modal=1',$category_payload)['body'],true,512,JSON_THROW_ON_ERROR);
    expect_http($saved['success'],'HTTP category creation failed'); $expense_category=$saved['id'];
    expect_http(json_decode(request_http('user/expense_action.php?action=category&modal=1',$category_payload)['body'],true,512,JSON_THROW_ON_ERROR)['id']===$expense_category,'Category replay duplicated a row');
    $expense_form=request_http('user/expense_action.php?action=create&modal=1');
    expect_http(str_contains($expense_form['body'],'&lt;HTTP expense category&gt;'),'Expense category escaping failed');
    $expense_tokens=[]; foreach (['csrf_token','request_key','request_signature'] as $name) { $expense_tokens[$name]=named_field($expense_form,$name); }
    $expense_payload=$expense_tokens+['expense_category_id'=>(string)$expense_category,'payment_method_id'=>'1','description'=>'HTTP validation only','amount'=>'0','expense_date'=>date('Y-m-d'),'expense_treatment'=>'operating','notes'=>''];
    $bad=request_http('user/expense_action.php?action=create&modal=1',$expense_payload); $validation=json_decode($bad['body'],true,512,JSON_THROW_ON_ERROR);
    expect_http($bad['status']===400 && isset($validation['fields']['amount']),'Zero expense accepted or field error absent');
    $forged=$expense_payload; $forged['csrf_token']='forged';
    expect_http(request_http('user/expense_action.php?action=create&modal=1',$forged)['status']===403,'Expense CSRF forgery accepted');
    $receipt_payload=$expense_payload; $receipt_payload['receipt']=new CURLFile(__DIR__.'/../assets/images/logo-icon.jpg','image/jpeg','receipt.jpg');
    $bad=request_http('user/expense_action.php?action=create&modal=1',$receipt_payload,true); $validation=json_decode($bad['body'],true,512,JSON_THROW_ON_ERROR);
    expect_http($bad['status']===400 && isset($validation['fields']['amount']) && !isset($validation['fields']['receipt']),'Valid receipt upload was not accepted by the HTTP validator');
    $receipt_payload['receipt']=new CURLFile(__DIR__.'/../README.md','image/jpeg','fake.jpg');
    $bad=request_http('user/expense_action.php?action=create&modal=1',$receipt_payload,true); $validation=json_decode($bad['body'],true,512,JSON_THROW_ON_ERROR);
    expect_http($bad['status']===400 && isset($validation['fields']['receipt']),'Fake JPG upload accepted');
    $foreign_expense=$connection->query('SELECT expense_id FROM expenses WHERE business_id<>'.(int)$owner['business_id'].' LIMIT 1')->fetchColumn();
    foreach (['details','edit','void'] as $action) { expect_http(request_http('user/expense_action.php?action='.$action.'&modal=1&id='.$foreign_expense)['status']===404,'Foreign expense accessible: '.$action); }
    expect_http(request_http('user/expense_receipt.php?id=1')['status']===404,'Missing/foreign receipt exposed');
    $schedule_form=request_http('user/expense_action.php?action=schedule&modal=1'); $schedule_tokens=[];
    foreach (['csrf_token','request_key','request_signature'] as $name) { $schedule_tokens[$name]=named_field($schedule_form,$name); }
    $schedule_payload=array_replace($expense_payload,$schedule_tokens,['amount'=>'12.50','frequency'=>'monthly','repeat_every'=>'1','start_date'=>date('Y-m-d'),'end_date'=>'']);
    $saved=json_decode(request_http('user/expense_action.php?action=schedule&modal=1',$schedule_payload)['body'],true,512,JSON_THROW_ON_ERROR); expect_http($saved['success'],'Schedule HTTP save failed');
    $schedule_id=$saved['id'];
    expect_http(json_decode(request_http('user/expense_action.php?action=schedule&modal=1',$schedule_payload)['body'],true,512,JSON_THROW_ON_ERROR)['id']===$schedule_id,'Schedule replay duplicated a template');
    expect_http(request_http('user/expense_action.php?action=confirm&modal=1&id='.$schedule_id)['status']===200,'Scheduled payment form unavailable');
    $guard=$connection->prepare('SELECT COUNT(*) FROM expenses WHERE business_id=?'); $guard->execute([$owner['business_id']]); expect_http((int)$guard->fetchColumn()===0,'HTTP validation tests retained expenses');
    $checks[]='PASS: expense HTTP forms, category/schedule saves and replay, field/date/scope/CSRF validation, valid/invalid receipt uploads and protected receipt storage; no financial rows committed';
    require __DIR__.'/http_customer_checks.php';
    require __DIR__.'/http_utang_checks.php';
    require __DIR__.'/http_product_checks.php';
    require __DIR__.'/http_report_checks.php';
    $checks[]='PASS: HTTP product references, draft create/replay/edit/detail/search, exact totals, CSRF/signatures, stale edits and sale isolation';
    $reply=request_http('user/dashboard.php');
    expect_http(request_http('auth/logout.php', ['csrf_token' => 'forged'])['status'] === 403, 'Forged logout accepted');
    expect_http(request_http('auth/logout.php', ['csrf_token' => form_token($reply)])['status'] === 303, 'Logout failed');
    expect_http(request_http('user/dashboard.php')['status'] === 303, 'Session survived logout');
    $query = $connection->prepare('SELECT COUNT(*) FROM auth_sessions WHERE user_id=? AND revoked_at IS NULL');
    $query->execute([$owner['user_id']]);
    expect_http((int) $query->fetchColumn() === 0, 'Logout did not revoke DB session');
    $checks[] = 'PASS: HTTP login, session rotation, escaping, owner/admin boundary, CSRF logout, and revocation';

    $reply = request_http('auth/login.php');
    expect_http(request_http('auth/login.php', ['csrf_token' => form_token($reply), 'identifier' => $values['email'], 'password' => $password])['status'] === 303, 'Email login failed');
    $reply = request_http('user/dashboard.php');
    request_http('auth/logout.php', ['csrf_token' => form_token($reply)]);

    foreach (['platform_admin' => $admin_username, 'support_admin' => $support_username] as $role_code => $fixture_username) {
        $query = $connection->prepare('SELECT role_id FROM roles WHERE role_code=? AND role_type=\'admin\'');
        $query->execute([$role_code]);
        $role_id = $query->fetchColumn();
        expect_http((bool) $role_id, 'Expected admin role missing');
        $query = $connection->prepare('INSERT INTO users (role_id,full_name,username,email,password_hash) VALUES (?,?,?,?,?)');
        $query->execute([$role_id, 'HTTP Test Admin', $fixture_username, $fixture_username . '@example.invalid', password_hash($password, PASSWORD_DEFAULT)]);
        $fixture_id = (int) $connection->lastInsertId();
        $fixture_ids[] = $fixture_id;
        $reply = request_http('auth/login.php');
        $reply = request_http('auth/login.php', ['csrf_token' => form_token($reply), 'identifier' => $fixture_username, 'password' => $password]);
        expect_http($reply['status'] === 303 && str_ends_with($reply['headers']['location'][0], '/admin/dashboard.php'), 'Admin login redirect failed');
        expect_http(request_http('user/reports.php')['status']===403 && request_http('user/reports.php?export=csv')['status']===403,'Administrator reached owner Reports/download');
        $reply = request_http('admin/dashboard.php');
        expect_http($reply['status'] === 200, 'Admin session failed');
        expect_http(request_http('user/dashboard.php')['status'] === 403, 'Admin reached owner page');
        foreach (['user/sales.php','user/sale_form.php','user/sale_product.php','user/sale_view.php?id=1'] as $path) { expect_http(request_http($path)['status']===403,'Admin reached sales route: '.$path); }
        foreach (['user/expenses.php','user/expense_action.php?modal=1','user/expense_receipt.php?id=1','user/customers.php','user/customer_action.php?modal=1','user/utang.php','user/utang_action.php?action=payment&id=1&modal=1','user/products.php','user/product_action.php?action=create&modal=1'] as $path) { expect_http(request_http($path)['status']===403,'Admin reached owner route: '.$path); }
        expect_http(request_http('admin/users.php')['status'] === ($role_code === 'platform_admin' ? 200 : 403), 'Admin permission boundary failed');
        $connection->prepare("UPDATE users SET account_status='suspended' WHERE user_id=?")->execute([$fixture_id]);
        expect_http(request_http('admin/dashboard.php')['status'] === 303, 'Suspended account retained HTTP access');
    }
    $checks[] = 'PASS: email login, administrator routing, support/platform permissions, and live suspension';
} catch (Throwable $exception) {
    $errors = $exception->getMessage();
} finally {
    // Discover only this run's owner if an assertion failed immediately after insert.
    $query = $connection->prepare('SELECT user_id FROM users WHERE username IN (?, ?, ?)');
    $query->execute([$username, $admin_username, $support_username]);
    $fixture_ids = array_unique(array_merge($fixture_ids, $query->fetchAll(PDO::FETCH_COLUMN)));
    $connection->beginTransaction();
    try {
        foreach ($fixture_ids as $id) {
            $connection->prepare('DELETE FROM auth_sessions WHERE user_id=?')->execute([$id]);
            $query=$connection->prepare('SELECT business_id FROM businesses WHERE owner_user_id=?'); $query->execute([$id]);
            foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $test_business) {
                $guard=$connection->prepare("SELECT COUNT(*) FROM sales s WHERE s.business_id=? AND (s.sale_status<>'draft' OR EXISTS(SELECT 1 FROM sale_payments p WHERE p.sale_id=s.sale_id) OR EXISTS(SELECT 1 FROM customer_credits c WHERE c.sale_id=s.sale_id))"); $guard->execute([$test_business]);
                expect_http((int)$guard->fetchColumn()===0,'Cleanup blocked: fixture has financial history');
                $connection->prepare('DELETE FROM sale_items WHERE business_id=?')->execute([$test_business]);
                $connection->prepare('DELETE FROM activity_logs WHERE business_id=?')->execute([$test_business]);
                $connection->prepare('DELETE FROM sales WHERE business_id=?')->execute([$test_business]);
                $connection->prepare('DELETE FROM customers WHERE business_id=?')->execute([$test_business]);
                $connection->prepare('DELETE FROM products WHERE business_id=?')->execute([$test_business]);
                $expense_guard=$connection->prepare('SELECT COUNT(*) FROM expenses WHERE business_id=?'); $expense_guard->execute([$test_business]);
                expect_http((int)$expense_guard->fetchColumn()===0,'Cleanup blocked: expense financial history exists');
                $connection->prepare('DELETE FROM activity_logs WHERE business_id=?')->execute([$test_business]);
                $connection->prepare('DELETE FROM recurring_expenses WHERE business_id=?')->execute([$test_business]);
                $connection->prepare('DELETE FROM expense_categories WHERE business_id=?')->execute([$test_business]);
            }
            $connection->prepare('DELETE FROM businesses WHERE owner_user_id=?')->execute([$id]);
            $connection->prepare('DELETE FROM activity_logs WHERE actor_user_id=?')->execute([$id]);
            $connection->prepare('DELETE FROM users WHERE user_id=?')->execute([$id]);
        }
        $connection->prepare("DELETE FROM activity_logs WHERE action_code='AUTH_LOGIN_FAILED' AND entity_type='authentication' AND JSON_UNQUOTE(JSON_EXTRACT(after_data, '$.identifier_hash'))=?")->execute([hash('sha256', strtolower($username))]);
        $connection->commit();
        $checks[] = 'PASS: temporary HTTP fixtures removed';
    } catch (Throwable $cleanup_error) {
        $connection->rollBack();
        $errors = 'Fixture cleanup failed; inspect test records with suffix ' . $suffix;
    }
    curl_close($handle);
}
echo implode(PHP_EOL, $checks) . PHP_EOL;
if ($errors !== null) {
    fwrite(STDERR, 'FAIL: ' . $errors . PHP_EOL);
    exit(1);
}
