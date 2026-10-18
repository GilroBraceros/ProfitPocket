<?php
declare(strict_types=1);

// Dashboard-only helpers; the existing authentication and connection are reused.
function dashboard_date(string $value): ?DateTimeImmutable
{
    if (!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $value)) { return null; }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Manila'));
    return $date && $date->format('Y-m-d') === $value && (int) $date->format('Y') >= 1000 ? $date : null;
}

function dashboard_period(array $input, ?DateTimeImmutable $today = null): array
{
    $today = ($today ?? new DateTimeImmutable('today', new DateTimeZone('Asia/Manila')))->setTime(0, 0);
    if (isset($input['period']) && !is_string($input['period'])) {
        throw new InvalidArgumentException('Choose a valid reporting period.');
    }
    $choice = isset($input['period']) && is_string($input['period']) ? $input['period'] : 'month';
    if (!in_array($choice, ['today', 'week', 'month', 'custom'], true)) {
        throw new InvalidArgumentException('Choose a valid reporting period.');
    }
    $end = $today;
    $start = $today;
    if ($choice === 'week') {
        $start = $today->modify('monday this week');
    } elseif ($choice === 'month') {
        $start = $today->modify('first day of this month');
    } elseif ($choice === 'custom') {
        $start = isset($input['start']) && is_string($input['start']) ? dashboard_date($input['start']) : null;
        $end = isset($input['end']) && is_string($input['end']) ? dashboard_date($input['end']) : null;
        if (!$start || !$end) {
            throw new InvalidArgumentException('Enter valid start and end dates.');
        }
        if ($start > $end) {
            throw new InvalidArgumentException('The start date must be on or before the end date.');
        }
    }
    $days = (int) $start->diff($end)->days + 1;
    if ($days > 366) {
        throw new InvalidArgumentException('Choose a date range of up to 366 days.');
    }
    $previous_end = $start->modify('-1 day');
    $previous_start = $start->modify('-' . $days . ' days');
    if ((int) $previous_start->format('Y') < 1000 || (int) $end->format('Y') > 9998) {
        throw new InvalidArgumentException('Choose dates within the supported reporting range.');
    }
    $label = $start == $end ? $start->format('j M Y') : $start->format('j M') . ' – ' . $end->format('j M Y');
    return [
        'choice' => $choice, 'start' => $start, 'end' => $end, 'days' => $days,
        'label' => $label, 'previous_start' => $previous_start, 'previous_end' => $previous_end,
        'exclusive_end' => $end->modify('+1 day')->format('Y-m-d'),
    ];
}

// Use integer centavos for presentation arithmetic rather than binary float sums.
function dashboard_cents($decimal): int
{
    $value = (string) ($decimal ?? '0.00');
    $parts = explode('.', ltrim($value, '-'), 2);
    $cents = (int) $parts[0] * 100 + (int) substr(str_pad($parts[1] ?? '', 2, '0'), 0, 2);
    return str_starts_with($value, '-') ? -$cents : $cents;
}

function dashboard_money(int $cents): string
{
    $amount = abs($cents);
    return ($cents < 0 ? '−' : '') . '₱' . number_format(intdiv($amount, 100)) . '.' . str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
}

function dashboard_icon(string $name, string $class = ''): string
{
    return '<svg class="pp-icon ' . e($class) . '" width="18" height="18" aria-hidden="true" focusable="false"><use href="' . e(app_url('assets/images/bootstrap-icons.svg') . '#' . $name) . '"></use></svg>';
}

function dashboard_totals(PDO $connection, int $business_id, string $start, string $end): array
{
    $statement = $connection->prepare(
        'SELECT COALESCE(SUM(net_sales), 0) AS sales, COALESCE(SUM(operating_expenses), 0) AS expenses,
                COALESCE(SUM(cost_of_goods_sold), 0) AS cogs, COALESCE(SUM(net_profit), 0) AS profit
         FROM v_daily_financials WHERE business_id = ? AND activity_date BETWEEN ? AND ?'
    );
    $statement->execute([$business_id, $start, $end]);
    return array_map('dashboard_cents', $statement->fetch());
}

function dashboard_comparison(int $current, int $previous): string
{
    if ($previous === 0) {
        return $current === 0 ? 'No change from previous period' : 'No previous amount to compare';
    }
    $change = ($current - $previous) / abs($previous) * 100;
    return abs($change) < 0.05 ? 'No change from previous period' : ($change > 0 ? '+' : '−') . number_format(abs($change), 1) . '% vs previous period';
}

function dashboard_chart(array $daily, array $period): array
{
    $grouping = $period['days'] <= 31 ? 'daily' : ($period['days'] <= 120 ? 'weekly' : 'monthly');
    $groups = [];
    for ($date = $period['start']; $date <= $period['end']; $date = $date->modify('+1 day')) {
        $key_date = $grouping === 'weekly' ? $date->modify('monday this week') : ($grouping === 'monthly' ? $date->modify('first day of this month') : $date);
        $key = $key_date->format('Y-m-d');
        if (!isset($groups[$key])) {
            $visible_date = $key_date < $period['start'] ? $period['start'] : $key_date;
            $groups[$key] = ['label' => $visible_date->format($grouping === 'monthly' ? 'M Y' : 'j M'), 'sales' => 0, 'expenses' => 0];
        }
    }
    foreach ($daily as $row) {
        $date = dashboard_date($row['activity_date']);
        $key_date = $grouping === 'weekly' ? $date->modify('monday this week') : ($grouping === 'monthly' ? $date->modify('first day of this month') : $date);
        $key = $key_date->format('Y-m-d');
        $groups[$key]['sales'] += dashboard_cents($row['net_sales']);
        $groups[$key]['expenses'] += dashboard_cents($row['operating_expenses']);
    }
    return [
        'grouping' => $grouping, 'labels' => array_column($groups, 'label'),
        'sales' => array_map(fn($value) => $value / 100, array_column($groups, 'sales')),
        'expenses' => array_map(fn($value) => $value / 100, array_column($groups, 'expenses')),
        'has_activity' => array_sum(array_column($groups, 'sales')) !== 0 || array_sum(array_column($groups, 'expenses')) !== 0,
    ];
}

