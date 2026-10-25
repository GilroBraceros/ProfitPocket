<?php
declare(strict_types=1);
require_once __DIR__ . '/dashboard.php';
require_once __DIR__ . '/payment_methods.php';

class SaleNotFound extends RuntimeException {}
const SALE_MAX_AMOUNT = '99999999999.99';

function sale_text(array $input, string $key, string $default = ''): string
{
    if (!isset($input[$key])) { return $default; }
    if (!is_string($input[$key])) { throw new InvalidArgumentException('Invalid ' . str_replace('_', ' ', $key) . '.'); }
    return trim($input[$key]);
}

function sale_id($value): int
{
    if (!is_string($value) || !ctype_digit($value) || strlen($value) > 18 || (int) $value < 1) {
        throw new InvalidArgumentException('Choose a valid record.');
    }
    return (int) $value;
}

function sale_decimal($value, int $scale, string $label, int $whole_digits = 11): string
{
    if (!is_string($value) || !preg_match('/\A[0-9]{1,' . $whole_digits . '}(?:\.[0-9]{1,' . $scale . '})?\z/', $value)) {
        throw new InvalidArgumentException($label . ' must be a nonnegative decimal with at most ' . $scale . ' decimal places.');
    }
    return bcadd($value, '0', $scale);
}

function sale_round(string $value): string { return bcadd($value, '0.005', 2); }
function sale_money($value): string { return dashboard_money(dashboard_cents($value)); }

function sale_filters(array $input): array
{
    $period = dashboard_period($input);
    $search = sale_text($input, 'q');
    $state = sale_text($input, 'state', 'posted');
    $payment = sale_text($input, 'payment', 'all');
    $sort = sale_text($input, 'sort', 'newest');
    if (mb_strlen($search) > 100 || !in_array($state, ['posted','draft','void','all'], true) ||
        !in_array($payment, ['all','paid','partial','unpaid'], true) || !in_array($sort, ['newest','oldest'], true)) {
        throw new InvalidArgumentException('Choose valid search and filter values.');
    }
    $page_value = sale_text($input, 'page', '1');
    if (!ctype_digit($page_value) || strlen($page_value) > 6 || (int) $page_value < 1) {
        throw new InvalidArgumentException('Choose a valid page.');
    }
    return ['period' => $period,'q' => $search,'state' => $state,'payment' => $payment,'sort' => $sort,'page' => (int) $page_value];
}

