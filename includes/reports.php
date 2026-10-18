<?php
declare(strict_types=1);
require_once __DIR__.'/payments.php';

function report_period(array $input, ?DateTimeImmutable $today=null): array
{
    $today=($today??new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->setTimezone(new DateTimeZone('Asia/Manila'))->setTime(0,0);
    $choice=sale_text($input,'period','month');
    if ($choice==='year') {
        $period=dashboard_period(['period'=>'custom','start'=>$today->format('Y').'-01-01','end'=>$today->format('Y-m-d')],$today);
        $period['choice']='year'; return $period;
    }
    return dashboard_period($input,$today);
}
function report_filters(array $input, ?DateTimeImmutable $today=null): array
{
    $f=['period'=>report_period($input,$today),'tab'=>sale_text($input,'tab','sales'),'q'=>sale_text($input,'q'),
        'status'=>sale_text($input,'status','all'),'category'=>sale_text($input,'category'),'page'=>sale_text($input,'page','1')];
    if (!in_array($f['tab'],['sales','expenses','profit','utang'],true) || !mb_check_encoding($f['q'],'UTF-8') || mb_strlen($f['q'])>100 ||
        !in_array($f['status'],['all','paid','partial','unpaid','overdue'],true) || ($f['status']==='overdue' && $f['tab']!=='utang') ||
        !ctype_digit($f['page']) || strlen($f['page'])>6 || (int)$f['page']<1) { throw new InvalidArgumentException('Choose valid report filters.'); }
    if ($f['category']!=='') { $f['category']=(string)sale_id($f['category']); }
    $f['page']=(int)$f['page']; return $f;
}
function report_params(array $f): array
{
    return ['period'=>$f['period']['choice'],'start'=>$f['period']['start']->format('Y-m-d'),'end'=>$f['period']['end']->format('Y-m-d'),
        'tab'=>$f['tab'],'q'=>$f['q'],'status'=>$f['status'],'category'=>$f['category'],'page'=>$f['page']];
}
function report_url(array $f, array $changes=[]): string { return app_url('user/reports.php').'?'.http_build_query(array_replace(report_params($f),$changes)); }
function report_status(array $row): string { return utang_status($row); }
function report_query(PDO $db, string $sql, array $values=[]): PDOStatement { return customer_query($db,$sql,$values); }

// The overview always covers the entire period. Search/status/category affect only
// the selected table and its CSV/print output, never silently change profit cards.
function report_data(PDO $db, int $business, array $f): array
{
    $p=$f['period']; $start=$p['start']->format('Y-m-d'); $end=$p['end']->format('Y-m-d');
    $totals=dashboard_totals($db,$business,$start,$end); $totals['gross_profit']=$totals['sales']-$totals['cogs'];
    $sales=report_query($db,"SELECT COALESCE(SUM(f.subtotal),0) AS revenue,COALESCE(SUM(f.subtotal-f.net_sale),0) AS discounts,
        COUNT(*) AS transactions,COALESCE(SUM(f.balance_due=0),0) AS paid,
        COALESCE(SUM(f.balance_due>0 AND f.total_paid>0),0) AS partial,COALESCE(SUM(f.balance_due>0 AND f.total_paid=0),0) AS unpaid
        FROM v_sale_financials f WHERE f.business_id=? AND f.sale_status='posted' AND f.sold_at>=? AND f.sold_at<?",[$business,$start,$p['exclusive_end']])->fetch();
    $categories=report_query($db,"SELECT c.expense_category_id,c.category_name,COUNT(*) AS expenses,SUM(e.amount) AS amount
        FROM expenses e JOIN expense_categories c ON c.expense_category_id=e.expense_category_id AND c.business_id=e.business_id
        WHERE e.business_id=? AND e.expense_status='posted' AND e.expense_treatment='operating' AND e.expense_date BETWEEN ? AND ?
        GROUP BY c.expense_category_id,c.category_name ORDER BY amount DESC,c.category_name",[$business,$start,$end])->fetchAll();
    $expense_count=array_sum(array_column($categories,'expenses'));
    $credit=report_query($db,"SELECT COALESCE(SUM(f.balance_due),0) AS outstanding,
        COALESCE(SUM(CASE WHEN cr.due_date<? THEN f.balance_due ELSE 0 END),0) AS overdue,
        COUNT(DISTINCT CASE WHEN f.balance_due>0 THEN f.customer_id END) AS customers".payment_balance_source(),[date('Y-m-d'),$business])->fetch();
    $credit['collected']=report_query($db,"SELECT COALESCE(SUM(CASE p.payment_kind WHEN 'collection' THEN p.amount ELSE -p.amount END),0)
        FROM sale_payments p JOIN sales s ON s.sale_id=p.sale_id AND s.business_id=p.business_id JOIN customer_credits cr ON cr.sale_id=s.sale_id
        WHERE p.business_id=? AND s.sale_type='credit' AND s.sale_status IN ('posted','void') AND p.paid_at>=? AND p.paid_at<?",[$business,$start,$p['exclusive_end']])->fetchColumn();
    $cash=dashboard_cents(report_query($db,'SELECT COALESCE(SUM(cash_received),0) FROM v_daily_financials WHERE business_id=? AND activity_date BETWEEN ? AND ?',[$business,$start,$end])->fetchColumn());
    $zero_cost=(int)report_query($db,"SELECT COUNT(*) FROM sale_items i JOIN sales s ON s.sale_id=i.sale_id AND s.business_id=i.business_id
        WHERE s.business_id=? AND s.sale_status='posted' AND s.sold_at>=? AND s.sold_at<? AND i.unit_cost=0",[$business,$start,$p['exclusive_end']])->fetchColumn();
    $daily=report_query($db,'SELECT activity_date,net_sales,operating_expenses,net_profit FROM v_daily_financials WHERE business_id=? AND activity_date BETWEEN ? AND ? ORDER BY activity_date',[$business,$start,$end])->fetchAll();
    $chart=dashboard_chart($daily,$p); $profit=[];
    for ($d=$p['start'];$d<=$p['end'];$d=$d->modify('+1 day')) { $profit[report_chart_key($d,$chart['grouping'])]=0; }
    foreach ($daily as $row) { $profit[report_chart_key(dashboard_date($row['activity_date']),$chart['grouping'])]+=dashboard_cents($row['net_profit']); }
    $chart['profit']=array_map(fn($c)=>$c/100,array_values($profit));
    $products=report_products($db,$business,$p);
    $collections=payment_collection_totals($db,$business,$p);
    return compact('totals','sales','categories','expense_count','credit','cash','zero_cost','chart','products','collections');
}
function report_chart_key(DateTimeImmutable $d, string $grouping): string
{
    return ($grouping==='weekly'?$d->modify('monday this week'):($grouping==='monthly'?$d->modify('first day of this month'):$d))->format('Y-m-d');
}

// Allocate each sale's discounted net amount proportionally to its rounded item
// amounts. Largest remainders receive leftover centavos, so no money is lost or
// created through rounding. Historical names/costs come only from sale_items.
function report_allocate(array $items, int $net, int $subtotal): array
{
    $allocated=[]; $remainders=[]; $assigned=0;
    foreach ($items as $index=>$item) {
        $numerator=bcmul((string)$net,(string)dashboard_cents($item['line_sales']),0);
        $cents=$subtotal>0?(int)bcdiv($numerator,(string)$subtotal,0):0;
        $allocated[$index]=$cents; $assigned+=$cents;
        $remainders[$index]=$subtotal>0?bcmod($numerator,(string)$subtotal,0):'0';
    }
    uksort($remainders,fn($a,$b)=>bccomp($remainders[$b],$remainders[$a],0)?:($a<=>$b));
    $remaining=$net-$assigned;
    foreach ($remainders as $index=>$unused) { if ($remaining<=0) { break; } $allocated[$index]++; $remaining--; }
    return $allocated;
}
function report_products(PDO $db, int $business, array $p): array
{
    $statement=report_query($db,"SELECT i.product_id,i.product_name_snapshot,i.quantity,ROUND(i.quantity*i.unit_price,2) AS line_sales,
        ROUND(i.quantity*i.unit_cost,2) AS line_cost,f.sale_id,f.net_sale,f.subtotal
        FROM sale_items i JOIN v_sale_financials f ON f.sale_id=i.sale_id AND f.business_id=i.business_id
        WHERE f.business_id=? AND f.sale_status='posted' AND f.sold_at>=? AND f.sold_at<?
        ORDER BY f.sold_at DESC,f.sale_id DESC,i.sale_item_id",[$business,$p['start']->format('Y-m-d'),$p['exclusive_end']]);
    $products=[]; $items=[]; $sale=null;
    $add=function(array $items) use (&$products): void {
        if (!$items) { return; }
        $allocations=report_allocate($items,dashboard_cents($items[0]['net_sale']),dashboard_cents($items[0]['subtotal']));
        foreach ($items as $index=>$item) {
            $id=$item['product_id'];
            if (!isset($products[$id])) { $products[$id]=['product_id'=>$id,'name'=>$item['product_name_snapshot'],'quantity'=>'0.000','sales'=>0,'cogs'=>0,'profit'=>0]; }
            $products[$id]['quantity']=bcadd($products[$id]['quantity'],$item['quantity'],3);
            $products[$id]['sales']+=$allocations[$index]; $products[$id]['cogs']+=dashboard_cents($item['line_cost']);
            $products[$id]['profit']=$products[$id]['sales']-$products[$id]['cogs'];
        }
    };
    while ($row=$statement->fetch()) { if ($sale!==null && $sale!==$row['sale_id']) { $add($items); $items=[]; } $sale=$row['sale_id']; $items[]=$row; }
    $add($items);
    usort($products,fn($a,$b)=>($b['sales']<=>$a['sales'])?:bccomp($b['quantity'],$a['quantity'],3)?:($a['product_id']<=>$b['product_id']));
    return $products;
}
function report_source(int $business, array $f): array
{
    $p=$f['period']; $values=[$business];
    if ($f['tab']==='expenses') {
        $select='SELECT e.expense_id,e.expense_date,c.category_name,e.description,e.amount,m.method_name';
        $source=" FROM expenses e JOIN expense_categories c ON c.expense_category_id=e.expense_category_id AND c.business_id=e.business_id
            JOIN payment_methods m ON m.payment_method_id=e.payment_method_id WHERE e.business_id=? AND e.expense_status='posted' AND e.expense_treatment='operating' AND e.expense_date BETWEEN ? AND ?";
        array_push($values,$p['start']->format('Y-m-d'),$p['end']->format('Y-m-d'));
        if ($f['q']!=='') { $source.=' AND (LOCATE(?,e.description)>0 OR LOCATE(?,c.category_name)>0)'; array_push($values,$f['q'],$f['q']); }
        if ($f['category']!=='') { $source.=' AND e.expense_category_id=?'; $values[]=$f['category']; }
        $order='e.expense_date DESC,e.expense_id DESC';
    } else {
        $select="SELECT f.*,COALESCE(c.customer_name,'Walk-in Customer') AS customer_name";
        if ($f['tab']==='utang') { $select.=',cr.due_date'; $source=payment_balance_source()." AND (f.sale_type='credit' OR f.balance_due>0)"; $order='(f.balance_due>0) DESC,cr.due_date IS NULL,cr.due_date,f.sold_at DESC,f.sale_id DESC'; }
        else {
            $source=" FROM v_sale_financials f LEFT JOIN customers c ON c.customer_id=f.customer_id AND c.business_id=f.business_id WHERE f.business_id=? AND f.sale_status='posted' AND f.sold_at>=? AND f.sold_at<?";
            array_push($values,$p['start']->format('Y-m-d'),$p['exclusive_end']); $order='f.sold_at DESC,f.sale_id DESC';
        }
        if ($f['q']!=='') { $source.=" AND (LOCATE(?,f.sale_number)>0 OR LOCATE(?,COALESCE(c.customer_name,'Walk-in customer'))>0)"; array_push($values,$f['q'],$f['q']); }
        $states=['paid'=>'f.balance_due=0','partial'=>'f.balance_due>0 AND f.total_paid>0','unpaid'=>'f.balance_due>0 AND f.total_paid=0','overdue'=>'f.balance_due>0 AND cr.due_date<?'];
        if ($f['status']!=='all') { $source.=' AND '.$states[$f['status']]; if ($f['status']==='overdue') { $values[]=date('Y-m-d'); } }
    }
    return compact('select','source','order','values');
}
function report_listing(PDO $db, int $business, array $f, bool $all=false): array
{
    if ($f['tab']==='profit') { return ['rows'=>[],'count'=>0,'page'=>1,'pages'=>1]; }
    $s=report_source($business,$f);
    $count=(int)report_query($db,'SELECT COUNT(*)'.$s['source'],$s['values'])->fetchColumn(); $pages=max(1,(int)ceil($count/15)); $page=min($f['page'],$pages);
    $limit=$all?'':' LIMIT 15 OFFSET '.(($page-1)*15);
    $rows=report_query($db,$s['select'].$s['source'].' ORDER BY '.$s['order'].$limit,$s['values'])->fetchAll();
    return compact('rows','count','pages','page');
}
function report_profit_lines(array $d): array
{
    return [
        ['Sales before discounts',dashboard_cents($d['sales']['revenue']),'Recorded item prices before transaction discounts.'],
        ['Less: discounts',-dashboard_cents($d['sales']['discounts']),'Already included in Total Sales below.'],
        ['Net sales',$d['totals']['sales'],'Posted sales after discounts; customer repayments do not add revenue.'],
        ['Less: product costs',-$d['totals']['cogs'],'Cost of goods sold uses recorded item quantities and historical costs.'],
        ['Gross profit',$d['totals']['gross_profit'],'Sales after discounts minus the cost of products sold.'],
        ['Less: operating expenses',-$d['totals']['expenses'],'Running costs only; inventory purchases and other cash outflows are excluded.'],
        ['Estimated net profit',$d['totals']['profit'],'Gross profit minus operating expenses, based on recorded costs.'],
    ];
}
function report_decimal(int $cents): string { return ($cents<0?'-':'').intdiv(abs($cents),100).'.'.str_pad((string)(abs($cents)%100),2,'0',STR_PAD_LEFT); }
function report_csv_cell($value): string
{
    $value=(string)$value;
    // Protect user-controlled names/descriptions, including whitespace-prefixed
    // formulas. Leading control characters also become literal spreadsheet text.
    return preg_match('/\A(?:[\s\x{FEFF}]*[=+\-@]|[\t\r\n])/u',$value)?"'".$value:$value;
}
function report_csv(PDO $db, int $business, string $name, array $f, $stream): void
{
    $write=function(array $cells) use ($stream): void { fputcsv($stream,array_map('report_csv_cell',$cells),',','"','',"\r\n"); };
    $write(['ProfitPocket',($f['tab']==='utang'?'Customer Receivables':ucfirst($f['tab']).' report')]); $write(['Business',$name]);
    $write(['Reporting period',$f['period']['start']->format('Y-m-d'),$f['period']['end']->format('Y-m-d'),'Asia/Manila']);
    $write(['Balance scope',$f['tab']==='utang'?'Current balances across all sale dates; collections use payment dates in reporting period':'Posted transaction dates in reporting period']);
    $write(['Search',$f['q'],'Payment status',$f['status'],'Expense category ID',$f['category']]);
    $write(['Currency','PHP','Amounts use two decimal places']);
    if ($f['tab']==='profit') {
        $d=report_data($db,$business,$f); $write(['Financial breakdown','Amount (PHP)','Explanation']);
        foreach (report_profit_lines($d) as [$label,$amount,$note]) { $write([$label,report_decimal($amount),$note]); }
        $write(['Payments received less refunds',report_decimal($d['cash']),'Cash received by payment date; separate from profit.']);
        $write(['Zero-cost item warning',$d['zero_cost'],'Profit may be overstated when recorded costs are zero.']);
        $write([]); $write(['Top-Selling Products','Quantity sold','Sales after allocated discounts (PHP)','Estimated gross profit (PHP)']);
        foreach (array_slice($d['products'],0,5) as $product) { $write([$product['name'],$product['quantity'],report_decimal($product['sales']),report_decimal($product['profit'])]); }
        return;
    }
    if ($f['tab']==='utang') {
        $d=report_data($db,$business,$f); $write(['Whole-business credit collections less refunds (selected payment dates)', $d['credit']['collected']]);
        $write(['Customer','Transaction reference','Original sale amount (PHP)','Amount paid less refunds (PHP)','Current balance (PHP)','Due date','Status']);
    } elseif ($f['tab']==='sales') { $write(['Transaction date','Transaction reference','Customer','Net amount (PHP)','Payment status (current)']); }
    else { $write(['Date','Category','Description','Amount (PHP)','Payment method']); }
    $s=report_source($business,$f); $statement=report_query($db,$s['select'].$s['source'].' ORDER BY '.$s['order'],$s['values']);
    while ($row=$statement->fetch()) {
        $cells=match($f['tab']) {
            'expenses'=>[$row['expense_date'],$row['category_name'],$row['description'],$row['amount'],$row['method_name']],
            'sales'=>[$row['sold_at'],$row['sale_number'],$row['customer_name']??'Walk-in customer',$row['net_sale'],report_status($row)],
            'utang'=>[$row['customer_name'],$row['sale_number'],$row['net_sale'],$row['total_paid'],$row['balance_due'],$row['due_date']??'',report_status($row).(utang_overdue($row)?' / Overdue':'')],
        }; $write($cells);
    }
}