function dashboard_data(PDO $connection, int $business_id, array $period): array
{
    $start = $period['start']->format('Y-m-d');
    $end = $period['end']->format('Y-m-d');
    $totals = dashboard_totals($connection, $business_id, $start, $end);
    $previous = dashboard_totals($connection, $business_id, $period['previous_start']->format('Y-m-d'), $period['previous_end']->format('Y-m-d'));
    // The credit view includes drafts, so enforce posted status in the application.
    $statement = $connection->prepare(
        "SELECT COALESCE(SUM(c.balance_due), 0) AS balance, COUNT(*) AS open_credits
         FROM v_sale_financials c
         WHERE c.business_id=? AND c.sale_status='posted' AND c.net_sale>0 AND c.balance_due>0"
    );
    $statement->execute([$business_id]);
    $credit = $statement->fetch();
    $statement = $connection->prepare('SELECT activity_date, net_sales, operating_expenses FROM v_daily_financials WHERE business_id=? AND activity_date BETWEEN ? AND ? ORDER BY activity_date');
    $statement->execute([$business_id, $start, $end]);
    $chart = dashboard_chart($statement->fetchAll(), $period);
    $statement = $connection->prepare("SELECT COUNT(*) FROM sale_items i JOIN sales s ON s.sale_id=i.sale_id AND s.business_id=i.business_id WHERE s.business_id=? AND s.sale_status='posted' AND s.sold_at>=? AND s.sold_at<? AND i.unit_cost=0");
    $statement->execute([$business_id, $start, $period['exclusive_end']]);
    $zero_cost_items = (int) $statement->fetchColumn();
    // Each UNION branch returns one activity. Collections never enter sales totals.
    $statement = $connection->prepare(
        "SELECT * FROM (
            SELECT f.sale_id AS event_id, f.sale_number AS reference, 'Sale' AS type,
                   COALESCE(c.customer_name, 'Walk-in customer') AS description, f.net_sale AS amount,
                   f.sold_at AS event_date,
                   CASE WHEN f.balance_due=0 THEN 'Paid' WHEN f.total_paid>0 THEN 'Partial' ELSE 'Unpaid' END AS status,
                   CASE WHEN f.sale_type='credit' THEN 'Credit sale' ELSE 'Immediate sale' END AS context
            FROM v_sale_financials f LEFT JOIN customers c ON c.customer_id=f.customer_id AND c.business_id=f.business_id
            WHERE f.business_id=? AND f.sale_status='posted' AND f.net_sale>0 AND f.sold_at>=? AND f.sold_at<?
            UNION ALL
            SELECT e.expense_id, CONCAT('EXP-', e.expense_id), 'Expense', e.description, e.amount,
                   CAST(e.expense_date AS DATETIME), 'Paid',
                   CASE e.expense_treatment WHEN 'inventory_purchase' THEN 'Inventory purchase · cash outflow'
                   WHEN 'other_cash_outflow' THEN 'Other cash outflow' ELSE 'Operating expense' END
            FROM expenses e WHERE e.business_id=? AND e.expense_status='posted' AND e.amount>0 AND e.expense_date BETWEEN ? AND ?
            UNION ALL
            SELECT p.payment_id, COALESCE(NULLIF(p.reference_number, ''), CONCAT('PAY-', p.payment_id)),
                   CASE WHEN p.payment_kind='refund' THEN 'Payment refund' ELSE 'Customer payment' END,
                   COALESCE(c.customer_name, 'Customer unavailable'),
                   CASE WHEN p.payment_kind='refund' THEN -p.amount ELSE p.amount END, p.paid_at,
                   CASE WHEN p.payment_kind='refund' THEN 'Refunded' ELSE 'Received' END,
                   CONCAT('Payment activity · ', s.sale_number)
            FROM sale_payments p JOIN sales s ON s.sale_id=p.sale_id AND s.business_id=p.business_id
            LEFT JOIN customers c ON c.customer_id=s.customer_id AND c.business_id=s.business_id
            WHERE p.business_id=? AND s.sale_status IN ('posted','void') AND p.amount>0 AND p.paid_at>=? AND p.paid_at<?
         ) activities ORDER BY event_date DESC, type, event_id DESC LIMIT 10"
    );
    $statement->execute([$business_id, $start, $period['exclusive_end'], $business_id, $start, $end, $business_id, $start, $period['exclusive_end']]);
    return [
        'totals' => $totals, 'previous' => $previous, 'outstanding' => dashboard_cents($credit['balance']),
        'open_credits' => (int) $credit['open_credits'], 'chart' => $chart,
        'transactions' => $statement->fetchAll(), 'zero_cost_items' => $zero_cost_items,
    ];
}