function sale_list(PDO $db, int $business_id, array $filters): array
{
    $where = 'f.business_id=? AND f.sold_at>=? AND f.sold_at<?';
    $values = [$business_id, $filters['period']['start']->format('Y-m-d'), $filters['period']['exclusive_end']];
    if ($filters['state'] !== 'all') { $where .= ' AND f.sale_status=?'; $values[] = $filters['state']; }
    if ($filters['q'] !== '') {
        $where .= " AND (LOCATE(?, f.sale_number)>0 OR LOCATE(?, COALESCE(c.customer_name,''))>0)";
        array_push($values, $filters['q'], $filters['q']);
    }
    $payment_conditions = ['paid' => 'f.balance_due=0','partial' => 'f.balance_due>0 AND f.total_paid>0','unpaid' => 'f.balance_due>0 AND f.total_paid=0'];
    if ($filters['payment'] !== 'all') { $where .= " AND f.sale_status='posted' AND " . $payment_conditions[$filters['payment']]; }
    $source = ' FROM v_sale_financials f LEFT JOIN customers c ON c.customer_id=f.customer_id AND c.business_id=f.business_id WHERE ' . $where;
    $statement = $db->prepare("SELECT COUNT(*) AS records,
        COALESCE(SUM(CASE WHEN f.sale_status='posted' THEN f.net_sale ELSE 0 END),0) AS sales,
        COALESCE(SUM(f.sale_status='posted' AND f.balance_due=0),0) AS completed,
        COALESCE(SUM(f.sale_status='posted' AND f.balance_due>0),0) AS pending" . $source);
    $statement->execute($values);
    $summary = $statement->fetch();
    $pages = max(1, (int) ceil((int) $summary['records'] / 15));
    $page = min($filters['page'], $pages);
    $offset = ($page - 1) * 15;
    $direction = $filters['sort'] === 'oldest' ? 'ASC' : 'DESC';
    $statement = $db->prepare('SELECT f.*, c.customer_name,
        (SELECT COUNT(*) FROM sale_items i WHERE i.sale_id=f.sale_id AND i.business_id=f.business_id) AS item_count,
        (SELECT due_date FROM customer_credits cr WHERE cr.sale_id=f.sale_id) AS due_date' . $source . " ORDER BY f.sold_at $direction, f.sale_id $direction LIMIT 15 OFFSET $offset");
    $statement->execute($values);
    $rows = $statement->fetchAll();
    $statement = $db->prepare("SELECT COALESCE(SUM(balance_due),0) FROM v_sale_financials WHERE business_id=? AND sale_status='posted' AND balance_due>0");
    $statement->execute([$business_id]);
    return ['rows' => $rows,'summary' => $summary,'outstanding' => $statement->fetchColumn(),'pages' => $pages,'page' => $page];
}

function sale_status(array $sale): string
{
    if ($sale['sale_status'] === 'draft') { return 'Draft'; }
    if ($sale['sale_status'] === 'void') { return 'Voided'; }
    return bccomp($sale['balance_due'], '0', 2) === 0 ? 'Fully paid' : (bccomp($sale['total_paid'], '0', 2) > 0 ? 'Partially paid' : 'Unpaid');
}

function sale_badge(array $sale): string
{
    return ['Fully paid' => 'paid','Partially paid' => 'partial','Unpaid' => 'unpaid','Draft' => 'draft','Voided' => 'refunded'][sale_status($sale)];
}

function sale_read(PDO $db, int $business_id, int $id, bool $lock = false): array
{
    if ($lock) {
        $statement = $db->prepare('SELECT sale_id FROM sales WHERE business_id=? AND sale_id=? FOR UPDATE');
        $statement->execute([$business_id,$id]);
        if (!$statement->fetchColumn()) { throw new SaleNotFound('Sale not found.'); }
    }
    $statement = $db->prepare('SELECT f.*, s.notes, s.updated_at, s.void_reason, s.voided_at,
        c.customer_name, c.phone, c.email, c.address, cr.due_date
        FROM v_sale_financials f JOIN sales s ON s.sale_id=f.sale_id AND s.business_id=f.business_id
        LEFT JOIN customers c ON c.customer_id=f.customer_id AND c.business_id=f.business_id
        LEFT JOIN customer_credits cr ON cr.sale_id=f.sale_id WHERE f.business_id=? AND f.sale_id=?');
    $statement->execute([$business_id,$id]);
    $sale = $statement->fetch();
    if (!$sale) { throw new SaleNotFound('Sale not found.'); }
    $statement = $db->prepare('SELECT * FROM sale_items WHERE business_id=? AND sale_id=? ORDER BY sale_item_id');
    $statement->execute([$business_id,$id]);
    $sale['items'] = $statement->fetchAll();
    $statement = $db->prepare('SELECT p.*, m.method_name,m.method_code FROM sale_payments p JOIN payment_methods m ON m.payment_method_id=p.payment_method_id WHERE p.business_id=? AND p.sale_id=? ORDER BY p.paid_at,p.payment_id');
    $statement->execute([$business_id,$id]);
    $sale['payments'] = $statement->fetchAll();
    return $sale;
}

function sale_revision(array $sale): string { return hash('sha256', json_encode($sale, JSON_THROW_ON_ERROR)); }

function sale_catalog(PDO $db, int $business_id): array
{
    $statement = $db->prepare('SELECT product_id,product_name,default_selling_price,default_unit_cost,is_active FROM products WHERE business_id=? ORDER BY product_name');
    $statement->execute([$business_id]);
    $products = $statement->fetchAll();
    $statement = $db->prepare('SELECT customer_id,customer_name,is_active FROM customers WHERE business_id=? ORDER BY customer_name');
    $statement->execute([$business_id]);
    return ['products' => $products,'customers' => $statement->fetchAll(),
        'methods' => payment_method_catalog($db)];
}

function sale_form_token(string $action, int $id = 0): array
{
    $_SESSION['sale_signing_key'] ??= bin2hex(random_bytes(32));
    $key = bin2hex(random_bytes(32));
    return ['request_key' => $key,'request_signature' => hash_hmac('sha256', "$action:$id:$key", $_SESSION['sale_signing_key'])];
}

function sale_valid_token(array $input, string $action, int $id = 0): bool
{
    $key = sale_text($input, 'request_key');
    $signature = sale_text($input, 'request_signature');
    return preg_match('/\A[a-f0-9]{64}\z/', $key) && !empty($_SESSION['sale_signing_key']) &&
        hash_equals(hash_hmac('sha256', "$action:$id:$key", $_SESSION['sale_signing_key']), $signature);
}

// All write helpers run inside the caller's transaction. The business lock also
// serializes edits, initial collections and refunds for this owner's business.
function sale_lock_business(PDO $db, array $user): void
{
    if (!$db->inTransaction()) { throw new LogicException('Sales writes require a transaction.'); }
    $statement = $db->prepare("SELECT b.business_id FROM businesses b JOIN users u ON u.user_id=b.owner_user_id JOIN roles r ON r.role_id=u.role_id WHERE b.business_id=? AND b.owner_user_id=? AND u.account_status='active' AND r.role_type='owner' FOR UPDATE");
    $statement->execute([$user['business_id'],$user['user_id']]);
    if (!$statement->fetchColumn()) { throw new InvalidArgumentException('Your business account is unavailable.'); }
}

function sale_calculate(PDO $db, array $user, array $input, ?array $existing = null): array
{
    $mode = sale_text($input, 'save_mode', 'posted');
    $plan = sale_text($input, 'payment_plan', 'full');
    if (!in_array($mode, ['posted','draft'], true) || !in_array($plan, ['full','partial','unpaid'], true)) { throw new InvalidArgumentException('Choose a valid sale and payment type.'); }
    $sold_at = sale_text($input, 'sold_at');
    $date = preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}\z/', $sold_at) ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $sold_at, new DateTimeZone('Asia/Manila')) : false;
    if (!$date || $date->format('Y-m-d\TH:i') !== $sold_at || (int) $date->format('Y') < 1000 || $date > new DateTimeImmutable('+1 minute', new DateTimeZone('Asia/Manila'))) { throw new InvalidArgumentException('Enter a valid sale date and time. Future dates are not allowed.'); }
    $customer_value = sale_text($input, 'customer_id');
    $customer_id = $customer_value === '' ? null : sale_id($customer_value);
    if ($customer_id) {
        $statement = $db->prepare('SELECT is_active FROM customers WHERE business_id=? AND customer_id=? FOR UPDATE');
        $statement->execute([$user['business_id'],$customer_id]);
        $active = $statement->fetchColumn();
        if ($active === false || (!(int) $active && (!$existing || (int) $existing['customer_id'] !== $customer_id))) { throw new InvalidArgumentException('Choose an active customer belonging to your business.'); }
    }
    if (($plan !== 'full' || ($existing && $existing['sale_type'] === 'credit')) && !$customer_id) { throw new InvalidArgumentException('Credit sales require an identified customer.'); }
    $rows = $input['items'] ?? null;
    if (!is_array($rows) || count($rows) < 1 || count($rows) > 50) { throw new InvalidArgumentException('Add between 1 and 50 sale items.'); }
    $old_items = [];
    foreach ($existing['items'] ?? [] as $item) { $old_items[$item['sale_item_id']] = $item; }
    $items = [];
    $subtotal = '0.00';
    $total_cost = '0.00';
    foreach ($rows as $row) {
        if (!is_array($row)) { throw new InvalidArgumentException('Invalid sale item.'); }
        $product_id = sale_id(sale_text($row, 'product_id'));
        $old_id = sale_text($row, 'sale_item_id');
        $old = $old_id !== '' ? ($old_items[sale_id($old_id)] ?? null) : null;
        if ($old_id !== '' && (!$old || (int) $old['product_id'] !== $product_id)) { throw new InvalidArgumentException('A sale item changed. Reload the form.'); }
        $statement = $db->prepare('SELECT * FROM products WHERE business_id=? AND product_id=? FOR UPDATE');
        $statement->execute([$user['business_id'],$product_id]);
        $product = $statement->fetch();
        if (!$product || (!$product['is_active'] && !$old)) { throw new InvalidArgumentException('Choose active products belonging to your business.'); }
        $quantity = sale_decimal(sale_text($row, 'quantity'), 3, 'Quantity', 9);
        $price = sale_decimal(sale_text($row, 'unit_price'), 2, 'Unit price');
        if (bccomp($quantity, '0', 3) <= 0) { throw new InvalidArgumentException('Quantities must be greater than zero.'); }
        $line_total = sale_round(bcmul($quantity, $price, 5));
        $subtotal = bcadd($subtotal, $line_total, 2);
        $cost = $old['unit_cost'] ?? $product['default_unit_cost'];
        $total_cost = bcadd($total_cost, sale_round(bcmul($quantity, $cost, 5)), 2);
        if (bccomp($subtotal, SALE_MAX_AMOUNT, 2) > 0 || bccomp($total_cost, SALE_MAX_AMOUNT, 2) > 0) { throw new InvalidArgumentException('The sale or its total cost exceeds the supported monetary limit.'); }
        $items[] = ['product_id' => $product_id,'product_name_snapshot' => $old['product_name_snapshot'] ?? $product['product_name'],
            'quantity' => $quantity,'unit_price' => $price,'unit_cost' => $old['unit_cost'] ?? $product['default_unit_cost']];
    }
    $discount_type = sale_text($input, 'discount_type', 'none');
    $discount_value = sale_decimal(sale_text($input, 'discount_value', '0'), 2, 'Discount');
    if (!in_array($discount_type, ['none','fixed','percent'], true) || ($discount_type === 'none' && bccomp($discount_value, '0', 2) !== 0)) { throw new InvalidArgumentException('Choose a valid discount type and amount.'); }
    if ($discount_type === 'fixed' && bccomp($discount_value, $subtotal, 2) > 0) { throw new InvalidArgumentException('Fixed discount cannot exceed the subtotal.'); }
    if ($discount_type === 'percent' && bccomp($discount_value, '100', 2) > 0) { throw new InvalidArgumentException('Percentage discount must be between 0 and 100.'); }
    $total = $discount_type === 'fixed' ? bcsub($subtotal, $discount_value, 2) : ($discount_type === 'percent' ? sale_round(bcmul($subtotal, bcsub('1', bcdiv($discount_value, '100', 4), 4), 6)) : $subtotal);
    if (bccomp($total, '0', 2) <= 0) { throw new InvalidArgumentException('The final sale total must be greater than zero.'); }
    $paid = sale_decimal(sale_text($input, 'initial_payment', '0'), 2, 'Payment');
    if (bccomp($paid, $total, 2) > 0) { throw new InvalidArgumentException('Payment cannot exceed the sale total.'); }
    if ($mode === 'draft' && bccomp($paid, '0', 2) !== 0) { throw new InvalidArgumentException('Drafts cannot record payments. Set the initial payment to zero.'); }
    if ($mode === 'posted' && (($plan === 'full' && bccomp($paid, $total, 2) !== 0) || ($plan === 'partial' && (bccomp($paid, '0', 2) <= 0 || bccomp($paid, $total, 2) >= 0)) || ($plan === 'unpaid' && bccomp($paid, '0', 2) !== 0))) { throw new InvalidArgumentException('The payment amount does not match the selected payment type.'); }
    $method_id = null; $provider_name=null;
    if (bccomp($paid, '0', 2) > 0) {
        $method=payment_method_data($db,$input);
        $method_id=$method['payment_method_id']; $provider_name=$method['provider_name'];
    }
    $due_date = sale_text($input, 'due_date');
    if ($plan === 'full') { $due_date = ''; }
    if ($due_date !== '' && (!dashboard_date($due_date) || $due_date < $date->format('Y-m-d'))) { throw new InvalidArgumentException('The due date must be valid and on or after the sale date.'); }
    $notes = sale_text($input, 'notes');
    $reference = sale_text($input, 'payment_reference');
    $payment_notes=sale_text($input,'payment_notes');
    if (!mb_check_encoding($payment_notes,'UTF-8') || mb_strlen($payment_notes)>4000) { throw new InvalidArgumentException('Payment notes may contain up to 4,000 valid characters.'); }
    if (mb_strlen($notes) > 4000 || mb_strlen($reference) > 100) { throw new InvalidArgumentException('Notes may contain up to 4,000 characters; payment references up to 100.'); }
    return ['items' => $items,'subtotal' => $subtotal,'total' => $total,'paid' => $paid,'mode' => $mode,
        'customer_id' => $customer_id,'sale_type' => ($existing && $existing['sale_type'] === 'credit') || $plan !== 'full' ? 'credit' : 'immediate',
        'discount_type' => $discount_type,'discount_value' => $discount_value,'sold_at' => $date->format('Y-m-d H:i:s'),
        'due_date' => $due_date ?: null,'notes' => $notes,'method_id' => $method_id,'provider_name'=>$provider_name,'payment_notes'=>$payment_notes,'reference' => $reference];
}

function sale_audit(PDO $db, array $user, int $id, string $action, array $before, array $after, string $entity = 'sale'): void
{
    $statement = $db->prepare('INSERT INTO activity_logs (actor_user_id,business_id,action_code,entity_type,entity_id,before_data,after_data) VALUES (?,?,?,?,?,?,?)');
    $statement->execute([$user['user_id'],$user['business_id'],$action,$entity,$id,json_encode($before, JSON_THROW_ON_ERROR),json_encode($after, JSON_THROW_ON_ERROR)]);
}

function sale_save_children(PDO $db, array $user, int $id, array $data, string $key): void
{
    $statement = $db->prepare('INSERT INTO sale_items (sale_id,business_id,product_id,product_name_snapshot,quantity,unit_price,unit_cost) VALUES (?,?,?,?,?,?,?)');
    foreach ($data['items'] as $item) { $statement->execute([$id,$user['business_id'],$item['product_id'],$item['product_name_snapshot'],$item['quantity'],$item['unit_price'],$item['unit_cost']]); }
    if ($data['sale_type'] === 'credit') {
        $db->prepare('INSERT INTO customer_credits (sale_id,due_date) VALUES (?,?) ON DUPLICATE KEY UPDATE due_date=VALUES(due_date)')->execute([$id,$data['due_date']]);
    }
    if (bccomp($data['paid'], '0', 2) > 0) {
        $db->prepare('INSERT INTO sale_payments (sale_id,business_id,payment_method_id,provider_name,entered_by,amount,paid_at,reference_number,idempotency_key,notes) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$id,$user['business_id'],$data['method_id'],$data['provider_name'],$user['user_id'],$data['paid'],$data['sold_at'],$data['reference']!==''?$data['reference']:null,'initial-' . $key,$data['payment_notes']!==''?$data['payment_notes']:null]);
    }
    if ($data['mode'] === 'posted') { $db->prepare("UPDATE sales SET sale_status='posted' WHERE sale_id=? AND business_id=?")->execute([$id,$user['business_id']]); }
    if ($data['mode']==='posted' && bccomp($data['paid'],'0',2)>0) {
        require_once __DIR__.'/notifications.php';
        $payment=$db->prepare('SELECT payment_id FROM sale_payments WHERE business_id=? AND idempotency_key=?'); $payment->execute([$user['business_id'],'initial-'.$key]);
        notification_payment_event($db,(int)$user['business_id'],(int)$payment->fetchColumn());
    }
}

function sale_create(PDO $db, array $user, array $input, string $key): int
{
    sale_lock_business($db, $user);
    if (!preg_match('/\A[a-f0-9]{64}\z/', $key)) { throw new InvalidArgumentException('Invalid submission key.'); }
    $reference = 'SL-' . strtoupper(substr($key, 0, 20));
    $statement = $db->prepare('SELECT sale_id FROM sales WHERE business_id=? AND sale_number=?');
    $statement->execute([$user['business_id'],$reference]);
    $existing_id = $statement->fetchColumn();
    if ($existing_id) { return (int) $existing_id; } // Safe replay after a lost response.
    $data = sale_calculate($db, $user, $input);
    $db->prepare('INSERT INTO sales (business_id,customer_id,created_by,sale_number,sale_type,sold_at,discount_type,discount_value,notes) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$user['business_id'],$data['customer_id'],$user['user_id'],$reference,$data['sale_type'],$data['sold_at'],$data['discount_type'],$data['discount_value'],$data['notes']]);
    $id = (int) $db->lastInsertId();
    sale_save_children($db, $user, $id, $data, $key);
    return $id;
}

function sale_edit(PDO $db, array $user, int $id, array $input, string $key): void
{
    sale_lock_business($db, $user);
    if (!preg_match('/\A[a-f0-9]{64}\z/', $key)) { throw new InvalidArgumentException('Invalid submission key.'); }
    $sale = sale_read($db, (int) $user['business_id'], $id, true);
    if (!hash_equals(sale_revision($sale), sale_text($input, 'revision'))) { throw new InvalidArgumentException('This sale changed in another request. Reload it before editing.'); }
    if ($sale['sale_status'] === 'void') { throw new InvalidArgumentException('Voided sales cannot be edited.'); }
    $notes = sale_text($input, 'notes');
    if (mb_strlen($notes) > 4000) { throw new InvalidArgumentException('Notes may contain up to 4,000 characters.'); }
    if ($sale['sale_status'] === 'posted') {
        $db->prepare('UPDATE sales SET notes=? WHERE business_id=? AND sale_id=?')->execute([$notes,$user['business_id'],$id]);
        sale_audit($db, $user, $id, 'SALE_NOTES_EDITED', ['notes' => $sale['notes']], ['notes' => $notes]);
        return;
    }
    if ($sale['payments']) { throw new InvalidArgumentException('This draft has payment history. Financial edits are blocked; void and recreate it if needed.'); }
    $data = sale_calculate($db, $user, $input, $sale);
    $db->prepare('UPDATE sales SET customer_id=?,sale_type=?,sold_at=?,discount_type=?,discount_value=?,notes=? WHERE business_id=? AND sale_id=?')->execute([$data['customer_id'],$data['sale_type'],$data['sold_at'],$data['discount_type'],$data['discount_value'],$notes,$user['business_id'],$id]);
    $db->prepare('DELETE FROM sale_items WHERE business_id=? AND sale_id=?')->execute([$user['business_id'],$id]);
    sale_save_children($db, $user, $id, $data, $key);
    sale_audit($db, $user, $id, 'SALE_DRAFT_EDITED', ['items' => $sale['items'],'notes' => $sale['notes'],'discount_type' => $sale['discount_type'],'discount_value' => $sale['discount_value']], $data);
}

function sale_void(PDO $db, array $user, int $id, array $input): void
{
    sale_lock_business($db, $user);
    $sale = sale_read($db, (int) $user['business_id'], $id, true);
    if ($sale['sale_status'] === 'void') { throw new InvalidArgumentException('This sale is already voided.'); }
    if (!hash_equals(sale_revision($sale), sale_text($input, 'revision'))) { throw new InvalidArgumentException('This sale or its payments changed. Reload the details before voiding.'); }
    $reason = sale_text($input, 'void_reason');
    if ($reason === '' || mb_strlen($reason) > 255) { throw new InvalidArgumentException('Provide a void reason of 1 to 255 characters.'); }
    if (bccomp($sale['total_paid'], '0', 2) > 0) {
        if (sale_text($input, 'refund_confirm') !== '1') { throw new InvalidArgumentException('Return the collected money and explicitly confirm the refunds before voiding this sale.'); }
        foreach ($sale['payments'] as $payment) {
            if ($payment['payment_kind'] !== 'collection') { continue; }
            $remaining = $payment['amount'];
            foreach ($sale['payments'] as $refund) {
                if ($refund['payment_kind'] === 'refund' && (int) $refund['reverses_payment_id'] === (int) $payment['payment_id']) { $remaining = bcsub($remaining, $refund['amount'], 2); }
            }
            if (bccomp($remaining, '0', 2) > 0) {
                $db->prepare("INSERT INTO sale_payments (sale_id,business_id,payment_method_id,provider_name,entered_by,payment_kind,amount,reverses_payment_id,idempotency_key,notes) VALUES (?,?,?,?,?,'refund',?,?,?,?)")->execute([$id,$user['business_id'],$payment['payment_method_id'],$payment['provider_name'],$user['user_id'],$remaining,$payment['payment_id'],'void-' . $id . '-' . $payment['payment_id'],$reason]);
            }
        }
    }
    $db->prepare("UPDATE sales SET sale_status='void',void_reason=?,voided_by=?,voided_at=CURRENT_TIMESTAMP WHERE business_id=? AND sale_id=?")->execute([$reason,$user['user_id'],$user['business_id'],$id]);
}

function sale_product_create(PDO $db, array $user, array $input, string $key): int
{
    sale_lock_business($db, $user);
    if (!preg_match('/\A[a-f0-9]{64}\z/', $key)) { throw new InvalidArgumentException('Invalid submission key.'); }
    $sku = 'REF-' . strtoupper(substr($key, 0, 24));
    $statement = $db->prepare('SELECT product_id FROM products WHERE business_id=? AND sku=?');
    $statement->execute([$user['business_id'],$sku]);
    if ($id = $statement->fetchColumn()) { return (int) $id; }
    $name = sale_text($input, 'product_name');
    if ($name === '' || mb_strlen($name) > 150) { throw new InvalidArgumentException('Product name must contain 1 to 150 characters.'); }
    $price = sale_decimal(sale_text($input, 'default_selling_price'), 2, 'Selling price');
    $cost = sale_decimal(sale_text($input, 'default_unit_cost'), 2, 'Unit cost');
    $db->prepare('INSERT INTO products (business_id,product_name,sku,default_selling_price,default_unit_cost) VALUES (?,?,?,?,?)')->execute([$user['business_id'],$name,$sku,$price,$cost]);
    $id = (int) $db->lastInsertId();
    sale_audit($db, $user, $id, 'PRODUCT_REFERENCE_CREATED', [], ['product_name' => $name,'price' => $price,'cost' => $cost], 'product');
    return $id;
}
